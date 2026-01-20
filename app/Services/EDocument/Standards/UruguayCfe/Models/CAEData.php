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
 * CAEData - Constancia de Autorización de Emisión (Authorization Data)
 *
 * Contains the authorization number (CAE) granted by DGI,
 * along with the authorized number range and expiration date.
 */
class CAEData extends BaseXmlModel
{
    protected string $caeId;
    protected int $dNro;  // From number (start of authorized range)
    protected int $hNro;  // To number (end of authorized range)
    protected string $fecVenc; // Expiration date

    public function getCaeId(): string
    {
        return $this->caeId;
    }

    public function setCaeId(string $caeId): self
    {
        // CAE is typically a numeric string
        $this->caeId = $caeId;
        return $this;
    }

    public function getDNro(): int
    {
        return $this->dNro;
    }

    public function setDNro(int $dNro): self
    {
        if ($dNro < 1) {
            throw new \InvalidArgumentException('DNro must be a positive integer');
        }
        $this->dNro = $dNro;
        return $this;
    }

    public function getHNro(): int
    {
        return $this->hNro;
    }

    public function setHNro(int $hNro): self
    {
        if ($hNro < 1) {
            throw new \InvalidArgumentException('HNro must be a positive integer');
        }
        $this->hNro = $hNro;
        return $this;
    }

    public function getFecVenc(): string
    {
        return $this->fecVenc;
    }

    public function setFecVenc(string $fecVenc): self
    {
        $this->fecVenc = $this->formatDate($fecVenc);
        return $this;
    }

    /**
     * Check if a given number is within the authorized range
     */
    public function isNumberInRange(int $number): bool
    {
        return $number >= $this->dNro && $number <= $this->hNro;
    }

    /**
     * Check if the CAE has expired
     */
    public function isExpired(): bool
    {
        return \Carbon\Carbon::parse($this->fecVenc)->isPast();
    }

    /**
     * Get remaining days until expiration
     */
    public function getDaysUntilExpiration(): int
    {
        $expDate = \Carbon\Carbon::parse($this->fecVenc);
        return max(0, now()->diffInDays($expDate, false));
    }

    /**
     * Get the total range size
     */
    public function getRangeSize(): int
    {
        return $this->hNro - $this->dNro + 1;
    }

    /**
     * Get remaining numbers in the range based on current number
     */
    public function getRemainingNumbers(int $currentNumber): int
    {
        if ($currentNumber > $this->hNro) {
            return 0;
        }
        return $this->hNro - $currentNumber;
    }

    public function toXml(\DOMDocument $doc): \DOMElement
    {
        $caeData = $this->createElement($doc, 'CAEData');

        $caeData->appendChild($this->createElement($doc, 'CAE_ID', $this->caeId));
        $caeData->appendChild($this->createElement($doc, 'DNro', (string) $this->dNro));
        $caeData->appendChild($this->createElement($doc, 'HNro', (string) $this->hNro));
        $caeData->appendChild($this->createElement($doc, 'FecVenc', $this->fecVenc));

        return $caeData;
    }

    public static function fromDOMElement(\DOMElement $element): self
    {
        $caeData = new self();

        $caeId = $element->getElementsByTagName('CAE_ID')->item(0);
        if ($caeId) {
            $caeData->setCaeId($caeId->nodeValue);
        }

        $dNro = $element->getElementsByTagName('DNro')->item(0);
        if ($dNro) {
            $caeData->setDNro((int) $dNro->nodeValue);
        }

        $hNro = $element->getElementsByTagName('HNro')->item(0);
        if ($hNro) {
            $caeData->setHNro((int) $hNro->nodeValue);
        }

        $fecVenc = $element->getElementsByTagName('FecVenc')->item(0);
        if ($fecVenc) {
            $caeData->setFecVenc($fecVenc->nodeValue);
        }

        return $caeData;
    }

    /**
     * Create CAEData from an array (e.g., from company settings)
     */
    public static function fromArray(array $data): self
    {
        $caeData = new self();

        if (isset($data['cae_id'])) {
            $caeData->setCaeId($data['cae_id']);
        }

        if (isset($data['d_nro'])) {
            $caeData->setDNro((int) $data['d_nro']);
        }

        if (isset($data['h_nro'])) {
            $caeData->setHNro((int) $data['h_nro']);
        }

        if (isset($data['fec_venc'])) {
            $caeData->setFecVenc($data['fec_venc']);
        }

        return $caeData;
    }

    /**
     * Convert to array for storage
     */
    public function toArray(): array
    {
        return [
            'cae_id' => $this->caeId,
            'd_nro' => $this->dNro,
            'h_nro' => $this->hNro,
            'fec_venc' => $this->fecVenc,
        ];
    }
}
