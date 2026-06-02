<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Email;
use App\Enum\EmailStatus;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    private function makeEmail(): Email
    {
        return new Email(
            recipientEmail: 'to@example.com',
            subject: 'Hello',
            htmlBody: '<p>Hello</p>',
            textBody: 'Hello',
            metadata: ['campaign' => 'test'],
            idempotencyKey: 'idem-123',
            preferredProvider: 'log',
            requestId: 'req-abc',
        );
    }

    public function testInitialState(): void
    {
        $email = $this->makeEmail();

        self::assertSame(EmailStatus::Queued, $email->getStatus());
        self::assertSame(0, $email->getRetryCount());
        self::assertNull($email->getProvider());
        self::assertNull($email->getProviderMessageId());
        self::assertNull($email->getLastError());
        self::assertNull($email->getProcessedAt());
        self::assertSame('to@example.com', $email->getRecipientEmail());
    }

    public function testMarkAsProcessing(): void
    {
        $email = $this->makeEmail();
        $before = $email->getUpdatedAt();

        $email->markAsProcessing();

        self::assertSame(EmailStatus::Processing, $email->getStatus());
        self::assertGreaterThanOrEqual($before, $email->getUpdatedAt());
    }

    public function testMarkAsSent(): void
    {
        $email = $this->makeEmail();
        $email->markAsProcessing();
        $email->markAsSent('log', 'msg-001');

        self::assertSame(EmailStatus::Sent, $email->getStatus());
        self::assertSame('log', $email->getProvider());
        self::assertSame('msg-001', $email->getProviderMessageId());
        self::assertNotNull($email->getProcessedAt());
        self::assertSame(0, $email->getRetryCount());
    }

    public function testMarkAsFailed(): void
    {
        $email = $this->makeEmail();
        $email->markAsProcessing();
        $email->markAsFailed('Connection refused', 'mailgun');

        self::assertSame(EmailStatus::Failed, $email->getStatus());
        self::assertSame('Connection refused', $email->getLastError());
        self::assertSame('mailgun', $email->getProvider());
        self::assertSame(1, $email->getRetryCount());
    }

    public function testRetryCountIncrementsOnEachFailure(): void
    {
        $email = $this->makeEmail();

        $email->markAsProcessing();
        $email->markAsFailed('err1');
        self::assertSame(1, $email->getRetryCount());

        $email->markAsProcessing();
        $email->markAsFailed('err2');
        self::assertSame(2, $email->getRetryCount());
    }

    public function testMarkAsDelivered(): void
    {
        $email = $this->makeEmail();
        $email->markAsProcessing();
        $email->markAsSent('smtp', 'msg-002');
        $email->markAsDelivered();

        self::assertSame(EmailStatus::Delivered, $email->getStatus());
    }

    public function testMarkAsBounced(): void
    {
        $email = $this->makeEmail();
        $email->markAsProcessing();
        $email->markAsSent('smtp', 'msg-003');
        $email->markAsBounced('mailbox full');

        self::assertSame(EmailStatus::Bounced, $email->getStatus());
        self::assertSame('mailbox full', $email->getLastError());
    }
}
