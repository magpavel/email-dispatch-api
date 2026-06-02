<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\EmailStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailStatusTest extends TestCase
{
    public function testTerminalStatuses(): void
    {
        self::assertTrue(EmailStatus::Sent->isTerminal());
        self::assertTrue(EmailStatus::Delivered->isTerminal());
        self::assertTrue(EmailStatus::Failed->isTerminal());
        self::assertTrue(EmailStatus::Bounced->isTerminal());
        self::assertFalse(EmailStatus::Queued->isTerminal());
        self::assertFalse(EmailStatus::Processing->isTerminal());
    }

    #[DataProvider('validTransitions')]
    public function testAllowedTransitions(EmailStatus $from, EmailStatus $to): void
    {
        self::assertTrue($from->canTransitionTo($to));
    }

    /** @return array<string, array{EmailStatus, EmailStatus}> */
    public static function validTransitions(): array
    {
        return [
            'queued → processing' => [EmailStatus::Queued, EmailStatus::Processing],
            'processing → sent' => [EmailStatus::Processing, EmailStatus::Sent],
            'processing → failed' => [EmailStatus::Processing, EmailStatus::Failed],
            'sent → delivered' => [EmailStatus::Sent, EmailStatus::Delivered],
            'failed → processing (retry)' => [EmailStatus::Failed, EmailStatus::Processing],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function testForbiddenTransitions(EmailStatus $from, EmailStatus $to): void
    {
        self::assertFalse($from->canTransitionTo($to));
    }

    /** @return array<string, array{EmailStatus, EmailStatus}> */
    public static function invalidTransitions(): array
    {
        return [
            'queued → sent (skipping processing)' => [EmailStatus::Queued, EmailStatus::Sent],
            'delivered → sent' => [EmailStatus::Delivered, EmailStatus::Sent],
            'bounced → queued' => [EmailStatus::Bounced, EmailStatus::Queued],
            'processing → queued' => [EmailStatus::Processing, EmailStatus::Queued],
        ];
    }
}
