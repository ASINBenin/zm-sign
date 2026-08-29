<?php declare(strict_types=1);

namespace Monarc\Extension\Service;

use Doctrine\ORM\EntityManagerInterface;
use Monarc\Core\Entity\UserSuperClass;
use Monarc\Extension\Entity\Identity;
use Monarc\Extension\Entity\Provider;
use Monarc\Extension\Entity\SsoSession;
use Monarc\Extension\Provider\ExternalIdentityDto;
use Monarc\FrontOffice\Entity\User;
use RuntimeException;

class IdentityManagementService
{
    private EntityManagerInterface $entityManager;
    private array $config;

    public function __construct(EntityManagerInterface $entityManager, array $config = [])
    {
        $this->entityManager = $entityManager;
        $this->config = $config;
    }

    public function getActiveProviders(): array
    {
        $configuredProviders = $this->config['sso_providers'] ?? [];
        if (!empty($configuredProviders)) {
            $providerRepo = $this->entityManager->getRepository(Provider::class);
            $activeList = [];
            foreach ($configuredProviders as $code => $details) {
                if (isset($details['is_active']) && !$details['is_active']) {
                    continue;
                }
                $provider = $providerRepo->findOneBy(['code' => (string)$code]);
                if (!$provider) {
                    $provider = new Provider();
                    $provider->setCode((string)$code);
                }
                $provider->setName($details['name'] ?? (string)$code);
                $provider->setIsActive(true);
                $this->entityManager->persist($provider);
                $activeList[] = [
                    'id' => $provider->getId(),
                    'code' => $provider->getCode(),
                    'name' => $provider->getName(),
                ];
            }
            $this->entityManager->flush();
            return $activeList;
        }

        $providers = $this->entityManager->getRepository(Provider::class)->findBy(['isActive' => true]);
        $result = [];
        foreach ($providers as $p) {
            $result[] = [
                'id' => $p->getId(),
                'code' => $p->getCode(),
                'name' => $p->getName(),
            ];
        }
        return $result;
    }

    public function getUserIdentity(int $userId): array
    {
        /** @var Identity|null $identity */
        $identity = $this->entityManager->getRepository(Identity::class)->findOneBy(['user' => $userId]);
        if (!$identity) {
            return [
                'isLinked' => false,
                'providerCode' => null,
                'providerName' => null,
                'providerIdentifier' => null,
                'sub' => null,
            ];
        }

        return [
            'isLinked' => true,
            'providerCode' => $identity->getProvider()->getCode(),
            'providerName' => $identity->getProvider()->getName(),
            'providerIdentifier' => $identity->getProviderIdentifier(),
            'sub' => $identity->getSub(),
        ];
    }

