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

namespace App\Services\EDocument\Standards\UruguayCfe;

use App\Services\EDocument\Standards\UruguayCfe\Models\CfeDocument;

/**
 * DgiClient - Client for Uruguay DGI e-Factura Web Services
 *
 * Handles communication with DGI's SOAP web services for:
 * - Testing/Certification environment (ePrueba)
 * - Production environment (eFact)
 * - CFE status queries
 *
 * @link https://www.efactura.dgi.gub.uy/
 * @link https://www.efactura.dgi.gub.uy/files/DocumentoServiciosWebExternos
 */
class DgiClient
{
    /**
     * DGI Web Service Endpoints
     */
    public const ENDPOINT_TESTING = 'https://efactura.dgi.gub.uy:6443/ePrueba/ws_eprueba';
    public const ENDPOINT_PRODUCTION = 'https://efactura.dgi.gub.uy/efactura/ws_efactura';
    public const ENDPOINT_CERTIFICATION = 'https://efactura.dgi.gub.uy:6443/efactura/ws_certificacion';
    public const ENDPOINT_QUERIES_TEST = 'https://efactura.dgi.gub.uy:6460/ePrueba/ws_consultasPrueba';
    public const ENDPOINT_QUERIES_PROD = 'https://efactura.dgi.gub.uy:6440/efactura/ws_consultas';
    public const ENDPOINT_COMPANY_INFO = 'https://efactura.dgi.gub.uy:6475/efactura/ws_personaGetActEmpresarial';

    /**
     * SOAP Actions
     */
    public const SOAP_ACTION_SEND = 'http://dgi.gub.uyaction/AWS_EFACTURA.EFACRECEPCIONSOBRE';
    public const SOAP_ACTION_QUERY = 'http://dgi.gub.uyaction/AWS_EFACTURA.EFACCONSULTAS';
    public const SOAP_ACTION_COMPANY = 'DGI_Modernizacion_Consolidadoaction/AWS_PERSONAGETACTEMPRESARIAL.Execute';

    /**
     * Response Status Codes
     */
    public const STATUS_ACCEPTED = 'AS';      // Sobre Aceptado
    public const STATUS_REJECTED = 'RS';      // Sobre Rechazado
    public const STATUS_CFE_ACCEPTED = 'AE';  // CFE Aceptado
    public const STATUS_CFE_REJECTED = 'RE';  // CFE Rechazado
    public const STATUS_CFE_OBSERVED = 'OE';  // CFE Observado

    protected bool $isProduction = false;
    protected string $certificatePath;
    protected string $privateKeyPath;
    protected ?string $certificatePassword = null;
    protected string $ruc;
    protected array $errors = [];
    protected array $responses = [];

    public function __construct(string $ruc)
    {
        $this->ruc = preg_replace('/[^0-9]/', '', $ruc);
    }

    /**
     * Set to production mode
     */
    public function setProduction(bool $production = true): self
    {
        $this->isProduction = $production;
        return $this;
    }

    /**
     * Configure digital certificate for signing
     */
    public function setCertificate(string $certificatePath, string $privateKeyPath, ?string $password = null): self
    {
        $this->certificatePath = $certificatePath;
        $this->privateKeyPath = $privateKeyPath;
        $this->certificatePassword = $password;
        return $this;
    }

    /**
     * Get the appropriate endpoint based on environment
     */
    protected function getEndpoint(): string
    {
        return $this->isProduction ? self::ENDPOINT_PRODUCTION : self::ENDPOINT_TESTING;
    }

    /**
     * Get queries endpoint
     */
    protected function getQueriesEndpoint(): string
    {
        return $this->isProduction ? self::ENDPOINT_QUERIES_PROD : self::ENDPOINT_QUERIES_TEST;
    }

    /**
     * Send a CFE to DGI (EnvioCFE/Sobre)
     *
     * @param CfeDocument $cfe The CFE document to send
     * @return array Response with status and details
     */
    public function sendCfe(CfeDocument $cfe): array
    {
        $sobre = $this->buildSobre([$cfe]);
        return $this->sendSobre($sobre);
    }

