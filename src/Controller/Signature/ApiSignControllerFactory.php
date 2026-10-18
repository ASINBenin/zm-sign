<?php declare(strict_types=1);

namespace Monarc\Sign\Controller\Signature;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\Sign\Service\Signature\SignatureProcessService;
use Monarc\Sign\Service\Signature\SignatureUrlBuilder;
use Monarc\Sign\Validator\InputValidator\Signature\PostInitiateSignInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostRejectSignatureInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostSignatureCallbackInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostUploadSignedDocumentInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\GetReauthUrlInputValidator;

class ApiSignControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ApiSignController
    {
        return new ApiSignController(
            $container->get(SignatureProcessService::class),
            $container->get(ConnectedUserService::class),
            $container->get(PostInitiateSignInputValidator::class),
            $container->get(PostRejectSignatureInputValidator::class),
            $container->get(PostSignatureCallbackInputValidator::class),
            $container->get(PostUploadSignedDocumentInputValidator::class),
            $container->get(GetReauthUrlInputValidator::class),
            $container->get(SignatureUrlBuilder::class),
        );
    }
}
