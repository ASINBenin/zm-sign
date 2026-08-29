<?php declare(strict_types=1);

namespace Monarc\Extension\Controller;

use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\View\Model\JsonModel;
use Monarc\Extension\Service\IdentityManagementService;

class ApiUsersIdentityController extends AbstractRestfulController
{
    public function __construct(private IdentityManagementService $identityService)
    {
    }

    public function getList()
    {
        $userId = (int)$this->params()->fromRoute('userId');
        return new JsonModel($this->identityService->getUserIdentity($userId));
    }

    public function get($id)
    {
        $userId = (int)$this->params()->fromRoute('userId') ?: (int)$id;
        return new JsonModel($this->identityService->getUserIdentity($userId));
    }

    public function create($data)
    {
        $userId = (int)$this->params()->fromRoute('userId');
        $providerCode = $data['providerCode'] ?? 'trustedx_pki';
        $providerIdentifier = trim((string)($data['providerIdentifier'] ?? ''));

        if (empty($providerIdentifier)) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => "L'identifiant SSO / NPI est requis."]);
        }

        try {
            $this->identityService->linkUserIdentityById($userId, (string)$providerCode, $providerIdentifier);
            return new JsonModel(['success' => true]);
        } catch (\Throwable $e) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => $e->getMessage()]);
        }
    }

    public function delete($id)
    {
        $userId = (int)$this->params()->fromRoute('userId') ?: (int)$id;
        $this->identityService->unlinkUserIdentityById($userId);

        return new JsonModel(['success' => true]);
    }

    public function deleteList($data)
    {
        $userId = (int)$this->params()->fromRoute('userId');
        $this->identityService->unlinkUserIdentityById($userId);

        return new JsonModel(['success' => true]);
    }
}