<?php declare(strict_types=1);

namespace Monarc\Extension\Controller;

use Monarc\Extension\Service\IdentityManagementService;
use Psr\Container\ContainerInterface;

class ApiIdentityProvidersControllerFactory
{
    public function __invoke(ContainerInterface $container): ApiIdentityProvidersController
    {
        return new ApiIdentityProvidersController($container->get(IdentityManagementService::class));
    }
}