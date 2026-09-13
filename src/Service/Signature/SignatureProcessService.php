<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Doctrine\ORM\EntityManager;
use Monarc\Core\Exception\Exception;
use Monarc\Core\Entity\UserSuperClass;
use Monarc\Sign\Entity\Signature\Signatory;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Monarc\Core\Service\Sso\IdentityManagementService;

class SignatureProcessService
{
    private EntityManager $em;
    private TrustedXSignatureClient $trustedXClient;
    private IdentityManagementService $identityService;
    private array $config;
    private string $storageDir;

    public function __construct(
        EntityManager $em,
        TrustedXSignatureClient $trustedXClient,
        IdentityManagementService $identityService,
        array $config
    ) {
        $this->em = $em;
        $this->trustedXClient = $trustedXClient;
        $this->identityService = $identityService;
        $this->config = $config;
        $this->storageDir = './data/signatures';
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
                'authUrl'     => $this->identityService->getAuthorizationUrl('trustedx_pki'),
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

        // 3. Construction et validation stricte de l'URL de callback pour TrustedX
        $appUrl = getenv('APP_URL') ?: null;
        if (empty($appUrl) && !empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $appUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}";
        }

        if (empty($appUrl)) {
            throw new Exception("Impossible de déterminer l'URL de l'application pour le callback TrustedX (APP_URL et HTTP_HOST non définis).", 500);
        }

