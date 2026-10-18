<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Monarc\Sign\Entity\Signature\SignatureRequest;

/**
 * URLs publiques des retours TrustedX et des redirections vers le front.
 * APP_URL (ex. https://monarc.example/anip) est prioritaire ; à défaut, les en-têtes transmis par Traefik.
 */
class SignatureUrlBuilder
{
    public function __construct(private ?string $appUrl)
    {
    }

    public function getApiUrl(int $requestId, string $action): string
    {
        return $this->getBaseUrl() . "/api/signature-requests/{$requestId}/{$action}";
    }

    // Connexion SSO via /auth/sso/redirect, qui lie le state OAuth2 au navigateur
    public function getSsoLoginUrl(string $providerCode): string
    {
        return $this->getBaseUrl() . '/auth/sso/redirect?' . http_build_query(['provider' => $providerCode]);
    }

    public function getFrontUrl(?SignatureRequest $request, string $page, array $query = []): string
    {
        $url = $request !== null && $request->getAnr() !== null
            ? $this->getBasePath() . "/#/client/project/{$request->getAnr()->getId()}/anr/{$page}/{$request->getId()}"
            : $this->getBasePath() . '/#/client';

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    private function getBaseUrl(): string
    {
        if (!empty($this->appUrl)) {
            return rtrim($this->appUrl, '/');
        }

        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO']
            ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';

        return "{$scheme}://{$host}" . $this->getForwardedPrefix();
    }

    private function getBasePath(): string
    {
        if (!empty($this->appUrl)) {
            return rtrim((string)parse_url($this->appUrl, PHP_URL_PATH), '/');
        }

        return $this->getForwardedPrefix();
    }

    private function getForwardedPrefix(): string
    {
        $prefix = trim((string)($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? ''), '/');

        return $prefix === '' ? '' : '/' . $prefix;
    }
}
