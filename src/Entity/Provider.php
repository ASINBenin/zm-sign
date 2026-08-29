<?php declare(strict_types=1);

namespace Monarc\Extension\Entity;

use Doctrine\ORM\Mapping as ORM;
use Monarc\Core\Entity\AbstractEntity;
use Monarc\Core\Entity\Traits\CreateEntityTrait;
use Monarc\Core\Entity\Traits\UpdateEntityTrait;

/**
 * @ORM\Table(name="providers")
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks()
 */
class Provider extends AbstractEntity
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
     * @var string
     * @ORM\Column(name="code", type="string", length=50, unique=true, nullable=false)
     */
    protected $code;

    /**
     * @var string
     * @ORM\Column(name="name", type="string", length=100, nullable=false)
     */
    protected $name;

    /**
     * @var bool
     * @ORM\Column(name="is_active", type="boolean", nullable=false, options={"default":1})
     */
    protected $isActive = true;

    public function getId(): ?int { return $this->id ? (int)$this->id : null; }
    public function getCode(): string { return (string)$this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getName(): string { return (string)$this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getIsActive(): bool { return (bool)$this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
}