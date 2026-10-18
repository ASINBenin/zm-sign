<?php declare(strict_types=1);

namespace Monarc\Sign\Controller\Signature;

use Monarc\Core\Controller\Handler\AbstractRestfulControllerRequestHandler;
use Monarc\Core\Controller\Handler\ControllerRequestResponseHandlerTrait;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\FrontOffice\Entity\Anr;
use Monarc\Sign\Service\Signature\SignatureRequestService;
use Monarc\Sign\Validator\InputValidator\Signature\PostSignatureRequestInputValidator;

class ApiSignatureRequestsController extends AbstractRestfulControllerRequestHandler
{
    use ControllerRequestResponseHandlerTrait;

    public function __construct(
        private SignatureRequestService $signatureRequestService,
        private PostSignatureRequestInputValidator $postSignatureRequestValidator,
        private ConnectedUserService $connectedUserService
    ) {
    }

    public function getList()
    {
        /** @var Anr $anr */
        $anr = $this->getRequest()->getAttribute('anr');

        return $this->getPreparedJsonResponse($this->signatureRequestService->getFormattedRequestsByAnr($anr));
    }

    public function get($id)
    {
        /** @var Anr $anr */
        $anr = $this->getRequest()->getAttribute('anr');

        return $this->getPreparedJsonResponse($this->signatureRequestService->getFormattedRequestById($anr, (int)$id));
    }

    public function create($data)
    {
        /** @var Anr $anr */
        $anr = $this->getRequest()->getAttribute('anr');

        $this->validatePostParams($this->postSignatureRequestValidator, (array)$data);

        return $this->getPreparedJsonResponse($this->signatureRequestService->createFromDeliverableData(
            $anr,
            (array)$data,
            $this->connectedUserService->getConnectedUser()
        ));
    }

    public function update($id, $data)
    {
        /** @var Anr $anr */
        $anr = $this->getRequest()->getAttribute('anr');

        $request = $this->signatureRequestService->updateSignatories(
            $anr,
            (int)$id,
            (array)($data['signatories'] ?? [])
        );

        return $this->getPreparedJsonResponse([
            'success'          => true,
            'requestId'        => $request->getId(),
            'status'           => $request->getStatus(),
            'signatoriesCount' => $request->getSignatories()->count(),
        ]);
    }

    public function delete($id)
    {
        /** @var Anr $anr */
        $anr = $this->getRequest()->getAttribute('anr');

        return $this->getPreparedJsonResponse($this->signatureRequestService->deleteSignatureRequest($anr, (int)$id));
    }
}
