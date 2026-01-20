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
 * Base XML Model for Uruguay CFE (Comprobante Fiscal Electrónico)
 *
 * Provides common XML generation functionality following DGI standard
 * Namespace: http://cfe.dgi.gub.uy
 */
abstract class BaseXmlModel
{
    public const XML_NAMESPACE = 'http://cfe.dgi.gub.uy';
    protected const XML_NAMESPACE_PREFIX = 'ns0';
    protected const XML_DS_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';
    protected const XML_DS_NAMESPACE_PREFIX = 'ds';

    /**
     * Create an XML element with optional value and attributes
     */
    protected function createElement(\DOMDocument $doc, string $name, ?string $value = null, array $attributes = []): \DOMElement
    {
        $element = $doc->createElement($name);
        if ($value !== null) {
            $textNode = $doc->createTextNode($value);
            $element->appendChild($textNode);
        }
        foreach ($attributes as $attrName => $attrValue) {
            $element->setAttribute($attrName, $attrValue);
        }
        return $element;
    }

    /**
     * Create an element with namespace prefix
     */
    protected function createElementNS(\DOMDocument $doc, string $name, ?string $value = null, array $attributes = []): \DOMElement
    {
        $element = $doc->createElementNS(self::XML_NAMESPACE, self::XML_NAMESPACE_PREFIX . ':' . $name);
        if ($value !== null) {
            $textNode = $doc->createTextNode($value);
            $element->appendChild($textNode);
        }
        foreach ($attributes as $attrName => $attrValue) {
            $element->setAttribute($attrName, $attrValue);
        }
        return $element;
    }

    /**
     * Create a digital signature namespace element
     */
    protected function createDsElement(\DOMDocument $doc, string $name, ?string $value = null, array $attributes = []): \DOMElement
    {
        $element = $doc->createElementNS(self::XML_DS_NAMESPACE, self::XML_DS_NAMESPACE_PREFIX . ':' . $name);
        if ($value !== null) {
            $textNode = $doc->createTextNode($value);
            $element->appendChild($textNode);
        }
        foreach ($attributes as $attrName => $attrValue) {
            $element->setAttribute($attrName, $attrValue);
        }
        return $element;
    }

    /**
     * Get element value from parent by tag name
     */
    protected function getElementValue(\DOMElement $parent, string $name, string $namespace = self::XML_NAMESPACE): ?string
    {
        $elements = $parent->getElementsByTagNameNS($namespace, $name);
        if ($elements->length > 0) {
            return $elements->item(0)->textContent;
        }
        return null;
    }

    /**
     * Format a number to 2 decimal places as string
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Format a number to 6 decimal places for quantities
     */
    protected function formatQuantity(float $quantity): string
    {
        return number_format($quantity, 6, '.', '');
    }

    /**
     * Format date in DGI format (YYYY-MM-DD)
     */
    protected function formatDate(string $date): string
    {
        return \Carbon\Carbon::parse($date)->format('Y-m-d');
    }

    /**
     * Convert to XML element
     */
    abstract public function toXml(\DOMDocument $doc): \DOMElement;

    /**
     * Create instance from XML
     */
    public static function fromXml($xml): self
    {
        if ($xml instanceof \DOMElement) {
            return static::fromDOMElement($xml);
        }

        if (!is_string($xml)) {
            throw new \InvalidArgumentException('Input must be either a string or DOMElement');
        }

        $doc = new \DOMDocument();
        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;
        if (!$doc->loadXML($xml)) {
            throw new \DOMException('Failed to load XML: Invalid XML format');
        }
        return static::fromDOMElement($doc->documentElement);
    }

    /**
     * Create instance from DOMElement
     */
    abstract public static function fromDOMElement(\DOMElement $element): self;
}
