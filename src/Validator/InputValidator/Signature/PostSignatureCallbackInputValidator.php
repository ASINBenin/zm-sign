<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\InputValidator\Signature;

use Doctrine\ORM\EntityManager;
use Laminas\Filter\ToInt;
use Laminas\Validator\GreaterThan;
use Monarc\Core\Validator\InputValidator\AbstractInputValidator;
use Monarc\Core\Validator\InputValidator\InputValidationTranslator;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Monarc\Sign\Validator\FieldValidator\SignatoryExistsForStepValidator;

// Valide les paramètres du callback TrustedX (code OAuth2 reçu)
class PostSignatureCallbackInputValidator extends AbstractInputValidator
{
    private \Doctrine\ORM\EntityRepository $signatureRequestRepo;

    public function __construct(
        array $config,
        InputValidationTranslator $translator,
        EntityManager $em
    ) {
        $this->signatureRequestRepo = $em->getRepository(SignatureRequest::class);

        parent::__construct($config, $translator);
    }

    protected function getRules(): array
    {
        $requestId = (int)($this->initialData['requestId'] ?? 0);

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
                        'name'    => SignatoryExistsForStepValidator::class,
                        'options' => [
                            'repository' => $this->signatureRequestRepo,
                            'requestId'  => $requestId,
                        ],
                    ],
                ],
            ],
        ];
    }
}
