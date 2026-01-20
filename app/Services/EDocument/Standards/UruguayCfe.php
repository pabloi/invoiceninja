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

namespace App\Services\EDocument\Standards;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Credit;
use App\Helpers\Invoice\Taxer;
use App\Services\AbstractService;
use App\Helpers\Invoice\InvoiceSum;
use App\Utils\Traits\NumberFormatter;
use App\Helpers\Invoice\InvoiceSumInclusive;
use App\Services\EDocument\Standards\UruguayCfe\Models\IdDoc;
use App\Services\EDocument\Standards\UruguayCfe\Models\Item;
use App\Services\EDocument\Standards\UruguayCfe\Models\Emisor;
use App\Services\EDocument\Standards\UruguayCfe\Models\Totales;
use App\Services\EDocument\Standards\UruguayCfe\Models\Receptor;
use App\Services\EDocument\Standards\UruguayCfe\Models\CAEData;
use App\Services\EDocument\Standards\UruguayCfe\Models\CfeDocument;

/**
 * UruguayCfe - Uruguay CFE (Comprobante Fiscal Electrónico) Standard
 *
 * Generates electronic invoices compliant with Uruguay's DGI e-factura standard.
 * Supports e-Factura (B2B), e-Ticket (B2C), and credit/debit notes.
 *
 * @link https://www.efactura.dgi.gub.uy/
 */
class UruguayCfe extends AbstractService
{
    use Taxer;
    use NumberFormatter;

    /**
     * Uruguay IVA rates
     */
    public const IVA_TASA_MINIMA = 10.00;  // 10%
    public const IVA_TASA_BASICA = 22.00; // 22%

    /**
     * Uruguay country code (ISO 3166-1)
     */
    public const COUNTRY_CODE_URUGUAY = 858;

    private Company $company;
    private InvoiceSum|InvoiceSumInclusive $calc;
    private CfeDocument $cfeDocument;
    private array $errors = [];

    public function __construct(public Invoice|Credit $invoice)
    {
        $this->company = $invoice->company;
        $this->calc = $this->invoice->calc();
        $this->cfeDocument = new CfeDocument();
    }

    /**
     * Entry point for building the CFE document
     */
    public function run(): self
    {
        try {
            $this->buildIdDoc()
                 ->buildEmisor()
                 ->buildReceptor()
                 ->buildItems()
                 ->buildTotales()
                 ->buildCAEData()
                 ->setSignatureTimestamp();

        } catch (\Throwable $e) {
            nlog("Unable to create Uruguay CFE - " . $e->getMessage());
            $this->errors[] = $e->getMessage();
        }

        return $this;
    }

    /**
     * Build document identification (IdDoc)
     */
    private function buildIdDoc(): self
    {
        $idDoc = new IdDoc();

        // Determine document type based on client and invoice type
        $tipoCfe = $this->determineTipoCfe();
        $idDoc->setTipoCfe($tipoCfe);

        // Get series from company settings or default
        $serie = $this->getSerieForTipoCfe($tipoCfe);
        $idDoc->setSerie($serie);

        // Get sequence number from invoice
        $nro = $this->extractInvoiceNumber();
        $idDoc->setNro($nro);

        // Set dates
        $idDoc->setFchEmis($this->invoice->date);

        if ($this->invoice->due_date) {
            $idDoc->setFchVenc($this->invoice->due_date);
        }

        // Set payment form
        $fmaPago = $this->invoice->due_date && $this->invoice->due_date !== $this->invoice->date
            ? IdDoc::FORMA_PAGO_CREDITO
            : IdDoc::FORMA_PAGO_CONTADO;
        $idDoc->setFmaPago($fmaPago);

        $this->cfeDocument->setIdDoc($idDoc);

        return $this;
    }

