<?php declare(strict_types=1);

namespace Monarc\Sign\Controller\Signature;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Monarc\Core\Controller\Handler\ControllerRequestResponseHandlerTrait;
use Monarc\Core\Exception\Exception;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\Sign\Service\Signature\SignatureProcessService;
use Monarc\Sign\Validator\InputValidator\Signature\PostInitiateSignInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostRejectSignatureInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostSignatureCallbackInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostUploadSignedDocumentInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\GetReauthUrlInputValidator;

/**
 * @method \Laminas\Http\PhpEnvironment\Response getResponse()
 */
class ApiSignController extends AbstractActionController
{
    use ControllerRequestResponseHandlerTrait;

    public function __construct(
        private SignatureProcessService $signatureProcessService,
        private ConnectedUserService $connectedUserService,
        private PostInitiateSignInputValidator $initiateSignValidator,
        private PostRejectSignatureInputValidator $rejectSignatureValidator,
        private PostSignatureCallbackInputValidator $callbackValidator,
        private PostUploadSignedDocumentInputValidator $uploadSignedValidator,
        private GetReauthUrlInputValidator $reauthUrlValidator,
    ) {
    }

    public function signAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $this->validatePostParams($this->initiateSignValidator, ['requestId' => $requestId]);

        $raw = (string)$this->getRequest()->getContent();
        $data = json_decode($raw, true) ?? [];
        $signatureImage = !empty($data['signatureImage']) ? (string)$data['signatureImage'] : null;

