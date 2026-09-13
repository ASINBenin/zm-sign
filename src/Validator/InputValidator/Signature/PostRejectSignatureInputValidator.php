<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\InputValidator\Signature;

use Laminas\Filter\StringTrim;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;
use Monarc\Core\Validator\InputValidator\AbstractInputValidator;
use Monarc\Core\Validator\InputValidator\InputValidationTranslator;

class PostRejectSignatureInputValidator extends AbstractInputValidator
{
    public function __construct(array $config, InputValidationTranslator $translator)
    {
        parent::__construct($config, $translator);
    }

    protected function getRules(): array
    {
        return [
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
