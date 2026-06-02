<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/webhooks', name: 'api_webhooks_')]
final class WebhookController extends AbstractController
{
    public function __construct(
        private readonly EmailRepository $emailRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $mailgunWebhookSecret,
    ) {
    }

    #[Route('/mailgun', name: 'mailgun', methods: ['POST'])]
    public function mailgun(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            $this->logger->warning('Mailgun webhook: invalid signature', [
                'remote_addr' => $request->getClientIp(),
            ]);

            return $this->json(['error' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $eventData = $data['event-data'] ?? $data;
        $eventType = $eventData['event'] ?? null;
        $messageId = $eventData['message']['headers']['message-id'] ?? $eventData['message-id'] ?? null;

        if (null === $messageId) {
            $this->logger->warning('Mailgun webhook: missing message-id', ['payload' => array_keys($eventData)]);

            return $this->json(['status' => 'ignored', 'reason' => 'missing message-id'], Response::HTTP_OK);
        }

        $email = $this->emailRepository->findOneBy(['providerMessageId' => $messageId]);

        if (null === $email) {
            $this->logger->info('Mailgun webhook: no matching email', ['message_id' => $messageId]);

            return $this->json(['status' => 'ignored', 'reason' => 'no matching email'], Response::HTTP_OK);
        }

        $this->logger->info('Mailgun webhook received', [
            'email_id' => (string) $email->getId(),
            'event' => $eventType,
            'message_id' => $messageId,
        ]);

        match ($eventType) {
            'delivered' => $email->markAsDelivered(),
            'failed', 'rejected' => $email->markAsFailed(
                $eventData['delivery-status']['message'] ?? $eventType,
                'mailgun',
            ),
            'complained', 'bounced' => $email->markAsBounced(
                $eventData['delivery-status']['message'] ?? $eventType,
            ),
            default => null,
        };

        $this->em->flush();

        return $this->json(['status' => 'ok'], Response::HTTP_OK);
    }

    private function verifySignature(Request $request): bool
    {
        if ('' === $this->mailgunWebhookSecret) {
            return true;
        }

        $providedSecret = $request->headers->get('X-Webhook-Secret')
            ?? $request->query->get('secret');

        return hash_equals($this->mailgunWebhookSecret, (string) $providedSecret);
    }
}
