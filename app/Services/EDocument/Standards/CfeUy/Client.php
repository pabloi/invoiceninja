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

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class Client
{
    private string $base_url;
    private string $token;
    private int $timeout;
    private bool $verify_ssl;

    public function __construct()
    {
        $this->base_url = rtrim(config('services.cfe_uy.base_url', ''), '/');
        $this->token = config('services.cfe_uy.token', '');
        $this->timeout = (int) config('services.cfe_uy.timeout', 30);
        $this->verify_ssl = (bool) config('services.cfe_uy.verify_ssl', true);
    }

    /**
     * Emit a CFE document via the external xml-cfe service.
     *
     * @param array $payload The mapped CFE request payload
     * @return Response
     */
    public function emit(array $payload): Response
    {
        nlog("CFE_UY: Emitting CFE to {$this->base_url}/v1/cfe/emit");

        return Http::withToken($this->token)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->withOptions([
                'verify' => $this->verify_ssl,
                'timeout' => $this->timeout,
            ])
            ->post("{$this->base_url}/v1/cfe/emit", $payload);
    }

    /**
     * Check the status of a previously submitted CFE.
     *
     * @param string $external_id The external invoice ID
     * @return Response
     */
    public function status(string $external_id): Response
    {
        nlog("CFE_UY: Checking status for {$external_id}");

        return Http::withToken($this->token)
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->withOptions([
                'verify' => $this->verify_ssl,
                'timeout' => $this->timeout,
            ])
            ->get("{$this->base_url}/v1/cfe/status/{$external_id}");
    }
}
