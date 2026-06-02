<?php

declare(strict_types=1);

namespace App\Handler;

use App\Message\SendEmailMessage;
use App\Provider\ProviderSelectorInterface;
use App\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class SendEmailHandler
{
    public function __construct(
        private readonly EmailRepository $emailRepository,
        private readonly ProviderSelectorInterface $providerSelector,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendEmailMessage $message): void
    {
        $email = $this->emailRepository->findByIdOrNull($message->emailId);

        if (null === $email) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Email %s not found — discarding message.', $message->emailId));
        }

        if ($email->getStatus()->isTerminal()) {
            $this->logger->info('Email already in terminal status, skipping', [
                'email_id' => $message->emailId,
                'status' => $email->getStatus()->value,
            ]);

            return;
        }

        $email->markAsProcessing();
        $this->em->flush();

        $providers = $this->providerSelector->getOrderedProviders($email);
        $startTime = microtime(true);
        $lastError = null;

        foreach ($providers as $provider) {
            $this->logger->info('Attempting to send email', [
                'email_id' => $message->emailId,
                'provider' => $provider->getName(),
            ]);

            try {
                $result = $provider->send($email);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger->error('Provider threw exception', [
                    'email_id' => $message->emailId,
                    'provider' => $provider->getName(),
                    'error' => $lastError,
                ]);
                continue;
            }

            $duration = round((microtime(true) - $startTime) * 1000);

            if ($result->success) {
                $email->markAsSent($provider->getName(), $result->providerMessageId);
                $this->em->flush();

                $this->logger->info('Email sent successfully', [
                    'email_id' => $message->emailId,
                    'provider' => $provider->getName(),
                    'provider_message_id' => $result->providerMessageId,
                    'duration_ms' => $duration,
                ]);

                return;
            }

            $lastError = $result->errorMessage;

            $this->logger->warning('Provider failed, trying fallback', [
                'email_id' => $message->emailId,
                'provider' => $provider->getName(),
                'error' => $lastError,
            ]);
        }

        $duration = round((microtime(true) - $startTime) * 1000);

        $email->markAsFailed($lastError ?? 'All providers failed');
        $this->em->flush();

        $this->logger->error('All providers failed, email marked as failed', [
            'email_id' => $message->emailId,
            'last_error' => $lastError,
            'duration_ms' => $duration,
        ]);
    }
}
