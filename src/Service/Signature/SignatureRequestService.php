<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Doctrine\ORM\EntityManager;
use Monarc\Core\Exception\Exception;
use Monarc\Core\Entity\UserSuperClass;
use Monarc\Sign\Entity\Signature\Signatory;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Monarc\FrontOffice\Entity\Anr;
use Monarc\FrontOffice\Entity\Delivery;
use Monarc\FrontOffice\Entity\User;
use Monarc\FrontOffice\Service\DeliverableGenerationService;

class SignatureRequestService
{
    // Statut « Final » d'un livrable (0 : brouillon)
    private const DELIVERY_STATUS_FINAL = 1;

    private EntityManager $em;
    private DeliverableGenerationService $deliverableService;
    private SignatureFileStorage $fileStorage;

    public function __construct(
        EntityManager $em,
        DeliverableGenerationService $deliverableService,
        SignatureFileStorage $fileStorage
    ) {
        $this->em = $em;
        $this->deliverableService = $deliverableService;
        $this->fileStorage = $fileStorage;
    }

    /**
     * Récupère la liste formatée (DTO) des demandes pour l'API REST
     */
    public function getFormattedRequestsByAnr(Anr $anr): array
    {
        $result = [];

        foreach ($this->getRequestsByAnr($anr) as $req) {
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
            'anrId'    => $anr->getId(),
            'requests' => $result,
        ];
    }

    /**
     * Récupère les détails formatés (DTO) d'une demande pour l'API REST
     */
    public function getFormattedRequestById(Anr $anr, int $id): array
    {
        $request = $this->getRequestForAnr($anr, $id);

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
    public function createFromDeliverableData(Anr $anr, array $data, UserSuperClass $connectedUser): array
    {
        $docType = (int)$data['deliverableDocType'];
        // Seul le rapport final, au statut « Final », se signe
        if ($docType !== Delivery::DOC_TYPE_FINAL_REPORT || (int)($data['status'] ?? 0) !== self::DELIVERY_STATUS_FINAL) {
            throw new Exception('Seul le rapport final au statut « Final » peut être signé.', 412);
        }
        $title = trim((string)($data['docname'] ?? $data['title'] ?? ''));
        if ($title === '') {
            $version = trim((string)($data['version'] ?? ''));
            $title = 'Rapport final - ' . $anr->getLabel() . ($version === '' ? '' : ' - v' . $version);
        }
        $signatories = (array)($data['signatories'] ?? []);

        // Si aucun signataire n'est passé, ajouter l'utilisateur connecté en 1er signataire
        if (empty($signatories)) {
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

        $deliverableParams = $data;
        $deliverableParams['typedoc'] = $docType;
        $deliverableParams['format'] = 'pdf';
        $deliverableParams['docname'] = $title;
        $generated = $this->deliverableService->generateDeliverableWithValues($anr, $docType, $deliverableParams);

        $request = $this->createSignatureRequest(
            $anr,
            $docType,
            $title,
            (string)($generated['path'] ?? ''),
            $signatories,
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
    public function updateSignatories(Anr $anr, int $requestId, array $signatoriesData): SignatureRequest
    {
        $request = $this->getRequestForAnr($anr, $requestId);

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
    public function deleteSignatureRequest(Anr $anr, int $requestId): array
    {
        $request = $this->getRequestForAnr($anr, $requestId);

        if ($request->getStatus() === SignatureRequest::STATUS_SIGNED || $request->getStatus() === SignatureRequest::STATUS_PARTIALLY_SIGNED) {
            throw new Exception("Impossible de supprimer une demande de signature déjà signée ou en cours de signature.", 412);
        }

        $this->fileStorage->deleteFiles([
            $request->getFileOriginal(),
            $request->getFileCurrent(),
            $request->getFileSigned(),
        ]);

        $this->em->remove($request);
        $this->em->flush();

        return [
            'success' => true,
            'message' => 'Demande de signature supprimée avec succès.',
        ];
    }

    // Helpers internes réutilisés par les méthodes ci-dessus

    private function getRequestForAnr(Anr $anr, int $id): SignatureRequest
    {
        $request = $this->em->getRepository(SignatureRequest::class)->findOneBy(['id' => $id, 'anr' => $anr]);
        if ($request === null) {
            throw new Exception("Demande de signature introuvable.", 404);
        }

        return $request;
    }

    /**
     * @return SignatureRequest[]
     */
    private function getRequestsByAnr(Anr $anr): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('sr', 's', 'u')
           ->from(SignatureRequest::class, 'sr')
           ->leftJoin('sr.signatories', 's')
           ->leftJoin('s.user', 'u')
           ->where('sr.anr = :anr')
           ->setParameter('anr', $anr)
           ->orderBy('sr.createdAt', 'DESC')
           ->addOrderBy('s.orderIndex', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Crée une demande de signature à partir d'un fichier PDF généré
     */
    private function createSignatureRequest(
        Anr $anr,
        int $deliverableDocType,
        string $title,
        string $sourcePdfPath,
        array $signatoriesData,
        UserSuperClass $creator
    ): SignatureRequest {
        if ($sourcePdfPath === '' || !file_exists($sourcePdfPath)) {
            throw new Exception("Le fichier source du livrable PDF est introuvable pour initialiser la signature.", 412);
        }

        $files = $this->fileStorage->importSourcePdf($sourcePdfPath);

        $signatureRequest = new SignatureRequest();
        $signatureRequest->setAnr($anr);
        $signatureRequest->setDeliverableDocType($deliverableDocType);
        $signatureRequest->setTitle($title);
        $signatureRequest->setFileOriginal($files['original']);
        $signatureRequest->setFileCurrent($files['current']);
        $signatureRequest->setStatus(SignatureRequest::STATUS_PENDING_CONFIG);
        $signatureRequest->setCurrentStep(1);

        $signatureRequest->setCreator($creator->getEmail());

        $this->applySignatoriesData($signatureRequest, $signatoriesData);

        $this->em->persist($signatureRequest);
        $this->em->flush();

        return $signatureRequest;
    }

    /**
     * Formate une date pour l'affichage, au fuseau du Bénin (les dates sont stockées au fuseau PHP de l'application)
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
     * Construit et attache les signataires à une demande à partir des données brutes du formulaire
     * (création et reconfiguration). L'ordre transmis sert au tri ; il est renuméroté de 1 à n.
     */
    private function applySignatoriesData(SignatureRequest $signatureRequest, array $signatoriesData): void
    {
        $userRepo = $this->em->getRepository(User::class);
        $signatoriesData = array_values($signatoriesData);
        uksort($signatoriesData, static fn (int $a, int $b) =>
            [(int)($signatoriesData[$a]['order'] ?? $a + 1), $a] <=> [(int)($signatoriesData[$b]['order'] ?? $b + 1), $b]);

        $order = 1;
        $userIds = [];
        foreach ($signatoriesData as $sigData) {
            $userId = (int)($sigData['userId'] ?? ($sigData['user']['id'] ?? 0));
            if (isset($userIds[$userId])) {
                throw new Exception("Un même utilisateur ne peut être signataire qu'une seule fois.", 412);
            }
            $user = $userRepo->find($userId);
            if (!$user) {
                throw new Exception("Signataire introuvable.", 412);
            }
            $userIds[$userId] = true;

            $signatory = new Signatory();
            $signatory->setUser($user);
            $signatory->setOrderIndex($order);
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