    /**
     * Build issuer (Emisor) information
     */
    private function buildEmisor(): self
    {
        $emisor = new Emisor();

        // RUT/RUC from VAT number
        $ruc = preg_replace('/[^0-9]/', '', $this->company->settings->vat_number ?? '');
        $emisor->setRucEmisor($ruc);

        // Company name
        $emisor->setRznSoc($this->company->present()->name());

        // Optional: Trade name
        if (!empty($this->company->settings->name)) {
            $emisor->setNombreFantasia($this->company->settings->name);
        }

        // Address
        $emisor->setDomFiscal($this->company->settings->address1 ?? '');
        $emisor->setCiudad($this->company->settings->city ?? '');
        $emisor->setDepartamento($this->company->settings->state ?? '');

        // Contact
        $emisor->setTelefono($this->company->settings->phone ?? null);
        $emisor->setCorreoEmisor($this->company->settings->email ?? null);

        // DGI branch code if configured
        if (isset($this->company->e_invoice->uruguay_sucursal_code)) {
            $emisor->setCdgDgiSucur($this->company->e_invoice->uruguay_sucursal_code);
        }

        $this->cfeDocument->setEmisor($emisor);

        return $this;
    }

    /**
     * Build receiver (Receptor) information
     */
    private function buildReceptor(): self
    {
        $tipoCfe = $this->cfeDocument->getIdDoc()->getTipoCfe();

        // For e-Ticket to final consumer, receptor may be optional
        if (IdDoc::isETicket($tipoCfe) && !$this->clientHasIdentification()) {
            return $this;
        }

        $receptor = new Receptor();
        $client = $this->invoice->client;

        // Client identification
        if ($client->country_id == self::COUNTRY_CODE_URUGUAY) {
            // Uruguayan client
            if (!empty($client->vat_number)) {
                // RUT
                $receptor->setTipoDocRecep(Receptor::TIPO_DOC_RUC);
                $receptor->setDocRecep(preg_replace('/[^0-9]/', '', $client->vat_number));
            } elseif (!empty($client->id_number)) {
                // CI (Cédula de Identidad)
                $receptor->setTipoDocRecep(Receptor::TIPO_DOC_CI);
                $receptor->setDocRecep($client->id_number);
            }
        } else {
            // Foreign client
            $receptor->setTipoDocRecep(Receptor::TIPO_DOC_PASAPORTE);
            $receptor->setCodPaisRecep($client->country->iso_3166_2 ?? 'XX');
            $receptor->setDocRecep($client->vat_number ?? $client->id_number ?? '');
        }

        // Client name
        $receptor->setRznSocRecep($client->present()->name());

        // Address
        $receptor->setDirRecep($client->address1 ?? '');
        $receptor->setCiudadRecep($client->city ?? '');
        $receptor->setDeptoRecep($client->state ?? '');
        $receptor->setPaisRecep($client->country->name ?? 'Uruguay');

        // Contact
        if (!empty($client->phone)) {
            $receptor->setTelefonoRecep($client->phone);
        }

        $primaryContact = $client->contacts()->first();
        if ($primaryContact && !empty($primaryContact->email)) {
            $receptor->setCorreoRecep($primaryContact->email);
        }

        $this->cfeDocument->setReceptor($receptor);

        return $this;
    }

    /**
     * Build line items (Detalle)
     */
    private function buildItems(): self
    {
        $lineNumber = 1;

        foreach ($this->invoice->line_items as $lineItem) {
            $item = new Item();

            $item->setNroLinDet($lineNumber);

            // Product code
            if (!empty($lineItem->product_key)) {
                $item->setCodItem($lineItem->product_key);
            }

            // Determine tax indicator
            $indFact = $this->determineIndFact($lineItem);
            $item->setIndFact($indFact);

            // Item name/description
            $nomItem = !empty($lineItem->product_key) ? $lineItem->product_key : 'Producto/Servicio';
            if (!empty($lineItem->notes)) {
                $nomItem = substr($lineItem->notes, 0, 80);
            }
            $item->setNomItem($nomItem);

            // Quantity and unit
            $item->setCantidad($lineItem->quantity);
            if (!empty($lineItem->unit_code)) {
                $item->setUniMed($lineItem->unit_code);
            }

            // Price
            $item->setPrecioUnitario($lineItem->cost);

            // Discount
            if ($lineItem->discount > 0) {
                if ($lineItem->is_amount_discount ?? false) {
                    $item->setDescuento($lineItem->discount);
                } else {
                    $item->setDescuentoPct($lineItem->discount);
                }
            }

            // Line total
            $montoItem = $this->invoice->uses_inclusive_taxes
                ? $lineItem->gross_line_total - $this->calcInclusiveLineTax($lineItem->tax_rate1, $lineItem->line_total)
                : $lineItem->line_total;
            $item->setMontoItem(round($montoItem, 2));

            $this->cfeDocument->addItem($item);
            $lineNumber++;
        }

        return $this;
    }

