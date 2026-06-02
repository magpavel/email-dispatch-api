<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Component\HttpFoundation\Response;

final class EmailControllerTest extends ApiTestCase
{
    /** @var array<string, mixed> */
    private array $validPayload = [
        'recipient_email' => 'user@example.com',
        'subject' => 'Welcome!',
        'html_body' => '<h1>Welcome</h1>',
        'text_body' => 'Welcome',
        'metadata' => ['campaign' => 'onboarding'],
    ];

    public function testSubmitEmailReturns202WithId(): void
    {
        $this->postJson('/api/emails', $this->validPayload);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $data = $this->responseData();
        self::assertArrayHasKey('id', $data);
        self::assertSame('queued', $data['status']);
    }

    public function testSubmitEmailValidationFailsOnMissingFields(): void
    {
        $this->postJson('/api/emails', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = $this->responseData();
        self::assertArrayHasKey('errors', $data);
        self::assertArrayHasKey('recipientEmail', $data['errors']);
        self::assertArrayHasKey('subject', $data['errors']);
        self::assertArrayHasKey('htmlBody', $data['errors']);
    }

    public function testSubmitEmailValidationFailsOnInvalidEmail(): void
    {
        $this->postJson('/api/emails', array_merge($this->validPayload, [
            'recipient_email' => 'not-a-valid-email',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = $this->responseData();
        self::assertArrayHasKey('recipientEmail', $data['errors']);
    }

    public function testSubmitWithInvalidJsonReturns400(): void
    {
        $this->client->request(
            'POST',
            '/api/emails',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not json',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testGetEmailReturnsCorrectData(): void
    {
        $this->postJson('/api/emails', $this->validPayload);
        $submittedData = $this->responseData();
        $id = $submittedData['id'];

        $this->getJson("/api/emails/{$id}");

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $data = $this->responseData();
        self::assertSame($id, $data['id']);
        self::assertSame('queued', $data['status']);
        self::assertArrayHasKey('created_at', $data);
    }

    public function testGetEmailReturns404ForUnknownId(): void
    {
        $this->getJson('/api/emails/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetEmailReturns404ForInvalidUuid(): void
    {
        $this->getJson('/api/emails/not-a-uuid');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testIdempotencyKeyPreventsDoubleSave(): void
    {
        $this->postJson('/api/emails', $this->validPayload, [
            'Idempotency-Key' => 'unique-key-xyz',
        ]);
        $first = $this->responseData();

        $this->postJson('/api/emails', $this->validPayload, [
            'Idempotency-Key' => 'unique-key-xyz',
        ]);
        $second = $this->responseData();

        self::assertSame($first['id'], $second['id']);
    }
}
