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

use RobRichards\XMLSecLibs\XMLSecurityKey;
use RobRichards\XMLSecLibs\XMLSecurityDSig;

/**
 * CfeDocument - Complete CFE (Comprobante Fiscal Electrónico) Document
 *
 * This is the main document model that contains:
 * - Encabezado (Header): IdDoc, Emisor, Receptor, Totales
 * - Detalle (Detail): Line items
 * - CAEData (Authorization)
 * - Signature (Digital signature)
 * - Adenda (Optional additional information)
 */
class CfeDocument extends BaseXmlModel
{
    protected string $version = '1.0';

    // Encabezado components
    protected IdDoc $idDoc;
    protected Emisor $emisor;
    protected ?Receptor $receptor = null;
    protected Totales $totales;

    // Detalle
    /** @var Item[] */
    protected array $items = [];

    // Optional reference information
    /** @var array */
    protected array $referencias = [];

    // CAE Authorization
    protected ?CAEData $caeData = null;

    // Signature timestamp
    protected ?string $tmstFirma = null;

    // Adenda (optional notes)
    protected ?string $adenda = null;

    // Signature paths for signing
    protected ?string $privateKeyPath = null;
    protected ?string $publicKeyPath = null;
    protected ?string $certificatePath = null;
    protected ?string $certificatePassword = null;

