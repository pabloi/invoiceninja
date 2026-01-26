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

namespace App\Services\EDocument\Standards\UruguayCfe;

use App\Services\EDocument\Standards\UruguayCfe\Models\CfeDocument;

/**
 * CfeValidator - Validates CFE documents against DGI XSD schemas
 *
 * Provides validation of CFE documents against Uruguay DGI's XML schemas
 * and business rules.
 *
 * @link https://www.efactura.dgi.gub.uy/principal/ampliacion_de_contenido/documentos-de-interes
 */
class CfeValidator
{
    /**
     * Schema file paths
     */
    protected string $schemaPath;

    /**
     * Validation errors
     */
    protected array $errors = [];

    /**
     * Validation warnings
     */
    protected array $warnings = [];

    /**
     * Uruguay IVA rates
     */
    public const IVA_TASA_MINIMA = 10.00;
    public const IVA_TASA_BASICA = 22.00;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath ?? storage_path('app/dgi/schemas');
    }

    /**
     * Validate a CFE document
     */
    public function validate(CfeDocument $cfe): bool
    {
        $this->errors = [];
        $this->warnings = [];

        // Generate XML
        $xml = $cfe->toXmlString();

        // Run all validations
        $this->validateXmlWellFormed($xml);
        $this->validateAgainstSchema($xml);
        $this->validateBusinessRules($cfe);
        $this->validateTaxCalculations($cfe);

        return empty($this->errors);
    }

    /**
     * Validate XML string directly
     */
    public function validateXml(string $xml): bool
    {
        $this->errors = [];
        $this->warnings = [];

        $this->validateXmlWellFormed($xml);
        $this->validateAgainstSchema($xml);

        return empty($this->errors);
    }

    /**
     * Check if XML is well-formed
     */
    protected function validateXmlWellFormed(string $xml): void
    {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $result = $doc->loadXML($xml);

        if (!$result) {
            foreach (libxml_get_errors() as $error) {
                $this->errors[] = [
                    'code' => 'XML_PARSE_ERROR',
                    'message' => trim($error->message),
                    'line' => $error->line,
                    'column' => $error->column,
                ];
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors(false);
    }

    /**
     * Validate against XSD schema
     */
    protected function validateAgainstSchema(string $xml): void
    {
        $schemaFile = $this->schemaPath . '/CFE.xsd';

        if (!file_exists($schemaFile)) {
            $this->warnings[] = [
                'code' => 'SCHEMA_NOT_FOUND',
                'message' => "XSD schema not found at: {$schemaFile}. Download from DGI for production validation.",
            ];
            return;
        }

        libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument();
            $doc->loadXML($xml);

            if (!$doc->schemaValidate($schemaFile)) {
                foreach (libxml_get_errors() as $error) {
                    $this->errors[] = [
                        'code' => 'XSD_VALIDATION_ERROR',
                        'message' => trim($error->message),
                        'line' => $error->line,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Handle network failures or other schema validation issues
            $this->warnings[] = [
                'code' => 'SCHEMA_VALIDATION_FAILED',
                'message' => "Schema validation could not complete: " . $e->getMessage(),
            ];
        }

        libxml_clear_errors();
        libxml_use_internal_errors(false);
    }

    /**
     * Validate business rules
     */
    protected function validateBusinessRules(CfeDocument $cfe): void
    {
        $idDoc = $cfe->getIdDoc();
        $emisor = $cfe->getEmisor();
        $receptor = $cfe->getReceptor();
        $totales = $cfe->getTotales();
        $items = $cfe->getItems();
        $caeData = $cfe->getCaeData();

        // Rule: Serie format (v1.43.5: [1-9A-Z][A-Z])
        try {
            $serie = $idDoc->getSerie();
            if (!preg_match('/^[1-9A-Z][A-Z]$/', $serie)) {
                $this->errors[] = [
                    'code' => 'INVALID_SERIE',
                    'message' => "Serie '{$serie}' must be 2 characters: [1-9A-Z][A-Z] (per XSD v1.43.5)",
                ];
            }
        } catch (\Throwable $e) {
            $this->errors[] = [
                'code' => 'MISSING_SERIE',
                'message' => 'Serie is required',
            ];
        }

        // Rule: RUC format (12 digits max)
        try {
            $ruc = $emisor->getRucEmisor();
            if (!preg_match('/^[0-9]{1,12}$/', $ruc)) {
                $this->errors[] = [
                    'code' => 'INVALID_RUC',
                    'message' => "RUCEmisor '{$ruc}' must be numeric, max 12 digits",
                ];
            }
        } catch (\Throwable $e) {
            $this->errors[] = [
                'code' => 'MISSING_RUC',
                'message' => 'RUCEmisor is required',
            ];
        }

        // Rule: e-Factura requires Receptor
        try {
            $tipoCfe = $idDoc->getTipoCfe();
            if (in_array($tipoCfe, [111, 112, 113, 211, 212, 213])) {
                if ($receptor === null || !$receptor->hasIdentification()) {
                    $this->errors[] = [
                        'code' => 'RECEPTOR_REQUIRED',
                        'message' => 'Receptor with identification is required for e-Factura',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Skip if tipoCfe not set
        }

        // Rule: Credit/Debit notes require reference
        try {
            $tipoCfe = $idDoc->getTipoCfe();
            if (in_array($tipoCfe, [102, 103, 112, 113, 202, 203, 212, 213])) {
                if (empty($cfe->getReferencias())) {
                    $this->errors[] = [
                        'code' => 'REFERENCE_REQUIRED',
                        'message' => 'Credit/Debit notes must reference the original document',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Skip if tipoCfe not set
        }

        // Rule: At least one item
        if (empty($items)) {
            $this->errors[] = [
                'code' => 'NO_ITEMS',
                'message' => 'At least one line item is required',
            ];
        }

        // Rule: Line numbers must be sequential
        $lineNumbers = array_map(fn($item) => $item->getNroLinDet(), $items);
        $expected = range(1, count($items));
        if ($lineNumbers !== $expected) {
            $this->warnings[] = [
                'code' => 'LINE_NUMBERS',
                'message' => 'Line numbers should be sequential starting from 1',
            ];
        }

        // Rule: CAE validation
        if ($caeData !== null) {
            try {
                $nro = $idDoc->getNro();
                if (!$caeData->isNumberInRange($nro)) {
                    $this->errors[] = [
                        'code' => 'CAE_RANGE',
                        'message' => "Document number {$nro} is outside CAE range ({$caeData->getDNro()}-{$caeData->getHNro()})",
                    ];
                }

                if ($caeData->isExpired()) {
                    $this->errors[] = [
                        'code' => 'CAE_EXPIRED',
                        'message' => "CAE expired on {$caeData->getFecVenc()}",
                    ];
                }

                // Warning if close to expiration
                if ($caeData->getDaysUntilExpiration() < 30) {
                    $this->warnings[] = [
                        'code' => 'CAE_EXPIRING_SOON',
                        'message' => "CAE expires in {$caeData->getDaysUntilExpiration()} days",
                    ];
                }
            } catch (\Throwable $e) {
                // Skip if required fields not set
            }
        }

        // Rule: Currency code format
        $currency = $totales->getTpoMoneda();
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->errors[] = [
                'code' => 'INVALID_CURRENCY',
                'message' => "Currency code '{$currency}' must be 3 uppercase letters (ISO 4217)",
            ];
        }

        // Rule: Exchange rate required for foreign currency
        if ($currency !== 'UYU' && $totales->getTipoCambio() === null) {
            $this->warnings[] = [
                'code' => 'EXCHANGE_RATE',
                'message' => 'Exchange rate (TpoCambio) recommended for foreign currency',
            ];
        }
    }

    /**
     * Validate tax calculations
     */
    protected function validateTaxCalculations(CfeDocument $cfe): void
    {
        $totales = $cfe->getTotales();
        $items = $cfe->getItems();

        // Calculate line items total
        $itemsTotal = 0;
        foreach ($items as $item) {
            $itemsTotal += $item->getMontoItem();
        }

        // Verify CantLinDet matches actual count
        if ($totales->getCantLinDet() !== count($items)) {
            $this->errors[] = [
                'code' => 'LINE_COUNT_MISMATCH',
                'message' => "CantLinDet ({$totales->getCantLinDet()}) does not match actual line count (" . count($items) . ")",
            ];
        }

        // Verify IVA calculations (with tolerance for rounding)
        $tolerance = 0.02; // 2 centavos tolerance

        // Check IVA tasa mínima
        if ($totales->getMntNetoIvaTasaMin() > 0) {
            $expectedIva = $totales->getMntNetoIvaTasaMin() * (self::IVA_TASA_MINIMA / 100);
            $actualIva = $totales->getIvaTasaMin();
            if (abs($expectedIva - $actualIva) > $tolerance) {
                $this->warnings[] = [
                    'code' => 'IVA_MIN_CALCULATION',
                    'message' => "IVA Tasa Mínima calculation may be incorrect. Expected: {$expectedIva}, Got: {$actualIva}",
                ];
            }
        }

        // Check IVA tasa básica
        if ($totales->getMntNetoIVATasaBasica() > 0) {
            $expectedIva = $totales->getMntNetoIVATasaBasica() * (self::IVA_TASA_BASICA / 100);
            $actualIva = $totales->getIvaTasaBasica();
            if (abs($expectedIva - $actualIva) > $tolerance) {
                $this->warnings[] = [
                    'code' => 'IVA_BASIC_CALCULATION',
                    'message' => "IVA Tasa Básica calculation may be incorrect. Expected: {$expectedIva}, Got: {$actualIva}",
                ];
            }
        }

        // Verify MntPagar equals MntTotal (unless there are retentions)
        if ($totales->getRetencPercib() === null) {
            if (abs($totales->getMontoPagar() - $totales->getMontoTotal()) > $tolerance) {
                $this->warnings[] = [
                    'code' => 'PAYMENT_MISMATCH',
                    'message' => "MntPagar should equal MntTotal when no retentions apply",
                ];
            }
        }
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get validation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get all issues (errors + warnings)
     */
    public function getAllIssues(): array
    {
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Check if validation passed (no errors)
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * Generate validation report
     */
    public function getReport(): string
    {
        $report = "CFE Validation Report\n";
        $report .= str_repeat("=", 50) . "\n\n";

        if (empty($this->errors) && empty($this->warnings)) {
            $report .= "✓ Document passed all validations\n";
            return $report;
        }

        if (!empty($this->errors)) {
            $report .= "ERRORS (" . count($this->errors) . "):\n";
            foreach ($this->errors as $error) {
                $report .= "  ✗ [{$error['code']}] {$error['message']}";
                if (isset($error['line'])) {
                    $report .= " (line {$error['line']})";
                }
                $report .= "\n";
            }
            $report .= "\n";
        }

        if (!empty($this->warnings)) {
            $report .= "WARNINGS (" . count($this->warnings) . "):\n";
            foreach ($this->warnings as $warning) {
                $report .= "  ⚠ [{$warning['code']}] {$warning['message']}\n";
            }
        }

        return $report;
    }

    /**
     * Validate XML string and return detailed result
     */
    public static function validateDocument(string $xml): array
    {
        $validator = new self();
        $isValid = $validator->validateXml($xml);

        return [
            'valid' => $isValid,
            'errors' => $validator->getErrors(),
            'warnings' => $validator->getWarnings(),
            'report' => $validator->getReport(),
        ];
    }
}
