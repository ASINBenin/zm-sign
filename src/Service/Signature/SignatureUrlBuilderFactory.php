<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SignatureUrlBuilderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SignatureUrlBuilder
    {
        return new SignatureUrlBuilder(getenv('APP_URL') ?: null);
    }
}
