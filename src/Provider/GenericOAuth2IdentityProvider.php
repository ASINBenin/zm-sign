<?php declare(strict_types=1);

namespace Monarc\Extension\Provider;

class GenericOAuth2IdentityProvider implements IdentityProviderInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'provider_code'    => 'oauth2',
            'authorize_url'    => '',
            'token_url'        => '',
            'userinfo_url'     => '',
            'client_id'        => '',
            'client_secret'    => '',
            'scope'            => 'openid profile email',
            'identifier_claim' => 'sub',
            'acr_values'       => '',
        ], $config);
    }

    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $baseUrl = rtrim($this->config['authorize_url'], '/');
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'],
            'redirect_uri'  => $redirectUri,
            'scope'         => $this->config['scope'],
            'state'         => $state,
        ];

        if (!empty($this->config['acr_values'])) {
            $params['acr_values'] = $this->config['acr_values'];
        }

        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($params);
    }

    public function authenticateCode(string $code, string $redirectUri): ExternalIdentityDto
    {
        // 1. Échange du code contre le token
        $ch = curl_init($this->config['token_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'client_id'     => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tokenData = json_decode((string)$response, true);
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            throw new \RuntimeException("Échec OAuth2 : Jeton non obtenu (HTTP {$httpCode} - {$response})");
        }

        $userData = is_array($tokenData) ? $tokenData : [];

        // 2. Décodage ID Token si présent
        if (!empty($tokenData['id_token'])) {
            $parts = explode('.', $tokenData['id_token']);
            if (count($parts) >= 2) {
                $jwtPayload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (is_array($jwtPayload)) {
                    $userData = array_merge($userData, $jwtPayload);
                }
            }
        }

        // 3. UserInfo Endpoint
        if (!empty($this->config['userinfo_url'])) {
            $ch = curl_init($this->config['userinfo_url']);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $userinfoResponse = curl_exec($ch);
            $uHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($uHttpCode === 200 && !empty($userinfoResponse)) {
                $decoded = json_decode((string)$userinfoResponse, true);
                if (is_array($decoded)) {
                    $userData = array_merge($userData, $decoded);
                }
            }
        }

        // 4. Extraction du NPI / Claim
        $claim = $this->config['identifier_claim'];
        $identifier = $userData[$claim] ?? $userData['sub'] ?? $userData['npi'] ?? null;

        if (!$identifier) {
            throw new \RuntimeException("Claim '{$claim}' absente du profil OAuth2.");
        }

        return new ExternalIdentityDto(
            $this->config['provider_code'],
            (string)$identifier,
            $userData['email'] ?? null,
            $userData['given_name'] ?? $userData['firstname'] ?? null,
            $userData['family_name'] ?? $userData['lastname'] ?? null,
            $userData
        );
    }
}