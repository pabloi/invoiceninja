<?php

namespace Tests\Unit\CfeUy;

use Tests\TestCase;
use App\Services\EDocument\Standards\CfeUy\Mapper;
use Tests\MockAccountData;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class MapperTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();
    }

    public function test_tipo_cfe_101_for_client_without_rut(): void
    {
        $this->client->vat_number = '';
        $this->client->id_number = '12345678';
        $this->client->save();

        $mapper = new Mapper($this->invoice);
        $this->assertEquals(101, $mapper->resolveTipoCfe());
    }

    public function test_tipo_cfe_111_for_client_with_rut(): void
    {
        $this->client->vat_number = '214100010018';
        $this->client->save();

        $mapper = new Mapper($this->invoice);
        $this->assertEquals(111, $mapper->resolveTipoCfe());
    }

    public function test_ind_fact_mapping_basica_22(): void
    {
        config(['services.cfe_uy.tax_map' => ['22' => 3, '10' => 2, '0_exempt' => 1, '0_none' => 6]]);

        $mapper = new Mapper($this->invoice);
        $item = (object) ['tax_rate1' => 22.0, 'tax_name1' => 'IVA', 'product_key' => 'Test'];

        $this->assertEquals(3, $mapper->resolveIndFact($item));
    }

    public function test_ind_fact_mapping_minima_10(): void
    {
        config(['services.cfe_uy.tax_map' => ['22' => 3, '10' => 2, '0_exempt' => 1, '0_none' => 6]]);

        $mapper = new Mapper($this->invoice);
        $item = (object) ['tax_rate1' => 10.0, 'tax_name1' => 'IVA Minima', 'product_key' => 'Test'];

        $this->assertEquals(2, $mapper->resolveIndFact($item));
    }

    public function test_ind_fact_mapping_exento(): void
    {
        config(['services.cfe_uy.tax_map' => ['22' => 3, '10' => 2, '0_exempt' => 1, '0_none' => 6]]);

        $mapper = new Mapper($this->invoice);
        $item = (object) ['tax_rate1' => 0, 'tax_name1' => 'Exento', 'product_key' => 'Test'];

        $this->assertEquals(1, $mapper->resolveIndFact($item));
    }

    public function test_ind_fact_mapping_no_facturable(): void
    {
        config(['services.cfe_uy.tax_map' => ['22' => 3, '10' => 2, '0_exempt' => 1, '0_none' => 6]]);

        $mapper = new Mapper($this->invoice);
        $item = (object) ['tax_rate1' => 0, 'tax_name1' => '', 'product_key' => 'Test'];

        $this->assertEquals(6, $mapper->resolveIndFact($item));
    }

    public function test_unmapped_tax_rate_throws(): void
    {
        config(['services.cfe_uy.tax_map' => ['22' => 3, '10' => 2, '0_exempt' => 1, '0_none' => 6]]);

        $mapper = new Mapper($this->invoice);
        $item = (object) ['tax_rate1' => 15.0, 'tax_name1' => 'Unknown', 'product_key' => 'TestItem'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unmapped tax rate');
        $mapper->resolveIndFact($item);
    }

    public function test_full_payload_structure(): void
    {
        config(['services.cfe_uy.tax_map' => ['22' => 3, '10' => 2, '0_exempt' => 1, '0_none' => 6]]);

        $this->client->vat_number = '';
        $this->client->save();

        $mapper = new Mapper($this->invoice);
        $payload = $mapper->toPayload();

        $this->assertArrayHasKey('idempotency_key', $payload);
        $this->assertArrayHasKey('external_invoice_id', $payload);
        $this->assertArrayHasKey('document', $payload);
        $this->assertArrayHasKey('emisor', $payload);
        $this->assertArrayHasKey('receptor', $payload);
        $this->assertArrayHasKey('items', $payload);
        $this->assertArrayHasKey('meta', $payload);

        $this->assertEquals($this->invoice->hashed_id, $payload['external_invoice_id']);
        $this->assertArrayHasKey('tipo_cfe', $payload['document']);
        $this->assertArrayHasKey('rut', $payload['emisor']);
        $this->assertArrayHasKey('razon_social', $payload['receptor']);
    }
}
