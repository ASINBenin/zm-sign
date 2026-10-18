<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\InputValidator\Signature;

use Doctrine\ORM\EntityManager;
use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;
use Monarc\Core\Service\ConnectedUserService;
use Monarc\Core\Validator\InputValidator\AbstractInputValidator;
use Monarc\Core\Validator\InputValidator\InputValidationTranslator;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Monarc\Sign\Validator\FieldValidator\SignatoryIsCurrentUserValidator;

class PostRejectSignatureInputValidator extends AbstractInputValidator
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
        return [
            [
                'name'      => 'requestId',
                'required'  => true,
                'filters'   => [['name' => ToInt::class]],
                'validators' => [
                    [
                        'name'    => SignatoryIsCurrentUserValidator::class,
                        'options' => [
                            'repository' => $this->signatureRequestRepo,
                            'userId'     => (int)$this->connectedUserService->getConnectedUser()->getId(),
                            'requestId'  => (int)($this->initialData['requestId'] ?? 0),
                        ],
                    ],
                ],
            ],
            [
                'name'      => 'reason',
                'required'  => true,
                'filters'   => [['name' => StringTrim::class]],
                'validators' => [
                    ['name' => NotEmpty::class],
                    [
                        'name'    => StringLength::class,
                        'options' => ['min' => 1, 'max' => 1000],
                    ],
                ],
            ],
        ];
    }
}
