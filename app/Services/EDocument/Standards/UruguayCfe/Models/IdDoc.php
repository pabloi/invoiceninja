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

namespace App\Services\EDocument\Standards\UruguayCfe\Models;

/**
 * IdDoc - Document Identification for Uruguay CFE
 *
 * Contains document type, series, number, dates, and payment information
 */
class IdDoc extends BaseXmlModel
{
    /**
     * CFE Type Codes
     */
    public const TIPO_E_TICKET = 101;
    public const TIPO_NOTA_CREDITO_E_TICKET = 102;
    public const TIPO_NOTA_DEBITO_E_TICKET = 103;
    public const TIPO_E_FACTURA = 111;
    public const TIPO_NOTA_CREDITO_E_FACTURA = 112;
    public const TIPO_NOTA_DEBITO_E_FACTURA = 113;
    public const TIPO_E_TICKET_CONTINGENCIA = 201;
    public const TIPO_NOTA_CREDITO_E_TICKET_CONTINGENCIA = 202;
    public const TIPO_NOTA_DEBITO_E_TICKET_CONTINGENCIA = 203;
    public const TIPO_E_FACTURA_CONTINGENCIA = 211;
    public const TIPO_NOTA_CREDITO_E_FACTURA_CONTINGENCIA = 212;
    public const TIPO_NOTA_DEBITO_E_FACTURA_CONTINGENCIA = 213;
    public const TIPO_E_REMITO = 181;
    public const TIPO_E_RESGUARDO = 182;
    public const TIPO_E_REMITO_CONTINGENCIA = 281;
    public const TIPO_E_RESGUARDO_CONTINGENCIA = 282;

    /**
     * Payment Form Codes
     */
    public const FORMA_PAGO_CONTADO = 1;
    public const FORMA_PAGO_CREDITO = 2;

    protected int $tipoCfe;
    protected string $serie;
    protected int $nro;
    protected string $fchEmis;
    protected ?string $fchVenc = null;
    protected int $fmaPago = self::FORMA_PAGO_CONTADO;
    protected ?string $periodoDesde = null;
    protected ?string $periodoHasta = null;
    protected ?int $cantPagos = null;
    protected ?string $fVencPagosPlazo = null;

    /**
     * Get valid CFE type codes
     */
    public static function getValidTipoCfe(): array
    {
        return [
            self::TIPO_E_TICKET,
            self::TIPO_NOTA_CREDITO_E_TICKET,
            self::TIPO_NOTA_DEBITO_E_TICKET,
            self::TIPO_E_FACTURA,
            self::TIPO_NOTA_CREDITO_E_FACTURA,
            self::TIPO_NOTA_DEBITO_E_FACTURA,
            self::TIPO_E_TICKET_CONTINGENCIA,
            self::TIPO_NOTA_CREDITO_E_TICKET_CONTINGENCIA,
            self::TIPO_NOTA_DEBITO_E_TICKET_CONTINGENCIA,
            self::TIPO_E_FACTURA_CONTINGENCIA,
            self::TIPO_NOTA_CREDITO_E_FACTURA_CONTINGENCIA,
            self::TIPO_NOTA_DEBITO_E_FACTURA_CONTINGENCIA,
            self::TIPO_E_REMITO,
            self::TIPO_E_RESGUARDO,
            self::TIPO_E_REMITO_CONTINGENCIA,
            self::TIPO_E_RESGUARDO_CONTINGENCIA,
        ];
    }

    /**
     * Check if CFE type is a credit note
     */
    public static function isNotaCredito(int $tipoCfe): bool
    {
        return in_array($tipoCfe, [
            self::TIPO_NOTA_CREDITO_E_TICKET,
            self::TIPO_NOTA_CREDITO_E_FACTURA,
            self::TIPO_NOTA_CREDITO_E_TICKET_CONTINGENCIA,
            self::TIPO_NOTA_CREDITO_E_FACTURA_CONTINGENCIA,
        ]);
    }

    /**
     * Check if CFE type is an e-Factura (B2B)
     */
    public static function isEFactura(int $tipoCfe): bool
    {
        return in_array($tipoCfe, [
            self::TIPO_E_FACTURA,
            self::TIPO_NOTA_CREDITO_E_FACTURA,
            self::TIPO_NOTA_DEBITO_E_FACTURA,
            self::TIPO_E_FACTURA_CONTINGENCIA,
            self::TIPO_NOTA_CREDITO_E_FACTURA_CONTINGENCIA,
            self::TIPO_NOTA_DEBITO_E_FACTURA_CONTINGENCIA,
        ]);
    }

    /**
     * Check if CFE type is an e-Ticket (B2C)
     */
    public static function isETicket(int $tipoCfe): bool
    {
        return in_array($tipoCfe, [
            self::TIPO_E_TICKET,
            self::TIPO_NOTA_CREDITO_E_TICKET,
            self::TIPO_NOTA_DEBITO_E_TICKET,
            self::TIPO_E_TICKET_CONTINGENCIA,
            self::TIPO_NOTA_CREDITO_E_TICKET_CONTINGENCIA,
            self::TIPO_NOTA_DEBITO_E_TICKET_CONTINGENCIA,
        ]);
    }

    public function getTipoCfe(): int
    {
        return $this->tipoCfe;
    }

    public function setTipoCfe(int $tipoCfe): self
    {
        if (!in_array($tipoCfe, self::getValidTipoCfe())) {
            throw new \InvalidArgumentException('Invalid TipoCFE value');
        }
        $this->tipoCfe = $tipoCfe;
        return $this;
    }

