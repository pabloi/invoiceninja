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
 * Totales - Document Totals for Uruguay CFE
 *
 * Contains currency, amounts, taxes (IVA), and payment totals
 */
class Totales extends BaseXmlModel
{
    /**
     * Currency Codes commonly used in Uruguay
     */
    public const MONEDA_UYU = 'UYU'; // Uruguayan Peso
    public const MONEDA_USD = 'USD'; // US Dollar
    public const MONEDA_EUR = 'EUR'; // Euro

    /**
     * IVA Rate Codes
     */
    public const IVA_TASA_MINIMA = 10.00;   // 10%
    public const IVA_TASA_BASICA = 22.00;   // 22%

    protected string $tpoMoneda = self::MONEDA_UYU;
    protected ?float $tipoCambio = null;
    protected float $mntNoGrv = 0;
    protected float $mntExpoyAsworeim = 0;
    protected float $mntImpuestoPerc = 0;
    protected float $mntNetoIvaTasaMin = 0;
    protected float $mntNetoIVATasaBasica = 0;
    protected float $mntNetoIVAOtra = 0;
    protected float $tasaMinIVA = 0;
    protected float $tasaBasicaIVA = 0;
    protected float $ivaEnSuspenso = 0;
    protected float $ivaTasaMin = 0;
    protected float $ivaTasaBasica = 0;
    protected float $montoIVAOtra = 0;
    protected float $montoTotal = 0;
    protected int $cantLinDet = 0;
    protected ?int $retencPercib = null;
    protected float $montoPagar = 0;

    public function getTpoMoneda(): string
    {
        return $this->tpoMoneda;
    }

    public function setTpoMoneda(string $tpoMoneda): self
    {
        $this->tpoMoneda = $tpoMoneda;
        return $this;
    }

    public function getTipoCambio(): ?float
    {
        return $this->tipoCambio;
    }

    public function setTipoCambio(?float $tipoCambio): self
    {
        $this->tipoCambio = $tipoCambio;
        return $this;
    }

    public function getMntNoGrv(): float
    {
        return $this->mntNoGrv;
    }

    public function setMntNoGrv(float $mntNoGrv): self
    {
        $this->mntNoGrv = $mntNoGrv;
        return $this;
    }

    public function getMntExpoyAsworeim(): float
    {
        return $this->mntExpoyAsworeim;
    }

    public function setMntExpoyAsworeim(float $mntExpoyAsworeim): self
    {
        $this->mntExpoyAsworeim = $mntExpoyAsworeim;
        return $this;
    }

    public function getMntImpuestoPerc(): float
    {
        return $this->mntImpuestoPerc;
    }

    public function setMntImpuestoPerc(float $mntImpuestoPerc): self
    {
        $this->mntImpuestoPerc = $mntImpuestoPerc;
        return $this;
    }

    public function getMntNetoIvaTasaMin(): float
    {
        return $this->mntNetoIvaTasaMin;
    }

    public function setMntNetoIvaTasaMin(float $mntNetoIvaTasaMin): self
    {
        $this->mntNetoIvaTasaMin = $mntNetoIvaTasaMin;
        return $this;
    }

    public function getMntNetoIVATasaBasica(): float
    {
        return $this->mntNetoIVATasaBasica;
    }

    public function setMntNetoIVATasaBasica(float $mntNetoIVATasaBasica): self
    {
        $this->mntNetoIVATasaBasica = $mntNetoIVATasaBasica;
        return $this;
    }

    public function getMntNetoIVAOtra(): float
    {
        return $this->mntNetoIVAOtra;
    }

    public function setMntNetoIVAOtra(float $mntNetoIVAOtra): self
    {
        $this->mntNetoIVAOtra = $mntNetoIVAOtra;
        return $this;
    }

    public function getTasaMinIVA(): float
    {
        return $this->tasaMinIVA;
    }

    public function setTasaMinIVA(float $tasaMinIVA): self
    {
        $this->tasaMinIVA = $tasaMinIVA;
        return $this;
    }

