<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Doctrine\ORM\EntityManager;
use Monarc\Core\Exception\Exception;
use Monarc\Core\Entity\UserSuperClass;
use Monarc\Sign\Entity\Signature\Signatory;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Monarc\FrontOffice\Entity\Anr;
use Monarc\FrontOffice\Entity\User;
use Monarc\FrontOffice\Service\DeliverableGenerationService;

class SignatureRequestService
{
    private EntityManager $em;
    private ?DeliverableGenerationService $deliverableService;
    private string $storageDir;

    public function __construct(
        EntityManager $em,
        array $config,
        ?DeliverableGenerationService $deliverableService = null
    ) {
        $this->em = $em;
        $this->deliverableService = $deliverableService;
        $this->storageDir = './data/signatures';
    }

    /**
     * Récupère la liste formatée (DTO) des demandes pour l'API REST
     */
    public function getFormattedRequestsByAnrId(int $anrId): array
    {
        if ($anrId <= 0) {
            throw new Exception("Identifiant ANR obligatoire.", 400);
        }

        $requests = $this->getRequestsByAnrId($anrId);
        $result = [];

        foreach ($requests as $req) {
            $signatories = [];
            foreach ($req->getSignatories() as $sig) {
                $user = $sig->getUser();
                $signatories[] = [
                    'id'              => $sig->getId(),
                    'userId'          => $user ? $user->getId() : null,
                    'userName'        => $user ? ($user->getFirstname() . ' ' . $user->getLastname()) : ('Signataire #' . $sig->getOrderIndex()),
                    'userEmail'       => $user ? $user->getEmail() : null,
                    'status'          => $sig->getStatus(),
                    'order'           => $sig->getOrderIndex(),
                    'signedAt'        => $this->formatDateTime($sig->getSignedAt()),
                    'rejectionReason' => $sig->getRejectionReason(),
                ];
            }

            $result[] = [
                'id'              => $req->getId(),
                'title'           => $req->getTitle(),
                'status'          => $req->getStatus(),
                'rejectionReason' => $req->getRejectionReason(),
                'currentStep'     => $req->getCurrentStep(),
                'totalSteps'      => count($signatories),
                'createdAt'       => $this->formatDateTime($req->getCreatedAt()),
                'signatories'     => $signatories,
            ];
        }

        return [
            'anrId'    => $anrId,
            'requests' => $result,
        ];
    }

    /**
     * Récupère les détails formatés (DTO) d'une demande pour l'API REST
     */
    public function getFormattedRequestById(int $id): array
    {
        $request = $this->getRequestById($id);
        if (!$request) {
            throw new Exception("Demande de signature introuvable.", 404);
        }

        $signatories = [];
        foreach ($request->getSignatories() as $sig) {
            $user = $sig->getUser();
            $signatories[] = [
                'id' => $sig->getId(),
                'user' => $user ? [
                    'id'        => $user->getId(),
                    'firstname' => $user->getFirstname(),
                    'lastname'  => $user->getLastname(),
                    'email'     => $user->getEmail(),
                ] : null,
                'order'           => $sig->getOrderIndex(),
                'page'            => $sig->getPageNumber(),
                'x'               => $sig->getCoordX(),
                'y'               => $sig->getCoordY(),
                'width'           => $sig->getWidth(),
                'height'          => $sig->getHeight(),
                'status'          => $sig->getStatus(),
                'rejectionReason' => $sig->getRejectionReason(),
            ];
        }

        return [
            'id'              => $request->getId(),
            'title'           => $request->getTitle(),
            'status'          => $request->getStatus(),
            'rejectionReason' => $request->getRejectionReason(),
            'currentStep'     => $request->getCurrentStep(),
            'signatories'     => $signatories,
        ];
    }