    public function linkUserIdentityById(int $userId, string $providerCode, string $providerIdentifier): Identity
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            throw new RuntimeException("Utilisateur introuvable.");
        }

        $provider = $this->entityManager->getRepository(Provider::class)->findOneBy(['code' => $providerCode]);
        if (!$provider) {
            throw new RuntimeException("Fournisseur SSO introuvable.");
        }

        $existing = $this->entityManager->getRepository(Identity::class)->findOneBy([
            'provider' => $provider,
            'providerIdentifier' => $providerIdentifier,
        ]);

        if ($existing && $existing->getUser()->getId() !== $userId) {
            throw new RuntimeException("Cet identifiant est déjà associé à un autre compte.");
        }

        $identity = $this->entityManager->getRepository(Identity::class)->findOneBy(['user' => $userId]) ?: new Identity();
        $identity->setUser($user);
        $identity->setProvider($provider);
        $identity->setProviderIdentifier($providerIdentifier);

        $this->entityManager->persist($identity);
        $this->entityManager->flush();

        return $identity;
    }

    public function unlinkUserIdentityById(int $userId): void
    {
        $identity = $this->entityManager->getRepository(Identity::class)->findOneBy(['user' => $userId]);
        if ($identity) {
            $this->entityManager->remove($identity);
            $this->entityManager->flush();
        }
    }

    public function findUserByIdentity(ExternalIdentityDto $dto): UserSuperClass
    {
        $provider = $this->entityManager->getRepository(Provider::class)->findOneBy(['code' => $dto->providerCode]);
        if (!$provider || !$provider->getIsActive()) {
            throw new RuntimeException("Fournisseur SSO {$dto->providerCode} inactif.");
        }

        $sub = $dto->attributes['sub'] ?? null;
        $identity = null;

        if (!empty($sub)) {
            $identity = $this->entityManager->getRepository(Identity::class)->findOneBy([
                'provider' => $provider,
                'sub' => (string)$sub,
            ]);
        }

        if (!$identity) {
            $identity = $this->entityManager->getRepository(Identity::class)->findOneBy([
                'provider' => $provider,
                'providerIdentifier' => $dto->providerIdentifier,
            ]);
        }

        if (!$identity) {
            throw new RuntimeException("Aucun compte MONARC n'est associé à cette identité SSO ({$dto->providerIdentifier}).");
        }

        if (!empty($sub) && $identity->getSub() !== (string)$sub) {
            $identity->setSub((string)$sub);
            $this->entityManager->persist($identity);
            $this->entityManager->flush();
        }

        return $identity->getUser();
    }

    public function storeSsoSession(
        UserSuperClass $user,
        string $providerCode,
        string $monarcSessionToken,
        array $tokenData
    ): SsoSession {
        $provider = $this->entityManager->getRepository(Provider::class)->findOneBy(['code' => $providerCode]);
        $secretKey = $this->getEncryptionKey();

        $session = new SsoSession();
        $session->setUser($user);
        $session->setProvider($provider);
        $session->setSessionToken($monarcSessionToken);

        $rawAccessToken = $tokenData['access_token'] ?? '';
        if (!empty($rawAccessToken)) {
            $session->setAccessTokenEncrypted($this->encryptToken((string)$rawAccessToken, $secretKey));
        }

        if (!empty($tokenData['refresh_token'])) {
            $session->setRefreshTokenEncrypted($this->encryptToken((string)$tokenData['refresh_token'], $secretKey));
        }

        if (!empty($tokenData['id_token'])) {
            $session->setIdTokenEncrypted($this->encryptToken((string)$tokenData['id_token'], $secretKey));
        }

        $session->setTokenType($tokenData['token_type'] ?? 'Bearer');
        $session->setScope($tokenData['scope'] ?? null);

        if (!empty($tokenData['expires_in'])) {
            $session->setExpiresAt((new \DateTime())->modify('+' . (int)$tokenData['expires_in'] . ' seconds'));
        }

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    public function getValidAccessToken(UserSuperClass $user, string $providerCode = 'trustedx_pki'): ?string
    {
        $provider = $this->entityManager->getRepository(Provider::class)->findOneBy(['code' => $providerCode]);
        if (!$provider) {
            return null;
        }

        /** @var SsoSession|null $session */
        $session = $this->entityManager->getRepository(SsoSession::class)->findOneBy(
            ['user' => $user, 'provider' => $provider],
            ['createdAt' => 'DESC']
        );

        if (!$session || empty($session->getAccessTokenEncrypted())) {
            return null;
        }

        return $this->decryptToken($session->getAccessTokenEncrypted(), $this->getEncryptionKey());
    }

    private function getEncryptionKey(): string
    {
        return 'MONARC_SSO_SECURE_ENCRYPTION_KEY_2026';
    }

    private function encryptToken(string $plainText, string $secretKey): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            hash('sha256', $secretKey, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $cipherText);
    }

    private function decryptToken(string $encryptedData, string $secretKey): ?string
    {
        $rawData = base64_decode($encryptedData, true);
        if (!$rawData || strlen($rawData) < 28) {
            return null;
        }

        $iv = substr($rawData, 0, 12);
        $tag = substr($rawData, 12, 16);
        $cipherText = substr($rawData, 28);

        $plain = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            hash('sha256', $secretKey, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plain !== false ? $plain : null;
    }
}