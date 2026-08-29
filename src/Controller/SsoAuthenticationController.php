<?php declare(strict_types=1);

namespace Monarc\Extension\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Monarc\Core\Service\AuthenticationService;
use Monarc\Extension\Provider\GenericOAuth2IdentityProvider;
use Monarc\Extension\Service\IdentityManagementService;

class SsoAuthenticationController extends AbstractActionController
{
    public function __construct(
        private GenericOAuth2IdentityProvider $identityProvider,
        private IdentityManagementService $identityService,
        private AuthenticationService $authenticationService
    ) {
    }

    private function getRedirectUri(): string
    {
        $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost:5001';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return "{$scheme}://{$httpHost}/auth/sso/callback";
    }

    public function redirectAction()
    {
        $redirectUri = $this->getRedirectUri();
        $state = bin2hex(random_bytes(16));
        $url = $this->identityProvider->getAuthorizationUrl($redirectUri, $state);

        return $this->redirect()->toUrl($url);
    }

    public function callbackAction()
    {
        $queryParams = $this->params()->fromQuery();

        if (!empty($queryParams['error'])) {
            $err = $queryParams['error'];
            $errDesc = $queryParams['error_description'] ?? '';
            return $this->redirect()->toUrl('/#/?error=' . urlencode("Erreur SSO: {$err} - {$errDesc}"));
        }

        $code = $queryParams['code'] ?? null;
        if (empty($code)) {
            return $this->redirect()->toUrl('/#/?error=sso_code_missing');
        }

        try {
            $redirectUri = $this->getRedirectUri();
            
            // 1. Échange du code contre le profil NPI
            $externalDto = $this->identityProvider->authenticateCode($code, $redirectUri);
            
            // 2. Recherche du compte MONARC
            $user = $this->identityService->findUserByIdentity($externalDto);
            
            // 3. Création de session native MONARC
            $authData = $this->authenticationService->createSessionForUser($user);
            $token = $authData['token'];
            $language = $user->getLanguage();

            // 4. Enregistrement chiffré des jetons PKI
            try {
                $this->identityService->storeSsoSession(
                    $user,
                    $externalDto->providerCode,
                    $token,
                    $externalDto->attributes
                );
            } catch (\Throwable $ex) {
                error_log("[SSO SESSION WARNING] " . $ex->getMessage());
            }

            // 5. Redirection vers l'application
            $targetUrl = sprintf(
                '/#/?token=%s&uid=%d&language=%s',
                urlencode($token),
                $user->getId(),
                urlencode((string)$language)
            );

            return $this->redirect()->toUrl($targetUrl);

        } catch (\Throwable $e) {
            error_log("[SSO ERROR] " . $e->getMessage());
            return $this->redirect()->toUrl('/#/?error=' . urlencode($e->getMessage()));
        }
    }
}