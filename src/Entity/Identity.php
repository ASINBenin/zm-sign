<?php declare(strict_types=1);

namespace Monarc\Extension\Entity;

use Doctrine\ORM\Mapping as ORM;
use Monarc\Core\Entity\AbstractEntity;
use Monarc\Core\Entity\Traits\CreateEntityTrait;
use Monarc\Core\Entity\Traits\UpdateEntityTrait;
use Monarc\FrontOffice\Entity\User;

/**
 * @ORM\Table(name="identities", indexes={
 *      @ORM\Index(name="idx_identity_user", columns={"user_id"}),
 *      @ORM\Index(name="idx_identity_provider", columns={"provider_id"})
 * })
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks()
 */
class Identity extends AbstractEntity
{
    use CreateEntityTrait;
    use UpdateEntityTrait;

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
     *   @ORM\JoinColumn(name="provider_id", referencedColumnName="id", nullable=false, onDelete="RESTRICT")
     * })
     */
    protected $provider;

    /**
     * @var string
     * @ORM\Column(name="provider_identifier", type="string", length=100, nullable=false)
     */
    protected $providerIdentifier;

    /**
     * @var string|null
     * @ORM\Column(name="sub", type="string", length=255, nullable=true)
     */
    protected $sub;

    /**
     * @var string|null
     * @ORM\Column(name="extra_data", type="text", nullable=true)
     */
    protected $extraData;

    public function getId(): ?int { return $this->id ? (int)$this->id : null; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }
    public function getProvider(): ?Provider { return $this->provider; }
    public function setProvider(Provider $provider): self { $this->provider = $provider; return $this; }
    public function getProviderIdentifier(): string { return (string)$this->providerIdentifier; }
    public function setProviderIdentifier(string $providerIdentifier): self { $this->providerIdentifier = $providerIdentifier; return $this; }
    public function getSub(): ?string { return $this->sub; }
    public function setSub(?string $sub): self { $this->sub = $sub; return $this; }
    public function getExtraData(): ?string { return $this->extraData; }
    public function setExtraData(?string $extraData): self { $this->extraData = $extraData; return $this; }
}