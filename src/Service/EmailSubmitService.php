<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\SubmitEmailRequest;
use App\Entity\Email;
use App\Message\SendEmailMessage;
use App\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class EmailSubmitService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailRepository $emailRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function submit(SubmitEmailRequest $request, ?string $idempotencyKey, ?string $requestId): Email
    {
        if (null !== $idempotencyKey) {
            $existing = $this->emailRepository->findByIdempotencyKey($idempotencyKey);
            if (null !== $existing) {
                $this->logger->info('Idempotent request: returning existing email', [
                    'email_id' => (string) $existing->getId(),
                    'idempotency_key' => $idempotencyKey,
                    'request_id' => $requestId,
                ]);

                return $existing;
            }
        }

        $email = new Email(
            recipientEmail: $request->recipientEmail,
            subject: $request->subject,
            htmlBody: $request->htmlBody,
            textBody: $request->textBody,
            metadata: $request->metadata,
            idempotencyKey: $idempotencyKey,
            preferredProvider: $request->preferredProvider,
            requestId: $requestId,
        );

        $this->emailRepository->save($email);
        $this->em->flush();

        $this->logger->info('Email submitted', [
            'email_id' => (string) $email->getId(),
            'to' => $email->getRecipientEmail(),
            'provider_preference' => $email->getPreferredProvider(),
            'request_id' => $requestId,
        ]);

        $this->bus->dispatch(new SendEmailMessage((string) $email->getId()));

        return $email;
    }
}
