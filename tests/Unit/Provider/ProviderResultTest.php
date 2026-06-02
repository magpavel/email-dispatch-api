<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Provider\ProviderResult;
use PHPUnit\Framework\TestCase;

final class ProviderResultTest extends TestCase
{
    public function testSuccessResult(): void
    {
        $result = ProviderResult::success('msg-123', ['raw' => true]);

        self::assertTrue($result->success);
        self::assertSame('msg-123', $result->providerMessageId);
        self::assertNull($result->errorMessage);
        self::assertSame(['raw' => true], $result->rawResponse);
    }

    public function testFailureResult(): void
    {
        $result = ProviderResult::failure('Connection refused');

        self::assertFalse($result->success);
        self::assertSame('Connection refused', $result->errorMessage);
        self::assertNull($result->providerMessageId);
    }
}
