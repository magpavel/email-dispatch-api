<?php

declare(strict_types=1);

namespace App\Enum;

enum EmailStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Sent, self::Delivered, self::Failed, self::Bounced => true,
            default => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Queued => self::Processing === $next,
            self::Processing => \in_array($next, [self::Sent, self::Failed], true),
            self::Sent => self::Delivered === $next,
            self::Failed => self::Processing === $next,
            self::Delivered, self::Bounced => false,
        };
    }
}
