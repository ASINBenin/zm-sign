<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\InputValidator\Signature;

use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\Validator\GreaterThan;
use Laminas\Validator\StringLength;
use Monarc\Core\Validator\InputValidator\AbstractInputValidator;
use Monarc\Core\Validator\InputValidator\InputValidationTranslator;

class PostSignatureRequestInputValidator extends AbstractInputValidator
{
    public function __construct(array $config, InputValidationTranslator $translator)
    {
        parent::__construct($config, $translator);
    }

    protected function getRules(): array
    {
        return [
            [
                'name'      => 'anrId',
                'required'  => true,
                'filters'   => [['name' => ToInt::class]],
                'validators' => [
                    [
                        'name'    => GreaterThan::class,
                        'options' => ['min' => 0, 'inclusive' => false],
                    ],
                ],
            ],
            [
                'name'      => 'deliverableId',
                'required'  => false,
                'filters'   => [['name' => ToInt::class]],
                'validators' => [],
            ],
            [
                'name'       => 'signatories',
                'required'   => false,
                'validators' => [],
            ],
            [
                'name'      => 'title',
                'required'  => false,
                'filters'   => [['name' => StringTrim::class]],
                'validators' => [
                    [
                        'name'    => StringLength::class,
                        'options' => ['min' => 1, 'max' => 500],
                    ],
                ],
            ],
        ];
    }
}
