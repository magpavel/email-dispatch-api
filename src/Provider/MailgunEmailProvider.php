<?php

declare(strict_types=1);

namespace App\Provider;

use App\Entity\Email;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MailgunEmailProvider implements EmailProviderInterface
{
    public const NAME = 'mailgun';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $domain,
        private readonly string $baseUrl = 'https://api.mailgun.net',
        private readonly bool $fakeMode = false,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function send(Email $email): ProviderResult
    {
        if ($this->fakeMode) {
            return $this->sendFake($email);
        }

        $url = \sprintf('%s/v3/%s/messages', rtrim($this->baseUrl, '/'), $this->domain);

        $body = [
            'from' => \sprintf('noreply@%s', $this->domain),
            'to' => $email->getRecipientEmail(),
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
        ];

        if (null !== $email->getTextBody()) {
            $body['text'] = $email->getTextBody();
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'auth_basic' => ['api', $this->apiKey],
                'body' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if (200 !== $statusCode) {
                $error = $data['message'] ?? \sprintf('Unexpected status code %d', $statusCode);

                $this->logger->error('Mailgun send failed', [
                    'provider' => self::NAME,
                    'email_id' => (string) $email->getId(),
                    'status_code' => $statusCode,
                    'error' => $error,
                ]);

                return ProviderResult::failure($error, $data);
            }

            $providerMessageId = $data['id'] ?? null;

            $this->logger->info('Email sent via Mailgun', [
                'provider' => self::NAME,
                'email_id' => (string) $email->getId(),
                'provider_message_id' => $providerMessageId,
            ]);

            return ProviderResult::success($providerMessageId, $data);
        } catch (HttpExceptionInterface|TransportException $e) {
            $this->logger->error('Mailgun HTTP request failed', [
                'provider' => self::NAME,
                'email_id' => (string) $email->getId(),
                'error' => $e->getMessage(),
            ]);

            return ProviderResult::failure($e->getMessage());
        }
    }

    private function sendFake(Email $email): ProviderResult
    {
        $fakeMessageId = '<fake-'.uniqid('mg-', true).'@sandbox.mailgun.org>';

        $this->logger->info('Email sent via Mailgun (FAKE mode)', [
            'provider' => self::NAME,
            'email_id' => (string) $email->getId(),
            'to' => $email->getRecipientEmail(),
            'provider_message_id' => $fakeMessageId,
        ]);

        return ProviderResult::success($fakeMessageId, ['fake' => true]);
    }
}
