<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\InputValidator\Signature;

use Doctrine\ORM\EntityManager;
use Laminas\Filter\ToInt;
use Laminas\Validator\GreaterThan;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\Core\Validator\InputValidator\AbstractInputValidator;
use Monarc\Core\Validator\InputValidator\InputValidationTranslator;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Monarc\Sign\Validator\FieldValidator\SignatoryIsCurrentUserValidator;

class GetReauthUrlInputValidator extends AbstractInputValidator
{
    private \Doctrine\ORM\EntityRepository $signatureRequestRepo;
    private ConnectedUserService $connectedUserService;

    public function __construct(
        array $config,
        InputValidationTranslator $translator,
        EntityManager $em,
        ConnectedUserService $connectedUserService
    ) {
        $this->signatureRequestRepo = $em->getRepository(SignatureRequest::class);
        $this->connectedUserService = $connectedUserService;

        parent::__construct($config, $translator);
    }

    protected function getRules(): array
    {
        $requestId = (int)($this->initialData['requestId'] ?? 0);
        $connectedUserId = (int)$this->connectedUserService->getConnectedUser()->getId();

        return [
            [
                'name'      => 'requestId',
                'required'  => true,
                'filters'   => [['name' => ToInt::class]],
                'validators' => [
                    [
                        'name'    => GreaterThan::class,
                        'options' => ['min' => 0, 'inclusive' => false],
                    ],
                    [
                        'name'    => SignatoryIsCurrentUserValidator::class,
                        'options' => [
                            'repository' => $this->signatureRequestRepo,
                            'userId'     => $connectedUserId,
                            'requestId'  => $requestId,
                        ],
                    ],
                ],
            ],
        ];
    }
}