    /**
     * Build totals (Totales)
     */
    private function buildTotales(): self
    {
        $totales = new Totales();

        // Currency
        $currency = $this->invoice->client->currency()->code ?? 'UYU';
        $totales->setTpoMoneda($currency);

        // Exchange rate for foreign currency
        if ($currency !== 'UYU' && $this->invoice->exchange_rate > 0) {
            $totales->setTipoCambio($this->invoice->exchange_rate);
        }

        // Calculate tax breakdowns
        $mntNoGrv = 0;      // Non-taxable
        $mntNetoMin = 0;    // Net at minimum rate
        $mntNetoBasica = 0; // Net at basic rate
        $ivaMin = 0;        // IVA at minimum rate
        $ivaBasica = 0;     // IVA at basic rate

        foreach ($this->invoice->line_items as $lineItem) {
            $lineTotal = $lineItem->line_total;

            if ($lineItem->tax_rate1 == 0 && $lineItem->tax_rate2 == 0 && $lineItem->tax_rate3 == 0) {
                // Non-taxable
                $mntNoGrv += $lineTotal;
            } elseif (abs($lineItem->tax_rate1 - self::IVA_TASA_MINIMA) < 0.01) {
                // Minimum rate (10%)
                $mntNetoMin += $lineTotal;
                $ivaMin += $this->calculateTaxAmount($lineItem);
            } elseif (abs($lineItem->tax_rate1 - self::IVA_TASA_BASICA) < 0.01) {
                // Basic rate (22%)
                $mntNetoBasica += $lineTotal;
                $ivaBasica += $this->calculateTaxAmount($lineItem);
            } else {
                // Default to basic rate for any other rate
                $mntNetoBasica += $lineTotal;
                $ivaBasica += $this->calculateTaxAmount($lineItem);
            }
        }

        if ($mntNoGrv > 0) {
            $totales->setMntNoGrv(round($mntNoGrv, 2));
        }

        if ($mntNetoMin > 0) {
            $totales->setMntNetoIvaTasaMin(round($mntNetoMin, 2));
            $totales->setTasaMinIVA(self::IVA_TASA_MINIMA);
            $totales->setIvaTasaMin(round($ivaMin, 2));
        }

        if ($mntNetoBasica > 0) {
            $totales->setMntNetoIVATasaBasica(round($mntNetoBasica, 2));
            $totales->setTasaBasicaIVA(self::IVA_TASA_BASICA);
            $totales->setIvaTasaBasica(round($ivaBasica, 2));
        }

        // Total amounts
        $totales->setMontoTotal(round($this->invoice->amount, 2));
        $totales->setCantLinDet(count($this->invoice->line_items));
        $totales->setMontoPagar(round($this->invoice->amount, 2));

        $this->cfeDocument->setTotales($totales);

        return $this;
    }

