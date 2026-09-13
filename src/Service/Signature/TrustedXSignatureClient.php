<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Monarc\Core\Exception\Exception;

class TrustedXSignatureClient
{
    private const IMAGE_BACKGROUND_BINARY = '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';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Crée un processus de signature sur TrustedX (multipart/form-data)
     */
    public function createSignerProcess(
        string $accessToken, 
        string $pdfFilePath, 
        string $finishCallbackUrl,
        int $pageNumber,
        float $coordX,
        float $coordY,
        float $width,
        float $height,
        string $signerName = '',
        ?string $signatureImageBase64 = null
    ): array {
        if (!file_exists($pdfFilePath)) {
            throw new Exception("Fichier PDF introuvable : " . $pdfFilePath, 404);
        }

        $url = $this->config['esignsp_processes_url'];
        if (empty($url)) {
            throw new Exception("URL de processus de signature manquante.", 400);
        }

        $sanitizedName = preg_replace('/\s+/', '-', trim($signerName ?: 'Signataire'));
        $fieldName = $sanitizedName . '-' . rand(1000, 9999);

        $hasImage = !empty($signatureImageBase64);

        $appearance = [
            'background_image' => [
                'x' => 0,
                'y' => 0,
                'binary' => self::IMAGE_BACKGROUND_BINARY,
            ],
            'signature_details' => [
                'details' => $hasImage ? [] : [
                    [
                        'type' => 'name',
                        'title' => '',
                    ],
                    [
                        'type' => 'date',
                        'title' => 'Le : ',
                        'format' => 'dd-MM-yyyy HH:mm:ss',
                    ],
                ],
            ],
        ];

        if ($hasImage) {
            $cleanImage = preg_replace('#^data:image/\w+;base64,#i', '', $signatureImageBase64);
            $appearance['foreground_image'] = [
                'x' => 0,
                'y' => 0,
                'binary' => $cleanImage,
            ];
        }

        // Récupération des dimensions réelles et de la rotation de la page ciblée (par défaut A4 portrait 595x842)
        $pageWidth = 595.0;
        $pageHeight = 842.0;
        $rotation = 0;

        if (file_exists($pdfFilePath)) {
            $pdfData = file_get_contents($pdfFilePath);
            $pageIdx = max(0, ((int)$pageNumber) - 1);

            if (preg_match_all('/\/MediaBox\s*\[\s*([\d\.\s]+)\s*\]/', $pdfData, $matches)) {
                $boxStr = $matches[1][$pageIdx] ?? $matches[1][0] ?? '0 0 595 842';
                $parts = preg_split('/\s+/', trim($boxStr));
                if (count($parts) >= 4) {
                    $pageWidth = (float)$parts[2];
                    $pageHeight = (float)$parts[3];
                }
            }

            if (preg_match_all('/\/Rotate\s+(\d+)/', $pdfData, $rotMatches)) {
                $rotation = (int)($rotMatches[1][$pageIdx] ?? $rotMatches[1][0] ?? 0);
            }
        }

        // Coordonnées pré-calculées au frontend (repère PDF cartésien bas-gauche, AVANT rotation).
        // Garde-fou serveur : on borne le rectangle DANS la vraie boîte de la page. Ainsi, même si
        // le calcul client dérape (page paysage/rotée, multi-pages), la signature ne peut pas se
        // retrouver hors page — donc invisible — dans le PDF signé.
        $rotNorm = ((int)$rotation % 360 + 360) % 360;
        $boxW = ($rotNorm === 90 || $rotNorm === 270) ? $pageHeight : $pageWidth;
        $boxH = ($rotNorm === 90 || $rotNorm === 270) ? $pageWidth  : $pageHeight;

        $reqX = round((float)$coordX, 2);
        $reqY = round((float)$coordY, 2);
        $reqW = round((float)$width, 2);
        $reqH = round((float)$height, 2);

        $finalW = min(max(10.0, $reqW), $boxW);
        $finalH = min(max(10.0, $reqH), $boxH);
        $finalX = min(max(0.0, $reqX), max(0.0, $boxW - $finalW));
        $finalY = min(max(0.0, $reqY), max(0.0, $boxH - $finalH));

        $processConfig = [
            'process_type' => 'urn:safelayer:eidas:processes:document:sign:esigp',
            'labels' => [
                ['server', 'citizen'],
            ],
            'ui_locales' => ['fr'],
            'finish_callback_url' => $finishCallbackUrl,
            'signer' => [
                'signature_policy_id' => 'urn:safelayer:eidas:policies:sign:document:pdf',
                'parameters' => [
                    'type' => 'pades-bes',
                    'default_digest_algorithm' => 'sha256',
                    'location' => '',
                    'signature_field' => [
                        'name' => $fieldName,
                        'location' => [
                            'page' => ['number' => (int)$pageNumber],
                            'rectangle' => [
                                'x'      => $finalX,
                                'y'      => $finalY,
                                'height' => $finalH,
                                'width'  => $finalW,
                            ],
                        ],
                        'appearance' => $appearance,
                    ],
                ],
            ],
            'views' => [
                'document_agreement' => [
                    'skip_server_id' => true,
                ],
            ],
        ];

        $processJson = json_encode($processConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $postFields = [
            'process'  => $processJson,
            'document' => new \CURLFile($pdfFilePath, 'application/pdf', 'document.pdf'),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erreur réseau lors de la création du process de signature TrustedX: " . $error, 500);
        }

        $data = json_decode((string)$response, true);
        if ($httpCode >= 400 || !is_array($data) || empty($data['id'])) {
            $msg = $data['error_description'] ?? $data['message'] ?? "Code HTTP $httpCode retourné par TrustedX";
            throw new Exception("Échec de création du processus de signature : " . $msg, 400);
        }

        return $data;
    }

    /**
     * Retourne l'URL de l'interface graphique de signature pour la redirection de l'utilisateur
     */
    public function getProcessUiUrl(string $processId): string
    {
        $uiBase = $this->config['esignsp_ui_url'] 
            ?? (rtrim($this->config['trustedx_url'] ?? '', '/') . '/trustedx-resources/esignsp/v2/ui');
        return $uiBase . '?signerProcessId=' . urlencode($processId);
    }

    /**
     * Récupère les métadonnées d'un processus de signature depuis TrustedX
     */
    /**
     * Télécharge le document signé à partir des données retournées par TrustedX
     */
    public function downloadSignedDocument(string $accessToken, string $processIdOrDocId): string
    {
        if (!str_starts_with($processIdOrDocId, 'http')) {
            // Stratégie 1 : essayer directement comme documentId (URL /documents/{id}/content)
            // C'est ce que fait portal-id — plus simple et plus fiable
            try {
                $content = $this->getSignedDocumentContent($accessToken, $processIdOrDocId);
                if (!empty($content)) {
                    return $content;
                }
            } catch (\Throwable $e) {
                // pas un documentId valide, tentative via process ci-dessous
            }

            // Stratégie 2 : interroger le process pour extraire le documentId, puis télécharger
            try {
                $processData = $this->getSignerProcess($accessToken, $processIdOrDocId);

                $doc = $processData['documents'][0] ?? $processData['document'] ?? null;
                if ($doc) {
                    $docId = $doc['id'] ?? $doc['content'] ?? $doc['url'] ?? $doc['href'] ?? null;
                    if (!empty($docId)) {
                        return $this->getSignedDocumentContent($accessToken, $docId);
                    }
                }
            } catch (\Throwable $e) {
                // échec d'interrogation du process, repli sur la dernière tentative ci-dessous
            }
        }

        return $this->getSignedDocumentContent($accessToken, $processIdOrDocId);
    }

    /**
     * Nettoie et supprime le processus de signature sur TrustedX
     */
    public function deleteSignerProcess(string $accessToken, string $processId): bool
    {
        $url = rtrim($this->config['trustedx_url'] ?? '', '/') . '/trustedx-resources/esignsp/v2/signer_processes/' . $processId;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 || $httpCode === 204);
    }

    // Helpers internes réutilisés par downloadSignedDocument()

    private function getSignerProcess(string $accessToken, string $processId): array
    {
        $url = rtrim($this->config['trustedx_url'] ?? '', '/') . '/trustedx-resources/esignsp/v2/signer_processes/' . $processId;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            throw new Exception("Impossible de récupérer les détails du processus $processId (HTTP $httpCode)", 400);
        }

        return json_decode((string)$response, true) ?? [];
    }

    /**
     * Télécharge le contenu binaire du document signé PAdES
     */
    private function getSignedDocumentContent(string $accessToken, string $documentIdOrUrl): string
    {
        $docsBase = $this->config['esignsp_documents_url']
            ?? (rtrim($this->config['trustedx_url'] ?? '', '/') . '/trustedx-resources/esignsp/v2/documents');
        $url = str_starts_with($documentIdOrUrl, 'http')
            ? $documentIdOrUrl
            : rtrim($docsBase, '/') . '/' . $documentIdOrUrl . '/content';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/pdf, application/octet-stream',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode >= 400 || empty($response)) {
            throw new Exception("Impossible de récupérer le document signé depuis TrustedX (HTTP $httpCode).", 400);
        }

        return (string)$response;
    }
}