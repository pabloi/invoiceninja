<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Services\EDocument\Standards\CfeUy;

use App\Models\CfeLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Activity;
use App\Models\SystemLog;
use App\Libraries\MultiDB;
use Illuminate\Bus\Queueable;
use App\Jobs\Util\SystemLogger;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendToCfeUy implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 5;

    public $uniqueFor = 12600;

    public $deleteWhenMissingModels = true;

    public function __construct(private int $invoice_id, private Company $company)
    {
    }

    public function backoff()
    {
        return [5, 30, 240, 3600, 7200];
    }

    public function uniqueId(): string
    {
        return "{$this->company->company_key}:{$this->invoice_id}";
    }

    public function handle()
    {
        MultiDB::setDB($this->company->db);

        // Commit the result before releasing mail or asking the queue to retry.
        $error = DB::connection()->transaction(function () {
            $invoice = Invoice::withTrashed()->where('company_id', $this->company->id)
                ->lockForUpdate()->find($this->invoice_id);

            if (!$invoice || $invoice->is_deleted || $invoice->status_id == Invoice::STATUS_DRAFT) {
                return null;
            }

            if (strlen($invoice->backup->guid ?? '') >= 1) {
                return null;
            }

            // Kill switch: never call xml-cfe while disabled. Mail stays held until a retry.
            if (!config('services.cfe_uy.enabled')) {
                $invoice->backup->cfe_status = 'failed';
                $invoice->backup->cfe_error = 'CFE emission is disabled (CFE_UY_ENABLED=false). Email is held until a retry after it is enabled.';
                $invoice->saveQuietly();
                return null;
            }

            $invoice->backup->cfe_status = 'pending';
            $invoice->backup->cfe_error = null;
            $invoice->saveQuietly();

            return $this->emitCfe($invoice);
        });

        if ($error) {
            throw $error;
        }

        $invoice = Invoice::withTrashed()->where('company_id', $this->company->id)->find($this->invoice_id);
        if ($invoice && !$invoice->is_deleted && strlen($invoice->backup->guid ?? '') >= 1) {
            $invoice->service()->deletePdf();
            $this->releaseEmails($invoice);
        }
    }

    private function emitCfe(Invoice $invoice): ?\Throwable
    {
        $payload = [];

        $client = new Client();
        $processor = new ResponseProcessor();

        try {
            $payload = (new Mapper($invoice))->toPayload();
            $httpResponse = $client->emit($payload);
            $result = $processor->process($httpResponse);
        } catch (\Throwable $e) {
            nlog("CFE_UY: Exception during emit for invoice {$invoice->number}: {$e->getMessage()}");

            $this->persistLog($invoice, $payload, null, 'ERROR', $e->getMessage());
            $this->writeActivity($invoice, Activity::CFE_UY_INVOICE_SENT_FAILURE, $e->getMessage());
            $this->systemLog($invoice, ['error' => $e->getMessage()], SystemLog::EVENT_CFE_UY_FAILURE, SystemLog::TYPE_CFE_UY_SEND);

            $invoice->backup->cfe_error = $e->getMessage();
            $invoice->saveQuietly();
            return $e; // Retry only after the failure log has committed.
        }

        // Persist the log regardless of success/failure
        $this->persistLog($invoice, $payload, $result);

        if ($result['success']) {
            $this->handleSuccess($invoice, $result);
            return null;
        }

        $this->handleFailure($invoice, $result);
        return $result['retryable'] ? new \RuntimeException($result['message']) : null;
    }

    private function handleSuccess(Invoice $invoice, array $result): void
    {
        // Set idempotency marker
        $invoice->backup->guid = (string) $result['cfe']['cae_id'];
        $invoice->backup->cfe_tipo = $result['cfe']['tipo'] ?? null;
        $invoice->backup->cfe_serie = $result['cfe']['serie'] ?? null;
        $invoice->backup->cfe_numero = $result['cfe']['numero'] ?? null;
        $invoice->backup->cfe_link_qr = ($result['cfe']['link_qr'] ?? '') ?: null;
        $invoice->backup->cfe_status = 'emitted';
        $invoice->backup->cfe_error = null;
        $invoice->saveQuietly();

        $message = $result['message'] ?? 'CFE emitted successfully';
        $this->writeActivity($invoice, Activity::CFE_UY_INVOICE_SENT, $message);
        $this->systemLog($invoice, $result['raw'] ?? $result, SystemLog::EVENT_CFE_UY_SUCCESS, SystemLog::TYPE_CFE_UY_SEND);

    }

    private function releaseEmails(Invoice $invoice): void
    {
        // Release primed email invitations
        $invoice->invitations()
            ->where('email_error', 'primed')
            ->whereHas('contact', function ($query) {
                $query->where(function ($sq) {
                    $sq->whereNotNull('email')
                        ->where('email', '!=', '');
                })->where('is_locked', false)
                ->withoutTrashed();
            })->each(function ($invitation) {
                $invitation->invoice->service()->sendEmail($invitation->contact);
                $invitation->email_error = '';
                $invitation->saveQuietly();
            });

        foreach ($invoice->backup->cfe_pending_payment_ids as $payment_id) {
            $payment = \App\Models\Payment::where('company_id', $invoice->company_id)->find($payment_id);
            if ($payment && !$payment->is_deleted) {
                $payment->service()->sendEmail();
            }
            $invoice->backup->cfe_pending_payment_ids = array_values(array_diff($invoice->backup->cfe_pending_payment_ids, [$payment_id]));
            $invoice->saveQuietly();
        }
    }

    private function handleFailure(Invoice $invoice, array $result): void
    {
        $message = $result['message'] ?? 'CFE emission failed';
        $this->writeActivity($invoice, Activity::CFE_UY_INVOICE_SENT_FAILURE, $message);
        $this->systemLog($invoice, $result['raw'] ?? $result, SystemLog::EVENT_CFE_UY_FAILURE, SystemLog::TYPE_CFE_UY_SEND);

        $invoice->backup->cfe_status = $result['retryable'] ? 'pending' : 'rejected';
        $invoice->backup->cfe_error = $message;
        $invoice->saveQuietly();
    }

    private function persistLog(Invoice $invoice, array $requestPayload, ?array $result, ?string $overrideStatus = null, ?string $overrideMessage = null): void
    {
        $cfe = $result['cfe'] ?? null;

        CfeLog::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'external_invoice_id' => $invoice->hashed_id,
            'direction' => 'sent',
            'provider_status' => $overrideStatus ?? ($result['status'] ?? 'ERROR'),
            'provider_code' => $result['provider']['codigo'] ?? null,
            'provider_description' => $overrideMessage ?? ($result['provider']['descripcion'] ?? null),
            'cfe_tipo' => $cfe['tipo'] ?? null,
            'cfe_serie' => $cfe['serie'] ?? null,
            'cfe_numero' => $cfe['numero'] ?? null,
            'cae_id' => $cfe['cae_id'] ?? null,
            'cae_dnro' => $cfe['cae_dnro'] ?? null,
            'cae_hnro' => $cfe['cae_hnro'] ?? null,
            'cae_vto' => $cfe['cae_vto'] ?? null,
            'hash' => $cfe['hash'] ?? null,
            'link_qr' => $cfe['link_qr'] ?? null,
            'imagen_qr_base64' => $cfe['imagen_qr_base64'] ?? null,
            'xml_firmado' => $cfe['xml_firmado'] ?? null,
            'request_payload' => $requestPayload,
            'response_payload' => $result['raw'] ?? null,
            'trace_id' => $result['trace_id'] ?? null,
        ]);
    }

    public function middleware()
    {
        return [(new WithoutOverlapping("send_to_cfe_uy_{$this->company->company_key}_{$this->invoice_id}"))->releaseAfter(60)->expireAfter(600)];
    }

    public function failed($exception = null)
    {
        MultiDB::setDB($this->company->db);
        $invoice = Invoice::withTrashed()->where('company_id', $this->company->id)->find($this->invoice_id);
        if (!$invoice || strlen($invoice->backup->guid ?? '') >= 1) {
            return;
        }

        $message = $exception?->getMessage() ?? 'CFE emission exhausted all retries';
        $invoice->backup->cfe_status = 'failed';
        $invoice->backup->cfe_error = $message;
        $invoice->saveQuietly();
        $this->writeActivity($invoice, Activity::CFE_UY_INVOICE_SENT_FAILURE, $message);
        $this->systemLog($invoice, ['error' => $message], SystemLog::EVENT_CFE_UY_FAILURE, SystemLog::TYPE_CFE_UY_SEND);
    }

    private function writeActivity(Invoice $invoice, int $activity_id, string $notes = ''): void
    {
        $activity = new Activity();
        $activity->user_id = $invoice->user_id;
        $activity->client_id = $invoice->client_id;
        $activity->company_id = $invoice->company_id;
        $activity->account_id = $invoice->company->account_id;
        $activity->activity_type_id = $activity_id;
        $activity->invoice_id = $invoice->id;
        $activity->notes = str_replace('"', '', $notes);
        $activity->is_system = true;
        $activity->save();
    }

    private function systemLog(Invoice $invoice, array $data, int $event_id, int $type_id): void
    {
        (new SystemLogger(
            $data,
            SystemLog::CATEGORY_CFE_UY,
            $event_id,
            $type_id,
            $invoice->client,
            $invoice->company
        ))->handle();
    }
}
