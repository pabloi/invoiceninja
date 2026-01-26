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

namespace Tests\Feature\EInvoice\UruguayCfe;

use Tests\TestCase;
use App\Services\EDocument\Standards\UruguayCfe\Models\IdDoc;
use App\Services\EDocument\Standards\UruguayCfe\Models\Item;
use App\Services\EDocument\Standards\UruguayCfe\Models\Emisor;
use App\Services\EDocument\Standards\UruguayCfe\Models\Totales;
use App\Services\EDocument\Standards\UruguayCfe\Models\Receptor;
use App\Services\EDocument\Standards\UruguayCfe\Models\CAEData;
use App\Services\EDocument\Standards\UruguayCfe\Models\CfeDocument;

/**
 * XML Validation Tests for Uruguay CFE
 *
 * These tests validate that the generated XML structure conforms to
 * DGI's CFE schema requirements.
 *
 * @see https://www.efactura.dgi.gub.uy/principal/ampliacion_de_contenido/documentos-de-interes
 */
class UruguayCfeXmlValidationTest extends TestCase
{
    /**
     * Test that e-Factura XML contains all required elements
     */
    public function test_efactura_contains_required_elements(): void
    {
        $cfe = $this->createCompleteEFactura();
        $xml = $cfe->toXmlString();

        // Required root element
        $this->assertStringContainsString('<eFact', $xml);
        $this->assertStringContainsString('version="1.0"', $xml);

        // Required Encabezado elements
        $this->assertStringContainsString('<Encabezado>', $xml);
        $this->assertStringContainsString('<IdDoc>', $xml);
        $this->assertStringContainsString('<TipoCFE>', $xml);
        $this->assertStringContainsString('<Serie>', $xml);
        $this->assertStringContainsString('<Nro>', $xml);
        $this->assertStringContainsString('<FchEmis>', $xml);
        $this->assertStringContainsString('<FmaPago>', $xml);

        // Required Emisor elements
        $this->assertStringContainsString('<Emisor>', $xml);
        $this->assertStringContainsString('<RUCEmisor>', $xml);
        $this->assertStringContainsString('<RznSoc>', $xml);

        // Required Receptor elements for e-Factura
        $this->assertStringContainsString('<Receptor>', $xml);
        $this->assertStringContainsString('<DocRecep>', $xml);
        $this->assertStringContainsString('<RznSocRecep>', $xml);

        // Required Totales elements
        $this->assertStringContainsString('<Totales>', $xml);
        $this->assertStringContainsString('<TpoMoneda>', $xml);
        $this->assertStringContainsString('<MntTotal>', $xml);
        $this->assertStringContainsString('<CantLinDet>', $xml);
        $this->assertStringContainsString('<MntPagar>', $xml);

        // Required Detalle elements
        $this->assertStringContainsString('<Detalle>', $xml);
        $this->assertStringContainsString('<Item>', $xml);
        $this->assertStringContainsString('<NroLinDet>', $xml);
        $this->assertStringContainsString('<IndFact>', $xml);
        $this->assertStringContainsString('<NomItem>', $xml);
        $this->assertStringContainsString('<Cantidad>', $xml);
        $this->assertStringContainsString('<PrecioUnitario>', $xml);
        $this->assertStringContainsString('<MontoItem>', $xml);

        // Required CAEData elements
        $this->assertStringContainsString('<CAEData>', $xml);
        $this->assertStringContainsString('<CAE_ID>', $xml);
        $this->assertStringContainsString('<DNro>', $xml);
        $this->assertStringContainsString('<HNro>', $xml);
        $this->assertStringContainsString('<FecVenc>', $xml);
    }

    /**
     * Test e-Ticket XML structure (B2C - receptor optional)
     */
    public function test_eticket_without_receptor(): void
    {
        $cfe = $this->createETicketWithoutReceptor();
        $xml = $cfe->toXmlString();

        // Should use eTck wrapper
        $this->assertStringContainsString('<eTck', $xml);

        // Should NOT require Receptor for e-Ticket
        $this->assertStringNotContainsString('<Receptor>', $xml);

        // Should still have all other required elements
        $this->assertStringContainsString('<Encabezado>', $xml);
        $this->assertStringContainsString('<TipoCFE>101</TipoCFE>', $xml);
        $this->assertStringContainsString('<Emisor>', $xml);
        $this->assertStringContainsString('<Detalle>', $xml);
        $this->assertStringContainsString('<CAEData>', $xml);
    }

