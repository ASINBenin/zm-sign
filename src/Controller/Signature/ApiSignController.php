<?php declare(strict_types=1);

namespace Monarc\Sign\Controller\Signature;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Monarc\Core\Controller\Handler\ControllerRequestResponseHandlerTrait;
use Monarc\Core\Exception\Exception;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Monarc\Sign\Service\Signature\SignatureProcessService;
use Monarc\Sign\Service\Signature\SignatureUrlBuilder;
use Monarc\Sign\Validator\InputValidator\Signature\PostInitiateSignInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostRejectSignatureInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostSignatureCallbackInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\PostUploadSignedDocumentInputValidator;
use Monarc\Sign\Validator\InputValidator\Signature\GetReauthUrlInputValidator;

/**
 * @method \Laminas\Http\PhpEnvironment\Request getRequest()
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
        private SignatureUrlBuilder $urlBuilder,
    ) {
    }

    public function signAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $this->validatePostParams($this->initiateSignValidator, ['requestId' => $requestId]);

        $data = $this->getJsonBody();
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
        return new JsonModel($this->signatureProcessService->checkTokenValidity(
            (int)$this->params()->fromRoute('id'),
            $this->connectedUserService->getConnectedUser()
        ));
    }

    public function rejectAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $this->validatePostParams(
            $this->rejectSignatureValidator,
            array_merge($this->getJsonBody(), ['requestId' => $requestId])
        );

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
        $pdfContent = (!empty($files['file']['tmp_name']) && is_uploaded_file($files['file']['tmp_name']))
            ? file_get_contents($files['file']['tmp_name'])
            : (string)$this->getRequest()->getContent();

        if (empty($pdfContent)) {
            throw new Exception('Contenu du fichier PDF signé manquant ou invalide.', 412);
        }

        return new JsonModel($this->signatureProcessService->handleLocalSignedDocument($requestId, $pdfContent));
    }

    public function callbackAction()
    {
        $params       = $this->params()->fromQuery();
        $requestId    = (int)$this->params()->fromRoute('id', (int)($params['requestId'] ?? 0));
        $status       = $params['status'] ?? null;
        $oauthCode    = $params['code']   ?? null;
        $signerProcessId = $params['signer_process_id'] ?? $params['processId'] ?? null;

        // NB : le finish_callback_url de TrustedX n'est PAS un redirect_uri OAuth2 ; TrustedX n'y
        // ajoute jamais de "code". Un "code" absent sur ce 1er retour est donc normal et attendu.
        $request = $requestId > 0 ? $this->signatureProcessService->getRequestById($requestId) : null;

        // ── CAS 1 : TrustedX notifie la fin du processus ─────────────────────────────────────
        // Route publique : aucune écriture ici, la page front (connectée) appelle finalize.
        if ($status !== null && $oauthCode === null) {
            $normalizedStatus = strtolower(trim((string)$status));
            $frontStatus = in_array($normalizedStatus, ['finished', 'finish', 'signed'], true)
                ? 'finished'
                : $normalizedStatus;

            return $this->redirect()->toUrl(
                $this->urlBuilder->getFrontUrl($request, 'signature-callback', ['status' => $frontStatus])
            );
        }

        // ── CAS 2 : Code OAuth2 reçu après ré-autorisation (state signé obligatoire) ─────────
        if ($oauthCode !== null) {
            try {
                $this->validatePostParams($this->callbackValidator, ['requestId' => $requestId]);

                $this->signatureProcessService->handleSignCallback(
                    $requestId,
                    $params['state'] ?? null,
                    (string)$oauthCode,
                    $this->urlBuilder->getApiUrl($requestId, 'callback'),
                    $signerProcessId
                );

                return $this->redirect()->toUrl(
                    $this->urlBuilder->getFrontUrl($request, 'signature-process', ['signatureSuccess' => 1])
                );
            } catch (\Throwable $e) {
                return $this->redirectWithError($request, $e);
            }
        }

        return $this->redirect()->toUrl($this->urlBuilder->getFrontUrl(
            $request,
            'signature-process',
            ['signatureError' => 'Paramètres de callback invalides.']
        ));
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
        $request   = $requestId > 0 ? $this->signatureProcessService->getRequestById($requestId) : null;

        if (empty($code)) {
            return $this->redirect()->toUrl(
                $this->urlBuilder->getFrontUrl($request, 'signature-process', ['signatureError' => 'Code OAuth2 manquant.'])
            );
        }

        try {
            $this->signatureProcessService->refreshTxToken(
                $requestId,
                $this->params()->fromQuery('state'),
                (string)$code,
                $this->urlBuilder->getApiUrl($requestId, 'refresh-tx-token')
            );
            return $this->redirect()->toUrl($this->urlBuilder->getFrontUrl($request, 'signature-process'));
        } catch (\Throwable $e) {
            return $this->redirectWithError($request, $e);
        }
    }

    // Contrôleur d'actions : le corps JSON n'est pas décodé automatiquement.
    private function getJsonBody(): array
    {
        $data = json_decode((string)$this->getRequest()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Seuls les messages métier (Monarc Exception) sont transmis au front ; les erreurs techniques restent internes.
     */
    private function redirectWithError(?SignatureRequest $request, \Throwable $e)
    {
        $message = $e instanceof Exception ? $e->getMessage() : 'Une erreur est survenue lors de la signature.';

        return $this->redirect()->toUrl(
            $this->urlBuilder->getFrontUrl($request, 'signature-process', ['signatureError' => $message])
        );
    }

    /**
     * Finalise la signature avec le token TX déjà stocké, après le retour status=finished de TrustedX.
     */
    public function finalizeAction()
    {
        $requestId = (int)$this->params()->fromRoute('id');
        $this->validatePostParams($this->reauthUrlValidator, ['requestId' => $requestId]);

        return new JsonModel($this->signatureProcessService->finalizeWithStoredToken(
            $requestId,
            $this->connectedUserService->getConnectedUser()
        ));
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
        $filePath = $this->signatureProcessService->getPdfFilePathForUser(
            (int)$this->params()->fromRoute('id'),
            $this->connectedUserService->getConnectedUser()
        );

        if ($filePath === null) {
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
