<?php declare(strict_types=1);

namespace Monarc\Extension\Controller;

use Monarc\Extension\Service\IdentityManagementService;
use Psr\Container\ContainerInterface;

class ApiUsersIdentityControllerFactory
{
    public function __invoke(ContainerInterface $container): ApiUsersIdentityController
    {
        return new ApiUsersIdentityController($container->get(IdentityManagementService::class));
    }
}