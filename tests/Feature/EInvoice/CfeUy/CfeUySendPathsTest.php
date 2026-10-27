<?php

namespace Tests\Feature\EInvoice\CfeUy;

use App\Jobs\Entity\EmailEntity;
use App\Jobs\Invoice\BulkInvoiceJob;
use App\Jobs\Payment\EmailPayment;
use App\Jobs\RecurringInvoice\SendRecurring;
use App\Jobs\Util\ReminderJob;
use App\Models\Invoice;
use App\Models\Scheduler;
use App\Models\Webhook;
use App\Services\EDocument\Standards\CfeUy\SendToCfeUy;
use App\Services\Email\Email;
use App\Services\Invoice\TriggeredActions;
use App\Services\Scheduler\EmailRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\MockAccountData;
use Tests\TestCase;

class CfeUySendPathsTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();
        $settings = $this->company->settings;
        $settings->e_invoice_type = 'CFE_UY';
        $this->company->settings = $settings;
        $this->company->saveQuietly();
        $this->company = $this->company->fresh();
        $this->invoice = $this->invoice->fresh();
        $this->invoice->status_id = Invoice::STATUS_DRAFT;
        $this->invoice->backup->guid = '';
        $this->invoice->saveQuietly();
        config(['services.cfe_uy.base_url' => 'https://cfe-test.example.com']);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function assertHeld(Invoice $invoice): void
    {
        Queue::assertPushed(SendToCfeUy::class);
        Queue::assertNotPushed(Email::class);
        Queue::assertNotPushed(EmailEntity::class);
        $this->assertGreaterThan(0, $invoice->invitations()->count());
        $this->assertSame($invoice->invitations()->count(), $invoice->invitations()->where('email_error', 'primed')->count());
        $this->assertNotEquals(Invoice::STATUS_DRAFT, $invoice->fresh()->status_id);
    }

    public function test_save_and_send_primes_before_dispatch(): void
    {
        (new TriggeredActions($this->invoice, new Request(['send_email' => 'true'])))->run();
        $this->assertHeld($this->invoice);
    }

    public function test_email_action_holds_mail(): void
    {
        $this->withHeaders(['X-API-TOKEN' => $this->token, 'X-API-SECRET' => config('ninja.api_secret')])
            ->postJson('/api/v1/emails', [
                'entity' => 'invoice', 'entity_id' => $this->invoice->hashed_id,
                'template' => 'email_template_invoice',
            ])->assertSuccessful();
        $this->assertHeld($this->invoice);
    }

    public function test_bulk_send_continues_past_first_cfe_invoice(): void
    {
        $second = $this->invoice->replicate();
        $second->number = 'second-cfe-invoice';
        $second->saveQuietly();
        foreach ($this->invoice->invitations as $invitation) {
            $copy = $invitation->replicate();
            $copy->invoice_id = $second->id;
            $copy->key = bin2hex(random_bytes(20));
            $copy->saveQuietly();
        }
        (new BulkInvoiceJob([$this->invoice->id, $second->id], $this->company->db, 'invoice'))->handle();
        $this->assertHeld($this->invoice);
        $this->assertHeld($second);
        Queue::assertPushed(SendToCfeUy::class, 2);
    }

    public function test_scheduled_email_marks_sent_and_holds_mail(): void
    {
        $scheduler = new Scheduler();
        $scheduler->parameters = ['entity' => 'invoice', 'entity_id' => $this->invoice->hashed_id];
        (new EmailRecord($scheduler))->run();
        $this->assertHeld($this->invoice);
    }

    public function test_recurring_auto_send_holds_mail(): void
    {
        $settings = $this->client->settings;
        $settings->auto_email_invoice = true;
        $this->client->settings = $settings;
        $this->client->saveQuietly();
        $this->recurring_invoice->auto_bill = 'off';
        $this->recurring_invoice->saveQuietly();
        (new SendRecurring($this->recurring_invoice->fresh(), $this->company->db))->handle();
        $invoice = Invoice::where('recurring_id', $this->recurring_invoice->id)->latest('id')->firstOrFail();
        $this->assertHeld($invoice);
    }

    public function test_mark_sent_dispatches_without_priming(): void
    {
        (new TriggeredActions($this->invoice, new Request(['mark_sent' => 'true'])))->run();
        Queue::assertPushed(SendToCfeUy::class);
        $this->assertSame(0, $this->invoice->invitations()->where('email_error', 'primed')->count());
        Queue::assertNotPushed(Email::class);
    }

    public function test_sent_event_dispatches_cfe_even_without_peppol(): void
    {
        $this->invoice->status_id = Invoice::STATUS_SENT;
        $this->invoice->saveQuietly();
        $this->invoice->sendEvent(Webhook::EVENT_SENT_INVOICE);
        Queue::assertPushed(SendToCfeUy::class);
    }

    public function test_retry_preserves_held_mail(): void
    {
        $this->invoice->status_id = Invoice::STATUS_SENT;
        $this->invoice->saveQuietly();
        $this->invoice->invitations()->update(['email_error' => 'primed']);
        (new TriggeredActions($this->invoice, new Request(['retry_e_send' => 'true'])))->run();
        $this->assertHeld($this->invoice);
    }

    public function test_mark_paid_defers_receipt_until_emission(): void
    {
        $settings = $this->client->settings;
        $settings->send_email_on_mark_paid = true;
        $this->client->settings = $settings;
        $this->client->saveQuietly();
        $this->invoice->unsetRelation('client');
        $this->invoice->service()->markPaid()->save();
        Queue::assertPushed(SendToCfeUy::class);
        Queue::assertNotPushed(EmailPayment::class);
        $this->assertNotEmpty($this->invoice->fresh()->backup->cfe_pending_payment_ids);

        Http::fake(['*' => Http::response(['success' => true, 'cfe' => ['cae_id' => '90253805312', 'serie' => 'A', 'numero' => 833]])]);
        (new SendToCfeUy($this->invoice->id, $this->company))->handle();
        Queue::assertPushed(EmailPayment::class, 1);
        $this->assertSame([], $this->invoice->fresh()->backup->cfe_pending_payment_ids);
    }

    public function test_reminder_does_nothing_before_emission(): void
    {
        $method = new \ReflectionMethod(ReminderJob::class, 'sendReminderForInvoice');
        $method->invoke(new ReminderJob(), $this->invoice);
        Queue::assertNotPushed(EmailEntity::class);
        Queue::assertNotPushed(SendToCfeUy::class);
    }

    public function test_save_send_and_sent_event_emit_once(): void
    {
        (new TriggeredActions($this->invoice, new Request(['send_email' => 'true'])))->run();
        $this->invoice->refresh()->sendEvent(Webhook::EVENT_SENT_INVOICE);
        Http::fake(['*' => Http::response(['success' => true, 'cfe' => ['cae_id' => '90253805312', 'serie' => 'A', 'numero' => 833]])]);
        $job = new SendToCfeUy($this->invoice->id, $this->company);
        $job->handle();
        $job->handle();
        Http::assertSentCount(1);
        Queue::assertPushed(Email::class, 2);
    }
}
