<?php

namespace Tests\Feature\EInvoice\CfeUy;

use App\Models\Invoice;
use App\Models\CfeLog;
use App\Models\Activity;
use App\Events\Invoice\InvoiceWasCancelled;
use App\Listeners\Invoice\InvoiceCancelledActivity;
use App\Services\Invoice\HandleCancellation;
use App\Services\Invoice\MarkInvoiceDeleted;
use App\Services\Invoice\HandleRestore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\MockAccountData;
use Tests\TestCase;

class CfeUyLifecycleTest extends TestCase
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
        $this->invoice->refresh();
        $this->invoice->unsetRelation('company');
        $this->invoice->status_id = Invoice::STATUS_SENT;
        $this->invoice->backup->guid = '90253805312';
        $this->invoice->backup->cfe_status = 'emitted';
        $this->invoice->backup->cfe_tipo = 101;
        $this->invoice->saveQuietly();
        $this->withHeaders(['X-API-TOKEN' => $this->token, 'X-API-SECRET' => config('ninja.api_secret')]);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_emitted_invoice_cannot_be_edited_even_with_paid_action(): void
    {
        $this->putJson('/api/v1/invoices/'.$this->invoice->hashed_id.'?paid=true', [
            'date' => '2020-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->assertNotEquals('2020-01-01', $this->invoice->fresh()->date);
    }

    public function test_payment_without_fiscal_edits_is_allowed(): void
    {
        $this->putJson('/api/v1/invoices/'.$this->invoice->hashed_id.'?paid=true', [])
            ->assertSuccessful();
        $this->assertEquals(Invoice::STATUS_PAID, $this->invoice->fresh()->status_id);
        $this->assertSame('90253805312', $this->invoice->fresh()->backup->guid);
    }

    public function test_delete_and_reverse_actions_are_unavailable(): void
    {
        $this->assertFalse($this->invoice->invoiceDeletable($this->invoice));
        $this->assertFalse($this->invoice->invoiceReversable($this->invoice));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new MarkInvoiceDeleted($this->invoice))->run();
    }

    public function test_reverse_service_rejects_emitted_invoice(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \App\Services\Invoice\HandleReversal($this->invoice))->run();
    }

    public function test_archive_and_restore_preserve_cfe(): void
    {
        $this->invoice->delete(); // soft archive, not markDeleted
        $this->assertTrue($this->invoice->invoiceRestorable($this->invoice));
        $restored = (new HandleRestore($this->invoice))->run();
        $this->assertNull($restored->fresh()->deleted_at);
        $this->assertSame('90253805312', $restored->fresh()->backup->guid);
    }

    public function test_deleted_cfe_cannot_be_restored(): void
    {
        $this->invoice->is_deleted = true;
        $this->invoice->saveQuietly();
        $this->invoice->delete();
        $this->assertFalse($this->invoice->invoiceRestorable($this->invoice));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new HandleRestore($this->invoice))->run();
    }

    public function test_cfe_detection_survives_company_setting_change(): void
    {
        $settings = $this->company->settings;
        $settings->e_invoice_type = '';
        $this->company->settings = $settings;
        $this->company->saveQuietly();
        $invoice = $this->invoice->fresh();
        $this->assertTrue($invoice->hasCfeUy());
        $this->assertFalse($invoice->invoiceDeletable($invoice));
        $invoice->backup->guid = '';
        $invoice->backup->cfe_status = null;
        $invoice->backup->cfe_tipo = null;
        $invoice->saveQuietly();
        CfeLog::create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id,
            'direction' => 'outbound', 'cae_id' => '90253805312']);
        $this->assertTrue($invoice->fresh()->hasCfeUy());
    }

    public function test_draft_without_cfe_is_editable_and_deletable(): void
    {
        $this->invoice->status_id = Invoice::STATUS_DRAFT;
        $this->invoice->backup->guid = '';
        $this->invoice->backup->cfe_status = null;
        $this->invoice->backup->cfe_tipo = null;
        $this->invoice->saveQuietly();
        $this->assertTrue($this->invoice->invoiceDeletable($this->invoice));
        $this->putJson('/api/v1/invoices/'.$this->invoice->hashed_id, ['date' => '2020-01-01'])
            ->assertSuccessful();
    }

    public function test_admin_cancel_requires_reason(): void
    {
        $this->putJson('/api/v1/invoices/'.$this->invoice->hashed_id.'?cancel=true', [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_non_admin_cancel_is_forbidden(): void
    {
        $this->user->company_users()->update(['is_admin' => false, 'is_owner' => false]);
        $this->putJson('/api/v1/invoices/'.$this->invoice->hashed_id.'?cancel=true', ['reason' => 'Manual credit required'])
            ->assertStatus(403);
        $this->assertEquals(Invoice::STATUS_SENT, $this->invoice->fresh()->status_id);
    }

    public function test_admin_cancel_records_reason_and_preserves_newer_backup(): void
    {
        Event::fake([InvoiceWasCancelled::class]);
        // The service starts with an older copy than the database.
        $fresh = $this->invoice->fresh();
        $fresh->backup->cfe_numero = 833;
        $fresh->saveQuietly();
        $this->putJson('/api/v1/invoices/'.$this->invoice->hashed_id.'?cancel=true', ['reason' => 'Manual credit required'])
            ->assertSuccessful();
        $invoice = $this->invoice->fresh();
        $this->assertEquals(Invoice::STATUS_CANCELLED, $invoice->status_id);
        $this->assertSame('Manual credit required', $invoice->backup->notes);
        $this->assertSame(833, $invoice->backup->cfe_numero);
        $this->assertSame('90253805312', $invoice->backup->guid);
        Event::assertDispatched(InvoiceWasCancelled::class, function ($event) {
            app(InvoiceCancelledActivity::class)->handle($event);
            return true;
        });
        $activity = Activity::where('invoice_id', $invoice->id)->where('activity_type_id', Activity::CANCELLED_INVOICE)->latest('id')->firstOrFail();
        $this->assertEquals($this->user->id, $activity->user_id);
        $this->assertSame('Manual credit required', $activity->notes);
    }

    public function test_health_reports_reachability_and_failure_without_provider_details(): void
    {
        config(['services.cfe_uy.enabled' => true, 'services.cfe_uy.base_url' => 'https://cfe-test.example.com']);
        Http::fakeSequence()->push(['status' => 'ok'])->push(['error' => 'private provider detail'], 500);
        $this->getJson('/api/v1/einvoice/cfe_uy/health')->assertOk()->assertJson(['reachable' => true]);
        $this->getJson('/api/v1/einvoice/cfe_uy/health')->assertStatus(503)->assertExactJson(['reachable' => false]);
    }

    public function test_unconfigured_health_does_not_call_provider(): void
    {
        config(['services.cfe_uy.enabled' => false]);
        $this->getJson('/api/v1/einvoice/cfe_uy/health')->assertStatus(503);
        Http::assertNothingSent();
    }
}
