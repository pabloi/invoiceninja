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

namespace App\Services\EDocument\Standards\Validation\CfeUy;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\EDocument\Standards\Validation\EntityLevelInterface;

class EntityLevel implements EntityLevelInterface
{
    private array $errors = [
        'client' => [],
        'company' => [],
        'invoice' => [],
        'passes' => true,
    ];

    public function checkClient(Client $client): array
    {
        $this->errors = ['client' => [], 'company' => [], 'invoice' => [], 'passes' => true];

        if (!$this->validString($client->name) && !$this->validString($client->present()->name())) {
            $this->errors['client'][] = ['field' => 'name', 'message' => 'Client name is required for CFE_UY.'];
        }

        // For e-Factura (111), client needs a valid tax ID
        $has_vat = $this->validString($client->vat_number) && strlen($client->vat_number) >= 12;
        $has_id = $this->validString($client->id_number);

        // At least some identification is recommended
        if (!$has_vat && !$has_id) {
            $this->errors['client'][] = ['field' => 'id_number', 'message' => 'Client document number (CI or RUC) is recommended for CFE_UY. Without it, e-Ticket (101) will be used.'];
        }

        if (count($this->errors['client']) > 0) {
            $this->errors['passes'] = false;
        }

        return $this->errors;
    }

    public function checkCompany(Company $company): array
    {
        $this->errors = ['client' => [], 'company' => [], 'invoice' => [], 'passes' => true];

        $settings = $company->settings;

        if (!$this->validString($settings->vat_number ?? '')) {
            $this->errors['company'][] = ['field' => 'vat_number', 'message' => 'Company RUT is required for CFE_UY emisor mapping.'];
        }

        if (!$this->validString($settings->name ?? '') && !$this->validString($company->present()->name())) {
            $this->errors['company'][] = ['field' => 'name', 'message' => 'Company name (razon social) is required for CFE_UY.'];
        }

        if (!$this->validString($settings->address1 ?? '')) {
            $this->errors['company'][] = ['field' => 'address1', 'message' => 'Company fiscal address is required for CFE_UY.'];
        }

        if (!$this->validString($settings->city ?? '')) {
            $this->errors['company'][] = ['field' => 'city', 'message' => 'Company city is required for CFE_UY.'];
        }

        if (!config('services.cfe_uy.enabled', false)) {
            $this->errors['company'][] = ['field' => 'cfe_uy_enabled', 'message' => 'CFE_UY service is not enabled in configuration.'];
        }

        if (!$this->validString(config('services.cfe_uy.base_url', ''))) {
            $this->errors['company'][] = ['field' => 'cfe_uy_base_url', 'message' => 'CFE_UY service base URL is not configured.'];
        }

        if (count($this->errors['company']) > 0) {
            $this->errors['passes'] = false;
        }

        return $this->errors;
    }

    public function checkInvoice(Invoice $invoice): array
    {
        $this->errors = ['client' => [], 'company' => [], 'invoice' => [], 'passes' => true];

        $tax_map = config('services.cfe_uy.tax_map', []);
        $line_items = $invoice->line_items ?? [];

        foreach ($line_items as $index => $item) {
            $rate = round((float)($item->tax_rate1 ?? 0), 0);
            $tax_name = $item->tax_name1 ?? '';
            $rate_key = (string) $rate;

            $mapped = false;

            if (isset($tax_map[$rate_key])) {
                $mapped = true;
            } elseif ($rate == 0 && strlen($tax_name) > 0 && isset($tax_map['0_exempt'])) {
                $mapped = true;
            } elseif ($rate == 0 && isset($tax_map['0_none'])) {
                $mapped = true;
            }

            if (!$mapped) {
                $product = $item->product_key ?? "Line {$index}";
                $this->errors['invoice'][] = [
                    'field' => "line_items[{$index}].tax_rate1",
                    'message' => "Tax rate {$rate}% on item '{$product}' is not mapped in CFE_UY tax configuration.",
                ];
            }
        }

        if (count($line_items) === 0) {
            $this->errors['invoice'][] = ['field' => 'line_items', 'message' => 'Invoice must have at least one line item for CFE_UY.'];
        }

        // Validate currency compatibility
        $currency_code = $invoice->client->currency()->code ?? 'UYU';
        if ($currency_code !== 'UYU' && !$invoice->exchange_rate) {
            $this->errors['invoice'][] = ['field' => 'exchange_rate', 'message' => "Exchange rate is required for non-UYU currency ({$currency_code})."];
        }

        if (count($this->errors['invoice']) > 0) {
            $this->errors['passes'] = false;
        }

        return $this->errors;
    }

    private function validString(?string $value): bool
    {
        return $value !== null && iconv_strlen(trim($value)) > 0;
    }
}
