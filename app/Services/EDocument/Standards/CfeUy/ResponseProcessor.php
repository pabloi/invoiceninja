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

use Illuminate\Http\Client\Response;

class ResponseProcessor
{
    /**
     * Process a successful emission response from xml-cfe service.
     *
     * Returns a normalized array regardless of success/failure.
     *
     * @param Response $response
     * @return array{success: bool, status: string, message: string, cfe: array|null, provider: array, trace_id: string|null, retryable: bool, raw: array}
     */
    public function process(Response $response): array
    {
        if ($response->failed()) {
            return $this->processHttpFailure($response);
        }

        $body = $response->json();

        if (!is_array($body)) {
            return $this->buildResult(
                success: false,
                status: 'ERROR',
                message: 'Malformed response: not valid JSON',
                retryable: true,
                raw: ['http_status' => $response->status(), 'body' => $response->body()],
            );
        }

        $success = data_get($body, 'success', false);

        return $this->buildResult(
            success: $success,
            status: data_get($body, 'status', $success ? 'EN' : 'ERROR'),
            message: data_get($body, 'message', ''),
            cfe: $success ? data_get($body, 'cfe') : null,
            provider: [
                'codigo' => data_get($body, 'provider.codigo'),
                'descripcion' => data_get($body, 'provider.descripcion', ''),
            ],
            trace_id: data_get($body, 'trace_id'),
            retryable: data_get($body, 'retryable', false),
            raw: $body,
        );
    }

    /**
     * Process an HTTP-level failure (timeout, 5xx, network error, etc.).
     */
    private function processHttpFailure(Response $response): array
    {
        $status = $response->status();
        $retryable = $status >= 500 || $status === 0;

        return $this->buildResult(
            success: false,
            status: 'ERROR',
            message: "HTTP {$status}: " . substr($response->body(), 0, 500),
            retryable: $retryable,
            raw: ['http_status' => $status, 'body' => $response->body()],
        );
    }

    /**
     * Build a normalized result array.
     */
    private function buildResult(
        bool $success,
        string $status,
        string $message,
        ?array $cfe = null,
        array $provider = [],
        ?string $trace_id = null,
        bool $retryable = false,
        array $raw = [],
    ): array {
        return [
            'success' => $success,
            'status' => $status,
            'message' => $message,
            'cfe' => $cfe,
            'provider' => $provider,
            'trace_id' => $trace_id,
            'retryable' => $retryable,
            'raw' => $raw,
        ];
    }
}