    /**
     * Test credit note references required fields
     */
    public function test_credit_note_with_reference(): void
    {
        $cfe = $this->createCreditNoteWithReference();
        $xml = $cfe->toXmlString();

        // Should be credit note type
        $this->assertStringContainsString('<TipoCFE>112</TipoCFE>', $xml);

        // Should have reference to original invoice
        $this->assertStringContainsString('<Referencia>', $xml);
        $this->assertStringContainsString('<NroLinRef>', $xml);
        $this->assertStringContainsString('<TpoDocRef>', $xml);
        $this->assertStringContainsString('<NroCFERef>', $xml);
        $this->assertStringContainsString('<RazonRef>', $xml);
    }

    /**
     * Test IVA breakdown in Totales
     */
    public function test_iva_breakdown_structure(): void
    {
        $cfe = $this->createDocumentWithIva();
        $xml = $cfe->toXmlString();

        // Should have IVA tasa básica (22%)
        $this->assertStringContainsString('<MntNetoIVATasaBasica>', $xml);
        $this->assertStringContainsString('<IVATasaBasica>22.00</IVATasaBasica>', $xml);
        $this->assertStringContainsString('<MontoIVATasaBasica>', $xml);
    }

    /**
     * Test foreign currency with exchange rate
     */
    public function test_foreign_currency_exchange_rate(): void
    {
        $cfe = $this->createDocumentWithForeignCurrency();
        $xml = $cfe->toXmlString();

        $this->assertStringContainsString('<TpoMoneda>USD</TpoMoneda>', $xml);
        $this->assertStringContainsString('<TpoCambio>', $xml);
    }

    /**
     * Test XML is well-formed
     */
    public function test_xml_is_well_formed(): void
    {
        $cfe = $this->createCompleteEFactura();
        $xml = $cfe->toXmlString();

        // Should be valid XML
        $doc = new \DOMDocument();
        $result = $doc->loadXML($xml);

        $this->assertTrue($result, 'Generated XML should be well-formed');
    }

    /**
     * Test date format compliance (YYYY-MM-DD)
     */
    public function test_date_format_compliance(): void
    {
        $cfe = $this->createCompleteEFactura();
        $xml = $cfe->toXmlString();

        // Date should be in YYYY-MM-DD format
        $this->assertMatchesRegularExpression(
            '/<FchEmis>\d{4}-\d{2}-\d{2}<\/FchEmis>/',
            $xml,
            'FchEmis should be in YYYY-MM-DD format'
        );

        $this->assertMatchesRegularExpression(
            '/<FecVenc>\d{4}-\d{2}-\d{2}<\/FecVenc>/',
            $xml,
            'FecVenc should be in YYYY-MM-DD format'
        );
    }

    /**
     * Test amount formatting (2 decimal places)
     */
    public function test_amount_formatting(): void
    {
        $cfe = $this->createCompleteEFactura();
        $xml = $cfe->toXmlString();

        // Amounts should have 2 decimal places
        $this->assertMatchesRegularExpression(
            '/<MntTotal>\d+\.\d{2}<\/MntTotal>/',
            $xml,
            'MntTotal should have 2 decimal places'
        );

        $this->assertMatchesRegularExpression(
            '/<MontoItem>\d+\.\d{2}<\/MontoItem>/',
            $xml,
            'MontoItem should have 2 decimal places'
        );
    }

    /**
     * Test quantity formatting (6 decimal places)
     */
    public function test_quantity_formatting(): void
    {
        $cfe = $this->createCompleteEFactura();
        $xml = $cfe->toXmlString();

        // Quantities should have 6 decimal places
        $this->assertMatchesRegularExpression(
            '/<Cantidad>\d+\.\d{6}<\/Cantidad>/',
            $xml,
            'Cantidad should have 6 decimal places'
        );
    }

