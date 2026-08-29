<?php declare(strict_types=1);

namespace Monarc\Extension\Controller;

use Monarc\Core\Service\AuthenticationService;
use Monarc\Extension\Provider\GenericOAuth2IdentityProvider;
use Monarc\Extension\Service\IdentityManagementService;
use Psr\Container\ContainerInterface;

class SsoAuthenticationControllerFactory
{
    public function __invoke(ContainerInterface $container, $requestedName = null, ?array $options = null): SsoAuthenticationController
    {
        return new SsoAuthenticationController(
            $container->get(GenericOAuth2IdentityProvider::class),
            $container->get(IdentityManagementService::class),
            $container->get(AuthenticationService::class)
        );
    }
}