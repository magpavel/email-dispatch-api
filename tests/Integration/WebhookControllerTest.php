<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Email;
use App\Enum\EmailStatus;
use App\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class WebhookControllerTest extends ApiTestCase
{
    private function createSentEmail(string $providerMessageId): Email
    {
        $email = new Email(
            recipientEmail: 'to@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );
        $email->markAsProcessing();
        $email->markAsSent('mailgun', $providerMessageId);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($email);
        $em->flush();

        return $email;
    }

    public function testDeliveredEventUpdatesStatus(): void
    {
        $msgId = '<msg-123@sandbox.mailgun.org>';
        $email = $this->createSentEmail($msgId);

        $this->postJson('/api/webhooks/mailgun', [
            'event-data' => [
                'event' => 'delivered',
                'message' => ['headers' => ['message-id' => $msgId]],
            ],
        ], ['X-Webhook-Secret' => 'test-webhook-secret']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var EmailRepository $repo */
        $repo = static::getContainer()->get(EmailRepository::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($email);
        $refreshed = $repo->findByIdOrNull((string) $email->getId());
        self::assertNotNull($refreshed);
        self::assertSame(EmailStatus::Delivered, $refreshed->getStatus());
    }

    public function testBouncedEventUpdatesStatus(): void
    {
        $msgId = '<msg-456@sandbox.mailgun.org>';
        $email = $this->createSentEmail($msgId);

        $this->postJson('/api/webhooks/mailgun', [
            'event-data' => [
                'event' => 'bounced',
                'message' => ['headers' => ['message-id' => $msgId]],
                'delivery-status' => ['message' => 'Mailbox does not exist'],
            ],
        ], ['X-Webhook-Secret' => 'test-webhook-secret']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($email);
        self::assertSame(EmailStatus::Bounced, $email->getStatus());
        self::assertSame('Mailbox does not exist', $email->getLastError());
    }

    public function testInvalidSignatureReturns401(): void
    {
        $this->postJson('/api/webhooks/mailgun', [
            'event-data' => ['event' => 'delivered'],
        ], ['X-Webhook-Secret' => 'wrong-secret']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUnknownMessageIdIsIgnored(): void
    {
        $this->postJson('/api/webhooks/mailgun', [
            'event-data' => [
                'event' => 'delivered',
                'message' => ['headers' => ['message-id' => '<unknown@example.com>']],
            ],
        ], ['X-Webhook-Secret' => 'test-webhook-secret']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $data = $this->responseData();
        self::assertSame('ignored', $data['status']);
    }
}
