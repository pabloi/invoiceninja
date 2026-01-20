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
 * Test suite for Uruguay CFE Model components
 */
class UruguayCfeModelTest extends TestCase
{
    /**
     * Test IdDoc model creation and serialization
     */
    public function test_id_doc_creation(): void
    {
        $idDoc = new IdDoc();
        $idDoc->setTipoCfe(IdDoc::TIPO_E_FACTURA)
              ->setSerie('AA')
              ->setNro(1234567)
              ->setFchEmis('2025-01-20')
              ->setFmaPago(IdDoc::FORMA_PAGO_CONTADO);

        $this->assertEquals(111, $idDoc->getTipoCfe());
        $this->assertEquals('AA', $idDoc->getSerie());
        $this->assertEquals(1234567, $idDoc->getNro());
        $this->assertEquals('2025-01-20', $idDoc->getFchEmis());
        $this->assertEquals(1, $idDoc->getFmaPago());
    }

    /**
     * Test IdDoc type detection methods
     */
    public function test_id_doc_type_detection(): void
    {
        $this->assertTrue(IdDoc::isEFactura(IdDoc::TIPO_E_FACTURA));
        $this->assertTrue(IdDoc::isEFactura(IdDoc::TIPO_NOTA_CREDITO_E_FACTURA));
        $this->assertTrue(IdDoc::isETicket(IdDoc::TIPO_E_TICKET));
        $this->assertTrue(IdDoc::isETicket(IdDoc::TIPO_NOTA_CREDITO_E_TICKET));
        $this->assertTrue(IdDoc::isNotaCredito(IdDoc::TIPO_NOTA_CREDITO_E_FACTURA));
        $this->assertTrue(IdDoc::isNotaCredito(IdDoc::TIPO_NOTA_CREDITO_E_TICKET));

        $this->assertFalse(IdDoc::isEFactura(IdDoc::TIPO_E_TICKET));
        $this->assertFalse(IdDoc::isETicket(IdDoc::TIPO_E_FACTURA));
    }

