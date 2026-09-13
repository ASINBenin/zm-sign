<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Monarc\FrontOffice\Service\DeliverableGenerationService;

class SignatureRequestServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SignatureRequestService
    {
        $em = $container->get('doctrine.entitymanager.orm_cli');
        $config = $container->get('config');
        $deliverableService = $container->has(DeliverableGenerationService::class) 
            ? $container->get(DeliverableGenerationService::class) 
            : null;

        return new SignatureRequestService($em, $config, $deliverableService);
    }
}