    /**
     * Send multiple CFEs in a single envelope (Sobre)
     *
     * @param CfeDocument[] $cfes Array of CFE documents
     * @return array Response with status and details
     */
    public function sendBatch(array $cfes): array
    {
        $sobre = $this->buildSobre($cfes);
        return $this->sendSobre($sobre);
    }

    /**
     * Build the Sobre (envelope) containing CFEs
     *
     * @param CfeDocument[] $cfes
     * @return string XML string of the complete Sobre
     */
    protected function buildSobre(array $cfes): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        // Create EnvioCFE root element
        $envioCfe = $doc->createElementNS('http://cfe.dgi.gub.uy', 'ns0:EnvioCFE');
        $envioCfe->setAttribute('version', '1.0');
        $doc->appendChild($envioCfe);

        // Create Caratula (envelope header)
        $caratula = $doc->createElement('Caratula');
        $caratula->setAttribute('version', '1.0');

        $caratula->appendChild($doc->createElement('RUCEmisor', $this->ruc));
        $caratula->appendChild($doc->createElement('RutReceptor', '214844360018')); // DGI's RUT
        $caratula->appendChild($doc->createElement('Idemisor', '1'));
        $caratula->appendChild($doc->createElement('CantCFE', (string) count($cfes)));
        $caratula->appendChild($doc->createElement('Fecha', now()->format('Y-m-d\TH:i:s')));

        $envioCfe->appendChild($caratula);

        // Add each CFE
        foreach ($cfes as $cfe) {
            $cfeElement = $cfe->toXml($doc);
            $envioCfe->appendChild($doc->importNode($cfeElement, true));
        }

        // Sign the document
        $this->signSobre($doc);

        return $doc->saveXML();
    }

    /**
     * Sign the Sobre with WS-Security
     */
    protected function signSobre(\DOMDocument $doc): void
    {
        if (!file_exists($this->certificatePath) || !file_exists($this->privateKeyPath)) {
            throw new \RuntimeException('Certificate files not configured');
        }

        // Note: Full WS-Security implementation would require additional libraries
        // This is a placeholder for the signing logic

        // The actual implementation should:
        // 1. Add WSSE Security header
        // 2. Create BinarySecurityToken with X.509 certificate
        // 3. Sign the SOAP body using XMLDSig
        // 4. Include KeyInfo with X509IssuerSerial

        // For production use, consider using:
        // - robrichards/xmlseclibs for XMLDSig
        // - A SOAP library with WS-Security support

        nlog('Warning: Full WS-Security signing not implemented. Use a proper WS-Security library for production.');
    }

    /**
     * Send the Sobre to DGI
     */
    protected function sendSobre(string $sobreXml): array
    {
        $endpoint = $this->getEndpoint();

        $soapEnvelope = $this->buildSoapEnvelope($sobreXml);

        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $soapEnvelope,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "' . self::SOAP_ACTION_SEND . '"',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLCERT => $this->certificatePath,
                CURLOPT_SSLKEY => $this->privateKeyPath,
                CURLOPT_TIMEOUT => 60,
            ]);

            if ($this->certificatePassword) {
                curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certificatePassword);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $this->errors[] = "CURL Error: {$error}";
                return [
                    'success' => false,
                    'error' => $error,
                    'http_code' => $httpCode,
                ];
            }

            return $this->parseResponse($response);

        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build SOAP envelope for the request
     */
    protected function buildSoapEnvelope(string $body): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:dgi="http://dgi.gub.uy">
    <soap:Header>
        <!-- WS-Security header would go here -->
    </soap:Header>
    <soap:Body>
        <dgi:WS_eFactura.Execute>
            <dgi:Datain>
                <![CDATA[{$body}]]>
            </dgi:Datain>
        </dgi:WS_eFactura.Execute>
    </soap:Body>
