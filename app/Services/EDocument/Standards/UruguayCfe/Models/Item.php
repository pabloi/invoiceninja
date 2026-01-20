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
 * Item - Line Item Detail for Uruguay CFE
 *
 * Contains product/service details including quantities, prices, and taxes
 */
class Item extends BaseXmlModel
{
    /**
     * Indicator Factor Codes (IndFact)
     */
    public const IND_FACT_EXENTO_IVA = 1;        // Exento de IVA
    public const IND_FACT_GRAVADO_TASA_MIN = 2; // Gravado a Tasa Mínima
    public const IND_FACT_GRAVADO_TASA_BAS = 3; // Gravado a Tasa Básica
    public const IND_FACT_GRAVADO_OTRA = 4;     // Gravado a Otra Tasa
    public const IND_FACT_ENTREGA_GRATUITA = 5; // Entrega Gratuita
    public const IND_FACT_NO_FACTURABLE = 6;    // Producto o servicio no facturable
    public const IND_FACT_EXPORTACIONES = 10;   // Exportaciones y asimiladas
    public const IND_FACT_IMESI = 11;           // Impuesto percibido (IMESI)
    public const IND_FACT_IVA_EN_SUSPENSO = 12; // IVA en suspenso

    protected int $nroLinDet;
    protected ?string $codItem = null;
    protected int $indFact;
    protected string $nomItem;
    protected float $cantidad;
    protected ?string $uniMed = null;
    protected float $precioUnitario;
    protected ?float $descuento = null;
    protected ?float $descuentoPct = null;
    protected float $montoItem;

    public function getNroLinDet(): int
    {
        return $this->nroLinDet;
    }

    public function setNroLinDet(int $nroLinDet): self
    {
        if ($nroLinDet < 1 || $nroLinDet > 9999) {
            throw new \InvalidArgumentException('NroLinDet must be between 1 and 9999');
        }
        $this->nroLinDet = $nroLinDet;
        return $this;
    }

    public function getCodItem(): ?string
    {
        return $this->codItem;
    }

    public function setCodItem(?string $codItem): self
    {
        if ($codItem !== null && strlen($codItem) > 35) {
            $codItem = substr($codItem, 0, 35);
        }
        $this->codItem = $codItem;
        return $this;
    }

    public function getIndFact(): int
    {
        return $this->indFact;
    }

    public function setIndFact(int $indFact): self
    {
        $validCodes = [
            self::IND_FACT_EXENTO_IVA,
            self::IND_FACT_GRAVADO_TASA_MIN,
            self::IND_FACT_GRAVADO_TASA_BAS,
            self::IND_FACT_GRAVADO_OTRA,
            self::IND_FACT_ENTREGA_GRATUITA,
            self::IND_FACT_NO_FACTURABLE,
            self::IND_FACT_EXPORTACIONES,
            self::IND_FACT_IMESI,
            self::IND_FACT_IVA_EN_SUSPENSO,
        ];
        if (!in_array($indFact, $validCodes)) {
            throw new \InvalidArgumentException('Invalid IndFact value');
        }
        $this->indFact = $indFact;
        return $this;
    }

    public function getNomItem(): string
    {
        return $this->nomItem;
    }

    public function setNomItem(string $nomItem): self
    {
        if (strlen($nomItem) > 80) {
            $nomItem = substr($nomItem, 0, 80);
        }
        $this->nomItem = $nomItem;
        return $this;
    }

    public function getCantidad(): float
    {
        return $this->cantidad;
    }

    public function setCantidad(float $cantidad): self
    {
        $this->cantidad = $cantidad;
        return $this;
    }

    public function getUniMed(): ?string
    {
        return $this->uniMed;
    }

    public function setUniMed(?string $uniMed): self
    {
        if ($uniMed !== null && strlen($uniMed) > 4) {
            $uniMed = substr($uniMed, 0, 4);
        }
        $this->uniMed = $uniMed;
        return $this;
    }

    public function getPrecioUnitario(): float
    {
        return $this->precioUnitario;
    }

    public function setPrecioUnitario(float $precioUnitario): self
    {
        $this->precioUnitario = $precioUnitario;
        return $this;
    }

    public function getDescuento(): ?float
    {
        return $this->descuento;
    }

    public function setDescuento(?float $descuento): self
    {
        $this->descuento = $descuento;
        return $this;
    }

    public function getDescuentoPct(): ?float
    {
        return $this->descuentoPct;
    }

    public function setDescuentoPct(?float $descuentoPct): self
    {
        if ($descuentoPct !== null && ($descuentoPct < 0 || $descuentoPct > 100)) {
            throw new \InvalidArgumentException('DescuentoPct must be between 0 and 100');
        }
        $this->descuentoPct = $descuentoPct;
        return $this;
    }

