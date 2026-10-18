<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Monarc\FrontOffice\Service\DeliverableGenerationService;

class SignatureRequestServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SignatureRequestService
    {
        return new SignatureRequestService(
            $container->get('doctrine.entitymanager.orm_cli'),
            $container->get(DeliverableGenerationService::class),
            $container->get(SignatureFileStorage::class)
        );
    }
}
