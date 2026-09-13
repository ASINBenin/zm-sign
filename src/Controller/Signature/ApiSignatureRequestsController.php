<?php declare(strict_types=1);

namespace Monarc\Sign\Controller\Signature;

use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\View\Model\JsonModel;
use Monarc\Core\Controller\Handler\ControllerRequestResponseHandlerTrait;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\Sign\Service\Signature\SignatureRequestService;
use Monarc\Sign\Validator\InputValidator\Signature\PostSignatureRequestInputValidator;

/**
 * @method \Laminas\Http\PhpEnvironment\Response getResponse()
 */
class ApiSignatureRequestsController extends AbstractRestfulController
{
    use ControllerRequestResponseHandlerTrait;

    public function __construct(
        private SignatureRequestService $signatureRequestService,
        private PostSignatureRequestInputValidator $postSignatureRequestValidator,
        private ?ConnectedUserService $connectedUserService = null
    ) {
    }

    public function getList()
    {
        $anrId = (int)$this->params()->fromRoute('anrId', 0);
        try {
            return new JsonModel($this->signatureRequestService->getFormattedRequestsByAnrId($anrId));
        } catch (\Throwable $e) {
            $this->getResponse()->setStatusCode($this->toHttpStatusCode($e, 400));
            return new JsonModel(['error' => $e->getMessage()]);
        }
    }

    public function get($id)
    {
        try {
            return new JsonModel($this->signatureRequestService->getFormattedRequestById((int)$id));
        } catch (\Throwable $e) {
            $this->getResponse()->setStatusCode($this->toHttpStatusCode($e, 404));
            return new JsonModel(['error' => $e->getMessage()]);
        }
    }

    public function create($data)
    {
        $anrId = (int)$this->params()->fromRoute('anrId', 0);
        $user = $this->connectedUserService ? $this->connectedUserService->getConnectedUser() : null;

        try {
            // anrId vient de la route (:anrId), pas du corps de la requête — on le fusionne pour la validation.
            $this->validatePostParams($this->postSignatureRequestValidator, array_merge((array)$data, ['anrId' => $anrId]));
            $result = $this->signatureRequestService->createFromDeliverableData($anrId, (array)$data, $user);
            return new JsonModel($result);
        } catch (\Throwable $e) {
            $this->getResponse()->setStatusCode($this->toHttpStatusCode($e, 400));
            return new JsonModel(['error' => $e->getMessage()]);
        }
    }

    public function update($id, $data)
    {
        try {
            $request = $this->signatureRequestService->updateSignatories((int)$id, (array)($data['signatories'] ?? []));
            return new JsonModel([
                'success'          => true,
                'requestId'        => $request->getId(),
                'status'           => $request->getStatus(),
                'signatoriesCount' => $request->getSignatories()->count(),
            ]);
        } catch (\Throwable $e) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => $e->getMessage()]);
        }
    }

    public function delete($id)
    {
        try {
            $user = $this->connectedUserService ? $this->connectedUserService->getConnectedUser() : null;
            return new JsonModel($this->signatureRequestService->deleteSignatureRequest((int)$id, $user));
        } catch (\Throwable $e) {
            $this->getResponse()->setStatusCode($this->toHttpStatusCode($e, 400));
            return new JsonModel(['error' => $e->getMessage()]);
        }
    }

    // Helper interne réutilisé par les méthodes ci-dessus

    /**
     * $e->getCode() peut être n'importe quoi (ex: code d'erreur SQL comme 1146), pas forcément
     * un code HTTP valide (100-599) — on ne le fait confiance que s'il l'est réellement.
     */
    private function toHttpStatusCode(\Throwable $e, int $default): int
    {
        $code = $e->getCode();

        return ($code >= 100 && $code < 600) ? (int)$code : $default;
    }
}