    /**
     * Test RUT format (12 digits max)
     */
    public function test_rut_format(): void
    {
        $cfe = $this->createCompleteEFactura();
        $xml = $cfe->toXmlString();

        // RUCEmisor should be numeric only, max 12 digits
        $this->assertMatchesRegularExpression(
            '/<RUCEmisor>\d{1,12}<\/RUCEmisor>/',
            $xml,
            'RUCEmisor should be numeric, max 12 digits'
        );
    }

    /**
     * Test Serie format (2 chars: number/letter + letter)
     */
    public function test_serie_format(): void
    {
        $cfe = $this->createCompleteEFactura();
        $xml = $cfe->toXmlString();

        // Serie should be 2 characters: [1-9A-Z][A-Z]
        $this->assertMatchesRegularExpression(
            '/<Serie>[1-9A-Z][A-Z]<\/Serie>/',
            $xml,
            'Serie should be 2 chars: number(1-9)/letter + letter'
        );
    }

    /**
     * Test document number range validation
     */
    public function test_document_number_in_cae_range(): void
    {
        $cfe = new CfeDocument();

        // Set up document with number 150000
        $cfe->getIdDoc()
            ->setTipoCfe(IdDoc::TIPO_E_FACTURA)
            ->setSerie('AA')
            ->setNro(150000)
            ->setFchEmis('2025-01-20');

        $cfe->getEmisor()
            ->setRucEmisor('123456789012')
            ->setRznSoc('Test Company');

        // Set CAE with range 100000-200000
        $caeData = new CAEData();
        $caeData->setCaeId('CAE123456')
                ->setDNro(100000)
                ->setHNro(200000)
                ->setFecVenc('2026-12-31');
        $cfe->setCaeData($caeData);

        // Should be valid - number is in range
        $this->assertTrue($caeData->isNumberInRange(150000));

        // Number outside range should fail
        $this->assertFalse($caeData->isNumberInRange(50000));
        $this->assertFalse($caeData->isNumberInRange(250000));
    }

    /**
     * Test timestamp format for signature
     */
    public function test_timestamp_format(): void
    {
        $cfe = $this->createCompleteEFactura();
        $cfe->setTmstFirma('2025-01-20T10:30:00');
        $xml = $cfe->toXmlString();

        // TmstFirma should be in ISO 8601 format
        $this->assertMatchesRegularExpression(
            '/<TmstFirma>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}<\/TmstFirma>/',
            $xml,
            'TmstFirma should be in ISO 8601 format'
        );
    }

    // ========== Helper Methods ==========

    private function createCompleteEFactura(): CfeDocument
    {
        $cfe = new CfeDocument();

        $cfe->getIdDoc()
            ->setTipoCfe(IdDoc::TIPO_E_FACTURA)
            ->setSerie('AA')
            ->setNro(123456)
            ->setFchEmis('2025-01-20')
            ->setFmaPago(IdDoc::FORMA_PAGO_CONTADO);

        $cfe->getEmisor()
            ->setRucEmisor('213456789012')
            ->setRznSoc('Empresa Prueba SRL')
            ->setDomFiscal('Av. 18 de Julio 1234')
            ->setCiudad('Montevideo')
            ->setDepartamento('Montevideo');

        $receptor = new Receptor();
        $receptor->setTipoDocRecep(Receptor::TIPO_DOC_RUC)
                 ->setDocRecep('987654321098')
                 ->setRznSocRecep('Cliente Prueba SA');
        $cfe->setReceptor($receptor);

        $item = new Item();
        $item->setNroLinDet(1)
             ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
             ->setNomItem('Servicio de Consultoría')
             ->setCantidad(1)
             ->setPrecioUnitario(1000.00)
             ->setMontoItem(1000.00);
        $cfe->addItem($item);

        $cfe->getTotales()
            ->setTpoMoneda('UYU')
            ->setMntNetoIVATasaBasica(1000.00)
            ->setTasaBasicaIVA(22.00)
            ->setIvaTasaBasica(220.00)
            ->setMontoTotal(1220.00)
            ->setCantLinDet(1)
            ->setMontoPagar(1220.00);

        $caeData = new CAEData();
        $caeData->setCaeId('90123456789012345678')
                ->setDNro(100000)
                ->setHNro(200000)
                ->setFecVenc('2026-12-31');
        $cfe->setCaeData($caeData);

        return $cfe;
    }

