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
 * Receptor - Receiver (Client) Information for Uruguay CFE
 *
 * Used for e-Factura (B2B) documents. For e-Ticket (B2C),
 * receiver information may be minimal or optional.
 */
class Receptor extends BaseXmlModel
{
    /**
     * Document Type Codes
     */
    public const TIPO_DOC_CI = 2;        // Cédula de Identidad
    public const TIPO_DOC_PASAPORTE = 3; // Pasaporte
    public const TIPO_DOC_RUC = 4;       // RUC (empresa extranjera)
    public const TIPO_DOC_OTRO = 5;      // Otro documento

    protected ?int $tipoDocRecep = null;
    protected ?string $codPaisRecep = null;
    protected ?string $docRecep = null;
    protected ?string $rznSocRecep = null;
    protected ?string $dirRecep = null;
    protected ?string $ciudadRecep = null;
    protected ?string $deptoRecep = null;
    protected ?string $paisRecep = null;
    protected ?string $telefonoRecep = null;
    protected ?string $correoRecep = null;

    public function getTipoDocRecep(): ?int
    {
        return $this->tipoDocRecep;
    }

    public function setTipoDocRecep(?int $tipoDocRecep): self
    {
        if ($tipoDocRecep !== null && !in_array($tipoDocRecep, [
            self::TIPO_DOC_CI,
            self::TIPO_DOC_PASAPORTE,
            self::TIPO_DOC_RUC,
            self::TIPO_DOC_OTRO,
        ])) {
            throw new \InvalidArgumentException('Invalid TipoDocRecep value');
        }
        $this->tipoDocRecep = $tipoDocRecep;
        return $this;
    }

    public function getCodPaisRecep(): ?string
    {
        return $this->codPaisRecep;
    }

    public function setCodPaisRecep(?string $codPaisRecep): self
    {
        if ($codPaisRecep !== null && strlen($codPaisRecep) > 2) {
            throw new \InvalidArgumentException('CodPaisRecep must be ISO 3166-1 alpha-2 code');
        }
        $this->codPaisRecep = $codPaisRecep;
        return $this;
    }

    public function getDocRecep(): ?string
    {
        return $this->docRecep;
    }

    public function setDocRecep(?string $docRecep): self
    {
        if ($docRecep !== null && strlen($docRecep) > 20) {
            $docRecep = substr($docRecep, 0, 20);
        }
        $this->docRecep = $docRecep;
        return $this;
    }

    public function getRznSocRecep(): ?string
    {
        return $this->rznSocRecep;
    }

    public function setRznSocRecep(?string $rznSocRecep): self
    {
        if ($rznSocRecep !== null && strlen($rznSocRecep) > 150) {
            $rznSocRecep = substr($rznSocRecep, 0, 150);
        }
        $this->rznSocRecep = $rznSocRecep;
        return $this;
    }

    public function getDirRecep(): ?string
    {
        return $this->dirRecep;
    }

    public function setDirRecep(?string $dirRecep): self
    {
        if ($dirRecep !== null && strlen($dirRecep) > 70) {
            $dirRecep = substr($dirRecep, 0, 70);
        }
        $this->dirRecep = $dirRecep;
        return $this;
    }

    public function getCiudadRecep(): ?string
    {
        return $this->ciudadRecep;
    }

    public function setCiudadRecep(?string $ciudadRecep): self
    {
        if ($ciudadRecep !== null && strlen($ciudadRecep) > 30) {
            $ciudadRecep = substr($ciudadRecep, 0, 30);
        }
        $this->ciudadRecep = $ciudadRecep;
        return $this;
    }

    public function getDeptoRecep(): ?string
    {
        return $this->deptoRecep;
    }

    public function setDeptoRecep(?string $deptoRecep): self
    {
        if ($deptoRecep !== null && strlen($deptoRecep) > 30) {
            $deptoRecep = substr($deptoRecep, 0, 30);
        }
        $this->deptoRecep = $deptoRecep;
        return $this;
    }

    public function getPaisRecep(): ?string
    {
        return $this->paisRecep;
    }

    public function setPaisRecep(?string $paisRecep): self
    {
        if ($paisRecep !== null && strlen($paisRecep) > 30) {
            $paisRecep = substr($paisRecep, 0, 30);
        }
        $this->paisRecep = $paisRecep;
        return $this;
    }

