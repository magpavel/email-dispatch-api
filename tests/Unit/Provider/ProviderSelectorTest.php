<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Entity\Email;
use App\Provider\EmailProviderInterface;
use App\Provider\LogEmailProvider;
use App\Provider\ProviderSelector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProviderSelectorTest extends TestCase
{
    private function makeProvider(string $name): EmailProviderInterface
    {
        $p = $this->createStub(EmailProviderInterface::class);
        $p->method('getName')->willReturn($name);

        return $p;
    }

    private function makeEmail(?string $preferredProvider): Email
    {
        return new Email(
            recipientEmail: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
            preferredProvider: $preferredProvider,
        );
    }

    public function testPreferredProviderComesFirst(): void
    {
        $log = new LogEmailProvider(new NullLogger());
        $smtp = $this->makeProvider('smtp');

        $selector = new ProviderSelector([$log, $smtp], 'log', new NullLogger());
        $ordered = $selector->getOrderedProviders($this->makeEmail('smtp'));

        self::assertSame('smtp', $ordered[0]->getName());
        self::assertSame('log', $ordered[1]->getName());
    }

    public function testDefaultProviderUsedWhenPreferenceIsNull(): void
    {
        $log = new LogEmailProvider(new NullLogger());
        $smtp = $this->makeProvider('smtp');

        $selector = new ProviderSelector([$log, $smtp], 'smtp', new NullLogger());
        $ordered = $selector->getOrderedProviders($this->makeEmail(null));

        self::assertSame('smtp', $ordered[0]->getName());
        self::assertSame('log', $ordered[1]->getName());
    }

    public function testLogProviderIsAlwaysLastFallback(): void
    {
        $log = new LogEmailProvider(new NullLogger());

        $selector = new ProviderSelector([$log], 'log', new NullLogger());
        $ordered = $selector->getOrderedProviders($this->makeEmail(null));

        self::assertCount(1, $ordered);
        self::assertSame('log', $ordered[0]->getName());
    }

    public function testUnknownPreferredProviderFallsBackToDefault(): void
    {
        $log = new LogEmailProvider(new NullLogger());

        $selector = new ProviderSelector([$log], 'log', new NullLogger());
        $ordered = $selector->getOrderedProviders($this->makeEmail('nonexistent'));

        self::assertCount(1, $ordered);
        self::assertSame('log', $ordered[0]->getName());
    }
}