</soap:Envelope>
XML;
    }

    /**
     * Parse DGI response
     */
    protected function parseResponse(string $response): array
    {
        $this->responses[] = $response;

        $doc = new \DOMDocument();
        if (!$doc->loadXML($response)) {
            return [
                'success' => false,
                'error' => 'Failed to parse response XML',
                'raw' => $response,
            ];
        }

        // Extract ACKSobre response
        $estado = $this->extractElementValue($doc, 'Estado');
        $token = $this->extractElementValue($doc, 'Token');
        $fechaHora = $this->extractElementValue($doc, 'Fechahora');

        $result = [
            'success' => $estado === self::STATUS_ACCEPTED,
            'status' => $estado,
            'token' => $token,
            'timestamp' => $fechaHora,
            'raw' => $response,
        ];

        // Parse individual CFE responses if present
        $cfeResponses = $this->parseCfeResponses($doc);
        if (!empty($cfeResponses)) {
            $result['cfe_responses'] = $cfeResponses;
        }

        return $result;
    }

    /**
     * Parse individual CFE responses from ACKSobre
     */
    protected function parseCfeResponses(\DOMDocument $doc): array
    {
        $responses = [];

        $detalles = $doc->getElementsByTagName('Detalle');
        foreach ($detalles as $detalle) {
            $response = [
                'serie' => $this->extractElementValue($detalle, 'Serie'),
                'numero' => $this->extractElementValue($detalle, 'Nro'),
                'estado' => $this->extractElementValue($detalle, 'Estado'),
            ];

            // Get rejection reason if present
            $motivo = $this->extractElementValue($detalle, 'MotivoRechazo');
            if ($motivo) {
                $response['motivo_rechazo'] = $motivo;
            }

            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * Query CFE status
     */
    public function queryCfeStatus(string $serie, int $numero): array
    {
        // Implementation for querying CFE status
        // Uses ws_consultas endpoint

        $endpoint = $this->getQueriesEndpoint();

        $queryXml = $this->buildStatusQuery($serie, $numero);

        // Similar SOAP call as sendSobre...
        // This is a placeholder - implement based on DGI documentation

        return [
            'implemented' => false,
            'message' => 'Status query implementation pending',
        ];
    }

    /**
     * Build status query XML
     */
    protected function buildStatusQuery(string $serie, int $numero): string
    {
        return <<<XML
<ConsultaCFE>
    <RUCEmisor>{$this->ruc}</RUCEmisor>
    <TipoCFE>111</TipoCFE>
    <Serie>{$serie}</Serie>
    <Nro>{$numero}</Nro>
</ConsultaCFE>
XML;
    }

    /**
     * Extract element value from XML
     */
    protected function extractElementValue($parent, string $tagName): ?string
    {
        if ($parent instanceof \DOMDocument) {
            $elements = $parent->getElementsByTagName($tagName);
        } else {
            $elements = $parent->getElementsByTagName($tagName);
        }

        if ($elements->length > 0) {
            return $elements->item(0)->nodeValue;
        }

        return null;
    }

    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get raw responses for debugging
     */
    public function getResponses(): array
    {
        return $this->responses;
    }

    /**
     * Validate connection to DGI (ping test)
     */
    public function testConnection(): array
    {
        $endpoint = $this->getEndpoint() . '?wsdl';

        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return [
                    'success' => false,
                    'error' => $error,
                ];
            }

            return [
                'success' => $httpCode === 200,
                'http_code' => $httpCode,
                'wsdl_available' => strpos($response, 'wsdl') !== false || strpos($response, 'definitions') !== false,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get QR code URL for CFE verification
     *
     * @param int $tipoCfe CFE type code
     * @param string $serie CFE series
     * @param int $numero CFE number
     * @param float $monto Total amount
     * @param string $fecha Issue date (YYYY-MM-DD)
     * @param string $hash Document hash (first 8 chars of SHA256)
     * @return string QR verification URL
     */
    public function getQrVerificationUrl(int $tipoCfe, string $serie, int $numero, float $monto, string $fecha, string $hash): string
    {
        $params = [
            $this->ruc,
            $tipoCfe,
            $serie,
            $numero,
            number_format($monto, 2, '.', ''),
            $fecha,
            substr($hash, 0, 8),
        ];

        return 'https://www.efactura.dgi.gub.uy/consultaQR/cfe?' . implode(',', $params);
    }
}