    public function getTelefonoRecep(): ?string
    {
        return $this->telefonoRecep;
    }

    public function setTelefonoRecep(?string $telefonoRecep): self
    {
        if ($telefonoRecep !== null && strlen($telefonoRecep) > 20) {
            $telefonoRecep = substr($telefonoRecep, 0, 20);
        }
        $this->telefonoRecep = $telefonoRecep;
        return $this;
    }

    public function getCorreoRecep(): ?string
    {
        return $this->correoRecep;
    }

    public function setCorreoRecep(?string $correoRecep): self
    {
        if ($correoRecep !== null && strlen($correoRecep) > 60) {
            $correoRecep = substr($correoRecep, 0, 60);
        }
        $this->correoRecep = $correoRecep;
        return $this;
    }

    /**
     * Check if receiver has identification
     */
    public function hasIdentification(): bool
    {
        return $this->docRecep !== null && $this->docRecep !== '';
    }

    public function toXml(\DOMDocument $doc): \DOMElement
    {
        $receptor = $this->createElement($doc, 'Receptor');

        if ($this->tipoDocRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'TipoDocRecep', (string) $this->tipoDocRecep));
        }

        if ($this->codPaisRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'CodPaisRecep', $this->codPaisRecep));
        }

        if ($this->docRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'DocRecep', $this->docRecep));
        }

        if ($this->rznSocRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'RznSocRecep', $this->rznSocRecep));
        }

        if ($this->dirRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'DirRecep', $this->dirRecep));
        }

        if ($this->ciudadRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'CiudadRecep', $this->ciudadRecep));
        }

        if ($this->deptoRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'DeptoRecep', $this->deptoRecep));
        }

        if ($this->paisRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'PaisRecep', $this->paisRecep));
        }

        if ($this->telefonoRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'TelefonoRecep', $this->telefonoRecep));
        }

        if ($this->correoRecep !== null) {
            $receptor->appendChild($this->createElement($doc, 'CorreoRecep', $this->correoRecep));
        }

        return $receptor;
    }

    public static function fromDOMElement(\DOMElement $element): self
    {
        $receptor = new self();

        $tipoDocRecep = $element->getElementsByTagName('TipoDocRecep')->item(0);
        if ($tipoDocRecep) {
            $receptor->setTipoDocRecep((int) $tipoDocRecep->nodeValue);
        }

        $codPaisRecep = $element->getElementsByTagName('CodPaisRecep')->item(0);
        if ($codPaisRecep) {
            $receptor->setCodPaisRecep($codPaisRecep->nodeValue);
        }

        $docRecep = $element->getElementsByTagName('DocRecep')->item(0);
        if ($docRecep) {
            $receptor->setDocRecep($docRecep->nodeValue);
        }

        $rznSocRecep = $element->getElementsByTagName('RznSocRecep')->item(0);
        if ($rznSocRecep) {
            $receptor->setRznSocRecep($rznSocRecep->nodeValue);
        }

        $dirRecep = $element->getElementsByTagName('DirRecep')->item(0);
        if ($dirRecep) {
            $receptor->setDirRecep($dirRecep->nodeValue);
        }

        $ciudadRecep = $element->getElementsByTagName('CiudadRecep')->item(0);
        if ($ciudadRecep) {
            $receptor->setCiudadRecep($ciudadRecep->nodeValue);
        }

        $deptoRecep = $element->getElementsByTagName('DeptoRecep')->item(0);
        if ($deptoRecep) {
            $receptor->setDeptoRecep($deptoRecep->nodeValue);
        }

        $paisRecep = $element->getElementsByTagName('PaisRecep')->item(0);
        if ($paisRecep) {
            $receptor->setPaisRecep($paisRecep->nodeValue);
        }

        $telefonoRecep = $element->getElementsByTagName('TelefonoRecep')->item(0);
        if ($telefonoRecep) {
            $receptor->setTelefonoRecep($telefonoRecep->nodeValue);
        }

        $correoRecep = $element->getElementsByTagName('CorreoRecep')->item(0);
        if ($correoRecep) {
            $receptor->setCorreoRecep($correoRecep->nodeValue);
        }

        return $receptor;
    }
}
