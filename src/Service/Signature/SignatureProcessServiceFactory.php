<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Monarc\Core\Service\Sso\IdentityManagementService;
use Monarc\FrontOffice\Table\UserAnrTable;

class SignatureProcessServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SignatureProcessService
    {
        $em = $container->get('doctrine.entitymanager.orm_cli');
        $trustedXClient = $container->get(TrustedXSignatureClient::class);
        $identityService = $container->get(IdentityManagementService::class);
        $userAnrTable = $container->get(UserAnrTable::class);

        return new SignatureProcessService(
            $em,
            $trustedXClient,
            $identityService,
            $userAnrTable,
            $container->get(LocalPdfSignatureVerifier::class),
            $container->get(SignatureUrlBuilder::class),
            $container->get(SignatureFileStorage::class),
            filter_var(getenv('SIGN_LOCAL_NPI_CHECK') ?: 'false', FILTER_VALIDATE_BOOLEAN)
        );
    }
}