    public function getTasaBasicaIVA(): float
    {
        return $this->tasaBasicaIVA;
    }

    public function setTasaBasicaIVA(float $tasaBasicaIVA): self
    {
        $this->tasaBasicaIVA = $tasaBasicaIVA;
        return $this;
    }

    public function getIvaEnSuspenso(): float
    {
        return $this->ivaEnSuspenso;
    }

    public function setIvaEnSuspenso(float $ivaEnSuspenso): self
    {
        $this->ivaEnSuspenso = $ivaEnSuspenso;
        return $this;
    }

    public function getIvaTasaMin(): float
    {
        return $this->ivaTasaMin;
    }

    public function setIvaTasaMin(float $ivaTasaMin): self
    {
        $this->ivaTasaMin = $ivaTasaMin;
        return $this;
    }

    public function getIvaTasaBasica(): float
    {
        return $this->ivaTasaBasica;
    }

    public function setIvaTasaBasica(float $ivaTasaBasica): self
    {
        $this->ivaTasaBasica = $ivaTasaBasica;
        return $this;
    }

    public function getMontoIVAOtra(): float
    {
        return $this->montoIVAOtra;
    }

    public function setMontoIVAOtra(float $montoIVAOtra): self
    {
        $this->montoIVAOtra = $montoIVAOtra;
        return $this;
    }

    public function getMontoTotal(): float
    {
        return $this->montoTotal;
    }

    public function setMontoTotal(float $montoTotal): self
    {
        $this->montoTotal = $montoTotal;
        return $this;
    }

    public function getCantLinDet(): int
    {
        return $this->cantLinDet;
    }

    public function setCantLinDet(int $cantLinDet): self
    {
        $this->cantLinDet = $cantLinDet;
        return $this;
    }

    public function getRetencPercib(): ?int
    {
        return $this->retencPercib;
    }

    public function setRetencPercib(?int $retencPercib): self
    {
        $this->retencPercib = $retencPercib;
        return $this;
    }

    public function getMontoPagar(): float
    {
        return $this->montoPagar;
    }

    public function setMontoPagar(float $montoPagar): self
    {
        $this->montoPagar = $montoPagar;
        return $this;
    }

    /**
     * Calculate total IVA
     */
    public function getTotalIVA(): float
    {
        return $this->ivaTasaMin + $this->ivaTasaBasica + $this->montoIVAOtra;
    }

