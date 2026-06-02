<?php

declare(strict_types=1);

namespace App\Provider;

use App\Entity\Email;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Uid\Uuid;

final class SmtpEmailProvider implements EmailProviderInterface
{
    public const NAME = 'smtp';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function send(Email $email): ProviderResult
    {
        $mimeEmail = (new MimeEmail())
            ->to($email->getRecipientEmail())
            ->subject($email->getSubject())
            ->html($email->getHtmlBody());

        if (null !== $email->getTextBody()) {
            $mimeEmail->text($email->getTextBody());
        }

        try {
            $this->mailer->send($mimeEmail);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('SMTP send failed', [
                'provider' => self::NAME,
                'email_id' => (string) $email->getId(),
                'error' => $e->getMessage(),
            ]);

            return ProviderResult::failure($e->getMessage());
        }

        $fakeMessageId = 'smtp-'.Uuid::v4()->toRfc4122();

        $this->logger->info('Email sent via SMTP', [
            'provider' => self::NAME,
            'email_id' => (string) $email->getId(),
            'provider_message_id' => $fakeMessageId,
        ]);

        return ProviderResult::success($fakeMessageId);
    }
}