    public function __construct()
    {
        $this->idDoc = new IdDoc();
        $this->emisor = new Emisor();
        $this->totales = new Totales();
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getIdDoc(): IdDoc
    {
        return $this->idDoc;
    }

    public function setIdDoc(IdDoc $idDoc): self
    {
        $this->idDoc = $idDoc;
        return $this;
    }

    public function getEmisor(): Emisor
    {
        return $this->emisor;
    }

    public function setEmisor(Emisor $emisor): self
    {
        $this->emisor = $emisor;
        return $this;
    }

    public function getReceptor(): ?Receptor
    {
        return $this->receptor;
    }

    public function setReceptor(?Receptor $receptor): self
    {
        $this->receptor = $receptor;
        return $this;
    }

    public function getTotales(): Totales
    {
        return $this->totales;
    }

    public function setTotales(Totales $totales): self
    {
        $this->totales = $totales;
        return $this;
    }

    /**
     * @return Item[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param Item[] $items
     */
    public function setItems(array $items): self
    {
        $this->items = $items;
        return $this;
    }

    public function addItem(Item $item): self
    {
        $this->items[] = $item;
        return $this;
    }

    public function getReferencias(): array
    {
        return $this->referencias;
    }

    public function setReferencias(array $referencias): self
    {
        $this->referencias = $referencias;
        return $this;
    }

    public function addReferencia(array $referencia): self
    {
        $this->referencias[] = $referencia;
        return $this;
    }

    public function getCaeData(): ?CAEData
    {
        return $this->caeData;
    }

    public function setCaeData(?CAEData $caeData): self
    {
        $this->caeData = $caeData;
        return $this;
    }

    public function getTmstFirma(): ?string
    {
        return $this->tmstFirma;
    }

    public function setTmstFirma(?string $tmstFirma): self
    {
        $this->tmstFirma = $tmstFirma;
        return $this;
    }

    public function getAdenda(): ?string
    {
        return $this->adenda;
    }

    public function setAdenda(?string $adenda): self
    {
        $this->adenda = $adenda;
        return $this;
    }

    public function setPrivateKeyPath(string $path): self
    {
        $this->privateKeyPath = $path;
        return $this;
    }

    public function setPublicKeyPath(string $path): self
    {
        $this->publicKeyPath = $path;
        return $this;
    }

    public function setCertificatePath(string $path): self
    {
        $this->certificatePath = $path;
        return $this;
    }

    public function setCertificatePassword(?string $password): self
    {
        $this->certificatePassword = $password;
        return $this;
    }

    /**
     * Determine the CFE document type wrapper element name
     */
    protected function getDocumentWrapperName(): string
    {
        $tipoCfe = $this->idDoc->getTipoCfe();

        if (IdDoc::isETicket($tipoCfe)) {
            return 'eTck';
        }

        if (IdDoc::isEFactura($tipoCfe)) {
            return 'eFact';
        }

        // For e-Remito, e-Resguardo, etc.
        return match ($tipoCfe) {
            IdDoc::TIPO_E_REMITO, IdDoc::TIPO_E_REMITO_CONTINGENCIA => 'eRem',
            IdDoc::TIPO_E_RESGUARDO, IdDoc::TIPO_E_RESGUARDO_CONTINGENCIA => 'eResg',
            default => 'CFE',
        };
    }

    /**
     * Generate the XML document
     */
    public function toXml(\DOMDocument $doc): \DOMElement
    {
        $wrapperName = $this->getDocumentWrapperName();

        // Create the wrapper element (eTck, eFact, etc.)
        $wrapper = $this->createElement($doc, $wrapperName);
        $wrapper->setAttribute('version', $this->version);

        // Add timestamp if set
        if ($this->tmstFirma !== null) {
            $wrapper->appendChild($this->createElement($doc, 'TmstFirma', $this->tmstFirma));
        }

        // Build Encabezado
        $encabezado = $this->createElement($doc, 'Encabezado');

        // Add IdDoc
        $encabezado->appendChild($this->idDoc->toXml($doc));

        // Add Emisor
        $encabezado->appendChild($this->emisor->toXml($doc));

        // Add Receptor (if present, required for e-Factura)
        if ($this->receptor !== null) {
            $encabezado->appendChild($this->receptor->toXml($doc));
        }

        // Add Totales
        $encabezado->appendChild($this->totales->toXml($doc));

        $wrapper->appendChild($encabezado);

        // Build Detalle
        $detalle = $this->createElement($doc, 'Detalle');
        foreach ($this->items as $item) {
            $detalle->appendChild($item->toXml($doc));
        }
        $wrapper->appendChild($detalle);

        // Add Referencias (if any)
        if (!empty($this->referencias)) {
            foreach ($this->referencias as $ref) {
                $referencia = $this->createElement($doc, 'Referencia');
                if (isset($ref['NroLinRef'])) {
                    $referencia->appendChild($this->createElement($doc, 'NroLinRef', (string) $ref['NroLinRef']));
                }
                if (isset($ref['TpoDocRef'])) {
                    $referencia->appendChild($this->createElement($doc, 'TpoDocRef', (string) $ref['TpoDocRef']));
                }
                if (isset($ref['Serie'])) {
                    $referencia->appendChild($this->createElement($doc, 'Serie', $ref['Serie']));
                }
                if (isset($ref['NroCFERef'])) {
                    $referencia->appendChild($this->createElement($doc, 'NroCFERef', (string) $ref['NroCFERef']));
                }
                if (isset($ref['RazonRef'])) {
                    $referencia->appendChild($this->createElement($doc, 'RazonRef', $ref['RazonRef']));
                }
                if (isset($ref['FechaCFERef'])) {
                    $referencia->appendChild($this->createElement($doc, 'FechaCFERef', $ref['FechaCFERef']));
                }
                $wrapper->appendChild($referencia);
            }
        }

        // Add CAEData
        if ($this->caeData !== null) {
            $wrapper->appendChild($this->caeData->toXml($doc));
        }

        // Add Adenda (outside signature scope)
        if ($this->adenda !== null && $this->adenda !== '') {
            $wrapper->appendChild($this->createElement($doc, 'Adenda', $this->adenda));
        }

        return $wrapper;
    }

    /**
     * Generate complete XML string
     */
    public function toXmlString(): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $cfe = $this->toXml($doc);
        $doc->appendChild($cfe);

        // Sign the document if certificates are configured
        if ($this->certificatePath && $this->privateKeyPath) {
            $this->signXml($doc);
        }

        return $doc->saveXML();
    }

