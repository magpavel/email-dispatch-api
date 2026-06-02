<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Entity\Email;
use App\Enum\EmailStatus;
use App\Handler\SendEmailHandler;
use App\Message\SendEmailMessage;
use App\Provider\EmailProviderInterface;
use App\Provider\ProviderResult;
use App\Provider\ProviderSelectorInterface;
use App\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class SendEmailHandlerTest extends TestCase
{
    private function makeEmail(): Email
    {
        return new Email(
            recipientEmail: 'to@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );
    }

    private function makeHandler(
        EmailRepository $repo,
        ProviderSelectorInterface $selector,
        EntityManagerInterface $em,
    ): SendEmailHandler {
        return new SendEmailHandler($repo, $selector, $em, new NullLogger());
    }

    public function testSuccessfulSend(): void
    {
        $email = $this->makeEmail();

        $repo = $this->createMock(EmailRepository::class);
        $repo->expects(self::once())->method('findByIdOrNull')->willReturn($email);

        $provider = $this->createStub(EmailProviderInterface::class);
        $provider->method('getName')->willReturn('log');
        $provider->method('send')->willReturn(ProviderResult::success('msg-001'));

        $selector = $this->createStub(ProviderSelectorInterface::class);
        $selector->method('getOrderedProviders')->willReturn([$provider]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('flush');

        ($this->makeHandler($repo, $selector, $em))(new SendEmailMessage('fake-id'));

        self::assertSame(EmailStatus::Sent, $email->getStatus());
        self::assertSame('log', $email->getProvider());
        self::assertSame('msg-001', $email->getProviderMessageId());
    }

    public function testProviderFailureFallsBackToNextProvider(): void
    {
        $email = $this->makeEmail();

        $repo = $this->createStub(EmailRepository::class);
        $repo->method('findByIdOrNull')->willReturn($email);

        $failing = $this->createStub(EmailProviderInterface::class);
        $failing->method('getName')->willReturn('mailgun');
        $failing->method('send')->willReturn(ProviderResult::failure('Timeout'));

        $fallback = $this->createStub(EmailProviderInterface::class);
        $fallback->method('getName')->willReturn('log');
        $fallback->method('send')->willReturn(ProviderResult::success('msg-fallback'));

        $selector = $this->createStub(ProviderSelectorInterface::class);
        $selector->method('getOrderedProviders')->willReturn([$failing, $fallback]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('flush');

        ($this->makeHandler($repo, $selector, $em))(new SendEmailMessage('fake-id'));

        self::assertSame(EmailStatus::Sent, $email->getStatus());
        self::assertSame('log', $email->getProvider());
    }

    public function testAllProvidersFailMarksFailed(): void
    {
        $email = $this->makeEmail();

        $repo = $this->createStub(EmailRepository::class);
        $repo->method('findByIdOrNull')->willReturn($email);

        $provider = $this->createStub(EmailProviderInterface::class);
        $provider->method('getName')->willReturn('smtp');
        $provider->method('send')->willReturn(ProviderResult::failure('SMTP down'));

        $selector = $this->createStub(ProviderSelectorInterface::class);
        $selector->method('getOrderedProviders')->willReturn([$provider]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('flush');

        ($this->makeHandler($repo, $selector, $em))(new SendEmailMessage('fake-id'));

        self::assertSame(EmailStatus::Failed, $email->getStatus());
        self::assertSame('SMTP down', $email->getLastError());
        self::assertSame(1, $email->getRetryCount());
    }

    public function testEmailNotFoundThrowsUnrecoverable(): void
    {
        $repo = $this->createStub(EmailRepository::class);
        $repo->method('findByIdOrNull')->willReturn(null);

        $selector = $this->createStub(ProviderSelectorInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->makeHandler($repo, $selector, $em))(new SendEmailMessage('nonexistent-id'));
    }

    public function testAlreadyTerminalEmailIsSkipped(): void
    {
        $email = $this->makeEmail();
        $email->markAsProcessing();
        $email->markAsSent('log', 'existing-msg');

        $repo = $this->createStub(EmailRepository::class);
        $repo->method('findByIdOrNull')->willReturn($email);

        $selector = $this->createMock(ProviderSelectorInterface::class);
        $selector->expects(self::never())->method('getOrderedProviders');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        ($this->makeHandler($repo, $selector, $em))(new SendEmailMessage('fake-id'));

        self::assertSame(EmailStatus::Sent, $email->getStatus());
    }

    public function testProviderThrowingExceptionFallsBackToNextProvider(): void
    {
        $email = $this->makeEmail();

        $repo = $this->createStub(EmailRepository::class);
        $repo->method('findByIdOrNull')->willReturn($email);

        $throwing = $this->createStub(EmailProviderInterface::class);
        $throwing->method('getName')->willReturn('mailgun');
        $throwing->method('send')->willThrowException(new \RuntimeException('Network error'));

        $fallback = $this->createStub(EmailProviderInterface::class);
        $fallback->method('getName')->willReturn('log');
        $fallback->method('send')->willReturn(ProviderResult::success('msg-safe'));

        $selector = $this->createStub(ProviderSelectorInterface::class);
        $selector->method('getOrderedProviders')->willReturn([$throwing, $fallback]);

        $em = $this->createStub(EntityManagerInterface::class);

        ($this->makeHandler($repo, $selector, $em))(new SendEmailMessage('fake-id'));

        self::assertSame(EmailStatus::Sent, $email->getStatus());
        self::assertSame('log', $email->getProvider());
    }
}