        $finishCallbackUrl = rtrim($appUrl, '/') . "/api/signature-requests/{$requestId}/callback";

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
            'authUrl'     => $this->buildRefreshTokenAuthUrl($requestId),
        ];
    }

    /**
     * Tentative de finalisation IMMÉDIATE avec le token SSO déjà stocké (sans round-trip OAuth2).
     * Appelé par le callback TrustedX (status=finished, sans code) : si le token stocké est encore
     * valide — cas courant quand la signature a été rapide — on télécharge et persiste le PDF signé
     * tout de suite. Sinon on ne touche À RIEN et on renvoie finalized=false : l'appelant enchaînera
     * alors sur la ré-autorisation OAuth2 (qui appellera handleSignCallback avec un code frais).
     *
     * @return array{finalized: bool, isCompleted?: bool, nextStep?: int}
     */
    public function finalizeWithStoredToken(int $requestId, ?string $signerProcessId = null): array
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return ['finalized' => false];
        }
        $currentStep = $request->getCurrentStep();
        $signatory   = $request->getSignatoryByOrder($currentStep);
        if (!$signatory) {
            return ['finalized' => false];
        }

        // Déjà signé (ex. double notification TrustedX) : rien à faire, idempotent.
        if ($signatory->getStatus() === Signatory::STATUS_SIGNED) {
            return [
                'finalized'   => true,
                'isCompleted' => ($request->getStatus() === SignatureRequest::STATUS_SIGNED),
                'nextStep'    => $currentStep,
            ];
        }

        $accessToken = $this->identityService->getValidAccessTokenForUser($signatory->getUser());
        if (empty($accessToken)) {
            // Aucun effet de bord : l'état reste intact pour la ré-autorisation.
            return ['finalized' => false];
        }

        $storedTxId       = (string)$signatory->getSignatureTxId();
        $processIdStored  = str_contains($storedTxId, '::') ? explode('::', $storedTxId, 2)[0] : ($storedTxId ?: null);
        $documentIdStored = str_contains($storedTxId, '::') ? explode('::', $storedTxId, 2)[1] : null;
        $fetchId          = $documentIdStored ?: ($signerProcessId ?: $processIdStored);

        $signedPdfContent = $this->trustedXClient->downloadSignedDocument($accessToken, (string)$fetchId);
        if (empty($signedPdfContent)) {
            // Échec de téléchargement : on laisse la ré-autorisation prendre le relais.
            return ['finalized' => false];
        }

        $this->ensureStorageDirectories();
        $newPath = $this->storageDir . '/current/sig_step_' . $currentStep . '_' . uniqid() . '.pdf';
        file_put_contents($newPath, $signedPdfContent);

        $request->setFileCurrent($newPath);
        $signatory->setStatus(Signatory::STATUS_SIGNED);
        $signatory->setSignedAt(new \DateTime());

        $totalSignatories = $request->getSignatories()->count();
        if ($currentStep < $totalSignatories) {
            $request->setCurrentStep($currentStep + 1);
            $request->setStatus(SignatureRequest::STATUS_PARTIALLY_SIGNED);
        } else {
            $request->setStatus(SignatureRequest::STATUS_SIGNED);
            $request->setFileSigned($newPath);
        }
        $this->em->flush();

        $this->deleteRemoteProcess($accessToken, $processIdStored);

        return [
            'finalized'   => true,
            'isCompleted' => ($request->getStatus() === SignatureRequest::STATUS_SIGNED),
            'nextStep'    => $request->getCurrentStep(),
        ];
    }

    /**
     * Traite le callback de retour après signature sur l'interface SafeLayer.
     * $oauthCode : code OAuth2 reçu de TrustedX dans le finish_callback_url (à préférer au token stocké).
     * $callbackRedirectUri : URL identique au finish_callback_url envoyé à TrustedX (requis pour l'échange de code).
     */
    public function handleSignCallback(int $requestId, string $status, ?string $signerProcessId = null, ?string $oauthCode = null, ?string $callbackRedirectUri = null): array
    {
        // Données déjà validées par PostSignatureCallbackInputValidator
        $request     = $this->getRequestById($requestId);
        $currentStep = $request->getCurrentStep();
        $signatory   = $request->getSignatoryByOrder($currentStep);

        $normalizedStatus = strtolower(trim($status));

        if ($normalizedStatus === 'rejected' || $normalizedStatus === 'cancel') {
            $signatory->setStatus(Signatory::STATUS_REJECTED);
            $signatory->setSignedAt(new \DateTime());
            $request->setStatus(SignatureRequest::STATUS_REJECTED);
            $this->em->flush();

            return [
                'success' => false,
                'status'  => 'rejected',
                'message' => 'La signature a été annulée ou refusée.',
            ];
        }

        if ($normalizedStatus !== 'finished' && $normalizedStatus !== 'finish' && $normalizedStatus !== 'signed') {
            return [
                'success' => false,
                'status'  => $normalizedStatus,
                'message' => "Statut de signature non finalisé ($normalizedStatus).",
            ];
        }

        // Parser le txId stocké : peut être "processId::documentId" ou juste "processId"
        $storedTxId = $signatory->getSignatureTxId();
        $processIdStored  = $storedTxId;
        $documentIdStored = null;
        if ($storedTxId && str_contains($storedTxId, '::')) {
            [$processIdStored, $documentIdStored] = explode('::', $storedTxId, 2);
        }

        $processIdToFetch = $signerProcessId ?: $processIdStored;
        $user = $signatory->getUser();

        // Priorité 1 : échange du code OAuth2 reçu dans le finish_callback_url (token frais garanti)
        $accessToken = null;
        if ($oauthCode && $callbackRedirectUri) {
            $accessToken = $this->identityService->exchangeSignatureCode($oauthCode, $callbackRedirectUri);
        }

        // Priorité 2 : token SSO stocké en base (peut être expiré)
        if (!$accessToken) {
            $accessToken = $this->identityService->getValidAccessTokenForUser($user);
        }

        if (!$accessToken) {
            // Enregistrer la signature comme effectuée mais sans le PDF (sera téléchargé au prochain accès)
            $signatory->setStatus(Signatory::STATUS_SIGNED);
            $signatory->setSignedAt(new \DateTime());

            $totalSignatories = $request->getSignatories()->count();
            if ($currentStep < $totalSignatories) {
                $request->setCurrentStep($currentStep + 1);
                $request->setStatus(SignatureRequest::STATUS_PARTIALLY_SIGNED);
            } else {
                $request->setStatus(SignatureRequest::STATUS_SIGNED);
            }
            $this->em->flush();

            return [
                'success'         => true,
                'pendingDownload' => true,
                'isCompleted'     => ($request->getStatus() === SignatureRequest::STATUS_SIGNED),
                'nextStep'        => $request->getCurrentStep(),
                'message'         => 'Signature enregistrée. Le PDF signé sera disponible après réauthentification.',
            ];
        }
        // Étape 3 — récupération du document signé : GET .../documents/{documentId}/content
        // (on privilégie le documentId direct, plus fiable que de repasser par le process).
        $fetchId = $documentIdStored ?: $processIdToFetch;
        $signedPdfContent = $this->trustedXClient->downloadSignedDocument($accessToken, $fetchId);
        if (empty($signedPdfContent)) {
            throw new Exception("Le document PDF signé renvoyé par TrustedX est vide.", 500);
        }

        $this->ensureStorageDirectories();
        $newFilename = 'sig_step_' . $currentStep . '_' . uniqid() . '.pdf';
        $newPath = $this->storageDir . '/current/' . $newFilename;

        file_put_contents($newPath, $signedPdfContent);

        $request->setFileCurrent($newPath);
        $signatory->setStatus(Signatory::STATUS_SIGNED);
        $signatory->setSignedAt(new \DateTime());

        $totalSignatories = $request->getSignatories()->count();
        if ($currentStep < $totalSignatories) {
            $request->setCurrentStep($currentStep + 1);
            $request->setStatus(SignatureRequest::STATUS_PARTIALLY_SIGNED);
        } else {
            $request->setStatus(SignatureRequest::STATUS_SIGNED);
            $request->setFileSigned($newPath);
        }

        $this->em->flush();

        // Étape 4 — le PDF signé est récupéré et persisté : on supprime le processus côté TrustedX
        // (non bloquant : un échec ici n'invalide pas la signature déjà enregistrée).
        $this->deleteRemoteProcess($accessToken, $processIdStored ?: $processIdToFetch);

        return [
            'success'     => true,
            'isCompleted' => ($request->getStatus() === SignatureRequest::STATUS_SIGNED),
            'nextStep'    => $request->getCurrentStep(),
        ];
    }

    /**
     * Traite le refus d'un signataire
     */
    public function rejectSignatureRequest(int $requestId, UserSuperClass $connectedUser, string $reason): SignatureRequest
    {
        $request     = $this->getRequestById($requestId);
        $currentStep = $request->getCurrentStep();
        $signatory   = $request->getSignatoryByOrder($currentStep);

        $signatory->setStatus(Signatory::STATUS_REJECTED);
        $signatory->setRejectionReason($reason);
        $signatory->setSignedAt(new \DateTime());

        $request->setStatus(SignatureRequest::STATUS_REJECTED);
        $this->em->flush();

        // Nettoyage best-effort d'un éventuel processus TrustedX déjà ouvert pour cette étape.
        $storedTxId = (string)$signatory->getSignatureTxId();
        if ($storedTxId !== '' && !str_starts_with($storedTxId, 'LOCAL:')) {
            $processId = str_contains($storedTxId, '::') ? explode('::', $storedTxId, 2)[0] : $storedTxId;
            try {
                $this->deleteRemoteProcess($this->identityService->getValidAccessTokenForUser($connectedUser), $processId);
            } catch (\Throwable $e) {
                // nettoyage best-effort : un échec ici n'invalide pas le refus déjà enregistré
            }
        }

        return $request;
    }

    /**
     * Enregistre le PDF signé localement via le pilote USB (BJ eToken Driver)
     */
    public function handleLocalSignedDocument(
        int $requestId,
        UserSuperClass $user,
        string $signedPdfContent,
        ?string $certificateSubject = null
    ): array {
        // Données déjà validées par PostUploadSignedDocumentInputValidator
        $request     = $this->getRequestById($requestId);
        $currentStep = $request->getCurrentStep();
        $signatory   = $request->getSignatoryByOrder($currentStep);

        $this->ensureStorageDirectories();
        $newFilename = 'sig_local_step_' . $currentStep . '_' . uniqid() . '.pdf';
        $newPath = $this->storageDir . '/current/' . $newFilename;

        file_put_contents($newPath, $signedPdfContent);

        $request->setFileCurrent($newPath);
        $signatory->setStatus(Signatory::STATUS_SIGNED);
        $signatory->setSignedAt(new \DateTime());

        if ($certificateSubject) {
            $signatory->setSignatureTxId("LOCAL:" . $certificateSubject);
        }

        $totalSignatories = $request->getSignatories()->count();
        if ($currentStep < $totalSignatories) {
            $request->setCurrentStep($currentStep + 1);
            $request->setStatus(SignatureRequest::STATUS_PARTIALLY_SIGNED);
        } else {
            $request->setStatus(SignatureRequest::STATUS_SIGNED);
            $request->setFileSigned($newPath);
        }

        $this->em->flush();

        return [
            'success'     => true,
            'isCompleted' => ($request->getStatus() === SignatureRequest::STATUS_SIGNED),
            'nextStep'    => $request->getCurrentStep(),
        ];
    }

    /**
     * Génère une URL OAuth2 TrustedX permettant d'obtenir un code frais pour télécharger le PDF signé.
     * Utilisé par le frontend après reception du status=finished de TrustedX.
     */
    public function getReauthUrl(int $requestId): string
    {
        // Données déjà validées par GetReauthUrlInputValidator
        $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host        = $_SERVER['HTTP_HOST'] ?? 'localhost:5001';
        $callbackUri = "{$scheme}://{$host}/api/signature-requests/{$requestId}/callback";

        return $this->identityService->getAuthorizationUrlWithCustomRedirect('trustedx_pki', $callbackUri);
    }

    /**
     * Échange un code OAuth2 TX contre un token frais et le sauvegarde en DB.
     * Appelé par refreshTxTokenAction après retour du OAuth2 pré-signature.
     */
    public function refreshTxToken(int $requestId, string $code, string $redirectUri): void
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new Exception("Demande de signature introuvable.", 404);
        }

        // L'utilisateur concerné est le signataire de l'étape en cours de CETTE demande —
        // jamais une session TrustedX prise au hasard en base (ambigu dès qu'il y a plusieurs
        // utilisateurs actifs sur ce provider).
        $signatory = $request->getSignatoryByOrder($request->getCurrentStep());
        if (!$signatory) {
            throw new Exception("Aucun signataire trouvé pour l'étape en cours de cette demande.", 404);
        }

        $this->identityService->exchangeAndSaveToken($code, $redirectUri, $signatory->getUser());
    }

    /**
     * Retourne le chemin sur disque du PDF à afficher/télécharger
     */
    public function getPdfFilePath(int $requestId): ?string
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return null;
        }

        $filePath = $request->getFileSigned() ?: ($request->getFileCurrent() ?: $request->getFileOriginal());
        return ($filePath && file_exists($filePath)) ? $filePath : null;
    }

    // Helpers internes réutilisés par les méthodes ci-dessus

    /**
     * Génère une URL OAuth2 TX dont le redirect_uri pointe vers le endpoint refresh-tx-token.
     * Évite le flux SSO complet (fetchUserInfo, session MONARC) — juste l'échange de token.
     */
    private function buildRefreshTokenAuthUrl(int $requestId): string
    {
        $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host        = $_SERVER['HTTP_HOST'] ?? 'localhost:5001';
        $callbackUri = "{$scheme}://{$host}/api/signature-requests/{$requestId}/refresh-tx-token";

        return $this->identityService->getAuthorizationUrlWithCustomRedirect('trustedx_pki', $callbackUri);
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
}
