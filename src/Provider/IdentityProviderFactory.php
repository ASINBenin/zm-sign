<?php declare(strict_types=1);

namespace Monarc\Extension\Provider;

use Monarc\Core\Service\ConfigService;
use Psr\Container\ContainerInterface;

class IdentityProviderFactory
{
    public function __invoke(ContainerInterface $container): GenericOAuth2IdentityProvider
    {
        $configService = $container->get(ConfigService::class);
        $ssoConfig = $configService->getConfigOption('sso_providers', []);
        $trustedxConfig = $ssoConfig['trustedx_pki'] ?? [];

        return new GenericOAuth2IdentityProvider(array_merge([
            'provider_code' => 'trustedx_pki',
        ], $trustedxConfig));
    }
}   