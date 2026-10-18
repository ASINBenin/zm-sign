<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\InputValidator\Signature;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\Core\Validator\InputValidator\InputValidationTranslator;

class SignatureInputValidatorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): object
    {
        $em         = $container->get('doctrine.entitymanager.orm_cli');
        $translator = $container->get(InputValidationTranslator::class);
        $config     = $container->get('config');

        $withUser = [
            PostInitiateSignInputValidator::class,
            PostRejectSignatureInputValidator::class,
            PostUploadSignedDocumentInputValidator::class,
            GetReauthUrlInputValidator::class,
        ];

        if (in_array($requestedName, $withUser, true)) {
            $connectedUserService = $container->get(ConnectedUserService::class);
            return new $requestedName($config, $translator, $em, $connectedUserService);
        }

        return new $requestedName($config, $translator, $em);
    }
}