    /**
     * Crée une demande à partir des données de livrable ou de formulaire
     */
    public function createFromDeliverableData(int $anrId, array $data, ?UserSuperClass $connectedUser = null): array
    {
        if ($anrId <= 0) {
            throw new Exception("Identifiant ANR obligatoire.", 400);
        }

        $docType = (int)($data['deliverableDocType'] ?? 0);
        if ($docType <= 0) {
            throw new Exception("Type de livrable obligatoire pour créer une demande de signature.", 400);
        }
        $title = !empty($data['title']) ? trim((string)$data['title']) : "Livrable type {$docType}";
        $tempPath = (string)($data['tempPdfPath'] ?? '');
        $signatories = (array)($data['signatories'] ?? []);

        // Si aucun signataire n'est passé, ajouter l'utilisateur connecté en 1er signataire
        if (empty($signatories) && $connectedUser) {
            $signatories[] = [
                'userId' => $connectedUser->getId(),
                'order'  => 1,
                'page'   => 1,
                'x'      => 100,
                'y'      => 150,
                'width'  => 180,
                'height' => 60,
            ];
        }

        // Génération automatique du PDF si livrable
        if ($this->deliverableService && $anrId > 0 && empty($tempPath)) {
            try {
                $anr = $this->getAnrById($anrId);
                if ($anr) {
                    $deliverableParams = $data;
                    $deliverableParams['typedoc'] = $docType;
                    $deliverableParams['format'] = 'pdf';
                    $generated = $this->deliverableService->generateDeliverableWithValues($anr, $docType, $deliverableParams);
                    if (!empty($generated['path']) && file_exists($generated['path'])) {
                        $tempPath = $generated['path'];
                    }
                }
            } catch (\Throwable $e) {
                // Ignore et laisse createSignatureRequest valider la présence du fichier
            }
        }

        $request = $this->createSignatureRequest(
            null,
            $docType,
            $title,
            $tempPath,
            $signatories,
            $anrId,
            $connectedUser
        );

        return [
            'success'   => true,
            'requestId' => $request->getId(),
            'status'    => $request->getStatus(),
        ];
    }

