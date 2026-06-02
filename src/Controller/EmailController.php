<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\EmailResponse;
use App\DTO\SubmitEmailRequest;
use App\Repository\EmailRepository;
use App\Service\EmailSubmitService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/emails', name: 'api_emails_')]
final class EmailController extends AbstractController
{
    public function __construct(
        private readonly EmailSubmitService $submitService,
        private readonly EmailRepository $emailRepository,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $requestId = $request->headers->get('X-Request-Id') ?? bin2hex(random_bytes(8));
        $idempotencyKey = $request->headers->get('Idempotency-Key');

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $dto = new SubmitEmailRequest(
            recipientEmail: $data['recipient_email'] ?? '',
            subject: $data['subject'] ?? '',
            htmlBody: $data['html_body'] ?? '',
            textBody: $data['text_body'] ?? null,
            metadata: $data['metadata'] ?? [],
            preferredProvider: $data['preferred_provider'] ?? null,
        );

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $email = $this->submitService->submit($dto, $idempotencyKey, $requestId);

        $this->logger->info('POST /api/emails', [
            'request_id' => $requestId,
            'email_id' => (string) $email->getId(),
        ]);

        return $this->json(
            new EmailResponse($email),
            Response::HTTP_ACCEPTED,
            ['X-Request-Id' => $requestId],
        );
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        $requestId = $request->headers->get('X-Request-Id') ?? bin2hex(random_bytes(8));

        $email = $this->emailRepository->findByIdOrNull($id);

        if (null === $email) {
            return $this->json(['error' => 'Email not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(
            new EmailResponse($email),
            Response::HTTP_OK,
            ['X-Request-Id' => $requestId],
        );
    }
}