    public function getSerie(): string
    {
        return $this->serie;
    }

    public function setSerie(string $serie): self
    {
        if (!preg_match('/^[1-9A-Z][A-Z]$/', $serie)) {
            throw new \InvalidArgumentException('Serie must be 2 characters: number(1-9)/letter(A-Z) + letter(A-Z)');
        }
        $this->serie = $serie;
        return $this;
    }

    public function getNro(): int
    {
        return $this->nro;
    }

    public function setNro(int $nro): self
    {
        if ($nro < 1 || $nro > 9999999) {
            throw new \InvalidArgumentException('Nro must be between 1 and 9999999');
        }
        $this->nro = $nro;
        return $this;
    }

    public function getFchEmis(): string
    {
        return $this->fchEmis;
    }

    public function setFchEmis(string $fchEmis): self
    {
        $this->fchEmis = $this->formatDate($fchEmis);
        return $this;
    }

    public function getFchVenc(): ?string
    {
        return $this->fchVenc;
    }

    public function setFchVenc(?string $fchVenc): self
    {
        $this->fchVenc = $fchVenc ? $this->formatDate($fchVenc) : null;
        return $this;
    }

    public function getFmaPago(): int
    {
        return $this->fmaPago;
    }

    public function setFmaPago(int $fmaPago): self
    {
        if (!in_array($fmaPago, [self::FORMA_PAGO_CONTADO, self::FORMA_PAGO_CREDITO])) {
            throw new \InvalidArgumentException('FmaPago must be 1 (Contado) or 2 (Crédito)');
        }
        $this->fmaPago = $fmaPago;
        return $this;
    }

    public function getPeriodoDesde(): ?string
    {
        return $this->periodoDesde;
    }

    public function setPeriodoDesde(?string $periodoDesde): self
    {
        $this->periodoDesde = $periodoDesde ? $this->formatDate($periodoDesde) : null;
        return $this;
    }

    public function getPeriodoHasta(): ?string
    {
        return $this->periodoHasta;
    }

    public function setPeriodoHasta(?string $periodoHasta): self
    {
        $this->periodoHasta = $periodoHasta ? $this->formatDate($periodoHasta) : null;
        return $this;
    }

    public function getCantPagos(): ?int
    {
        return $this->cantPagos;
    }

    public function setCantPagos(?int $cantPagos): self
    {
        $this->cantPagos = $cantPagos;
        return $this;
    }

    public function getFVencPagosPlazo(): ?string
    {
        return $this->fVencPagosPlazo;
    }

    public function setFVencPagosPlazo(?string $fVencPagosPlazo): self
    {
        $this->fVencPagosPlazo = $fVencPagosPlazo ? $this->formatDate($fVencPagosPlazo) : null;
        return $this;
    }

    public function toXml(\DOMDocument $doc): \DOMElement
    {
        $idDoc = $this->createElement($doc, 'IdDoc');

        $idDoc->appendChild($this->createElement($doc, 'TipoCFE', (string) $this->tipoCfe));
        $idDoc->appendChild($this->createElement($doc, 'Serie', $this->serie));
        $idDoc->appendChild($this->createElement($doc, 'Nro', (string) $this->nro));
        $idDoc->appendChild($this->createElement($doc, 'FchEmis', $this->fchEmis));

        if ($this->fchVenc !== null) {
            $idDoc->appendChild($this->createElement($doc, 'FchVenc', $this->fchVenc));
        }

        $idDoc->appendChild($this->createElement($doc, 'FmaPago', (string) $this->fmaPago));

        if ($this->periodoDesde !== null) {
            $idDoc->appendChild($this->createElement($doc, 'PeriodoDesde', $this->periodoDesde));
        }

        if ($this->periodoHasta !== null) {
            $idDoc->appendChild($this->createElement($doc, 'PeriodoHasta', $this->periodoHasta));
        }

        if ($this->cantPagos !== null) {
            $idDoc->appendChild($this->createElement($doc, 'CantPagos', (string) $this->cantPagos));
        }

        if ($this->fVencPagosPlazo !== null) {
            $idDoc->appendChild($this->createElement($doc, 'FVencPagosPlazo', $this->fVencPagosPlazo));
        }

        return $idDoc;
    }

    public static function fromDOMElement(\DOMElement $element): self
    {
        $idDoc = new self();

        $tipoCfe = $element->getElementsByTagName('TipoCFE')->item(0);
        if ($tipoCfe) {
            $idDoc->setTipoCfe((int) $tipoCfe->nodeValue);
        }

        $serie = $element->getElementsByTagName('Serie')->item(0);
        if ($serie) {
            $idDoc->setSerie($serie->nodeValue);
        }

        $nro = $element->getElementsByTagName('Nro')->item(0);
        if ($nro) {
            $idDoc->setNro((int) $nro->nodeValue);
        }

        $fchEmis = $element->getElementsByTagName('FchEmis')->item(0);
        if ($fchEmis) {
            $idDoc->setFchEmis($fchEmis->nodeValue);
        }

        $fchVenc = $element->getElementsByTagName('FchVenc')->item(0);
        if ($fchVenc) {
            $idDoc->setFchVenc($fchVenc->nodeValue);
        }

        $fmaPago = $element->getElementsByTagName('FmaPago')->item(0);
        if ($fmaPago) {
            $idDoc->setFmaPago((int) $fmaPago->nodeValue);
        }

        return $idDoc;
    }
}
