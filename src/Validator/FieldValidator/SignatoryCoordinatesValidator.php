<?php declare(strict_types=1);

namespace Monarc\Sign\Validator\FieldValidator;

use Laminas\Validator\AbstractValidator;

// Vérifie que le signataire à l'étape courante a toutes ses coordonnées renseignées
class SignatoryCoordinatesValidator extends AbstractValidator
{
    private const MISSING_COORDINATES = 'MISSING_COORDINATES';
    private const REQUEST_INVALID     = 'REQUEST_INVALID';

    protected $messageTemplates = [
        self::MISSING_COORDINATES => 'Les coordonnées de la signature (page, x, y, largeur, hauteur) sont obligatoires.',
        self::REQUEST_INVALID     => 'Demande de signature introuvable.',
    ];

    public function isValid($value): bool
    {
        $repository = $this->getOptions()['repository'];
        $requestId  = (int)($this->getOptions()['requestId'] ?? $value);

        $request   = $repository->find($requestId);
        if (!$request) {
            $this->error(self::REQUEST_INVALID);
            return false;
        }

        $signatory = $request->getSignatoryByOrder($request->getCurrentStep());
        if (!$signatory) {
            return true;
        }

        $missing =
            $signatory->getPageNumber() === null
            || $signatory->getCoordX()   === null
            || $signatory->getCoordY()   === null
            || $signatory->getWidth()    === null
            || $signatory->getHeight()   === null;

        if ($missing) {
            $this->error(self::MISSING_COORDINATES);
            return false;
        }

        return true;
    }
}
