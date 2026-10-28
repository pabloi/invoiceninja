<?php

namespace Tests\Feature\EInvoice\CfeUy;

use App\DataMapper\CompanySettings;
use App\Models\CfeLog;
use App\Models\Invoice;
use App\Models\InvoiceInvitation;
use App\Utils\HtmlEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\MockAccountData;
use Tests\TestCase;

class CfeUyPdfTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();
        Bus::fake();
        $this->invoice->refresh();
    }

    private function values(): array
    {
        $invitation = InvoiceInvitation::factory()
            ->for($this->invoice)->for($this->client->contacts->first(), 'contact')
            ->for($this->company)->for($this->user)->make();

        return (new HtmlEngine($invitation))->makeValues();
    }

    private function emitted(int $type = 101): CfeLog
    {
        $this->invoice->status_id = Invoice::STATUS_SENT;
        $this->invoice->backup->guid = '90253805312';
        $this->invoice->backup->cfe_tipo = $type;
        $this->invoice->backup->cfe_serie = 'A';
        $this->invoice->backup->cfe_numero = 833;
        $this->invoice->saveQuietly();

        return CfeLog::create([
            'company_id' => $this->company->id, 'invoice_id' => $this->invoice->id,
            'direction' => 'sent', 'provider_status' => 'EN',
            'cfe_tipo' => $type, 'cfe_serie' => 'A', 'cfe_numero' => 833,
            'cae_id' => '90253805312', 'cae_dnro' => 801, 'cae_hnro' => 1000,
            'cae_vto' => '2027-08-28', 'hash' => 'abc123rest',
            'link_qr' => 'https://example.com/verify?cfe=833',
            'imagen_qr_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS1sAAAAASUVORK5CYII=',
        ]);
    }

    public function test_variables_are_empty_before_emission_and_for_drafts(): void
    {
        $this->invoice->backup->guid = '';
        $this->invoice->saveQuietly();
        foreach ($this->values() as $key => $value) {
            if (str_starts_with($key, '$cfe_')) {
                $this->assertSame('', $value, $key);
            }
        }
        $this->emitted();
        $this->invoice->status_id = Invoice::STATUS_DRAFT;
        $this->invoice->saveQuietly();
        $this->assertSame('', $this->values()['$cfe_cae']);
    }

    public function test_emitted_variables_use_committed_cae_and_company_settings(): void
    {
        $this->emitted();
        $settings = $this->company->settings;
        $settings->cfe_uy_verification_url = 'https://issuer.example/verify';
        $settings->cfe_uy_issuer_since = '2020-01-02';
        $settings->cfe_uy_iva_al_dia_text = 'IVA al día';
        $this->company->settings = $settings;
        $this->company->saveQuietly();
        CfeLog::create([
            'company_id' => $this->company->id, 'invoice_id' => $this->invoice->id,
            'direction' => 'sent', 'provider_status' => 'ERROR',
        ]);
        $values = $this->values();
        $this->assertSame('e-Ticket', $values['$cfe_type']);
        $this->assertSame('A 833', $values['$cfe_series_number']);
        $this->assertStringContainsString('90253805312', $values['$cfe_cae']);
        $this->assertStringContainsString('801–1000', $values['$cfe_cae']);
        $this->assertStringContainsString('2027-08-28', $values['$cfe_cae']);
        $this->assertSame('abc123', $values['$cfe_security_code']);
        $this->assertStringContainsString('data:image/png;base64,iVBOR', $values['$cfe_qr_code']);
        $this->assertSame('Fecha emisor: 2020-01-02<br/>Puede verificar comprobante en https://issuer.example/verify', $values['$cfe_legend']);
        $this->assertSame('IVA al día', $values['$cfe_iva_al_dia']);
    }

    public function test_eticket_missing_url_falls_back_and_iva_defaults_empty(): void
    {
        $this->emitted();
        $values = $this->values();
        $this->assertSame('Puede verificar comprobante en www.dgi.gub.uy', $values['$cfe_legend']);
        $this->assertSame('', $values['$cfe_iva_al_dia']);
    }

    public function test_efactura_uses_dgi_and_generates_qr_from_link(): void
    {
        $log = $this->emitted(111);
        $log->imagen_qr_base64 = null;
        $log->save();
        $settings = $this->company->settings;
        $settings->cfe_uy_verification_url = 'https://issuer.example/verify';
        $this->company->settings = $settings;
        $this->company->saveQuietly();
        $values = $this->values();
        $this->assertSame('e-Factura', $values['$cfe_type']);
        $this->assertSame('Puede verificar comprobante en www.dgi.gub.uy', $values['$cfe_legend']);
        $this->assertStringContainsString('data:image/png;base64,', $values['$cfe_qr_code']);
    }

    public function test_settings_defaults_and_casts_include_footer_fields(): void
    {
        $defaults = CompanySettings::defaults();
        foreach (['cfe_uy_verification_url', 'cfe_uy_issuer_since', 'cfe_uy_iva_al_dia_text'] as $field) {
            $this->assertSame('', $defaults->{$field});
            $this->assertSame('string', CompanySettings::$casts[$field]);
        }
    }

    public function test_correction_types_use_the_matching_legend(): void
    {
        $this->emitted();
        $settings = $this->company->settings;
        $settings->cfe_uy_verification_url = 'issuer.example';
        $this->company->settings = $settings;
        $this->company->saveQuietly();
        foreach ([102, 103, 112, 113] as $type) {
            $this->invoice->backup->cfe_tipo = $type;
            $this->invoice->saveQuietly();
            $url = $type < 110 ? 'issuer.example' : 'www.dgi.gub.uy';
            $this->assertSame('Puede verificar comprobante en ' . $url, $this->values()['$cfe_legend']);
        }
    }

    public function test_issuer_text_is_escaped_and_missing_emission_log_is_empty(): void
    {
        $log = $this->emitted();
        $settings = $this->company->settings;
        $settings->cfe_uy_iva_al_dia_text = 'IVA <al día> & vigente';
        $this->company->settings = $settings;
        $this->company->saveQuietly();
        $this->assertSame('IVA &lt;al día&gt; &amp; vigente', $this->values()['$cfe_iva_al_dia']);
        $log->delete();
        $this->assertSame('', $this->values()['$cfe_legend']);
    }
}
