<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Monarc\Core\Service\Sso\IdentityManagementService;

class SignatureProcessServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SignatureProcessService
    {
        $em = $container->get('doctrine.entitymanager.orm_cli');
        $trustedXClient = $container->get(TrustedXSignatureClient::class);
        $identityService = $container->get(IdentityManagementService::class);
        $config = $container->get('config');

        return new SignatureProcessService($em, $trustedXClient, $identityService, $config);
    }
}
