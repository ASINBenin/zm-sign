<?php declare(strict_types=1);

namespace Monarc\Sign\Entity\Signature;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Monarc\Core\Entity\Traits\CreateEntityTrait;
use Monarc\Core\Entity\Traits\UpdateEntityTrait;
use Monarc\FrontOffice\Entity\Anr;

/**
 * @ORM\Entity
 * @ORM\Table(name="signature_requests", indexes={
 *     @ORM\Index(name="idx_sig_req_anr", columns={"anr_id"}),
 *     @ORM\Index(name="idx_sig_req_status", columns={"status"})
 * })
 * @ORM\HasLifecycleCallbacks()
 */
class SignatureRequest
{
    use CreateEntityTrait;
    use UpdateEntityTrait;

    public const STATUS_PENDING_CONFIG = 'pending_configuration';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIALLY_SIGNED = 'partially_signed';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private int $id;

    /**
     * @ORM\ManyToOne(targetEntity="Monarc\FrontOffice\Entity\Anr")
     * @ORM\JoinColumn(name="anr_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?Anr $anr = null;

    /**
     * @ORM\Column(name="deliverable_doc_type", type="smallint", options={"default": 1})
     */
    private int $deliverableDocType = 1;

    /**
     * @ORM\Column(type="string", length=255, nullable=false)
     */
    private string $title;

    /**
     * @ORM\Column(name="file_original", type="string", length=500, nullable=false)
     */
    private string $fileOriginal;

    /**
     * @ORM\Column(name="file_current", type="string", length=500, nullable=false)
     */
    private string $fileCurrent;

    /**
     * @ORM\Column(name="file_signed", type="string", length=500, nullable=true)
     */
    private ?string $fileSigned = null;

    /**
     * @ORM\Column(type="string", length=50, options={"default": "pending_configuration"})
     */
    private string $status = self::STATUS_PENDING_CONFIG;

    /**
     * @ORM\Column(name="current_step", type="integer", options={"default": 1})
     */
    private int $currentStep = 1;

    /**
     * @ORM\OneToMany(targetEntity="Monarc\Sign\Entity\Signature\Signatory", mappedBy="request", cascade={"persist", "remove"})
     * @ORM\OrderBy({"orderIndex" = "ASC"})
     */
    private Collection $signatories;

    public function __construct()
    {
        $this->signatories = new ArrayCollection();
    }

    public function getId(): int { return $this->id; }

    public function getAnr(): ?Anr { return $this->anr; }
    public function setAnr(?Anr $anr): self { $this->anr = $anr; return $this; }

    public function getDeliverableDocType(): int { return $this->deliverableDocType; }
    public function setDeliverableDocType(int $type): self { $this->deliverableDocType = $type; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getFileOriginal(): string { return $this->fileOriginal; }
    public function setFileOriginal(string $file): self { $this->fileOriginal = $file; return $this; }

    public function getFileCurrent(): string { return $this->fileCurrent; }
    public function setFileCurrent(string $file): self { $this->fileCurrent = $file; return $this; }

    /**
     * @ORM\Column(name="rejection_reason", type="text", nullable=true)
     */
    private ?string $rejectionReason = null;

    public function getFileSigned(): ?string { return $this->fileSigned; }
    public function setFileSigned(?string $file): self { $this->fileSigned = $file; return $this; }

    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function setRejectionReason(?string $reason): self { $this->rejectionReason = $reason; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function isOpenForSigning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PARTIALLY_SIGNED], true);
    }

    public function getCurrentStep(): int { return $this->currentStep; }
    public function setCurrentStep(int $step): self { $this->currentStep = $step; return $this; }

    public function getSignatories(): Collection { return $this->signatories; }
    public function addSignatory(Signatory $signatory): self
    {
        if (!$this->signatories->contains($signatory)) {
            $this->signatories->add($signatory);
            $signatory->setRequest($this);
        }
        return $this;
    }

    public function getSignatoryByOrder(int $order): ?Signatory
    {
        foreach ($this->signatories as $signatory) {
            if ($signatory->getOrderIndex() === $order) {
                return $signatory;
            }
        }
        return null;
    }
}