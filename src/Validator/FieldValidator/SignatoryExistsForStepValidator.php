<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\FieldValidator;

use Laminas\Validator\AbstractValidator;

// Vérifie qu'un signataire existe pour l'étape courante de la demande
class SignatoryExistsForStepValidator extends AbstractValidator
{
    private const NO_SIGNATORY    = 'NO_SIGNATORY';
    private const REQUEST_INVALID = 'REQUEST_INVALID';
    private const NO_PROCESS_ID   = 'NO_PROCESS_ID';

    protected $messageTemplates = [
        self::NO_SIGNATORY    => 'Aucun signataire trouvé pour cette étape de signature.',
        self::REQUEST_INVALID => 'Demande de signature introuvable.',
        self::NO_PROCESS_ID   => 'Identifiant de transaction TrustedX manquant pour ce signataire.',
    ];

    public function isValid($value): bool
    {
        $repository = $this->getOptions()['repository'];
        $requestId  = (int)($this->getOptions()['requestId'] ?? $value);

        $request = $repository->find($requestId);
        if (!$request) {
            $this->error(self::REQUEST_INVALID);
            return false;
        }

        $signatory = $request->getSignatoryByOrder($request->getCurrentStep());
        if (!$signatory) {
            $this->error(self::NO_SIGNATORY);
            return false;
        }

        $txId = $signatory->getSignatureTxId();
        if (empty($txId)) {
            $this->error(self::NO_PROCESS_ID);
            return false;
        }

        return true;
    }
}
