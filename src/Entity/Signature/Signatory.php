<?php declare(strict_types=1);

namespace Monarc\Sign\Entity\Signature;

use Doctrine\ORM\Mapping as ORM;
use Monarc\Core\Entity\Traits\CreateEntityTrait;
use Monarc\Core\Entity\Traits\UpdateEntityTrait;
use Monarc\Core\Entity\UserSuperClass;

/**
 * @ORM\Entity
 * @ORM\Table(name="signature_signatories", indexes={
 *     @ORM\Index(name="idx_signatory_req", columns={"request_id"}),
 *     @ORM\Index(name="idx_signatory_user", columns={"user_id"})
 * })
 * @ORM\HasLifecycleCallbacks()
 */
class Signatory
{
    use CreateEntityTrait;
    use UpdateEntityTrait;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private int $id;

    /**
     * @ORM\ManyToOne(targetEntity="Monarc\Sign\Entity\Signature\SignatureRequest", inversedBy="signatories")
     * @ORM\JoinColumn(name="request_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private SignatureRequest $request;

    /**
     * @ORM\ManyToOne(targetEntity="Monarc\FrontOffice\Entity\User")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false, onDelete="RESTRICT")
     */
    private UserSuperClass $user;

    /**
     * @ORM\Column(name="order_index", type="integer", options={"default": 1})
     */
    private int $orderIndex = 1;

    /**
     * @ORM\Column(type="string", length=50, options={"default": "pending"})
     */
    private string $status = self::STATUS_PENDING;

    /**
     * @ORM\Column(name="page_number", type="integer", options={"default": 1})
     */
    private int $pageNumber = 1;

    /**
     * @ORM\Column(name="coord_x", type="float", options={"default": 0})
     */
    private float $coordX = 0;

    /**
     * @ORM\Column(name="coord_y", type="float", options={"default": 0})
     */
    private float $coordY = 0;

    /**
     * @ORM\Column(name="width", type="float", options={"default": 150})
     */
    private float $width = 150;

    /**
     * @ORM\Column(name="height", type="float", options={"default": 60})
     */
    private float $height = 60;

    /**
     * @ORM\Column(name="signed_at", type="datetime", nullable=true)
     */
    private ?\DateTime $signedAt = null;

    /**
     * @ORM\Column(name="signature_tx_id", type="string", length=255, nullable=true)
     */
    private ?string $signatureTxId = null;

    /**
     * @ORM\Column(name="certificate_subject", type="string", length=255, nullable=true)
     */
    private ?string $certificateSubject = null;

    /**
     * @ORM\Column(name="rejection_reason", type="text", nullable=true)
     */
    private ?string $rejectionReason = null;

    public function getId(): int { return $this->id; }

    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function setRejectionReason(?string $reason): self { $this->rejectionReason = $reason; return $this; }

    public function getRequest(): SignatureRequest { return $this->request; }
    public function setRequest(SignatureRequest $request): self { $this->request = $request; return $this; }

    public function getUser(): UserSuperClass { return $this->user; }
    public function setUser(UserSuperClass $user): self { $this->user = $user; return $this; }

    public function getOrderIndex(): int { return $this->orderIndex; }
    public function setOrderIndex(int $order): self { $this->orderIndex = $order; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getPageNumber(): int { return $this->pageNumber; }
    public function setPageNumber(int $page): self { $this->pageNumber = $page; return $this; }

    public function getCoordX(): float { return $this->coordX; }
    public function setCoordX(float $x): self { $this->coordX = $x; return $this; }

    public function getCoordY(): float { return $this->coordY; }
    public function setCoordY(float $y): self { $this->coordY = $y; return $this; }

    public function getWidth(): float { return $this->width; }
    public function setWidth(float $width): self { $this->width = $width; return $this; }

    public function getHeight(): float { return $this->height; }
    public function setHeight(float $height): self { $this->height = $height; return $this; }

    public function getSignedAt(): ?\DateTime { return $this->signedAt; }
    public function setSignedAt(?\DateTime $date): self { $this->signedAt = $date; return $this; }

    public function getSignatureTxId(): ?string { return $this->signatureTxId; }
    public function setSignatureTxId(?string $txId): self { $this->signatureTxId = $txId; return $this; }

    public function getCertificateSubject(): ?string { return $this->certificateSubject; }
    public function setCertificateSubject(?string $subject): self { $this->certificateSubject = $subject; return $this; }
}