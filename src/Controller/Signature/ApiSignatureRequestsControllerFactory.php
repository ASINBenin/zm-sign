<?php declare(strict_types=1);

namespace Monarc\Sign\Controller\Signature;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\Sign\Service\Signature\SignatureRequestService;
use Monarc\Sign\Validator\InputValidator\Signature\PostSignatureRequestInputValidator;

class ApiSignatureRequestsControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ApiSignatureRequestsController
    {
        return new ApiSignatureRequestsController(
            $container->get(SignatureRequestService::class),
            $container->get(PostSignatureRequestInputValidator::class),
            $container->get(ConnectedUserService::class)
        );
    }
}
