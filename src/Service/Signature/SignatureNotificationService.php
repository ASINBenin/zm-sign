<?php declare(strict_types=1);

namespace Monarc\Sign\Service\Signature;

use Monarc\Core\Service\ConfigService;
use Monarc\Core\Service\MailService;
use Monarc\Sign\Entity\Signature\Signatory;
use Monarc\Sign\Entity\Signature\SignatureRequest;
use Throwable;

// Courriels du circuit de signature : un échec d'envoi n'interrompt jamais le circuit
class SignatureNotificationService
{
    public function __construct(
        private MailService $mailService,
        private ConfigService $configService,
        private SignatureUrlBuilder $urlBuilder
    ) {
    }

    public function notifyCircuitStarted(SignatureRequest $request): void
    {
        $this->notifyCurrentSignatory($request);
    }

    public function notifyStepSigned(SignatureRequest $request, Signatory $signer): void
    {
        $signerEmail = $signer->getUser()?->getEmail();
        if ($request->getStatus() === SignatureRequest::STATUS_SIGNED) {
            $this->send(
                $this->getAllParticipantEmails($request),
                'Document entièrement signé',
                sprintf('Le document « %s » a été signé par tous les signataires.', $request->getTitle()),
                $request
            );

            return;
        }

        $this->notifyCurrentSignatory($request);
        $this->send(
            array_diff([$request->getCreator()], [$signerEmail]),
            'Avancement de la signature',
            sprintf(
                '%s a signé le document « %s » (étape %d sur %d).',
                $this->getName($signer),
                $request->getTitle(),
                $signer->getOrderIndex(),
                $request->getSignatories()->count()
            ),
            $request
        );
    }

    public function notifyRejected(SignatureRequest $request, Signatory $rejecter): void
    {
        $recipients = [$request->getCreator()];
        foreach ($request->getSignatories() as $signatory) {
            if ($signatory->getOrderIndex() < $rejecter->getOrderIndex()) {
                $recipients[] = $signatory->getUser()?->getEmail();
            }
        }

        $this->send(
            array_diff($recipients, [$rejecter->getUser()?->getEmail()]),
            'Signature refusée',
            sprintf(
                '%s a refusé de signer le document « %s ». Motif : %s',
                $this->getName($rejecter),
                $request->getTitle(),
                (string)$rejecter->getRejectionReason()
            ),
            $request
        );
    }

    private function notifyCurrentSignatory(SignatureRequest $request): void
    {
        $signatory = $request->getSignatoryByOrder($request->getCurrentStep());
        $this->send(
            [$signatory?->getUser()?->getEmail()],
            'Un document attend votre signature',
            sprintf(
                'Le document « %s » attend votre signature (étape %d sur %d).',
                $request->getTitle(),
                $request->getCurrentStep(),
                $request->getSignatories()->count()
            ),
            $request
        );
    }

    private function getAllParticipantEmails(SignatureRequest $request): array
    {
        $emails = [$request->getCreator()];
        foreach ($request->getSignatories() as $signatory) {
            $emails[] = $signatory->getUser()?->getEmail();
        }

        return $emails;
    }

    private function getName(Signatory $signatory): string
    {
        $user = $signatory->getUser();

        return $user !== null ? trim($user->getFirstname() . ' ' . $user->getLastname()) : 'Un signataire';
    }

    private function send(array $recipients, string $subject, string $text, SignatureRequest $request): void
    {
        $message = sprintf(
            '<p>%s</p><p><a href="%s">Ouvrir la demande de signature dans MONARC</a></p>',
            htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->urlBuilder->getAbsoluteFrontUrl($request, 'signature-process'), ENT_QUOTES, 'UTF-8')
        );
        foreach (array_unique(array_filter($recipients)) as $email) {
            try {
                $this->mailService->send($email, $subject, $message, $this->configService->getEmail());
            } catch (Throwable $e) {
                error_log(sprintf('Signature notification "%s" to %s failed: %s', $subject, $email, $e->getMessage()));
            }
        }
    }
}
