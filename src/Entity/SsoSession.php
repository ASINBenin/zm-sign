<?php declare(strict_types=1);

namespace Monarc\Extension\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Monarc\Core\Entity\AbstractEntity;
use Monarc\FrontOffice\Entity\User;

/**
 * @ORM\Table(name="sso_sessions", indexes={
 *      @ORM\Index(name="idx_sso_user_session", columns={"user_id", "session_token"})
 * })
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks()
 */
class SsoSession extends AbstractEntity
{
    /**
     * @var int
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    protected $id;

    /**
     * @var User
     * @ORM\ManyToOne(targetEntity="Monarc\FrontOffice\Entity\User")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    protected $user;

    /**
     * @var Provider
     * @ORM\ManyToOne(targetEntity="Monarc\Extension\Entity\Provider")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="provider_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    protected $provider;

    /**
     * @var string
     * @ORM\Column(name="session_token", type="string", length=100, nullable=false)
     */
    protected $sessionToken;

    /**
     * @var string
     * @ORM\Column(name="access_token_encrypted", type="text", nullable=false)
     */
    protected $accessTokenEncrypted;

    /**
     * @var string|null
     * @ORM\Column(name="refresh_token_encrypted", type="text", nullable=true)
     */
    protected $refreshTokenEncrypted;

    /**
     * @var string|null
     * @ORM\Column(name="id_token_encrypted", type="text", nullable=true)
     */
    protected $idTokenEncrypted;

    /**
     * @var string|null
     * @ORM\Column(name="token_type", type="string", length=50, nullable=true, options={"default":"Bearer"})
     */
    protected $tokenType = 'Bearer';

    /**
     * @var string|null
     * @ORM\Column(name="scope", type="text", nullable=true)
     */
    protected $scope;

    /**
     * @var DateTime|null
     * @ORM\Column(name="expires_at", type="datetime", nullable=true)
     */
    protected $expiresAt;

    /**
     * @var DateTime
     * @ORM\Column(name="created_at", type="datetime", nullable=true)
     */
    protected $createdAt;

    /**
     * @var DateTime|null
     * @ORM\Column(name="updated_at", type="datetime", nullable=true)
     */
    protected $updatedAt;

    /**
     * @ORM\PrePersist
     */
    public function setCreatedAtValue(): self
    {
        $this->createdAt = new DateTime();
        return $this;
    }

    /**
     * @ORM\PreUpdate
     */
    public function setUpdatedAtValue(): self
    {
        $this->updatedAt = new DateTime();
        return $this;
    }

    public function getId(): ?int { return $this->id ? (int)$this->id : null; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }
    public function getProvider(): ?Provider { return $this->provider; }
    public function setProvider(Provider $provider): self { $this->provider = $provider; return $this; }
    public function getSessionToken(): string { return (string)$this->sessionToken; }
    public function setSessionToken(string $sessionToken): self { $this->sessionToken = $sessionToken; return $this; }
    public function getAccessTokenEncrypted(): string { return (string)$this->accessTokenEncrypted; }
    public function setAccessTokenEncrypted(string $token): self { $this->accessTokenEncrypted = $token; return $this; }
    public function getRefreshTokenEncrypted(): ?string { return $this->refreshTokenEncrypted; }
    public function setRefreshTokenEncrypted(?string $token): self { $this->refreshTokenEncrypted = $token; return $this; }
    public function getIdTokenEncrypted(): ?string { return $this->idTokenEncrypted; }
    public function setIdTokenEncrypted(?string $token): self { $this->idTokenEncrypted = $token; return $this; }
    public function getTokenType(): ?string { return $this->tokenType; }
    public function setTokenType(?string $tokenType): self { $this->tokenType = $tokenType; return $this; }
    public function getScope(): ?string { return $this->scope; }
    public function setScope(?string $scope): self { $this->scope = $scope; return $this; }
    public function getExpiresAt(): ?DateTime { return $this->expiresAt; }
    public function setExpiresAt(?DateTime $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getCreatedAt(): ?DateTime { return $this->createdAt; }
    public function setCreatedAt(?DateTime $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?DateTime { return $this->updatedAt; }
    public function setUpdatedAt(?DateTime $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}