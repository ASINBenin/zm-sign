<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Monarc\Core\Exception\Exception;

/**
 * Contrôle un PDF signé localement (signature PAdES ajoutée en mise à jour incrémentale) avant de l'accepter.
 * La chaîne de certification n'est pas vérifiée (OPENSSL_CMS_NOVERIFY) : aucune autorité de confiance configurée.
 */
class LocalPdfSignatureVerifier
{
    private const BYTE_RANGE_PATTERN = '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/';

    /**
     * Vérifie que $signedPdf est $currentPdf complété d'une seule signature valide couvrant tout le fichier.
     *
     * @return array{dn: string, serialNumber: ?string} Sujet du certificat signataire, lu dans la signature.
     */
    public function verifyAddedSignature(string $currentPdf, string $signedPdf): array
    {
        if ($currentPdf === '' || !str_starts_with($signedPdf, $currentPdf)) {
            throw new Exception("Le document signé ne correspond pas au document à signer.", 412);
        }

        $byteRanges = $this->findByteRanges($signedPdf);
        if (count($byteRanges) !== count($this->findByteRanges($currentPdf)) + 1) {
            throw new Exception("Le document doit contenir exactement une nouvelle signature.", 412);
        }

        [$offset1, $length1, $offset2, $length2] = end($byteRanges);
        $contentsStart = $offset1 + $length1;
        if ($offset1 !== 0
            || $contentsStart < strlen($currentPdf)
            || $offset2 <= $contentsStart
            || $offset2 + $length2 !== strlen($signedPdf)
        ) {
            throw new Exception("La signature ne couvre pas l'intégralité du document.", 412);
        }

        $signerCertificate = $this->verifyCmsSignature(
            substr($signedPdf, $offset1, $length1) . substr($signedPdf, $offset2, $length2),
            $this->extractCms($signedPdf, $contentsStart, $offset2)
        );

        $certificate = openssl_x509_parse($signerCertificate) ?: [];

        return [
            'dn'           => (string)($certificate['name'] ?? ''),
            'serialNumber' => isset($certificate['subject']['serialNumber'])
                ? (string)$certificate['subject']['serialNumber']
                : null,
        ];
    }

    /**
     * @return int[][]
     */
    private function findByteRanges(string $pdf): array
    {
        preg_match_all(self::BYTE_RANGE_PATTERN, $pdf, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match) => array_map('intval', array_slice($match, 1)), $matches);
    }

    // /Contents est un DER hexadécimal entre < et >, complété par des zéros jusqu'à la taille réservée.
    private function extractCms(string $pdf, int $start, int $end): string
    {
        $der = hex2bin(substr($pdf, $start + 1, $end - $start - 2));
        if ($der === false || strlen($der) < 2) {
            throw new Exception("Signature PDF illisible.", 412);
        }

        return substr($der, 0, $this->getDerLength($der));
    }

    private function getDerLength(string $der): int
    {
        $lengthByte = ord($der[1]);
        if ($lengthByte < 0x80) {
            return 2 + $lengthByte;
        }

        $lengthSize = $lengthByte & 0x7f;
        $length = 0;
        for ($i = 0; $i < $lengthSize; $i++) {
            $length = ($length << 8) | ord($der[2 + $i]);
        }

        return 2 + $lengthSize + $length;
    }

    /**
     * @return string Certificat signataire (PEM).
     */
    private function verifyCmsSignature(string $signedContent, string $cms): string
    {
        $contentFile = tempnam(sys_get_temp_dir(), 'sigc');
        $cmsFile     = tempnam(sys_get_temp_dir(), 'sigs');
        $signerFile  = tempnam(sys_get_temp_dir(), 'sigp');

        try {
            file_put_contents($contentFile, $signedContent);
            file_put_contents($cmsFile, $cms);

            $isValid = openssl_cms_verify(
                $contentFile,
                OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                $signerFile,
                [],
                null,
                null,
                null,
                $cmsFile,
                OPENSSL_ENCODING_DER
            );
            if ($isValid !== true) {
                throw new Exception("La signature du document est invalide.", 412);
            }

            return (string)file_get_contents($signerFile);
        } finally {
            @unlink($contentFile);
            @unlink($cmsFile);
            @unlink($signerFile);
        }
    }
}
