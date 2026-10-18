<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Monarc\Core\Exception\Exception;

/**
 * Stockage disque des PDF de signature, relatif à la racine de l'application (MONARC s'y place en web et en CLI).
 */
class SignatureFileStorage
{
    private const BASE_DIR = './data/signatures';

    /**
     * Copie le PDF source en version d'origine et en version courante d'une nouvelle demande.
     *
     * @return array{original: string, current: string}
     */
    public function importSourcePdf(string $sourcePath): array
    {
        $this->ensureDirectories();
        $id = uniqid('sig_');
        $original = self::BASE_DIR . "/original/{$id}_original.pdf";
        $current  = self::BASE_DIR . "/current/{$id}_current.pdf";

        if (!copy($sourcePath, $original) || !copy($sourcePath, $current)) {
            throw new Exception("Impossible d'enregistrer le document à signer.", 500);
        }

        return ['original' => $original, 'current' => $current];
    }

    public function storeSignedVersion(string $filePrefix, int $step, string $content): string
    {
        $this->ensureDirectories();
        $path = self::BASE_DIR . "/current/{$filePrefix}_step_{$step}_" . uniqid() . '.pdf';

        if (file_put_contents($path, $content) === false) {
            throw new Exception("Impossible d'enregistrer le document signé.", 500);
        }

        return $path;
    }

    /**
     * @param string[] $paths
     */
    public function deleteFiles(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function ensureDirectories(): void
    {
        foreach (['/original', '/current'] as $subDir) {
            $dir = self::BASE_DIR . $subDir;
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new Exception("Impossible de créer le dossier de stockage des signatures.", 500);
            }
        }
    }
}
