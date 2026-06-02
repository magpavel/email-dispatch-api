<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EmailStatus;
use App\Repository\EmailRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EmailRepository::class)]
#[ORM\Table(name: 'emails')]
#[ORM\Index(columns: ['idempotency_key'], name: 'idx_emails_idempotency_key')]
#[ORM\Index(columns: ['provider_message_id'], name: 'idx_emails_provider_message_id')]
#[ORM\Index(columns: ['status'], name: 'idx_emails_status')]
#[ORM\HasLifecycleCallbacks]
class Email
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 320)]
    private string $recipientEmail;

    #[ORM\Column(length: 998)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $htmlBody;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $textBody = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $preferredProvider = null;

    #[ORM\Column(length: 32, enumType: EmailStatus::class)]
    private EmailStatus $status;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerMessageId = null;

    #[ORM\Column]
    private int $retryCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $recipientEmail,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $preferredProvider = null,
        ?string $requestId = null,
    ) {
        $this->id = Uuid::v7();
        $this->recipientEmail = $recipientEmail;
        $this->subject = $subject;
        $this->htmlBody = $htmlBody;
        $this->textBody = $textBody;
        $this->metadata = $metadata;
        $this->idempotencyKey = $idempotencyKey;
        $this->preferredProvider = $preferredProvider;
        $this->requestId = $requestId;
        $this->status = EmailStatus::Queued;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }

    public function getTextBody(): ?string
    {
        return $this->textBody;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getPreferredProvider(): ?string
    {
        return $this->preferredProvider;
    }

    public function getStatus(): EmailStatus
    {
        return $this->status;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function markAsProcessing(): void
    {
        $this->status = EmailStatus::Processing;
        $this->touch();
    }

    public function markAsSent(string $provider, ?string $providerMessageId): void
    {
        $this->status = EmailStatus::Sent;
        $this->provider = $provider;
        $this->providerMessageId = $providerMessageId;
        $this->processedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markAsFailed(string $errorMessage, ?string $provider = null): void
    {
        $this->status = EmailStatus::Failed;
        $this->lastError = mb_substr($errorMessage, 0, 65535);
        if (null !== $provider) {
            $this->provider = $provider;
        }
        ++$this->retryCount;
        $this->touch();
    }

    public function markAsDelivered(): void
    {
        $this->status = EmailStatus::Delivered;
        $this->touch();
    }

    public function markAsBounced(?string $errorMessage = null): void
    {
        $this->status = EmailStatus::Bounced;
        if (null !== $errorMessage) {
            $this->lastError = mb_substr($errorMessage, 0, 65535);
        }
        $this->touch();
    }

    public function resetToQueued(): void
    {
        $this->status = EmailStatus::Queued;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