    /**
     * Build CAE authorization data
     */
    private function buildCAEData(): self
    {
        // Get CAE configuration from company e_invoice settings
        if (!isset($this->company->e_invoice->uruguay_cae)) {
            $this->errors[] = 'CAE configuration not found in company settings';
            return $this;
        }

        $caeConfig = (array) $this->company->e_invoice->uruguay_cae;
        $tipoCfe = $this->cfeDocument->getIdDoc()->getTipoCfe();

        // Find CAE for the specific document type
        $caeKey = 'tipo_' . $tipoCfe;
        if (!isset($caeConfig[$caeKey])) {
            // Try generic CAE
            $caeKey = 'default';
            if (!isset($caeConfig[$caeKey])) {
                $this->errors[] = "No CAE configured for document type {$tipoCfe}";
                return $this;
            }
        }

        $caeData = CAEData::fromArray((array) $caeConfig[$caeKey]);
        $this->cfeDocument->setCaeData($caeData);

        return $this;
    }

    /**
     * Set signature timestamp
     */
    private function setSignatureTimestamp(): self
    {
        $this->cfeDocument->setTmstFirma(now()->format('Y-m-d\TH:i:s'));
        return $this;
    }

    /**
     * Determine the CFE type based on client and invoice type
     */
    private function determineTipoCfe(): int
    {
        $isCredit = $this->invoice instanceof Credit || $this->invoice->amount < 0;
        $isB2B = $this->clientHasRut();

        if ($isCredit) {
            return $isB2B
                ? IdDoc::TIPO_NOTA_CREDITO_E_FACTURA
                : IdDoc::TIPO_NOTA_CREDITO_E_TICKET;
        }

        return $isB2B
            ? IdDoc::TIPO_E_FACTURA
            : IdDoc::TIPO_E_TICKET;
    }

    /**
     * Check if client has RUT (Uruguayan tax ID)
     */
    private function clientHasRut(): bool
    {
        $client = $this->invoice->client;

        // Check if Uruguayan client with VAT number
        if ($client->country_id == self::COUNTRY_CODE_URUGUAY && !empty($client->vat_number)) {
            return true;
        }

        // Foreign company with tax ID
        if ($client->country_id != self::COUNTRY_CODE_URUGUAY &&
            !empty($client->vat_number) &&
            ($client->classification ?? 'company') !== 'individual') {
            return true;
        }

        return false;
    }

    /**
     * Check if client has identification document
     */
    private function clientHasIdentification(): bool
    {
        $client = $this->invoice->client;
        return !empty($client->vat_number) || !empty($client->id_number);
    }

    /**
     * Get the series code for the document type
     */
    private function getSerieForTipoCfe(int $tipoCfe): string
    {
        // Check company settings for configured series
        if (isset($this->company->e_invoice->uruguay_series[$tipoCfe])) {
            return $this->company->e_invoice->uruguay_series[$tipoCfe];
        }

        // Default series by document type
        return match ($tipoCfe) {
            IdDoc::TIPO_E_FACTURA, IdDoc::TIPO_NOTA_CREDITO_E_FACTURA, IdDoc::TIPO_NOTA_DEBITO_E_FACTURA => 'AA',
            IdDoc::TIPO_E_TICKET, IdDoc::TIPO_NOTA_CREDITO_E_TICKET, IdDoc::TIPO_NOTA_DEBITO_E_TICKET => 'BB',
            default => 'AA',
        };
    }

    /**
     * Extract numeric invoice number
     */
    private function extractInvoiceNumber(): int
    {
        // Try to extract number from invoice number
        $number = preg_replace('/[^0-9]/', '', $this->invoice->number);

        if (empty($number)) {
            $number = $this->invoice->id;
        }

        return (int) $number;
    }

