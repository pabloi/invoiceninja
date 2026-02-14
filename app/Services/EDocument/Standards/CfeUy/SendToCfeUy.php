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
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendToCfeUy implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 5;

    public $deleteWhenMissingModels = true;

    public function __construct(private int $invoice_id, private Company $company)
    {
    }

    public function backoff()
    {
        return [5, 30, 240, 3600, 7200];
    }

    public function handle()
    {
        MultiDB::setDB($this->company->db);

        $invoice = Invoice::withTrashed()->find($this->invoice_id);

        if (!$invoice || $invoice->is_deleted) {
            return;
        }

        $invoice = $invoice->service()->markSent()->save();

        // Idempotency: return early if already emitted
        if (strlen($invoice->backup->guid ?? '') >= 1) {
            nlog("CFE_UY: Invoice {$invoice->number} already emitted, skipping.");
            return;
        }

        $this->emitCfe($invoice);
    }

    private function emitCfe(Invoice $invoice): void
    {
        $mapper = new Mapper($invoice);
        $payload = $mapper->toPayload();

        $client = new Client();
        $processor = new ResponseProcessor();

        try {
            $httpResponse = $client->emit($payload);
            $result = $processor->process($httpResponse);
        } catch (\Exception $e) {
            nlog("CFE_UY: Exception during emit for invoice {$invoice->number}: {$e->getMessage()}");

            $this->persistLog($invoice, $payload, null, 'ERROR', $e->getMessage());
            $this->writeActivity($invoice, Activity::CFE_UY_INVOICE_SENT_FAILURE, $e->getMessage());
            $this->systemLog($invoice, ['error' => $e->getMessage()], SystemLog::EVENT_CFE_UY_FAILURE, SystemLog::TYPE_CFE_UY_SEND);

            throw $e; // Let the queue retry
        }

        // Persist the log regardless of success/failure
        $this->persistLog($invoice, $payload, $result);

        if ($result['success']) {
            $this->handleSuccess($invoice, $result);
        } else {
            $this->handleFailure($invoice, $result);
        }
    }

    private function handleSuccess(Invoice $invoice, array $result): void
    {
        // Set idempotency marker
        $invoice->backup->guid = $result['trace_id'] ?? $result['cfe']['cae_id'] ?? 'cfe_uy_emitted';
        $invoice->saveQuietly();

        $message = $result['message'] ?? 'CFE emitted successfully';
        $this->writeActivity($invoice, Activity::CFE_UY_INVOICE_SENT, $message);
        $this->systemLog($invoice, $result['raw'] ?? $result, SystemLog::EVENT_CFE_UY_SUCCESS, SystemLog::TYPE_CFE_UY_SEND);

        // Release primed email invitations
        $invoice->invitations()
            ->where('email_error', 'primed')
            ->whereHas('contact', function ($query) {
                $query->where(function ($sq) {
                    $sq->whereNotNull('email')
                        ->orWhere('email', '!=', '');
                })->where('is_locked', false)
                ->withoutTrashed();
            })->each(function ($invitation) {
                $invitation->invoice->service()->sendEmail($invitation->contact);
                $invitation->email_error = '';
                $invitation->saveQuietly();
            });
    }

    private function handleFailure(Invoice $invoice, array $result): void
    {
        $message = $result['message'] ?? 'CFE emission failed';
        $this->writeActivity($invoice, Activity::CFE_UY_INVOICE_SENT_FAILURE, $message);
        $this->systemLog($invoice, $result['raw'] ?? $result, SystemLog::EVENT_CFE_UY_FAILURE, SystemLog::TYPE_CFE_UY_SEND);

        // If retryable, let the queue handle it
        if ($result['retryable'] && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 7200);
        }
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
        return [(new WithoutOverlapping("send_to_cfe_uy_{$this->company->company_key}_{$this->invoice_id}"))->releaseAfter(60)->expireAfter(60)];
    }

    public function failed($exception = null)
    {
        if ($exception) {
            nlog("CFE_UY: Job failed permanently: {$exception->getMessage()}");
        }
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