    public function getMontoItem(): float
    {
        return $this->montoItem;
    }

    public function setMontoItem(float $montoItem): self
    {
        $this->montoItem = $montoItem;
        return $this;
    }

    /**
     * Calculate the line item total based on quantity, price, and discount
     */
    public function calculateMontoItem(): float
    {
        $subtotal = $this->cantidad * $this->precioUnitario;

        if ($this->descuento !== null && $this->descuento > 0) {
            $subtotal -= $this->descuento;
        } elseif ($this->descuentoPct !== null && $this->descuentoPct > 0) {
            $subtotal -= $subtotal * ($this->descuentoPct / 100);
        }

        return round($subtotal, 2);
    }

    /**
     * Check if item is taxable
     */
    public function isTaxable(): bool
    {
        return in_array($this->indFact, [
            self::IND_FACT_GRAVADO_TASA_MIN,
            self::IND_FACT_GRAVADO_TASA_BAS,
            self::IND_FACT_GRAVADO_OTRA,
        ]);
    }

    /**
     * Check if item is exempt from IVA
     */
    public function isExempt(): bool
    {
        return $this->indFact === self::IND_FACT_EXENTO_IVA;
    }

    public function toXml(\DOMDocument $doc): \DOMElement
    {
        $item = $this->createElement($doc, 'Item');

        $item->appendChild($this->createElement($doc, 'NroLinDet', (string) $this->nroLinDet));

        if ($this->codItem !== null) {
            $item->appendChild($this->createElement($doc, 'CodItem', $this->codItem));
        }

        $item->appendChild($this->createElement($doc, 'IndFact', (string) $this->indFact));
        $item->appendChild($this->createElement($doc, 'NomItem', $this->nomItem));
        $item->appendChild($this->createElement($doc, 'Cantidad', $this->formatQuantity($this->cantidad)));

        if ($this->uniMed !== null) {
            $item->appendChild($this->createElement($doc, 'UniMed', $this->uniMed));
        }

        $item->appendChild($this->createElement($doc, 'PrecioUnitario', $this->formatAmount($this->precioUnitario)));

        if ($this->descuento !== null && $this->descuento > 0) {
            $item->appendChild($this->createElement($doc, 'DescuentoMonto', $this->formatAmount($this->descuento)));
        }

        if ($this->descuentoPct !== null && $this->descuentoPct > 0) {
            $item->appendChild($this->createElement($doc, 'DescuentoPct', $this->formatAmount($this->descuentoPct)));
        }

        $item->appendChild($this->createElement($doc, 'MontoItem', $this->formatAmount($this->montoItem)));

        return $item;
    }

    public static function fromDOMElement(\DOMElement $element): self
    {
        $item = new self();

        $nroLinDet = $element->getElementsByTagName('NroLinDet')->item(0);
        if ($nroLinDet) {
            $item->setNroLinDet((int) $nroLinDet->nodeValue);
        }

        $codItem = $element->getElementsByTagName('CodItem')->item(0);
        if ($codItem) {
            $item->setCodItem($codItem->nodeValue);
        }

        $indFact = $element->getElementsByTagName('IndFact')->item(0);
        if ($indFact) {
            $item->setIndFact((int) $indFact->nodeValue);
        }

        $nomItem = $element->getElementsByTagName('NomItem')->item(0);
        if ($nomItem) {
            $item->setNomItem($nomItem->nodeValue);
        }

        $cantidad = $element->getElementsByTagName('Cantidad')->item(0);
        if ($cantidad) {
            $item->setCantidad((float) $cantidad->nodeValue);
        }

        $uniMed = $element->getElementsByTagName('UniMed')->item(0);
        if ($uniMed) {
            $item->setUniMed($uniMed->nodeValue);
        }

        $precioUnitario = $element->getElementsByTagName('PrecioUnitario')->item(0);
        if ($precioUnitario) {
            $item->setPrecioUnitario((float) $precioUnitario->nodeValue);
        }

        $descuento = $element->getElementsByTagName('DescuentoMonto')->item(0);
        if ($descuento) {
            $item->setDescuento((float) $descuento->nodeValue);
        }

        $descuentoPct = $element->getElementsByTagName('DescuentoPct')->item(0);
        if ($descuentoPct) {
            $item->setDescuentoPct((float) $descuentoPct->nodeValue);
        }

        $montoItem = $element->getElementsByTagName('MontoItem')->item(0);
        if ($montoItem) {
            $item->setMontoItem((float) $montoItem->nodeValue);
        }

        return $item;
    }
}
