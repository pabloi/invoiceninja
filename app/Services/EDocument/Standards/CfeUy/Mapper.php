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

use App\Models\Invoice;
use App\Models\Company;
use App\Models\Client;

class Mapper
{
    private Invoice $invoice;
    private Company $company;
    private Client $client;
    private array $tax_map;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
        $this->company = $invoice->company;
        $this->client = $invoice->client;
        $this->tax_map = config('services.cfe_uy.tax_map', []);
    }

    /**
     * Map the Invoice Ninja invoice into the xml-cfe service request contract.
     *
     * @return array The full request payload for POST /v1/cfe/emit
     */
    public function toPayload(): array
    {
        return [
            'idempotency_key' => $this->buildIdempotencyKey(),
            'external_invoice_id' => $this->invoice->hashed_id,
            'document' => $this->mapDocument(),
            'emisor' => $this->mapEmisor(),
            'receptor' => $this->mapReceptor(),
            'items' => $this->mapItems(),
            'meta' => $this->mapMeta(),
        ];
    }

    /**
     * Build idempotency key from immutable-ish tuple.
     */
    private function buildIdempotencyKey(): string
    {
        return implode(':', [
            $this->company->company_key,
            $this->invoice->id,
            $this->invoice->updated_at->timestamp,
        ]);
    }

    /**
     * Determine the CFE type: 111 (e-Factura) if client has RUT, else 101 (e-Ticket).
     */
    public function resolveTipoCfe(): int
    {
        if ($this->hasRuc()) {
            return 111;
        }

        return 101;
    }

    /**
     * Map the document-level fields.
     */
    private function mapDocument(): array
    {
        return [
            'tipo_cfe' => $this->resolveTipoCfe(),
            'serie' => 'A',
            'numero' => $this->resolveDocumentNumber(),
            'fecha_emision' => $this->invoice->date ?? now()->format('Y-m-d'),
            'forma_pago' => $this->resolveFormaPago(),
            'fecha_vencimiento' => $this->invoice->due_date,
            'monto_bruto' => true,
            'moneda' => $this->client->currency()->code ?? 'UYU',
            'tipo_cambio' => $this->resolveTipoCambio(),
        ];
    }

    /**
     * Resolve payment form: 1 = contado, 2 = credito.
     */
    private function resolveFormaPago(): int
    {
        if ($this->invoice->due_date && $this->invoice->due_date > $this->invoice->date) {
            return 2;
        }
        return 1;
    }

    /**
     * Resolve exchange rate. Null for UYU.
     */
    private function resolveTipoCambio(): ?string
    {
        $currency_code = $this->client->currency()->code ?? 'UYU';

        if ($currency_code === 'UYU') {
            return null;
        }

        return $this->invoice->exchange_rate ? number_format($this->invoice->exchange_rate, 3, '.', '') : null;
    }

    /**
     * Map company data to emisor block.
     */
    private function mapEmisor(): array
    {
        $settings = $this->company->settings;

        return [
            'rut' => $this->company->settings->vat_number ?? '',
            'razon_social' => $settings->name ?? $this->company->present()->name(),
            'nombre_fantasia' => $settings->name ?? '',
            'domicilio_fiscal' => trim(implode(', ', array_filter([
                $settings->address1 ?? '',
                $settings->address2 ?? '',
                $settings->city ?? '',
                $settings->state ?? '',
            ]))),
            'ciudad' => $settings->city ?? '',
            'departamento' => $settings->state ?? '',
        ];
    }

    /**
     * Map client data to receptor block.
     */
    private function mapReceptor(): array
    {
        $docNumero = $this->normalizeDocNumber();
        $countryCode = $this->client->country ? $this->client->country->iso_3166_2 : 'UY';

        $receptor = [
            'tipo_doc' => $this->resolveReceptorDocType(),
            'cod_pais' => $countryCode ?: 'UY',
            'doc_numero' => $docNumero,
            'razon_social' => $this->client->present()->name(),
            'direccion' => trim(implode(', ', array_filter([
                $this->client->address1 ?? '',
                $this->client->address2 ?? '',
                $this->client->city ?? '',
                $this->client->state ?? '',
            ]))),
            'ciudad' => $this->client->city ?? '',
            'departamento' => $this->client->state ?? '',
            'pais' => $this->client->country ? $this->client->country->name : 'Uruguay',
        ];

        return $receptor;
    }

    /**
     * Resolve receptor document type.
     * 2 = RUC, 3 = CI, 4 = Otros, 5 = Pasaporte, 6 = DNI, 7 = NIFE
     */
    private function resolveReceptorDocType(): int
    {
        if ($this->hasRuc()) {
            return 2; // RUC
        }

        $id = preg_replace('/\D+/', '', (string) ($this->client->id_number ?? ''));
        if (strlen($id) >= 6 && strlen($id) <= 8) {
            return 3; // CI
        }

        return 4; // Otros
    }

    /**
     * Map invoice line items.
     */
    private function mapItems(): array
    {
        $items = [];
        $line_items = $this->invoice->line_items ?? [];

        foreach ($line_items as $item) {
            $items[] = [
                'indicador_facturacion' => $this->resolveIndFact($item),
                'nombre' => $item->product_key ?: ($item->notes ?: 'Item'),
                'descripcion' => $item->notes ?? '',
                'cantidad' => number_format($item->quantity, 4, '.', ''),
                'precio_unitario' => number_format($item->cost, 4, '.', ''),
                'descuento' => number_format($item->discount ?? 0, 2, '.', ''),
                'es_porcentaje_descuento' => $item->is_amount_discount ? false : true,
            ];
        }

        return $items;
    }

    /**
     * Map tax rate to IndFact (indicador de facturacion).
     *
     * Uses the config-driven tax map:
     *   22% -> 3 (basica)
     *   10% -> 2 (minima)
     *   0% with tax name -> 1 (exento)
     *   0% no tax -> 6 (no facturable)
     *
     * @throws \RuntimeException if tax rate is unmapped
     */
    public function resolveIndFact(object $item): int
    {
        $rate = round((float)($item->tax_rate1 ?? 0), 0);
        $tax_name = $item->tax_name1 ?? '';

        $rate_key = (string) $rate;

        if (isset($this->tax_map[$rate_key])) {
            return (int) $this->tax_map[$rate_key];
        }

        if ($rate == 0 && strlen($tax_name) > 0) {
            return (int) ($this->tax_map['0_exempt'] ?? 1);
        }

        if ($rate == 0) {
            return (int) ($this->tax_map['0_none'] ?? 6);
        }

        throw new \RuntimeException(
            "CFE_UY: Unmapped tax rate {$rate}% for item '{$item->product_key}'. Configure services.cfe_uy.tax_map."
        );
    }

    /**
     * Map metadata block.
     */
    private function mapMeta(): array
    {
        return [
            'company_key' => $this->company->company_key,
            'invoice_number' => $this->invoice->number,
            'environment' => app()->environment(),
        ];
    }

    /**
     * Resolve CFE document number (required by xml-cfe contract).
     */
    private function resolveDocumentNumber(): int
    {
        $invoiceNumber = preg_replace('/\D+/', '', (string) ($this->invoice->number ?? ''));

        if ($invoiceNumber !== '' && (int) $invoiceNumber > 0) {
            return (int) $invoiceNumber;
        }

        return max(1, (int) $this->invoice->id);
    }

    /**
     * Normalize client document number while preserving non-empty fallback values.
     */
    private function normalizeDocNumber(): string
    {
        if ($this->hasRuc()) {
            return preg_replace('/\D+/', '', (string) $this->client->vat_number);
        }

        $idRaw = trim((string) ($this->client->id_number ?? ''));
        $idDigits = preg_replace('/\D+/', '', $idRaw);

        if ($idDigits !== '') {
            return $idDigits;
        }

        return $idRaw;
    }

    /**
     * Determine whether the client has a valid-length RUC-like identifier.
     */
    private function hasRuc(): bool
    {
        $vatDigits = preg_replace('/\D+/', '', (string) ($this->client->vat_number ?? ''));
        return strlen($vatDigits) >= 12;
    }
}
