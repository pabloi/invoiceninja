<?php

namespace Tests\Unit\CfeUy;

use Tests\TestCase;
use App\Services\EDocument\Standards\CfeUy\ResponseProcessor;
use Illuminate\Support\Facades\Http;

class ResponseProcessorTest extends TestCase
{
    private ResponseProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new ResponseProcessor();
    }

    public function test_successful_emission_response(): void
    {
        Http::fake([
            '*' => Http::response([
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
                    'hash' => 'abc123',
                    'link_qr' => 'https://example.com/qr',
                ],
                'provider' => [
                    'codigo' => 0,
                    'descripcion' => 'Operacion exitosa',
                ],
                'trace_id' => 'test-trace-id',
            ], 200),
        ]);

        $response = Http::get('https://example.com/test');
        $result = $this->processor->process($response);

        $this->assertTrue($result['success']);
        $this->assertEquals('EN', $result['status']);
        $this->assertEquals('Operacion exitosa', $result['message']);
        $this->assertNotNull($result['cfe']);
        $this->assertEquals(101, $result['cfe']['tipo']);
        $this->assertEquals('90253805312', $result['cfe']['cae_id']);
        $this->assertEquals(0, $result['provider']['codigo']);
        $this->assertEquals('test-trace-id', $result['trace_id']);
        $this->assertFalse($result['retryable']);
    }

    public function test_provider_error_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'status' => 'ERROR',
                'message' => 'SICFE error',
                'provider' => [
                    'codigo' => 5,
                    'descripcion' => 'RUC no registrado',
                ],
                'retryable' => false,
                'trace_id' => 'error-trace',
            ], 200),
        ]);

        $response = Http::get('https://example.com/test');
        $result = $this->processor->process($response);

        $this->assertFalse($result['success']);
        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(5, $result['provider']['codigo']);
        $this->assertEquals('RUC no registrado', $result['provider']['descripcion']);
        $this->assertFalse($result['retryable']);
        $this->assertNull($result['cfe']);
    }

    public function test_http_server_error_is_retryable(): void
    {
        Http::fake([
            '*' => Http::response('Internal Server Error', 500),
        ]);

        $response = Http::get('https://example.com/test');
        $result = $this->processor->process($response);

        $this->assertFalse($result['success']);
        $this->assertEquals('ERROR', $result['status']);
        $this->assertTrue($result['retryable']);
        $this->assertStringContains('HTTP 500', $result['message']);
    }

    public function test_http_client_error_is_not_retryable(): void
    {
        Http::fake([
            '*' => Http::response('Bad Request', 400),
        ]);

        $response = Http::get('https://example.com/test');
        $result = $this->processor->process($response);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
    }

    public function test_malformed_json_response(): void
    {
        Http::fake([
            '*' => Http::response('not json at all', 200),
        ]);

        $response = Http::get('https://example.com/test');
        $result = $this->processor->process($response);

        $this->assertFalse($result['success']);
        $this->assertEquals('ERROR', $result['status']);
        $this->assertTrue($result['retryable']);
        $this->assertStringContains('Malformed response', $result['message']);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