    private function createETicketWithoutReceptor(): CfeDocument
    {
        $cfe = new CfeDocument();

        $cfe->getIdDoc()
            ->setTipoCfe(IdDoc::TIPO_E_TICKET)
            ->setSerie('BB')
            ->setNro(100001)
            ->setFchEmis('2025-01-20')
            ->setFmaPago(IdDoc::FORMA_PAGO_CONTADO);

        $cfe->getEmisor()
            ->setRucEmisor('213456789012')
            ->setRznSoc('Tienda Ejemplo SRL');

        $item = new Item();
        $item->setNroLinDet(1)
             ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
             ->setNomItem('Producto Ejemplo')
             ->setCantidad(2)
             ->setPrecioUnitario(500.00)
             ->setMontoItem(1000.00);
        $cfe->addItem($item);

        $cfe->getTotales()
            ->setTpoMoneda('UYU')
            ->setMontoTotal(1220.00)
            ->setCantLinDet(1)
            ->setMontoPagar(1220.00);

        $caeData = new CAEData();
        $caeData->setCaeId('CAE_TICKET_123')
                ->setDNro(100000)
                ->setHNro(200000)
                ->setFecVenc('2026-12-31');
        $cfe->setCaeData($caeData);

        return $cfe;
    }

    private function createCreditNoteWithReference(): CfeDocument
    {
        $cfe = new CfeDocument();

        $cfe->getIdDoc()
            ->setTipoCfe(IdDoc::TIPO_NOTA_CREDITO_E_FACTURA)
            ->setSerie('AA')
            ->setNro(500001)
            ->setFchEmis('2025-01-25')
            ->setFmaPago(IdDoc::FORMA_PAGO_CONTADO);

        $cfe->getEmisor()
            ->setRucEmisor('213456789012')
            ->setRznSoc('Empresa Prueba SRL');

        $receptor = new Receptor();
        $receptor->setTipoDocRecep(Receptor::TIPO_DOC_RUC)
                 ->setDocRecep('987654321098')
                 ->setRznSocRecep('Cliente Prueba SA');
        $cfe->setReceptor($receptor);

        // Add reference to original invoice
        $cfe->addReferencia([
            'NroLinRef' => 1,
            'TpoDocRef' => IdDoc::TIPO_E_FACTURA,
            'Serie' => 'AA',
            'NroCFERef' => 123456,
            'FechaCFERef' => '2025-01-20',
            'RazonRef' => 'Devolución de mercadería',
        ]);

        $item = new Item();
        $item->setNroLinDet(1)
             ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
             ->setNomItem('Devolución - Servicio de Consultoría')
             ->setCantidad(1)
             ->setPrecioUnitario(500.00)
             ->setMontoItem(500.00);
        $cfe->addItem($item);

        $cfe->getTotales()
            ->setTpoMoneda('UYU')
            ->setMontoTotal(610.00)
            ->setCantLinDet(1)
            ->setMontoPagar(610.00);

        $caeData = new CAEData();
        $caeData->setCaeId('CAE_NC_123')
                ->setDNro(500000)
                ->setHNro(600000)
                ->setFecVenc('2026-12-31');
        $cfe->setCaeData($caeData);

        return $cfe;
    }

    private function createDocumentWithIva(): CfeDocument
    {
        $cfe = $this->createCompleteEFactura();

        // Already has IVA básica, let's ensure it's correct
        $cfe->getTotales()
            ->setMntNetoIVATasaBasica(1000.00)
            ->setTasaBasicaIVA(22.00)
            ->setIvaTasaBasica(220.00)
            ->setMontoTotal(1220.00)
            ->setMontoPagar(1220.00);

        return $cfe;
    }

    private function createDocumentWithForeignCurrency(): CfeDocument
    {
        $cfe = $this->createCompleteEFactura();

        $cfe->getTotales()
            ->setTpoMoneda('USD')
            ->setTipoCambio(42.50)
            ->setMontoTotal(100.00)
            ->setMontoPagar(100.00);

        return $cfe;
    }
}
