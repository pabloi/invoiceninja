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
 * Emisor - Issuer (Company) Information for Uruguay CFE
 *
 * Contains company identification, address, and fiscal information
 */
class Emisor extends BaseXmlModel
{
    protected string $rucEmisor;
    protected string $rznSoc;
    protected ?string $nombreFantasia = null;
    protected ?string $cdgDgiSucur = null;
    protected ?string $domFiscal = null;
    protected ?string $ciudad = null;
    protected ?string $departamento = null;
    protected ?string $telefono = null;
    protected ?string $correoEmisor = null;

    public function getRucEmisor(): string
    {
        return $this->rucEmisor;
    }

    public function setRucEmisor(string $rucEmisor): self
    {
        // RUT format: 12 digits
        $cleaned = preg_replace('/[^0-9]/', '', $rucEmisor);
        if (strlen($cleaned) > 12) {
            throw new \InvalidArgumentException('RUCEmisor must be up to 12 digits');
        }
        $this->rucEmisor = $cleaned;
        return $this;
    }

    public function getRznSoc(): string
    {
        return $this->rznSoc;
    }

    public function setRznSoc(string $rznSoc): self
    {
        if (strlen($rznSoc) > 150) {
            $rznSoc = substr($rznSoc, 0, 150);
        }
        $this->rznSoc = $rznSoc;
        return $this;
    }

    public function getNombreFantasia(): ?string
    {
        return $this->nombreFantasia;
    }

    public function setNombreFantasia(?string $nombreFantasia): self
    {
        if ($nombreFantasia !== null && strlen($nombreFantasia) > 30) {
            $nombreFantasia = substr($nombreFantasia, 0, 30);
        }
        $this->nombreFantasia = $nombreFantasia;
        return $this;
    }

    public function getCdgDgiSucur(): ?string
    {
        return $this->cdgDgiSucur;
    }

    public function setCdgDgiSucur(?string $cdgDgiSucur): self
    {
        $this->cdgDgiSucur = $cdgDgiSucur;
        return $this;
    }

    public function getDomFiscal(): ?string
    {
        return $this->domFiscal;
    }

    public function setDomFiscal(?string $domFiscal): self
    {
        if ($domFiscal !== null && strlen($domFiscal) > 70) {
            $domFiscal = substr($domFiscal, 0, 70);
        }
        $this->domFiscal = $domFiscal;
        return $this;
    }

    public function getCiudad(): ?string
    {
        return $this->ciudad;
    }

    public function setCiudad(?string $ciudad): self
    {
        if ($ciudad !== null && strlen($ciudad) > 30) {
            $ciudad = substr($ciudad, 0, 30);
        }
        $this->ciudad = $ciudad;
        return $this;
    }

    public function getDepartamento(): ?string
    {
        return $this->departamento;
    }

    public function setDepartamento(?string $departamento): self
    {
        if ($departamento !== null && strlen($departamento) > 30) {
            $departamento = substr($departamento, 0, 30);
        }
        $this->departamento = $departamento;
        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): self
    {
        if ($telefono !== null && strlen($telefono) > 20) {
            $telefono = substr($telefono, 0, 20);
        }
        $this->telefono = $telefono;
        return $this;
    }

    public function getCorreoEmisor(): ?string
    {
        return $this->correoEmisor;
    }

    public function setCorreoEmisor(?string $correoEmisor): self
    {
        if ($correoEmisor !== null && strlen($correoEmisor) > 60) {
            $correoEmisor = substr($correoEmisor, 0, 60);
        }
        $this->correoEmisor = $correoEmisor;
        return $this;
    }

    public function toXml(\DOMDocument $doc): \DOMElement
    {
        $emisor = $this->createElement($doc, 'Emisor');

        $emisor->appendChild($this->createElement($doc, 'RUCEmisor', $this->rucEmisor));
        $emisor->appendChild($this->createElement($doc, 'RznSoc', $this->rznSoc));

        if ($this->nombreFantasia !== null) {
            $emisor->appendChild($this->createElement($doc, 'NomComercial', $this->nombreFantasia));
        }

        if ($this->cdgDgiSucur !== null) {
            $emisor->appendChild($this->createElement($doc, 'CdgDGISucur', $this->cdgDgiSucur));
        }

        if ($this->domFiscal !== null) {
            $emisor->appendChild($this->createElement($doc, 'DomFiscal', $this->domFiscal));
        }

        if ($this->ciudad !== null) {
            $emisor->appendChild($this->createElement($doc, 'Ciudad', $this->ciudad));
        }

        if ($this->departamento !== null) {
            $emisor->appendChild($this->createElement($doc, 'Departamento', $this->departamento));
        }

        if ($this->telefono !== null) {
            $emisor->appendChild($this->createElement($doc, 'Telefono', $this->telefono));
        }

        if ($this->correoEmisor !== null) {
            $emisor->appendChild($this->createElement($doc, 'CorreoEmisor', $this->correoEmisor));
        }

        return $emisor;
    }

    public static function fromDOMElement(\DOMElement $element): self
    {
        $emisor = new self();

        $rucEmisor = $element->getElementsByTagName('RUCEmisor')->item(0);
        if ($rucEmisor) {
            $emisor->setRucEmisor($rucEmisor->nodeValue);
        }

        $rznSoc = $element->getElementsByTagName('RznSoc')->item(0);
        if ($rznSoc) {
            $emisor->setRznSoc($rznSoc->nodeValue);
        }

        $nomComercial = $element->getElementsByTagName('NomComercial')->item(0);
        if ($nomComercial) {
            $emisor->setNombreFantasia($nomComercial->nodeValue);
        }

        $cdgDgiSucur = $element->getElementsByTagName('CdgDGISucur')->item(0);
        if ($cdgDgiSucur) {
            $emisor->setCdgDgiSucur($cdgDgiSucur->nodeValue);
        }

        $domFiscal = $element->getElementsByTagName('DomFiscal')->item(0);
        if ($domFiscal) {
            $emisor->setDomFiscal($domFiscal->nodeValue);
        }

        $ciudad = $element->getElementsByTagName('Ciudad')->item(0);
        if ($ciudad) {
            $emisor->setCiudad($ciudad->nodeValue);
        }

        $departamento = $element->getElementsByTagName('Departamento')->item(0);
        if ($departamento) {
            $emisor->setDepartamento($departamento->nodeValue);
        }

        $telefono = $element->getElementsByTagName('Telefono')->item(0);
        if ($telefono) {
            $emisor->setTelefono($telefono->nodeValue);
        }

        $correoEmisor = $element->getElementsByTagName('CorreoEmisor')->item(0);
        if ($correoEmisor) {
            $emisor->setCorreoEmisor($correoEmisor->nodeValue);
        }

        return $emisor;
    }
}
