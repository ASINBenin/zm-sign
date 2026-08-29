<?php declare(strict_types=1);

namespace Monarc\Extension\Service;

use Psr\Container\ContainerInterface;

class IdentityManagementServiceFactory
{
    public function __invoke(ContainerInterface $container): IdentityManagementService
    {
        $entityManager = $container->has('doctrine.entitymanager.orm_cli')
            ? $container->get('doctrine.entitymanager.orm_cli')
            : $container->get('doctrine.entitymanager.orm_default');

        $config = $container->has('Config') ? $container->get('Config') : [];

        return new IdentityManagementService($entityManager, $config);
    }
}