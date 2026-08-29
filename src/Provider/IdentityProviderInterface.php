<?php declare(strict_types=1);

namespace Monarc\Extension\Provider;

interface IdentityProviderInterface
{
    public function getAuthorizationUrl(string $redirectUri, string $state): string;
    public function authenticateCode(string $code, string $redirectUri): ExternalIdentityDto;
}