    /**
     * Determine the IndFact code based on tax configuration
     */
    private function determineIndFact($lineItem): int
    {
        $taxRate1 = $lineItem->tax_rate1 ?? 0;
        $taxRate2 = $lineItem->tax_rate2 ?? 0;
        $taxRate3 = $lineItem->tax_rate3 ?? 0;

        // No taxes
        if ($taxRate1 == 0 && $taxRate2 == 0 && $taxRate3 == 0) {
            // Check if it's an export
            if ($this->invoice->client->country_id != self::COUNTRY_CODE_URUGUAY) {
                return Item::IND_FACT_EXPORTACIONES;
            }
            return Item::IND_FACT_EXENTO_IVA;
        }

        // Check for IVA rates
        if (abs($taxRate1 - self::IVA_TASA_MINIMA) < 0.01) {
            return Item::IND_FACT_GRAVADO_TASA_MIN;
        }

        if (abs($taxRate1 - self::IVA_TASA_BASICA) < 0.01) {
            return Item::IND_FACT_GRAVADO_TASA_BAS;
        }

        // Other tax rate
        return Item::IND_FACT_GRAVADO_OTRA;
    }

    /**
     * Calculate tax amount for a line item
     */
    private function calculateTaxAmount($lineItem): float
    {
        $lineTotal = $lineItem->line_total;
        $taxRate = $lineItem->tax_rate1 ?? 0;

        if ($this->invoice->uses_inclusive_taxes) {
            return $lineTotal - ($lineTotal / (1 + $taxRate / 100));
        }

        return $lineTotal * ($taxRate / 100);
    }

    /**
     * Get the CFE document
     */
    public function getCfeDocument(): CfeDocument
    {
        return $this->cfeDocument;
    }

    /**
     * Convert to XML string
     */
    public function toXml(): string
    {
        return $this->cfeDocument->toXmlString();
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'cfe' => [
                'version' => $this->cfeDocument->getVersion(),
                'tipo_cfe' => $this->cfeDocument->getIdDoc()->getTipoCfe(),
                'serie' => $this->cfeDocument->getIdDoc()->getSerie(),
                'numero' => $this->cfeDocument->getIdDoc()->getNro(),
                'fecha_emision' => $this->cfeDocument->getIdDoc()->getFchEmis(),
                'emisor' => [
                    'ruc' => $this->cfeDocument->getEmisor()->getRucEmisor(),
                    'razon_social' => $this->cfeDocument->getEmisor()->getRznSoc(),
                ],
                'receptor' => $this->cfeDocument->getReceptor() ? [
                    'documento' => $this->cfeDocument->getReceptor()->getDocRecep(),
                    'razon_social' => $this->cfeDocument->getReceptor()->getRznSocRecep(),
                ] : null,
                'totales' => [
                    'moneda' => $this->cfeDocument->getTotales()->getTpoMoneda(),
                    'total' => $this->cfeDocument->getTotales()->getMontoTotal(),
                    'iva_min' => $this->cfeDocument->getTotales()->getIvaTasaMin(),
                    'iva_basica' => $this->cfeDocument->getTotales()->getIvaTasaBasica(),
                ],
                'items_count' => count($this->cfeDocument->getItems()),
            ],
        ];
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        $documentErrors = $this->cfeDocument->validate();
        return array_merge($this->errors, $documentErrors);
    }

    /**
     * Check if the document is valid
     */
    public function isValid(): bool
    {
        return empty($this->getErrors());
    }

    /**
     * Configure signing certificates
     */
    public function configureSigning(string $certificatePath, string $privateKeyPath, ?string $password = null): self
    {
        $this->cfeDocument->setCertificatePath($certificatePath);
        $this->cfeDocument->setPrivateKeyPath($privateKeyPath);
        $this->cfeDocument->setCertificatePassword($password);

        return $this;
    }

    /**
     * Add reference to another CFE (for credit/debit notes)
     */
    public function addCfeReference(int $tipoCfeRef, string $serie, int $nroCfeRef, string $fechaRef, string $razonRef): self
    {
        $this->cfeDocument->addReferencia([
            'NroLinRef' => count($this->cfeDocument->getReferencias()) + 1,
            'TpoDocRef' => $tipoCfeRef,
            'Serie' => $serie,
            'NroCFERef' => $nroCfeRef,
            'FechaCFERef' => $fechaRef,
            'RazonRef' => $razonRef,
        ]);

        return $this;
    }
}
