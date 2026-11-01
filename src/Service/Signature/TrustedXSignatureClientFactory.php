<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TrustedXSignatureClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): TrustedXSignatureClient
    {
        $config = $container->get('Config');
        $ssoConfig = $config['sso_providers']['trustedx_pki'] ?? [];

        // Peut rester vide : seul l'appel à TrustedX l'exige, pas l'aperçu du PDF ni la signature locale
        $baseUrl = rtrim((string)(($ssoConfig['base_url'] ?? '') ?: getenv('TRUSTEDX_URL') ?: ''), '/');

        return new TrustedXSignatureClient([
            'trustedx_url'           => $baseUrl,
            'client_id'              => $ssoConfig['client_id'] ?? '',
            'esignsp_processes_url'  => $ssoConfig['esignsp_processes_url'] ?? ($baseUrl . '/trustedx-resources/esignsp/v2/signer_processes'),
            'esignsp_ui_url'         => $ssoConfig['esignsp_ui_url'] ?? ($baseUrl . '/trustedx-resources/esignsp/v2/ui'),
            'esignsp_documents_url'  => $ssoConfig['esignsp_documents_url'] ?? ($baseUrl . '/trustedx-resources/esignsp/v2/documents'),
        ]);
    }
}