        return new JsonModel(
            $this->signatureProcessService->initiateSignStep(
                $requestId,
                $this->connectedUserService->getConnectedUser(),
                $signatureImage
            )
        );
    }

    public function checkTokenAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $user = $this->connectedUserService->getConnectedUser();

        try {
            return new JsonModel($this->signatureProcessService->checkTokenValidity($requestId, $user));
        } catch (\Throwable $e) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => $e->getMessage()]);
        }
    }

    public function rejectAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $raw  = (string)$this->getRequest()->getContent();
        $data = json_decode($raw, true) ?? [];

        $this->validatePostParams($this->rejectSignatureValidator, $data);

        $this->signatureProcessService->rejectSignatureRequest(
            $requestId,
            $this->connectedUserService->getConnectedUser(),
            $this->rejectSignatureValidator->getValidData()['reason']
        );

        return $this->getSuccessfulJsonResponse(['message' => 'Document refusé avec succès']);
    }

    public function uploadSignedAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $this->validatePostParams($this->uploadSignedValidator, ['requestId' => $requestId]);

        $files      = $this->getRequest()->getFiles()->toArray();
        $pdfContent = (!empty($files['file']['tmp_name']) && file_exists($files['file']['tmp_name']))
            ? file_get_contents($files['file']['tmp_name'])
            : (string)$this->getRequest()->getContent();

        if (empty($pdfContent)) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => 'Contenu du fichier PDF signé manquant ou invalide.']);
        }

        $certSubject = $this->params()->fromPost('certificateSubject') ?? $this->params()->fromQuery('certificateSubject') ?? null;

        return new JsonModel(
            $this->signatureProcessService->handleLocalSignedDocument(
                $requestId,
                $this->connectedUserService->getConnectedUser(),
                $pdfContent,
                $certSubject
            )
        );
    }

    public function callbackAction()
    {
        $params       = $this->params()->fromQuery();
        $requestId    = (int)$this->params()->fromRoute('id', (int)($params['requestId'] ?? 0));
        $status       = $params['status'] ?? null;
        $oauthCode    = $params['code']   ?? null;
        $signerProcessId = $params['signer_process_id'] ?? $params['processId'] ?? null;

        // $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        // $host   = $_SERVER['HTTP_HOST'] ?? 'localhost:5001';
        // // Ce redirect_uri DOIT être identique au finish_callback_url envoyé à TrustedX
        // $callbackRedirectUri = "{$scheme}://{$host}/api/signature-requests/{$requestId}/callback";


        $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host   = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost:5001';
        $prefix = !empty($_SERVER['HTTP_X_FORWARDED_PREFIX']) ? '/' . trim((string)$_SERVER['HTTP_X_FORWARDED_PREFIX'], '/') : '';

        $callbackRedirectUri = "{$scheme}://{$host}{$prefix}/api/signature-requests/{$requestId}/callback";

        // NB : le finish_callback_url de TrustedX n'est PAS un redirect_uri OAuth2 ; TrustedX n'y
        // ajoute jamais de "code". Un "code" absent sur ce 1er retour est donc normal et attendu.
        $request = $requestId > 0 ? $this->signatureProcessService->getRequestById($requestId) : null;
        $anrId   = ($request && $request->getAnr()) ? $request->getAnr()->getId() : 1;

        // ── CAS 1 : TrustedX notifie status=finished (l'utilisateur vient de signer) ──────────
        if ($status !== null && $oauthCode === null) {
            $normalizedStatus = strtolower(trim((string)$status));

            if ($normalizedStatus === 'finished' || $normalizedStatus === 'finish' || $normalizedStatus === 'signed') {
                // 1) Essai IMMÉDIAT avec le token SSO déjà stocké : s'il est encore valide (signature
                //    rapide), on évite complètement le 2ᵉ aller-retour OAuth2. Aucun effet de bord
                //    si le token est expiré (finalized=false).
                try {
                    $res = $this->signatureProcessService->finalizeWithStoredToken($requestId, $signerProcessId);
                    if (!empty($res['finalized'])) {
                        return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-process/{$requestId}?signatureSuccess=1");
                    }
                } catch (\Throwable $e) {
                    // finalisation immédiate impossible, on repli sur la ré-autorisation OAuth2
                }
                // 2) Repli : la page callback front déclenche la ré-autorisation OAuth2 (token frais).
                return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-callback/{$requestId}?status=finished");
            }

            // Annulation ou échec côté TrustedX
            $safeStatus = urlencode($normalizedStatus);
            return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-callback/{$requestId}?status={$safeStatus}");
        }

        // ── CAS 2 : Code OAuth2 reçu (2ème round-trip après ré-autorisation) ────────────────
        // C'est ici qu'on a un token FRAIS garanti — on télécharge le PDF signé.
        if ($oauthCode !== null) {
            try {
                $this->validatePostParams($this->callbackValidator, ['requestId' => $requestId]);

                $this->signatureProcessService->handleSignCallback(
                    $requestId,
                    'finished',
                    $signerProcessId,
                    $oauthCode,
                    $callbackRedirectUri
                );

                return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-process/{$requestId}?signatureSuccess=1");

            } catch (\Throwable $e) {
                $msg = urlencode($e->getMessage());
                return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-process/{$requestId}?signatureError={$msg}");
            }
        }

        // ── Fallback ─────────────────────────────────────────────────────────────────────────
        return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-process/{$requestId}?signatureError=" . urlencode("Paramètres de callback invalides."));
    }

    /**
     * Callback OAuth2 dédié au rafraîchissement du token TX avant signature.
     * Reçoit le code OAuth2, l'échange contre un token frais, le sauvegarde en DB,
     * puis redirige vers la page signature-process (sans passer par le flux SSO complet).
     */
    public function refreshTxTokenAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $code      = $this->params()->fromQuery('code');

        $scheme      = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host        = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost:5001';
        $prefix      = !empty($_SERVER['HTTP_X_FORWARDED_PREFIX']) ? '/' . trim((string)$_SERVER['HTTP_X_FORWARDED_PREFIX'], '/') : '';
        $redirectUri = "{$scheme}://{$host}{$prefix}/api/signature-requests/{$requestId}/refresh-tx-token";

        $request = $requestId > 0 ? $this->signatureProcessService->getRequestById($requestId) : null;
        $anrId   = ($request && $request->getAnr()) ? $request->getAnr()->getId() : 1;

        if (empty($code)) {
            return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-process/{$requestId}?signatureError=" . urlencode("Code OAuth2 manquant."));
        }

        try {
            $this->signatureProcessService->refreshTxToken($requestId, $code, $redirectUri);
            return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-process/{$requestId}");
        } catch (\Throwable $e) {
            $msg = urlencode($e->getMessage());
            return $this->redirect()->toUrl("/#/client/project/{$anrId}/anr/signature-process/{$requestId}?signatureError={$msg}");
        }
    }

    /**
     * Génère une URL de ré-autorisation OAuth2 pour obtenir un code frais permettant
     * de télécharger le PDF signé. Appelé par le frontend après reception de status=finished.
     */
    public function reauthUrlAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $this->validatePostParams($this->reauthUrlValidator, ['requestId' => $requestId]);

        return new JsonModel([
            'url' => $this->signatureProcessService->getReauthUrl($requestId),
        ]);
    }

    public function pdfAction()
    {
        $id = (int)$this->params()->fromRoute('id');
        $filePath = $this->signatureProcessService->getPdfFilePath($id);

        if (!$filePath || !file_exists($filePath)) {
            $this->getResponse()->setStatusCode(404);
            return $this->getResponse();
        }

        $content = file_get_contents($filePath);
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->getHeaders()
                 ->addHeaderLine('Content-Type', 'application/pdf')
                 ->addHeaderLine('Content-Length', (string)strlen($content))
                 ->addHeaderLine('Content-Disposition', 'inline; filename="document.pdf"');
        $response->setContent($content);

        return $response;
    }
}