    /**
     * Test IdDoc serie validation
     */
    public function test_id_doc_serie_validation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $idDoc = new IdDoc();
        $idDoc->setSerie('INVALID'); // Should throw - must be 2 chars
    }

    /**
     * Test Emisor model creation
     */
    public function test_emisor_creation(): void
    {
        $emisor = new Emisor();
        $emisor->setRucEmisor('213456789012')
               ->setRznSoc('Test Company SRL')
               ->setNombreFantasia('TestCo')
               ->setDomFiscal('Av. 18 de Julio 1234')
               ->setCiudad('Montevideo')
               ->setDepartamento('Montevideo')
               ->setTelefono('+598 2 1234567')
               ->setCorreoEmisor('test@company.uy');

        $this->assertEquals('213456789012', $emisor->getRucEmisor());
        $this->assertEquals('Test Company SRL', $emisor->getRznSoc());
        $this->assertEquals('TestCo', $emisor->getNombreFantasia());
    }

    /**
     * Test Receptor model creation
     */
    public function test_receptor_creation(): void
    {
        $receptor = new Receptor();
        $receptor->setTipoDocRecep(Receptor::TIPO_DOC_RUC)
                 ->setDocRecep('123456789012')
                 ->setRznSocRecep('Client Company SA')
                 ->setDirRecep('Calle Principal 456')
                 ->setCiudadRecep('Montevideo')
                 ->setDeptoRecep('Montevideo')
                 ->setPaisRecep('Uruguay');

        $this->assertEquals(4, $receptor->getTipoDocRecep());
        $this->assertEquals('123456789012', $receptor->getDocRecep());
        $this->assertEquals('Client Company SA', $receptor->getRznSocRecep());
        $this->assertTrue($receptor->hasIdentification());
    }

    /**
     * Test Item model creation
     */
    public function test_item_creation(): void
    {
        $item = new Item();
        $item->setNroLinDet(1)
             ->setCodItem('PROD001')
             ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
             ->setNomItem('Test Product')
             ->setCantidad(10.5)
             ->setUniMed('UN')
             ->setPrecioUnitario(100.00)
             ->setMontoItem(1050.00);

        $this->assertEquals(1, $item->getNroLinDet());
        $this->assertEquals('PROD001', $item->getCodItem());
        $this->assertEquals(3, $item->getIndFact());
        $this->assertEquals('Test Product', $item->getNomItem());
        $this->assertEquals(10.5, $item->getCantidad());
        $this->assertEquals(100.00, $item->getPrecioUnitario());
        $this->assertEquals(1050.00, $item->getMontoItem());
        $this->assertTrue($item->isTaxable());
        $this->assertFalse($item->isExempt());
    }

    /**
     * Test Item with discount
     */
    public function test_item_with_discount(): void
    {
        $item = new Item();
        $item->setNroLinDet(1)
             ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
             ->setNomItem('Discounted Product')
             ->setCantidad(10)
             ->setPrecioUnitario(100.00)
             ->setDescuento(50.00);

        $this->assertEquals(50.00, $item->getDescuento());

        // Test calculated amount
        $item->setMontoItem($item->calculateMontoItem());
        $this->assertEquals(950.00, $item->getMontoItem());
    }

    /**
     * Test Totales model creation
     */
    public function test_totales_creation(): void
    {
        $totales = new Totales();
        $totales->setTpoMoneda('UYU')
                ->setMntNetoIVATasaBasica(1000.00)
                ->setTasaBasicaIVA(22.00)
                ->setIvaTasaBasica(220.00)
                ->setMontoTotal(1220.00)
                ->setCantLinDet(2)
                ->setMontoPagar(1220.00);

        $this->assertEquals('UYU', $totales->getTpoMoneda());
        $this->assertEquals(1000.00, $totales->getMntNetoIVATasaBasica());
        $this->assertEquals(22.00, $totales->getTasaBasicaIVA());
        $this->assertEquals(220.00, $totales->getIvaTasaBasica());
        $this->assertEquals(1220.00, $totales->getMontoTotal());
        $this->assertEquals(2, $totales->getCantLinDet());
    }

    /**
     * Test CAEData model creation and validation
     */
    public function test_cae_data_creation(): void
    {
        $caeData = new CAEData();
        $caeData->setCaeId('12345678901234567890')
                ->setDNro(1)
                ->setHNro(1000000)
                ->setFecVenc('2026-12-31');

        $this->assertEquals('12345678901234567890', $caeData->getCaeId());
        $this->assertEquals(1, $caeData->getDNro());
        $this->assertEquals(1000000, $caeData->getHNro());
        $this->assertEquals('2026-12-31', $caeData->getFecVenc());
        $this->assertEquals(1000000, $caeData->getRangeSize());
        $this->assertTrue($caeData->isNumberInRange(500000));
        $this->assertFalse($caeData->isNumberInRange(1000001));
        $this->assertFalse($caeData->isExpired());
    }

    /**
     * Test CAEData fromArray factory method
     */
    public function test_cae_data_from_array(): void
    {
        $data = [
            'cae_id' => 'CAE123456',
            'd_nro' => 100,
            'h_nro' => 200,
            'fec_venc' => '2026-06-30',
        ];

        $caeData = CAEData::fromArray($data);

        $this->assertEquals('CAE123456', $caeData->getCaeId());
        $this->assertEquals(100, $caeData->getDNro());
        $this->assertEquals(200, $caeData->getHNro());
        $this->assertEquals(101, $caeData->getRangeSize());
    }

    /**
     * Test complete CFE document creation and XML generation
     */
    public function test_complete_cfe_document_creation(): void
    {
        // Create IdDoc
        $idDoc = new IdDoc();
        $idDoc->setTipoCfe(IdDoc::TIPO_E_FACTURA)
              ->setSerie('AA')
              ->setNro(123456)
              ->setFchEmis('2025-01-20')
              ->setFmaPago(IdDoc::FORMA_PAGO_CONTADO);

        // Create Emisor
        $emisor = new Emisor();
        $emisor->setRucEmisor('213456789012')
               ->setRznSoc('Empresa Prueba SRL')
               ->setDomFiscal('Av. 18 de Julio 1234')
               ->setCiudad('Montevideo')
               ->setDepartamento('Montevideo');

        // Create Receptor
        $receptor = new Receptor();
        $receptor->setTipoDocRecep(Receptor::TIPO_DOC_RUC)
                 ->setDocRecep('987654321098')
                 ->setRznSocRecep('Cliente Prueba SA')
                 ->setDirRecep('Calle Test 789');

        // Create Item
        $item = new Item();
        $item->setNroLinDet(1)
             ->setCodItem('SERV001')
             ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
             ->setNomItem('Servicio de Consultoría')
             ->setCantidad(1)
             ->setPrecioUnitario(1000.00)
             ->setMontoItem(1000.00);

        // Create Totales
        $totales = new Totales();
        $totales->setTpoMoneda('UYU')
                ->setMntNetoIVATasaBasica(1000.00)
                ->setTasaBasicaIVA(22.00)
                ->setIvaTasaBasica(220.00)
                ->setMontoTotal(1220.00)
                ->setCantLinDet(1)
                ->setMontoPagar(1220.00);

        // Create CAEData
        $caeData = new CAEData();
        $caeData->setCaeId('90123456789012345678')
                ->setDNro(100000)
                ->setHNro(200000)
                ->setFecVenc('2026-12-31');

        // Assemble the document
        $cfe = new CfeDocument();
        $cfe->setIdDoc($idDoc)
            ->setEmisor($emisor)
            ->setReceptor($receptor)
            ->addItem($item)
            ->setTotales($totales)
            ->setCaeData($caeData)
            ->setTmstFirma('2025-01-20T10:30:00');

        // Generate XML
        $xml = $cfe->toXmlString();

        // Verify XML contains expected elements
        $this->assertStringContainsString('<eFact', $xml);
        $this->assertStringContainsString('<TipoCFE>111</TipoCFE>', $xml);
        $this->assertStringContainsString('<Serie>AA</Serie>', $xml);
        $this->assertStringContainsString('<Nro>123456</Nro>', $xml);
        $this->assertStringContainsString('<RUCEmisor>213456789012</RUCEmisor>', $xml);
        $this->assertStringContainsString('<RznSoc>Empresa Prueba SRL</RznSoc>', $xml);
        $this->assertStringContainsString('<DocRecep>987654321098</DocRecep>', $xml);
        $this->assertStringContainsString('<NomItem>Servicio de Consultoría</NomItem>', $xml);
        $this->assertStringContainsString('<MntTotal>1220.00</MntTotal>', $xml);
        $this->assertStringContainsString('<CAE_ID>90123456789012345678</CAE_ID>', $xml);
    }

    /**
     * Test CFE document validation
     */
    public function test_cfe_document_validation(): void
    {
        $cfe = new CfeDocument();

        // Empty document should have validation errors
        $cfe->getIdDoc()->setTipoCfe(IdDoc::TIPO_E_FACTURA)
            ->setSerie('AA')
            ->setNro(123456)
            ->setFchEmis('2025-01-20');

        $errors = $cfe->validate();

        // Should have errors for missing required fields
        $this->assertNotEmpty($errors);
    }

    /**
     * Test e-Ticket vs e-Factura wrapper element
     */
    public function test_document_wrapper_element(): void
    {
        // e-Factura should use eFact wrapper
        $efactura = new CfeDocument();
        $efactura->getIdDoc()->setTipoCfe(IdDoc::TIPO_E_FACTURA)
                 ->setSerie('AA')
                 ->setNro(1)
                 ->setFchEmis('2025-01-20');
        $efactura->getEmisor()->setRucEmisor('123456789012')
                              ->setRznSoc('Test');
        $efactura->addItem((new Item())
            ->setNroLinDet(1)
            ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
            ->setNomItem('Test')
            ->setCantidad(1)
            ->setPrecioUnitario(100)
            ->setMontoItem(100));
        $efactura->getTotales()
                 ->setTpoMoneda('UYU')
                 ->setMontoTotal(100)
                 ->setCantLinDet(1)
                 ->setMontoPagar(100);

        $xml = $efactura->toXmlString();
        $this->assertStringContainsString('<eFact', $xml);

        // e-Ticket should use eTck wrapper
        $eticket = new CfeDocument();
        $eticket->getIdDoc()->setTipoCfe(IdDoc::TIPO_E_TICKET)
                ->setSerie('BB')
                ->setNro(1)
                ->setFchEmis('2025-01-20');
        $eticket->getEmisor()->setRucEmisor('123456789012')
                             ->setRznSoc('Test');
        $eticket->addItem((new Item())
            ->setNroLinDet(1)
            ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
            ->setNomItem('Test')
            ->setCantidad(1)
            ->setPrecioUnitario(100)
            ->setMontoItem(100));
        $eticket->getTotales()
                ->setTpoMoneda('UYU')
                ->setMontoTotal(100)
                ->setCantLinDet(1)
                ->setMontoPagar(100);

        $xml = $eticket->toXmlString();
        $this->assertStringContainsString('<eTck', $xml);
    }

    /**
     * Test XML deserialization
     */
    public function test_xml_deserialization(): void
    {
        // Create a document
        $original = new CfeDocument();
        $original->getIdDoc()->setTipoCfe(IdDoc::TIPO_E_FACTURA)
                 ->setSerie('AA')
                 ->setNro(999)
                 ->setFchEmis('2025-01-20');
        $original->getEmisor()->setRucEmisor('111111111111')
                              ->setRznSoc('Original Company');
        $original->setReceptor((new Receptor())
                 ->setTipoDocRecep(Receptor::TIPO_DOC_RUC)
                 ->setDocRecep('222222222222')
                 ->setRznSocRecep('Client Company'));
        $original->addItem((new Item())
            ->setNroLinDet(1)
            ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS)
            ->setNomItem('Test Item')
            ->setCantidad(5)
            ->setPrecioUnitario(200)
            ->setMontoItem(1000));
        $original->getTotales()
                 ->setTpoMoneda('UYU')
                 ->setMontoTotal(1220)
                 ->setCantLinDet(1)
                 ->setMontoPagar(1220);

        // Serialize to XML
        $xml = $original->toXmlString();

        // Deserialize
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $deserialized = CfeDocument::fromDOMElement($doc->documentElement);

        // Verify key fields
        $this->assertEquals($original->getIdDoc()->getTipoCfe(), $deserialized->getIdDoc()->getTipoCfe());
        $this->assertEquals($original->getIdDoc()->getSerie(), $deserialized->getIdDoc()->getSerie());
        $this->assertEquals($original->getIdDoc()->getNro(), $deserialized->getIdDoc()->getNro());
        $this->assertEquals($original->getEmisor()->getRucEmisor(), $deserialized->getEmisor()->getRucEmisor());
        $this->assertEquals(count($original->getItems()), count($deserialized->getItems()));
    }
}
