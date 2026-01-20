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

namespace App\Services\EDocument\Standards\Validation\UruguayCfe;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\EDocument\Standards\Validation\EntityLevelInterface;
use App\Services\EDocument\Standards\UruguayCfe;
use App\Services\EDocument\Standards\UruguayCfe\Models\IdDoc;

/**
 * EntityLevel - Uruguay CFE Validation
 *
 * Validates company, client, and invoice data for Uruguay DGI CFE compliance.
 */
class EntityLevel implements EntityLevelInterface
{
    /**
     * Uruguay country ID in Invoice Ninja database
     */
    private const COUNTRY_URUGUAY = 858;

    /**
     * Check client data for Uruguay CFE compliance
     */
    public function checkClient(Client $client): array
    {
        $errors = [];

        // Check basic client data
        if (empty($client->name) && empty($client->contacts()->first()?->first_name)) {
            $errors[] = ['key' => 'client_name', 'message' => ctrans('texts.client_name_required')];
        }

        // For Uruguayan clients
        if ($client->country_id == self::COUNTRY_URUGUAY) {
            // B2B clients should have RUT
            if (($client->classification ?? 'company') !== 'individual') {
                if (empty($client->vat_number)) {
                    $errors[] = ['key' => 'client_vat', 'message' => 'RUT is required for Uruguayan business clients'];
                }
            }
        }

        // For e-Factura (B2B), client identification is required
        if (!empty($client->vat_number) || !empty($client->id_number)) {
            // Validate format if provided
            if (!empty($client->vat_number)) {
                $rut = preg_replace('/[^0-9]/', '', $client->vat_number);
                if (strlen($rut) > 12) {
                    $errors[] = ['key' => 'client_vat_format', 'message' => 'RUT must be up to 12 digits'];
                }
            }
        }

        return $errors;
    }

    /**
     * Check company data for Uruguay CFE compliance
     */
    public function checkCompany(Company $company): array
    {
        $errors = [];

        // Company must be Uruguayan
        if ($company->settings->country_id != self::COUNTRY_URUGUAY) {
            $errors[] = ['key' => 'company_country', 'message' => 'Company must be registered in Uruguay for CFE'];
        }

        // RUT is required
        if (empty($company->settings->vat_number)) {
            $errors[] = ['key' => 'company_vat', 'message' => 'Company RUT is required for CFE'];
        } else {
            $rut = preg_replace('/[^0-9]/', '', $company->settings->vat_number);
            if (strlen($rut) > 12) {
                $errors[] = ['key' => 'company_vat_format', 'message' => 'Company RUT must be up to 12 digits'];
            }
        }

        // Company name is required
        if (empty($company->settings->name)) {
            $errors[] = ['key' => 'company_name', 'message' => 'Company name (Razón Social) is required'];
        }

        // Check CAE configuration
        if (!isset($company->e_invoice->uruguay_cae)) {
            $errors[] = ['key' => 'company_cae', 'message' => 'CAE configuration is required for Uruguay CFE'];
        } else {
            $caeConfig = (array) $company->e_invoice->uruguay_cae;
            $hasValidCae = false;

            foreach ($caeConfig as $key => $cae) {
                if (isset($cae->cae_id) && isset($cae->d_nro) && isset($cae->h_nro) && isset($cae->fec_venc)) {
                    $expDate = \Carbon\Carbon::parse($cae->fec_venc);
                    if ($expDate->isFuture()) {
                        $hasValidCae = true;
                        break;
                    }
                }
            }

            if (!$hasValidCae) {
                $errors[] = ['key' => 'company_cae_expired', 'message' => 'No valid (non-expired) CAE found'];
            }
        }

        return $errors;
    }

