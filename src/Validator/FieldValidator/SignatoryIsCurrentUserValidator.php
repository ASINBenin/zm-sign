<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\FieldValidator;

use Laminas\Validator\AbstractValidator;
use Monarc\Sign\Entity\Signature\SignatureRequest;

// Vérifie que l'utilisateur connecté est bien le signataire attendu à l'étape courante
class SignatoryIsCurrentUserValidator extends AbstractValidator
{
    private const NOT_YOUR_TURN   = 'NOT_YOUR_TURN';
    private const ALREADY_SIGNED  = 'ALREADY_SIGNED';
    private const REQUEST_INVALID = 'REQUEST_INVALID';

    protected $messageTemplates = [
        self::NOT_YOUR_TURN   => "Ce n'est pas votre tour de signer ce document.",
        self::ALREADY_SIGNED  => 'Vous avez déjà signé ce document.',
        self::REQUEST_INVALID => 'Demande de signature introuvable ou invalide.',
    ];

    public function isValid($value): bool
    {
        $repository = $this->getOptions()['repository'];
        $userId     = (int)$this->getOptions()['userId'];
        $requestId  = (int)($this->getOptions()['requestId'] ?? $value);

        /** @var SignatureRequest|null $request */
        $request = $repository->find($requestId);

        if (!$request) {
            $this->error(self::REQUEST_INVALID);
            return false;
        }

        $currentStep = $request->getCurrentStep();
        $signatory   = $request->getSignatoryByOrder($currentStep);

        if (!$signatory || $signatory->getUser()->getId() !== $userId) {
            $this->error(self::NOT_YOUR_TURN);
            return false;
        }

        if ($signatory->getStatus() === \Monarc\Sign\Entity\Signature\Signatory::STATUS_SIGNED) {
            $this->error(self::ALREADY_SIGNED);
            return false;
        }

        return true;
    }
}