    /**
     * Met à jour la liste et le positionnement des signataires pour une demande
     */
    public function updateSignatories(int $requestId, array $signatoriesData): SignatureRequest
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new Exception("Demande de signature introuvable.", 404);
        }

        $hasAlreadyStarted = ($request->getCurrentStep() > 1)
            || in_array($request->getStatus(), [SignatureRequest::STATUS_PARTIALLY_SIGNED, SignatureRequest::STATUS_SIGNED], true);

        if (!$hasAlreadyStarted) {
            foreach ($request->getSignatories() as $sig) {
                if ($sig->getStatus() === Signatory::STATUS_SIGNED) {
                    $hasAlreadyStarted = true;
                    break;
                }
            }
        }

        if ($hasAlreadyStarted) {
            throw new Exception("Impossible de modifier la configuration d'un document dont le processus de signature a déjà démarré.", 403);
        }

        // Nettoyage des signataires existants
        foreach ($request->getSignatories() as $sig) {
            $this->em->remove($sig);
        }
        $request->getSignatories()->clear();
        $this->em->flush();

        $this->applySignatoriesData($request, $signatoriesData);

        if ($request->getSignatories()->count() > 0) {
            $request->setStatus(SignatureRequest::STATUS_PENDING);
        } else {
            $request->setStatus(SignatureRequest::STATUS_PENDING_CONFIG);
        }

        $this->em->flush();

        return $request;
    }

    /**
     * Supprime une demande de signature si elle n'a pas encore été signée
     */
    public function deleteSignatureRequest(int $requestId, ?UserSuperClass $user = null): array
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new Exception("Demande de signature introuvable.", 404);
        }

        if ($request->getStatus() === SignatureRequest::STATUS_SIGNED || $request->getStatus() === SignatureRequest::STATUS_PARTIALLY_SIGNED) {
            throw new Exception("Impossible de supprimer une demande de signature déjà signée ou en cours de signature.", 400);
        }

        $filesToDelete = array_filter([
            $request->getFileOriginal(),
            $request->getFileCurrent(),
            $request->getFileSigned(),
        ]);

        foreach ($filesToDelete as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $this->em->remove($request);
        $this->em->flush();

        return [
            'success' => true,
            'message' => 'Demande de signature supprimée avec succès.',
        ];
    }

    // Helpers internes réutilisés par les méthodes ci-dessus

    private function getRequestById(int $id): ?SignatureRequest
    {
        return $this->em->getRepository(SignatureRequest::class)->find($id);
    }

    private function getAnrById(int $anrId): ?Anr
    {
        return $this->em->getRepository(Anr::class)->find($anrId);
    }

    /**
     * Récupère toutes les demandes de signature associées à une ANR
     *
     * @return SignatureRequest[]
     */
    private function getRequestsByAnrId(int $anrId): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('sr', 's', 'u')
           ->from(SignatureRequest::class, 'sr')
           ->leftJoin('sr.signatories', 's')
           ->leftJoin('s.user', 'u')
           ->where('sr.anr = :anrId')
           ->setParameter('anrId', $anrId)
           ->orderBy('sr.createdAt', 'DESC')
           ->addOrderBy('s.orderIndex', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Crée une demande de signature à partir d'un fichier PDF généré
     */
    private function createSignatureRequest(
        ?Anr $anr,
        int $deliverableDocType,
        string $title,
        string $sourcePdfPath,
        array $signatoriesData,
        int $anrId = 0,
        ?UserSuperClass $creator = null
    ): SignatureRequest {
        $this->ensureStorageDirectories();

        if (!$anr && $anrId > 0) {
            $anr = $this->getAnrById($anrId);
        }

        if (!$anr) {
            throw new Exception("Analyse de risques introuvable pour cette demande de signature.", 404);
        }

        if (empty($sourcePdfPath) || !file_exists($sourcePdfPath)) {
            throw new Exception("Le fichier source du livrable PDF est introuvable pour initialiser la signature.", 400);
        }

        $requestIdUniq = uniqid('sig_');
        $filenameOriginal = $requestIdUniq . '_original.pdf';
        $filenameCurrent  = $requestIdUniq . '_current.pdf';

        $destOriginal = $this->storageDir . '/original/' . $filenameOriginal;
        $destCurrent  = $this->storageDir . '/current/' . $filenameCurrent;

        copy($sourcePdfPath, $destOriginal);
        copy($sourcePdfPath, $destCurrent);

        $signatureRequest = new SignatureRequest();
        $signatureRequest->setAnr($anr);
        $signatureRequest->setDeliverableDocType($deliverableDocType);
        $signatureRequest->setTitle($title);
        $signatureRequest->setFileOriginal($destOriginal);
        $signatureRequest->setFileCurrent($destCurrent);
        $signatureRequest->setStatus(SignatureRequest::STATUS_PENDING_CONFIG);
        $signatureRequest->setCurrentStep(1);

        if ($creator && method_exists($creator, 'getEmail')) {
            $signatureRequest->setCreator($creator->getEmail());
        }

        $this->applySignatoriesData($signatureRequest, $signatoriesData);

        $this->em->persist($signatureRequest);
        $this->em->flush();

        return $signatureRequest;
    }

    /**
     * Formate une date avec le fuseau horaire de l'application
     */
    private function formatDateTime(?\DateTime $dateTime): ?string
    {
        if (!$dateTime) {
            return null;
        }

        $dt = clone $dateTime;
        $dt->setTimezone(new \DateTimeZone('Africa/Porto-Novo'));

        return $dt->format('d/m/Y H:i');
    }

    /**
     * Initialise les dossiers de stockage sur le disque
     */
    private function ensureStorageDirectories(): void
    {
        foreach (['', '/original', '/current'] as $sub) {
            $dir = $this->storageDir . $sub;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    /**
     * Construit et attache les signataires à une demande à partir des données brutes du formulaire.
     * Factorisé entre createSignatureRequest() (création) et updateSignatories() (reconfiguration) :
     * les deux traitent exactement la même forme de données.
     */
    private function applySignatoriesData(SignatureRequest $signatureRequest, array $signatoriesData): void
    {
        $userRepo = $this->em->getRepository(User::class);
        $order = 1;

        foreach ($signatoriesData as $sigData) {
            $userId = (int)($sigData['userId'] ?? ($sigData['user']['id'] ?? 0));
            $user = $userRepo->find($userId);
            if (!$user) {
                continue;
            }

            $signatory = new Signatory();
            $signatory->setUser($user);
            $signatory->setOrderIndex((int)($sigData['order'] ?? $order));
            $signatory->setStatus(Signatory::STATUS_PENDING);
            $signatory->setPageNumber((int)($sigData['page'] ?? 1));
            $signatory->setCoordX((float)($sigData['x'] ?? 100));
            $signatory->setCoordY((float)($sigData['y'] ?? 700));
            $signatory->setWidth((float)($sigData['width'] ?? 180));
            $signatory->setHeight((float)($sigData['height'] ?? 60));

            $signatureRequest->addSignatory($signatory);
            $order++;
        }
    }
}
