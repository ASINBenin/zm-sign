<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Doctrine\ORM\EntityManager;
use Monarc\Core\Exception\Exception;
use Monarc\Core\Entity\UserSuperClass;
use Monarc\Sign\Entity\Signature\Signatory;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Monarc\Core\Service\Sso\IdentityManagementService;
use Monarc\FrontOffice\Table\UserAnrTable;

class SignatureProcessService
{
    private EntityManager $em;
    private TrustedXSignatureClient $trustedXClient;
    private IdentityManagementService $identityService;
    private UserAnrTable $userAnrTable;
    private LocalPdfSignatureVerifier $localSignatureVerifier;
    private SignatureUrlBuilder $urlBuilder;
    private SignatureFileStorage $fileStorage;
    private bool $checkLocalSignerNpi;

    public function __construct(
        EntityManager $em,
        TrustedXSignatureClient $trustedXClient,
        IdentityManagementService $identityService,
        UserAnrTable $userAnrTable,
        LocalPdfSignatureVerifier $localSignatureVerifier,
        SignatureUrlBuilder $urlBuilder,
        SignatureFileStorage $fileStorage,
        bool $checkLocalSignerNpi
    ) {
        $this->em = $em;
        $this->trustedXClient = $trustedXClient;
        $this->identityService = $identityService;
        $this->userAnrTable = $userAnrTable;
        $this->localSignatureVerifier = $localSignatureVerifier;
        $this->urlBuilder = $urlBuilder;
        $this->fileStorage = $fileStorage;
        $this->checkLocalSignerNpi = $checkLocalSignerNpi;
    }

    public function getRequestById(int $id): ?SignatureRequest
    {
        return $this->em->getRepository(SignatureRequest::class)->find($id);
    }

    /**
     * Déclenche l'étape de signature pour le signataire dont c'est le tour (Signature en ligne / SafeLayer)
     */
    public function initiateSignStep(int $requestId, UserSuperClass $connectedUser, ?string $signatureImageBase64 = null): array
    {
        $request   = $this->getRequestById($requestId);
        $currentStep = $request->getCurrentStep();
        $signatory = $request->getSignatoryByOrder($currentStep);

        // 1. Récupération du jeton d'accès SSO
        $accessToken = $this->identityService->getValidAccessTokenForUser($connectedUser);
        if (empty($accessToken)) {
            return [
                'requireAuth' => true,
                'authUrl'     => $this->urlBuilder->getSsoLoginUrl('trustedx_pki'),
            ];
        }

        // 2. Récupération du document PDF courant
        $pdfPath = $request->getFileCurrent();
        if (!file_exists($pdfPath)) {
            $pdfPath = $request->getFileOriginal();
        }

        if (!file_exists($pdfPath)) {
            throw new Exception("Le fichier PDF à signer est introuvable sur le serveur.", 500);
        }

        // 3. URL de retour TrustedX
        $finishCallbackUrl = $this->urlBuilder->getApiUrl($requestId, 'callback');

        // 4. Extraction des coordonnées et nom du signataire (déjà validés par PostInitiateSignInputValidator)
        $pageNumber = $signatory->getPageNumber();
        $coordX     = $signatory->getCoordX();
        $coordY     = $signatory->getCoordY();
        $width      = $signatory->getWidth();
        $height     = $signatory->getHeight();

        $signerName = trim(($connectedUser->getFirstname() ?? '') . ' ' . ($connectedUser->getLastname() ?? ''));
        if (empty($signerName) && method_exists($connectedUser, 'getEmail')) {
            $signerName = (string)$connectedUser->getEmail();
        }

        // 5. Création du processus de signature auprès de TrustedX
        $trustedXResponse = $this->trustedXClient->createSignerProcess(
            $accessToken,
            $pdfPath,
            $finishCallbackUrl,
            (int)$pageNumber,
            (float)$coordX,
            (float)$coordY,
            (float)$width,
            (float)$height,
            $signerName,
            $signatureImageBase64
        );

        $processId  = $trustedXResponse['id'] ?? null;
        if (!$processId) {
            throw new Exception("Identifiant de processus manquant dans la réponse de TrustedX.", 500);
        }

        // Sauvegarder le documentId dès maintenant pour éviter de re-requêter TrustedX au callback
        $documentId = $trustedXResponse['documents'][0]['id'] ?? null;
        $uiUrl = $this->trustedXClient->getProcessUiUrl($processId);

        // Stockage combiné : processId::documentId (documentId peut être null si TrustedX ne le fournit pas encore)
        $txId = $documentId ? "{$processId}::{$documentId}" : $processId;
        $signatory->setSignatureTxId($txId);
        $this->em->flush();

        return [
            'processId'   => $processId,
            'redirectUrl' => $uiUrl,
            'requireAuth' => false,
        ];
    }

