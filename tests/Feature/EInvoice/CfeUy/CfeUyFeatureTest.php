<?php

namespace Tests\Feature\EInvoice\CfeUy;

use Tests\TestCase;
use Tests\MockAccountData;
use App\Models\CfeLog;
use App\Models\Activity;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\EDocument\Standards\CfeUy\SendToCfeUy;
use App\Services\EDocument\Standards\CfeUy\Mapper;
use App\Services\EDocument\Standards\CfeUy\Client;
use App\Services\EDocument\Standards\CfeUy\ResponseProcessor;

class CfeUyFeatureTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();

        config([
            'services.cfe_uy.enabled' => true,
            'services.cfe_uy.base_url' => 'https://cfe-test.example.com',
            'services.cfe_uy.token' => 'test-token',
            'services.cfe_uy.timeout' => 10,
            'services.cfe_uy.verify_ssl' => false,
            'services.cfe_uy.tax_map' => [
                '22' => 3,
                '10' => 2,
                '0_exempt' => 1,
                '0_none' => 6,
            ],
        ]);
    }

    public function test_successful_cfe_emission(): void
    {
        Http::fake([
            'cfe-test.example.com/v1/cfe/emit' => Http::response([
                'success' => true,
                'status' => 'EN',
                'message' => 'Operacion exitosa',
                'cfe' => [
                    'tipo' => 101,
                    'serie' => 'A',
                    'numero' => 833,
                    'cae_id' => '90253805312',
                    'cae_dnro' => 801,
                    'cae_hnro' => 1000,
                    'cae_vto' => '2027-08-28',
                    'hash' => 'abc123hash',
                    'link_qr' => 'https://example.com/qr',
                    'imagen_qr_base64' => 'base64data',
                    'xml_firmado' => '<CFE>signed</CFE>',
                ],
                'provider' => [
                    'codigo' => 0,
                    'descripcion' => 'Operacion exitosa',
                ],
                'trace_id' => 'trace-success-123',
            ], 200),
        ]);

        // Ensure invoice has no guid yet
        $this->invoice->backup->guid = '';
        $this->invoice->saveQuietly();

        $job = new SendToCfeUy($this->invoice->id, $this->invoice->company);
        $job->handle();

        $this->invoice->refresh();

        // Verify idempotency marker was set
        $this->assertNotEmpty($this->invoice->backup->guid);

        // Verify CfeLog was created
        $log = CfeLog::where('invoice_id', $this->invoice->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('EN', $log->provider_status);
        $this->assertEquals(101, $log->cfe_tipo);
        $this->assertEquals('A', $log->cfe_serie);
        $this->assertEquals(833, $log->cfe_numero);
        $this->assertEquals('90253805312', $log->cae_id);
        $this->assertEquals('abc123hash', $log->hash);
        $this->assertEquals('<CFE>signed</CFE>', $log->xml_firmado);
        $this->assertEquals('trace-success-123', $log->trace_id);
    }

    public function test_duplicate_send_is_idempotent(): void
    {
        // Pre-set the guid to simulate already emitted
        $this->invoice->backup->guid = 'already-emitted';
        $this->invoice->saveQuietly();

        Http::fake();

        $job = new SendToCfeUy($this->invoice->id, $this->invoice->company);
        $job->handle();

        // Should NOT have called the service
        Http::assertNothingSent();

        // No new CfeLog should be created
        $this->assertEquals(0, CfeLog::where('invoice_id', $this->invoice->id)->count());
    }

    public function test_provider_rejection_creates_failure_log(): void
    {
        Http::fake([
            'cfe-test.example.com/v1/cfe/emit' => Http::response([
                'success' => false,
                'status' => 'ERROR',
                'message' => 'RUC no registrado',
                'provider' => [
                    'codigo' => 5,
                    'descripcion' => 'RUC no registrado',
                ],
                'retryable' => false,
                'trace_id' => 'trace-error-456',
            ], 200),
        ]);

        $this->invoice->backup->guid = '';
        $this->invoice->saveQuietly();

        $job = new SendToCfeUy($this->invoice->id, $this->invoice->company);
        $job->handle();

        $this->invoice->refresh();

        // Guid should NOT be set on failure
        $this->assertEmpty($this->invoice->backup->guid);

        // CfeLog should record the failure
        $log = CfeLog::where('invoice_id', $this->invoice->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('ERROR', $log->provider_status);
        $this->assertEquals(5, $log->provider_code);
        $this->assertEquals('RUC no registrado', $log->provider_description);
    }

    public function test_mapper_produces_valid_payload(): void
    {
        $this->client->vat_number = '214100010018';
        $this->client->save();

        $mapper = new Mapper($this->invoice);
        $payload = $mapper->toPayload();

        $this->assertEquals(111, $payload['document']['tipo_cfe']);
        $this->assertArrayHasKey('numero', $payload['document']);
        $this->assertEquals($this->invoice->hashed_id, $payload['external_invoice_id']);
        $this->assertNotEmpty($payload['idempotency_key']);
        $this->assertNotEmpty($payload['emisor']['rut']);
        $this->assertEquals('214100010018', $payload['receptor']['doc_numero']);
    }

    public function test_response_processor_handles_success(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'status' => 'EN',
                'message' => 'OK',
                'cfe' => ['tipo' => 101],
                'provider' => ['codigo' => 0, 'descripcion' => 'OK'],
                'trace_id' => 'tid',
            ], 200),
        ]);

        $response = Http::get('https://example.com');
        $result = (new ResponseProcessor())->process($response);

        $this->assertTrue($result['success']);
        $this->assertEquals('EN', $result['status']);
        $this->assertNotNull($result['cfe']);
    }

    public function test_response_processor_handles_http_500(): void
    {
        Http::fake([
            '*' => Http::response('Internal Server Error', 500),
        ]);

        $response = Http::get('https://example.com');
        $result = (new ResponseProcessor())->process($response);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
    }

    public function test_send_cfe_uy_dispatches_job(): void
    {
        Queue::fake();

        $this->invoice->service()->sendCfeUy();

        Queue::assertPushed(SendToCfeUy::class);
    }

    public function test_validation_rejects_missing_company_rut(): void
    {
        $settings = $this->company->settings;
        $settings->vat_number = '';
        $this->company->settings = $settings;
        $this->company->save();

        $validator = new \App\Services\EDocument\Standards\Validation\CfeUy\EntityLevel();
        $result = $validator->checkCompany($this->company);

        $this->assertFalse($result['passes']);
        $this->assertNotEmpty($result['company']);
    }

    public function test_validation_accepts_valid_invoice(): void
    {
        // Set up a line item with mapped tax rate
        $item = new \stdClass();
        $item->quantity = 1;
        $item->cost = 100;
        $item->product_key = 'Test';
        $item->notes = 'Test item';
        $item->discount = 0;
        $item->is_amount_discount = false;
        $item->tax_rate1 = 22;
        $item->tax_name1 = 'IVA';
        $item->tax_rate2 = 0;
        $item->tax_name2 = '';
        $item->tax_rate3 = 0;
        $item->tax_name3 = '';
        $item->type_id = '1';
        $item->custom_value1 = '';
        $item->custom_value2 = '';
        $item->custom_value3 = '';
        $item->custom_value4 = '';

        $this->invoice->line_items = [$item];
        $this->invoice->save();

        $validator = new \App\Services\EDocument\Standards\Validation\CfeUy\EntityLevel();
        $result = $validator->checkInvoice($this->invoice);

        $this->assertTrue($result['passes']);
    }
}
