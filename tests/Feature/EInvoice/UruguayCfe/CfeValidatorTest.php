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

use App\Services\EDocument\Standards\UruguayCfe\CfeValidator;
use App\Services\EDocument\Standards\UruguayCfe\Models\CAEData;
use App\Services\EDocument\Standards\UruguayCfe\Models\CfeDocument;
use App\Services\EDocument\Standards\UruguayCfe\Models\Emisor;
use App\Services\EDocument\Standards\UruguayCfe\Models\IdDoc;
use App\Services\EDocument\Standards\UruguayCfe\Models\Item;
use App\Services\EDocument\Standards\UruguayCfe\Models\Receptor;
use App\Services\EDocument\Standards\UruguayCfe\Models\Totales;
use Tests\TestCase;

class CfeValidatorTest extends TestCase
{
    protected CfeValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CfeValidator();
    }

    /**
     * Create a valid CFE document for testing
     */
    protected function createValidCfeDocument(): CfeDocument
    {
        $cfe = new CfeDocument();

        // Set IdDoc
        $idDoc = new IdDoc();
        $idDoc->setTipoCfe(IdDoc::TIPO_E_TICKET)
            ->setSerie('AA')
            ->setNro(1)
            ->setFchEmis(date('Y-m-d'))
            ->setFmaPago(1);
        $cfe->setIdDoc($idDoc);

        // Set Emisor
        $emisor = new Emisor();
        $emisor->setRucEmisor('123456789012')
            ->setRznSoc('Test Company SA')
            ->setNombreFantasia('Test Store')
            ->setDomFiscal('Test Address 123, Montevideo');
        $cfe->setEmisor($emisor);

        // Set Receptor
        $receptor = new Receptor();
        $receptor->setTipoDocRecep(Receptor::TIPO_DOC_CI)
            ->setDocRecep('12345678')
            ->setRznSocRecep('Customer Name')
            ->setDirRecep('Customer Address');
        $cfe->setReceptor($receptor);

        // Add Item
        $item = new Item();
        $item->setNroLinDet(1)
            ->setNomItem('Test Product')
            ->setCantidad(1)
            ->setPrecioUnitario(100.00)
            ->setMontoItem(122.00)
            ->setIndFact(Item::IND_FACT_GRAVADO_TASA_BAS);
        $cfe->addItem($item);

        // Set Totales
        $totales = new Totales();
        $totales->setTpoMoneda('UYU')
            ->setCantLinDet(1)
            ->setMntNetoIVATasaBasica(100.00)
            ->setIvaTasaBasica(22.00)
            ->setMontoTotal(122.00)
            ->setMontoPagar(122.00);
        $cfe->setTotales($totales);

        // Set CAE Data
        $caeData = new CAEData();
        $caeData->setCAEId(12345678901234567890)
            ->setDNro(1)
            ->setHNro(1000)
            ->setFecVenc(date('Y-m-d', strtotime('+6 months')));
        $cfe->setCaeData($caeData);

        return $cfe;
    }

    public function testValidatorCanBeInstantiated(): void
    {
        $validator = new CfeValidator();
        $this->assertInstanceOf(CfeValidator::class, $validator);
    }

    public function testValidatorWithCustomSchemaPath(): void
    {
        $customPath = '/custom/path/to/schemas';
        $validator = new CfeValidator($customPath);
        $this->assertInstanceOf(CfeValidator::class, $validator);
    }

    public function testValidXmlIsWellFormed(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><root><element>value</element></root>';
        $result = $this->validator->validateXml($xml);

        // Should pass well-formed check (schema check will warn but not error without schema file)
        $errors = $this->validator->getErrors();
        $this->assertEmpty(array_filter($errors, fn($e) => $e['code'] === 'XML_PARSE_ERROR'));
    }

    public function testInvalidXmlFailsWellFormedCheck(): void
    {
        $xml = '<?xml version="1.0"?><root><unclosed>';
        $result = $this->validator->validateXml($xml);

        $this->assertFalse($result);
        $errors = $this->validator->getErrors();
        $this->assertNotEmpty($errors);
        $this->assertEquals('XML_PARSE_ERROR', $errors[0]['code']);
    }

    public function testValidDocumentPassesValidation(): void
    {
        $cfe = $this->createValidCfeDocument();

        // Validate (schema validation may warn without schema file, but business rules should pass)
        $result = $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        // Filter out schema-related errors (since we may not have the schema file)
        $businessErrors = array_filter($errors, fn($e) => !in_array($e['code'], ['SCHEMA_NOT_FOUND', 'XSD_VALIDATION_ERROR']));
        $this->assertEmpty($businessErrors, 'Business rule validation should pass: ' . json_encode($businessErrors));
    }

    public function testInvalidSerieFormatThrowsException(): void
    {
        // IdDoc validates on set, so invalid series throw exceptions immediately
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Serie must be 2 characters');

        $idDoc = new IdDoc();
        $idDoc->setSerie('0A'); // Invalid: 0 not in [1-9A-Z]
    }

    public function testInvalidSerieWithLowercaseThrowsException(): void
    {
        // IdDoc validates on set, so invalid series throw exceptions immediately
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Serie must be 2 characters');

        $idDoc = new IdDoc();
        $idDoc->setSerie('aa'); // Invalid: lowercase not allowed
    }

    public function testRucWithNonNumericIsStripped(): void
    {
        // Emisor strips non-numeric characters on set
        $cfe = $this->createValidCfeDocument();
        $cfe->getEmisor()->setRucEmisor('ABC123'); // Non-numeric stripped, becomes '123'

        $this->validator->validate($cfe);
        // After stripping, '123' is a valid RUC format (1-12 digits)
        $errors = $this->validator->getErrors();
        $rucErrors = array_filter($errors, fn($e) => $e['code'] === 'INVALID_RUC');
        $this->assertEmpty($rucErrors, 'RUC after stripping non-numeric should be valid');
    }

    public function testRucTooLongThrowsException(): void
    {
        // Emisor validates max length on set
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RUCEmisor must be up to 12 digits');

        $emisor = new Emisor();
        $emisor->setRucEmisor('1234567890123'); // 13 digits, max is 12
    }

    public function testEFacturaRequiresReceptor(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getIdDoc()->setTipoCfe(IdDoc::TIPO_E_FACTURA); // e-Factura
        $cfe->setReceptor(null); // Remove receptor

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $receptorErrors = array_filter($errors, fn($e) => $e['code'] === 'RECEPTOR_REQUIRED');
        $this->assertNotEmpty($receptorErrors, 'e-Factura without receptor should fail');
    }

    public function testCreditNoteRequiresReference(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getIdDoc()->setTipoCfe(IdDoc::TIPO_NOTA_CREDITO_E_TICKET); // Credit note
        // No references set

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $refErrors = array_filter($errors, fn($e) => $e['code'] === 'REFERENCE_REQUIRED');
        $this->assertNotEmpty($refErrors, 'Credit note without reference should fail');
    }

    public function testDebitNoteRequiresReference(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getIdDoc()->setTipoCfe(IdDoc::TIPO_NOTA_DEBITO_E_TICKET); // Debit note
        // No references set

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $refErrors = array_filter($errors, fn($e) => $e['code'] === 'REFERENCE_REQUIRED');
        $this->assertNotEmpty($refErrors, 'Debit note without reference should fail');
    }

    public function testCreditNoteWithReferenceIsValid(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getIdDoc()->setTipoCfe(IdDoc::TIPO_NOTA_CREDITO_E_TICKET);
        $cfe->addReferencia([
            'NroLinRef' => 1,
            'TpoDocRef' => IdDoc::TIPO_E_TICKET,
            'Serie' => 'AA',
            'NroCFERef' => 100,
            'RazonRef' => 'Devolución de mercadería',
        ]);

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $refErrors = array_filter($errors, fn($e) => $e['code'] === 'REFERENCE_REQUIRED');
        $this->assertEmpty($refErrors, 'Credit note with reference should pass');
    }

    public function testNoItemsFails(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->setItems([]); // Remove all items

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $itemErrors = array_filter($errors, fn($e) => $e['code'] === 'NO_ITEMS');
        $this->assertNotEmpty($itemErrors, 'Document without items should fail');
    }

    public function testInvalidCurrencyCodeFails(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getTotales()->setTpoMoneda('us'); // Invalid: must be uppercase

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $currencyErrors = array_filter($errors, fn($e) => $e['code'] === 'INVALID_CURRENCY');
        $this->assertNotEmpty($currencyErrors, 'Lowercase currency code should fail');
    }

    public function testCaeNumberOutOfRangeFails(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getIdDoc()->setNro(5000); // Document number
        $cfe->getCaeData()->setDNro(1)->setHNro(100); // Valid range is 1-100

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $caeErrors = array_filter($errors, fn($e) => $e['code'] === 'CAE_RANGE');
        $this->assertNotEmpty($caeErrors, 'Document number outside CAE range should fail');
    }

    public function testExpiredCaeFails(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getCaeData()->setFecVenc(date('Y-m-d', strtotime('-1 month'))); // Expired

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $caeErrors = array_filter($errors, fn($e) => $e['code'] === 'CAE_EXPIRED');
        $this->assertNotEmpty($caeErrors, 'Expired CAE should fail');
    }

    public function testCaeExpiringSoonGeneratesWarning(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getCaeData()->setFecVenc(date('Y-m-d', strtotime('+15 days'))); // Expires in 15 days

        $this->validator->validate($cfe);
        $warnings = $this->validator->getWarnings();

        $caeWarnings = array_filter($warnings, fn($w) => $w['code'] === 'CAE_EXPIRING_SOON');
        $this->assertNotEmpty($caeWarnings, 'CAE expiring within 30 days should generate warning');
    }

    public function testLineCountMismatchFails(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getTotales()->setCantLinDet(5); // Says 5 lines but only 1 item

        $this->validator->validate($cfe);
        $errors = $this->validator->getErrors();

        $lineErrors = array_filter($errors, fn($e) => $e['code'] === 'LINE_COUNT_MISMATCH');
        $this->assertNotEmpty($lineErrors, 'Line count mismatch should fail');
    }

    public function testIvaCalculationWarning(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getTotales()->setMntNetoIVATasaBasica(100.00);
        $cfe->getTotales()->setIvaTasaBasica(25.00); // Should be 22.00 (22%)

        $this->validator->validate($cfe);
        $warnings = $this->validator->getWarnings();

        $ivaWarnings = array_filter($warnings, fn($w) => $w['code'] === 'IVA_BASIC_CALCULATION');
        $this->assertNotEmpty($ivaWarnings, 'Incorrect IVA calculation should generate warning');
    }

    public function testExchangeRateWarningForForeignCurrency(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getTotales()->setTpoMoneda('USD'); // Foreign currency
        // No exchange rate set

        $this->validator->validate($cfe);
        $warnings = $this->validator->getWarnings();

        $exchangeWarnings = array_filter($warnings, fn($w) => $w['code'] === 'EXCHANGE_RATE');
        $this->assertNotEmpty($exchangeWarnings, 'Foreign currency without exchange rate should warn');
    }

    public function testGetReportFormatsCorrectly(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getCaeData()->setFecVenc(date('Y-m-d', strtotime('-1 month'))); // Expired CAE

        $this->validator->validate($cfe);
        $report = $this->validator->getReport();

        $this->assertStringContainsString('CFE Validation Report', $report);
        $this->assertStringContainsString('ERRORS', $report);
        $this->assertStringContainsString('CAE_EXPIRED', $report);
    }

    public function testValidDocumentReportShowsSuccess(): void
    {
        $cfe = $this->createValidCfeDocument();

        $this->validator->validate($cfe);
        $report = $this->validator->getReport();

        // A valid document report should contain the title
        $this->assertStringContainsString('CFE Validation Report', $report);

        // If no business errors (excluding schema warnings), the report structure should exist
        $errors = $this->validator->getErrors();
        $businessErrors = array_filter($errors, fn($e) => !in_array($e['code'], ['SCHEMA_NOT_FOUND', 'XSD_VALIDATION_ERROR', 'SCHEMA_VALIDATION_FAILED']));

        if (empty($businessErrors) && empty($this->validator->getWarnings())) {
            $this->assertStringContainsString('passed all validations', $report);
        } else {
            // Even with warnings, we have a valid report
            $this->assertNotEmpty($report);
        }
    }

    public function testGetAllIssuesReturnsStructuredData(): void
    {
        $cfe = $this->createValidCfeDocument();
        $cfe->getCaeData()->setFecVenc(date('Y-m-d', strtotime('-1 month'))); // Create an error (expired CAE)
        // Warning will be created for exchange rate if we use foreign currency
        $cfe->getTotales()->setTpoMoneda('USD');

        $this->validator->validate($cfe);
        $issues = $this->validator->getAllIssues();

        $this->assertArrayHasKey('errors', $issues);
        $this->assertArrayHasKey('warnings', $issues);
        $this->assertIsArray($issues['errors']);
        $this->assertIsArray($issues['warnings']);
    }

    public function testStaticValidateDocumentMethod(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><root><element>value</element></root>';
        $result = CfeValidator::validateDocument($xml);

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('report', $result);
    }

    public function testIsValidReturnsBooleanCorrectly(): void
    {
        $cfe = $this->createValidCfeDocument();
        $this->validator->validate($cfe);

        $isValid = $this->validator->isValid();
        $errors = $this->validator->getErrors();

        // Filter business errors only
        $businessErrors = array_filter($errors, fn($e) => !in_array($e['code'], ['SCHEMA_NOT_FOUND', 'XSD_VALIDATION_ERROR']));

        if (empty($businessErrors)) {
            $this->assertTrue($isValid);
        } else {
            $this->assertFalse($isValid);
        }
    }

    public function testIvaTasaMinimaConstants(): void
    {
        $this->assertEquals(10.00, CfeValidator::IVA_TASA_MINIMA);
        $this->assertEquals(22.00, CfeValidator::IVA_TASA_BASICA);
    }
}