    /**
     * Check invoice data for Uruguay CFE compliance
     */
    public function checkInvoice(Invoice $invoice): array
    {
        $errors = [];

        // Check company first
        $companyErrors = $this->checkCompany($invoice->company);
        if (!empty($companyErrors)) {
            return $companyErrors;
        }

        // Check client
        $clientErrors = $this->checkClient($invoice->client);
        $errors = array_merge($errors, $clientErrors);

        // Invoice number is required
        if (empty($invoice->number)) {
            $errors[] = ['key' => 'invoice_number', 'message' => 'Invoice number is required'];
        }

        // Invoice date is required
        if (empty($invoice->date)) {
            $errors[] = ['key' => 'invoice_date', 'message' => 'Invoice date is required'];
        }

        // Must have line items
        if (empty($invoice->line_items) || count($invoice->line_items) == 0) {
            $errors[] = ['key' => 'invoice_items', 'message' => 'At least one line item is required'];
        }

        // Validate line items
        $lineNumber = 1;
        foreach ($invoice->line_items as $item) {
            if ($item->quantity <= 0) {
                $errors[] = ['key' => "line_{$lineNumber}_quantity", 'message' => "Line {$lineNumber}: Quantity must be positive"];
            }
            if ($item->cost < 0) {
                $errors[] = ['key' => "line_{$lineNumber}_cost", 'message' => "Line {$lineNumber}: Unit price cannot be negative"];
            }
            $lineNumber++;
        }

        // Validate tax rates (Uruguay uses 10% or 22% IVA)
        foreach ($invoice->line_items as $item) {
            $taxRate = $item->tax_rate1 ?? 0;
            if ($taxRate > 0 && !in_array($taxRate, [10.0, 22.0, 10, 22])) {
                // Just a warning, not an error
                nlog("Warning: Unusual IVA rate {$taxRate}% used. Uruguay standard rates are 10% (mínima) and 22% (básica).");
            }
        }

        // Try to generate the CFE to catch any generation errors
        if (empty($errors)) {
            try {
                $cfe = new UruguayCfe($invoice);
                $cfe->run();

                $cfeErrors = $cfe->getErrors();
                foreach ($cfeErrors as $error) {
                    $errors[] = ['key' => 'cfe_generation', 'message' => $error];
                }
            } catch (\Throwable $e) {
                $errors[] = ['key' => 'cfe_generation_exception', 'message' => 'CFE generation failed: ' . $e->getMessage()];
            }
        }

        return $errors;
    }

    /**
     * Validate CAE number range
     */
    public function validateCaeRange(Company $company, int $tipoCfe, int $invoiceNumber): array
    {
        $errors = [];

        if (!isset($company->e_invoice->uruguay_cae)) {
            $errors[] = ['key' => 'cae_config', 'message' => 'CAE configuration not found'];
            return $errors;
        }

        $caeConfig = (array) $company->e_invoice->uruguay_cae;
        $caeKey = 'tipo_' . $tipoCfe;

        if (!isset($caeConfig[$caeKey]) && !isset($caeConfig['default'])) {
            $errors[] = ['key' => 'cae_type', 'message' => "No CAE configured for document type {$tipoCfe}"];
            return $errors;
        }

        $cae = $caeConfig[$caeKey] ?? $caeConfig['default'];

        // Check if number is in range
        if ($invoiceNumber < $cae->d_nro || $invoiceNumber > $cae->h_nro) {
            $errors[] = [
                'key' => 'cae_range',
                'message' => "Invoice number {$invoiceNumber} is outside authorized CAE range ({$cae->d_nro} - {$cae->h_nro})"
            ];
        }

        // Check if CAE is expired
        $expDate = \Carbon\Carbon::parse($cae->fec_venc);
        if ($expDate->isPast()) {
            $errors[] = [
                'key' => 'cae_expired',
                'message' => "CAE expired on {$cae->fec_venc}"
            ];
        }

        // Warning if close to expiration (30 days)
        if ($expDate->diffInDays(now()) < 30) {
            nlog("Warning: CAE expires in less than 30 days ({$cae->fec_venc})");
        }

        // Warning if close to running out of numbers (10%)
        $totalRange = $cae->h_nro - $cae->d_nro + 1;
        $remaining = $cae->h_nro - $invoiceNumber;
        if ($remaining < ($totalRange * 0.10)) {
            nlog("Warning: Less than 10% of CAE numbers remaining ({$remaining} of {$totalRange})");
        }

        return $errors;
    }
}
