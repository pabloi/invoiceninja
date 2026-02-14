<?php

namespace Tests\Unit\CfeUy;

use Tests\TestCase;
use App\Services\EDocument\Standards\CfeUy\Client;
use Illuminate\Support\Facades\Http;

class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.cfe_uy.base_url' => 'https://cfe-test.example.com',
            'services.cfe_uy.token' => 'test-token-123',
            'services.cfe_uy.timeout' => 10,
            'services.cfe_uy.verify_ssl' => false,
        ]);
    }

    public function test_emit_sends_post_with_auth(): void
    {
        Http::fake([
            'cfe-test.example.com/v1/cfe/emit' => Http::response([
                'success' => true,
                'status' => 'EN',
            ], 200),
        ]);

        $client = new Client();
        $response = $client->emit(['test' => 'payload']);

        $this->assertTrue($response->successful());
        $this->assertTrue($response->json('success'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://cfe-test.example.com/v1/cfe/emit'
                && $request->hasHeader('Authorization', 'Bearer test-token-123')
                && $request['test'] === 'payload';
        });
    }

    public function test_status_sends_get_with_auth(): void
    {
        Http::fake([
            'cfe-test.example.com/v1/cfe/status/*' => Http::response([
                'success' => true,
                'status' => 'EN',
            ], 200),
        ]);

        $client = new Client();
        $response = $client->status('ext-123');

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/cfe/status/ext-123')
                && $request->hasHeader('Authorization', 'Bearer test-token-123');
        });
    }

    public function test_emit_handles_timeout(): void
    {
        Http::fake([
            'cfe-test.example.com/*' => Http::response('', 504),
        ]);

        $client = new Client();
        $response = $client->emit(['test' => 'payload']);

        $this->assertTrue($response->failed());
        $this->assertEquals(504, $response->status());
    }
}
