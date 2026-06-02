<?php

declare(strict_types=1);

namespace App\Provider;

use App\Entity\Email;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class LogEmailProvider implements EmailProviderInterface
{
    public const NAME = 'log';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function send(Email $email): ProviderResult
    {
        $fakeMessageId = 'log-'.Uuid::v4()->toRfc4122();

        $this->logger->info('Email dispatched via log provider', [
            'provider' => self::NAME,
            'email_id' => (string) $email->getId(),
            'to' => $email->getRecipientEmail(),
            'subject' => $email->getSubject(),
            'provider_message_id' => $fakeMessageId,
        ]);

        return ProviderResult::success($fakeMessageId, ['logged' => true]);
    }
}