    public function toXml(\DOMDocument $doc): \DOMElement
    {
        $totales = $this->createElement($doc, 'Totales');

        $totales->appendChild($this->createElement($doc, 'TpoMoneda', $this->tpoMoneda));

        if ($this->tipoCambio !== null && $this->tpoMoneda !== self::MONEDA_UYU) {
            $totales->appendChild($this->createElement($doc, 'TpoCambio', $this->formatAmount($this->tipoCambio)));
        }

        if ($this->mntNoGrv > 0) {
            $totales->appendChild($this->createElement($doc, 'MntNoGrv', $this->formatAmount($this->mntNoGrv)));
        }

        if ($this->mntExpoyAsworeim > 0) {
            $totales->appendChild($this->createElement($doc, 'MntExpoyAsworeim', $this->formatAmount($this->mntExpoyAsworeim)));
        }

        if ($this->mntImpuestoPerc > 0) {
            $totales->appendChild($this->createElement($doc, 'MntImpuestoPerc', $this->formatAmount($this->mntImpuestoPerc)));
        }

        if ($this->mntNetoIvaTasaMin > 0) {
            $totales->appendChild($this->createElement($doc, 'MntNetoIvaTasaMin', $this->formatAmount($this->mntNetoIvaTasaMin)));
        }

        if ($this->mntNetoIVATasaBasica > 0) {
            $totales->appendChild($this->createElement($doc, 'MntNetoIVATasaBasica', $this->formatAmount($this->mntNetoIVATasaBasica)));
        }

        if ($this->mntNetoIVAOtra > 0) {
            $totales->appendChild($this->createElement($doc, 'MntNetoIVAOtra', $this->formatAmount($this->mntNetoIVAOtra)));
        }

        if ($this->ivaTasaMin > 0) {
            $totales->appendChild($this->createElement($doc, 'IVATasaMin', $this->formatAmount($this->tasaMinIVA)));
            $totales->appendChild($this->createElement($doc, 'MontoIVATasaMin', $this->formatAmount($this->ivaTasaMin)));
        }

        if ($this->ivaTasaBasica > 0) {
            $totales->appendChild($this->createElement($doc, 'IVATasaBasica', $this->formatAmount($this->tasaBasicaIVA)));
            $totales->appendChild($this->createElement($doc, 'MontoIVATasaBasica', $this->formatAmount($this->ivaTasaBasica)));
        }

        if ($this->montoIVAOtra > 0) {
            $totales->appendChild($this->createElement($doc, 'MontoIVAOtra', $this->formatAmount($this->montoIVAOtra)));
        }

        $totales->appendChild($this->createElement($doc, 'MntTotal', $this->formatAmount($this->montoTotal)));
        $totales->appendChild($this->createElement($doc, 'CantLinDet', (string) $this->cantLinDet));

        if ($this->retencPercib !== null) {
            $totales->appendChild($this->createElement($doc, 'RetencPercib', (string) $this->retencPercib));
        }

        $totales->appendChild($this->createElement($doc, 'MntPagar', $this->formatAmount($this->montoPagar)));

        return $totales;
    }

    public static function fromDOMElement(\DOMElement $element): self
    {
        $totales = new self();

        $tpoMoneda = $element->getElementsByTagName('TpoMoneda')->item(0);
        if ($tpoMoneda) {
            $totales->setTpoMoneda($tpoMoneda->nodeValue);
        }

        $tipoCambio = $element->getElementsByTagName('TpoCambio')->item(0);
        if ($tipoCambio) {
            $totales->setTipoCambio((float) $tipoCambio->nodeValue);
        }

        $mntNoGrv = $element->getElementsByTagName('MntNoGrv')->item(0);
        if ($mntNoGrv) {
            $totales->setMntNoGrv((float) $mntNoGrv->nodeValue);
        }

        $mntNetoIvaTasaMin = $element->getElementsByTagName('MntNetoIvaTasaMin')->item(0);
        if ($mntNetoIvaTasaMin) {
            $totales->setMntNetoIvaTasaMin((float) $mntNetoIvaTasaMin->nodeValue);
        }

        $mntNetoIVATasaBasica = $element->getElementsByTagName('MntNetoIVATasaBasica')->item(0);
        if ($mntNetoIVATasaBasica) {
            $totales->setMntNetoIVATasaBasica((float) $mntNetoIVATasaBasica->nodeValue);
        }

        $ivaTasaMin = $element->getElementsByTagName('MontoIVATasaMin')->item(0);
        if ($ivaTasaMin) {
            $totales->setIvaTasaMin((float) $ivaTasaMin->nodeValue);
        }

        $ivaTasaBasica = $element->getElementsByTagName('MontoIVATasaBasica')->item(0);
        if ($ivaTasaBasica) {
            $totales->setIvaTasaBasica((float) $ivaTasaBasica->nodeValue);
        }

        $montoTotal = $element->getElementsByTagName('MntTotal')->item(0);
        if ($montoTotal) {
            $totales->setMontoTotal((float) $montoTotal->nodeValue);
        }

        $cantLinDet = $element->getElementsByTagName('CantLinDet')->item(0);
        if ($cantLinDet) {
            $totales->setCantLinDet((int) $cantLinDet->nodeValue);
        }

        $montoPagar = $element->getElementsByTagName('MntPagar')->item(0);
        if ($montoPagar) {
            $totales->setMontoPagar((float) $montoPagar->nodeValue);
        }

        return $totales;
    }
}