    /**
     * Vérifie la validité du jeton SSO pour l'utilisateur connecté
     */
    public function checkTokenValidity(int $requestId, UserSuperClass $connectedUser): array
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new Exception("Demande de signature introuvable.", 404);
        }

        $signatory = $request->getSignatoryByOrder($request->getCurrentStep());
        if (!$signatory || $signatory->getUser()->getId() !== $connectedUser->getId()) {
            return ['valid' => false, 'requireAuth' => false, 'message' => "Ce n'est pas votre tour de signer."];
        }

        try {
            $token = $this->identityService->getValidAccessTokenForUser($connectedUser);
        } catch (\Throwable) {
            $token = null;
        }

        if (!empty($token)) {
            return ['valid' => true, 'requireAuth' => false];
        }

        return [
            'valid'       => false,
            'requireAuth' => true,
            'authUrl'     => $this->buildRefreshTokenAuthUrl($request),
        ];
    }

    /**
     * Finalisation avec le token TX déjà stocké, à la demande du signataire connecté (étape validée par
     * GetReauthUrlInputValidator). Sans token valide ou sans document, rien n'est modifié : finalized=false,
     * le front enchaîne sur la ré-autorisation OAuth2 (handleSignCallback).
     *
     * @return array{finalized: bool, isCompleted?: bool, nextStep?: int}
     */
    public function finalizeWithStoredToken(int $requestId, UserSuperClass $connectedUser): array
    {
        $request   = $this->getRequestById($requestId);
        $signatory = $request->getSignatoryByOrder($request->getCurrentStep());

        $accessToken = $this->identityService->getValidAccessTokenForUser($connectedUser);
        if (empty($accessToken)) {
            return ['finalized' => false];
        }

        [$processId, $documentId] = $this->parseTxId($signatory);
        $signedPdfContent = $this->trustedXClient->downloadSignedDocument($accessToken, (string)($documentId ?: $processId));
        if (empty($signedPdfContent)) {
            // Échec de téléchargement : on laisse la ré-autorisation prendre le relais.
            return ['finalized' => false];
        }

        $result = $this->recordSignedStep($request, $signatory, $signedPdfContent, 'sig');
        $this->deleteRemoteProcess($accessToken, $processId);

        return ['finalized' => true] + $result;
    }

    /**
     * Retour OAuth2 après ré-autorisation (getReauthUrl) : le state doit correspondre à l'étape en cours.
     * $callbackRedirectUri doit être identique au redirect_uri envoyé à TrustedX (échange du code).
     */
    public function handleSignCallback(
        int $requestId,
        ?string $state,
        string $oauthCode,
        string $callbackRedirectUri,
        ?string $signerProcessId = null
    ): array {
        // Existence de la demande et du signataire déjà validée par PostSignatureCallbackInputValidator
        $request   = $this->getRequestById($requestId);
        $signatory = $this->getSignatoryMatchingState($request, $state);

        [$processIdStored, $documentIdStored] = $this->parseTxId($signatory);
        $processIdToFetch = $signerProcessId ?: $processIdStored;

        $accessToken = $this->identityService->exchangeSignatureCode($oauthCode, $callbackRedirectUri)
            ?? $this->identityService->getValidAccessTokenForUser($signatory->getUser());
        if ($accessToken === null) {
            throw new Exception("Impossible d'obtenir un jeton TrustedX pour récupérer le document signé.", 401);
        }
        // Le documentId direct est plus fiable que de repasser par le process.
        $signedPdfContent = $this->trustedXClient->downloadSignedDocument(
            $accessToken,
            (string)($documentIdStored ?: $processIdToFetch)
        );
        if (empty($signedPdfContent)) {
            throw new Exception("Le document PDF signé renvoyé par TrustedX est vide.", 500);
        }

        $result = $this->recordSignedStep($request, $signatory, $signedPdfContent, 'sig');
        // Non bloquant : un échec de suppression du processus TrustedX n'invalide pas la signature enregistrée.
        $this->deleteRemoteProcess($accessToken, $processIdStored ?: $processIdToFetch);

        return ['success' => true] + $result;
    }

    /**
     * Traite le refus d'un signataire
     */
    public function rejectSignatureRequest(int $requestId, UserSuperClass $connectedUser, string $reason): SignatureRequest
    {
        // Données déjà validées par PostRejectSignatureInputValidator
        $request     = $this->getRequestById($requestId);
        $currentStep = $request->getCurrentStep();
        $signatory   = $request->getSignatoryByOrder($currentStep);

        $signatory->setStatus(Signatory::STATUS_REJECTED);
        $signatory->setRejectionReason($reason);
        $signatory->setSignedAt(new \DateTime());

        $request->setStatus(SignatureRequest::STATUS_REJECTED);
        $request->setRejectionReason($reason);
        $this->em->flush();

        // Nettoyage best-effort d'un éventuel processus TrustedX déjà ouvert pour cette étape.
        // "LOCAL:" : marqueur des anciennes signatures locales, sans processus TrustedX.
        [$processId] = $this->parseTxId($signatory);
        if ($processId !== null && !str_starts_with($processId, 'LOCAL:')) {
            try {
                $this->deleteRemoteProcess($this->identityService->getValidAccessTokenForUser($connectedUser), $processId);
            } catch (\Throwable) {
                // nettoyage best-effort : un échec ici n'invalide pas le refus déjà enregistré
            }
        }

        return $request;
    }

    /**
     * Enregistre le PDF signé localement via le pilote USB (BJ eToken Driver), après contrôle de la signature ajoutée.
     */
    public function handleLocalSignedDocument(int $requestId, string $signedPdfContent): array
    {
        // Données déjà validées par PostUploadSignedDocumentInputValidator
        $request   = $this->getRequestById($requestId);
        $signatory = $request->getSignatoryByOrder($request->getCurrentStep());

        $signer = $this->localSignatureVerifier->verifyAddedSignature(
            (string)file_get_contents($request->getFileCurrent()),
            $signedPdfContent
        );
        if ($this->checkLocalSignerNpi) {
            $this->assertCertificateBelongsToSignatory($signatory, $signer['serialNumber']);
        }
        $signatory->setCertificateSubject(mb_substr($signer['dn'], 0, 255));

        return ['success' => true] + $this->recordSignedStep($request, $signatory, $signedPdfContent, 'sig_local');
    }

    /**
     * Génère une URL OAuth2 TrustedX permettant d'obtenir un code frais pour télécharger le PDF signé.
     * Utilisé par le frontend après reception du status=finished de TrustedX.
     */
    public function getReauthUrl(int $requestId): string
    {
        // Données déjà validées par GetReauthUrlInputValidator
        $request = $this->getRequestById($requestId);

        $callbackUri = $this->urlBuilder->getApiUrl($requestId, 'callback');

        return $this->identityService->getAuthorizationUrlWithCustomRedirect(
            'trustedx_pki',
            $callbackUri,
            $this->buildStateContext($request)
        );
    }

    /**
     * Échange un code OAuth2 TX contre un token frais et le sauvegarde en DB pour le signataire
     * de l'étape en cours, désigné par le state signé (route publique : retour navigateur de TrustedX).
     */
    public function refreshTxToken(int $requestId, ?string $state, string $code, string $redirectUri): void
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new Exception("Demande de signature introuvable.", 404);
        }

        $signatory = $this->getSignatoryMatchingState($request, $state);

        $this->identityService->exchangeAndSaveToken($code, $redirectUri, $signatory->getUser());
    }


    // Helpers internes réutilisés par les méthodes ci-dessus

    /**
     * Génère une URL OAuth2 TX dont le redirect_uri pointe vers le endpoint refresh-tx-token.
     * Évite le flux SSO complet (fetchUserInfo, session MONARC) — juste l'échange de token.
     */
    private function buildRefreshTokenAuthUrl(SignatureRequest $request): string
    {
        $callbackUri = $this->urlBuilder->getApiUrl($request->getId(), 'refresh-tx-token');

        return $this->identityService->getAuthorizationUrlWithCustomRedirect(
            'trustedx_pki',
            $callbackUri,
            $this->buildStateContext($request)
        );
    }

    /**
     * Contexte signé dans le state OAuth2 : lie le retour TrustedX à la demande, à l'étape et au signataire.
     */
    private function buildStateContext(SignatureRequest $request): array
    {
        return [
            'requestId' => $request->getId(),
            'step'      => $request->getCurrentStep(),
            'userId'    => $request->getSignatoryByOrder($request->getCurrentStep())->getUser()->getId(),
        ];
    }

    private function getSignatoryMatchingState(SignatureRequest $request, ?string $state): Signatory
    {
        $signatory = $request->getSignatoryByOrder($request->getCurrentStep());
        if ($signatory === null
            || $signatory->getStatus() !== Signatory::STATUS_PENDING
            || !$request->isOpenForSigning()
            || $this->identityService->getVerifiedStateContext($state) !== $this->buildStateContext($request)
        ) {
            throw new Exception("Retour TrustedX invalide pour cette demande de signature.", 403);
        }

        return $signatory;
    }

    /**
     * Enregistre le PDF signé de l'étape en cours puis fait avancer la demande (étape suivante ou demande signée).
     *
     * @return array{isCompleted: bool, nextStep: int}
     */
    private function recordSignedStep(
        SignatureRequest $request,
        Signatory $signatory,
        string $signedPdfContent,
        string $filePrefix
    ): array {
        $currentStep = $request->getCurrentStep();
        $newPath = $this->fileStorage->storeSignedVersion($filePrefix, $currentStep, $signedPdfContent);

        $request->setFileCurrent($newPath);
        $signatory->setStatus(Signatory::STATUS_SIGNED);
        $signatory->setSignedAt(new \DateTime());

        if ($currentStep < $request->getSignatories()->count()) {
            $request->setCurrentStep($currentStep + 1);
            $request->setStatus(SignatureRequest::STATUS_PARTIALLY_SIGNED);
        } else {
            $request->setStatus(SignatureRequest::STATUS_SIGNED);
            $request->setFileSigned($newPath);
        }
        $this->em->flush();

        return [
            'isCompleted' => $request->getStatus() === SignatureRequest::STATUS_SIGNED,
            'nextStep'    => $request->getCurrentStep(),
        ];
    }

    /**
     * Contrôle activable (SIGN_LOCAL_NPI_CHECK) : le serialNumber du certificat de la clé USB doit être
     * le NPI du signataire, lié à son compte via son identité TrustedX. Comparaison sur les seuls chiffres
     * (le serialNumber peut porter un préfixe, ex. IDCBJ-).
     */
    private function assertCertificateBelongsToSignatory(Signatory $signatory, ?string $certificateSerialNumber): void
    {
        $npi = null;
        foreach ($this->identityService->getUserIdentities($signatory->getUser()->getId()) as $identity) {
            if ($identity['providerCode'] === 'trustedx_pki') {
                $npi = (string)$identity['providerIdentifier'];
            }
        }

        $npiDigits = preg_replace('/\D/', '', (string)$npi);
        if ($npiDigits === '' || $npiDigits !== preg_replace('/\D/', '', (string)$certificateSerialNumber)) {
            throw new Exception("Le certificat de la clé USB n'appartient pas au signataire attendu (NPI différent).", 412);
        }
    }

    /**
     * Identifiants TrustedX stockés sous la forme "processId::documentId" ou "processId".
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseTxId(Signatory $signatory): array
    {
        $storedTxId = (string)$signatory->getSignatureTxId();
        if ($storedTxId === '') {
            return [null, null];
        }
        $parts = explode('::', $storedTxId, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * Supprime le processus de signature sur TrustedX (DELETE .../signer_processes/{id}).
     * Toujours non bloquant : les échecs sont ignorés, on n'interrompt jamais le flux métier.
     */
    private function deleteRemoteProcess(?string $accessToken, ?string $processId): void
    {
        if (empty($accessToken) || empty($processId)) {
            return;
        }
        try {
            $this->trustedXClient->deleteSignerProcess($accessToken, $processId);
        } catch (\Throwable $e) {
            // échec ignoré : la suppression du process distant n'est pas bloquante
        }
    }

    /**
     * Chemin du PDF à afficher, ou null si la demande n'existe pas ou n'est pas accessible à l'utilisateur.
     */
    public function getPdfFilePathForUser(int $requestId, UserSuperClass $user): ?string
    {
        $request = $this->getRequestById($requestId);
        if ($request === null || !$this->canViewRequest($request, $user)) {
            return null;
        }

        $filePath = $request->getFileSigned() ?: ($request->getFileCurrent() ?: $request->getFileOriginal());
        return ($filePath && file_exists($filePath)) ? $filePath : null;
    }

    private function canViewRequest(SignatureRequest $request, UserSuperClass $user): bool
    {
        foreach ($request->getSignatories() as $signatory) {
            if ($signatory->getUser() !== null && $signatory->getUser()->getId() === $user->getId()) {
                return true;
            }
        }

        return $request->getAnr() !== null
            && $this->userAnrTable->findByAnrAndUser($request->getAnr(), $user) !== null;
    }
}