    /**
     * Sign the XML document using XMLDSig
     */
    public function signXml(\DOMDocument $doc): void
    {
        if (!file_exists($this->certificatePath)) {
            throw new \RuntimeException("Certificate file not found at: " . $this->certificatePath);
        }
        if (!file_exists($this->privateKeyPath)) {
            throw new \RuntimeException("Private key file not found at: " . $this->privateKeyPath);
        }

        try {
            $objDSig = new XMLSecurityDSig(); //@phpstan-ignore-line
            $objDSig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N); //@phpstan-ignore-line

            // Create a new security key
            $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']); //@phpstan-ignore-line

            // Load the private key
            $objKey->loadKey($this->privateKeyPath, true);

            // Add the reference
            $objDSig->addReference(
                $doc,
                XMLSecurityDSig::SHA256, //@phpstan-ignore-line
                [
                    'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
                    'http://www.w3.org/2001/10/xml-exc-c14n#'
                ],
                ['force_uri' => true]
            );

            // Add the certificate
            $objDSig->add509Cert(file_get_contents($this->certificatePath));

            // Sign
            $objDSig->sign($objKey);

            // Append signature to document
            $objDSig->appendSignature($doc->documentElement);

        } catch (\Exception $e) {
            throw new \RuntimeException("Error signing XML: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate the document structure
     */
    public function validate(): array
    {
        $errors = [];

        // Validate IdDoc
        try {
            if (!isset($this->idDoc) || $this->idDoc->getTipoCfe() === null) {
                $errors[] = 'TipoCFE is required';
            }
            if (!isset($this->idDoc) || $this->idDoc->getSerie() === null) {
                $errors[] = 'Serie is required';
            }
            if (!isset($this->idDoc) || $this->idDoc->getNro() === null) {
                $errors[] = 'Nro is required';
            }
        } catch (\Throwable $e) {
            $errors[] = 'IdDoc validation error: ' . $e->getMessage();
        }

        // Validate Emisor
        try {
            if (!isset($this->emisor) || $this->emisor->getRucEmisor() === null) {
                $errors[] = 'RUCEmisor is required';
            }
            if (!isset($this->emisor) || $this->emisor->getRznSoc() === null) {
                $errors[] = 'RznSoc (Razón Social) is required';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Emisor validation error: ' . $e->getMessage();
        }

        // Validate Receptor for e-Factura
        try {
            $tipoCfe = $this->idDoc->getTipoCfe();
            if (IdDoc::isEFactura($tipoCfe)) {
                if ($this->receptor === null) {
                    $errors[] = 'Receptor is required for e-Factura';
                }
            }
        } catch (\Throwable $e) {
            // TipoCFE not set, skip receptor validation
        }

        // Validate items
        if (empty($this->items)) {
            $errors[] = 'At least one item is required';
        }

        // Validate CAE
        try {
            if ($this->caeData === null) {
                $errors[] = 'CAEData is required';
            } elseif (!$this->caeData->isNumberInRange($this->idDoc->getNro())) {
                $errors[] = 'Invoice number is outside authorized CAE range';
            } elseif ($this->caeData->isExpired()) {
                $errors[] = 'CAE has expired';
            }
        } catch (\Throwable $e) {
            $errors[] = 'CAE validation error: ' . $e->getMessage();
        }

        // Validate totals match
        $calculatedTotal = 0;
        foreach ($this->items as $item) {
            $calculatedTotal += $item->getMontoItem();
        }

        if (abs($calculatedTotal - $this->totales->getMontoTotal()) > 0.01) {
            $errors[] = 'Line items total does not match document total';
        }

        return $errors;
    }

    public static function fromDOMElement(\DOMElement $element): self
    {
        $cfe = new self();

        // Parse version
        $version = $element->getAttribute('version');
        if ($version) {
            $cfe->setVersion($version);
        }

        // Parse TmstFirma
        $tmstFirma = $element->getElementsByTagName('TmstFirma')->item(0);
        if ($tmstFirma) {
            $cfe->setTmstFirma($tmstFirma->nodeValue);
        }

        // Parse Encabezado
        $encabezado = $element->getElementsByTagName('Encabezado')->item(0);
        if ($encabezado) {
            // Parse IdDoc
            $idDoc = $encabezado->getElementsByTagName('IdDoc')->item(0);
            if ($idDoc) {
                $cfe->setIdDoc(IdDoc::fromDOMElement($idDoc));
            }

            // Parse Emisor
            $emisor = $encabezado->getElementsByTagName('Emisor')->item(0);
            if ($emisor) {
                $cfe->setEmisor(Emisor::fromDOMElement($emisor));
            }

            // Parse Receptor
            $receptor = $encabezado->getElementsByTagName('Receptor')->item(0);
            if ($receptor) {
                $cfe->setReceptor(Receptor::fromDOMElement($receptor));
            }

            // Parse Totales
            $totales = $encabezado->getElementsByTagName('Totales')->item(0);
            if ($totales) {
                $cfe->setTotales(Totales::fromDOMElement($totales));
            }
        }

        // Parse Detalle items
        $items = $element->getElementsByTagName('Item');
        foreach ($items as $item) {
            $cfe->addItem(Item::fromDOMElement($item));
        }

        // Parse CAEData
        $caeData = $element->getElementsByTagName('CAEData')->item(0);
        if ($caeData) {
            $cfe->setCaeData(CAEData::fromDOMElement($caeData));
        }

        // Parse Adenda
        $adenda = $element->getElementsByTagName('Adenda')->item(0);
        if ($adenda) {
            $cfe->setAdenda($adenda->nodeValue);
        }

        return $cfe;
    }
}
