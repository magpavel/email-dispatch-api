<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Email;

final readonly class EmailResponse
{
    public string $id;
    public string $status;
    public ?string $provider;
    public ?string $providerMessageId;
    public int $retryCount;
    public ?string $lastError;
    public string $createdAt;
    public string $updatedAt;
    public ?string $processedAt;

    public function __construct(Email $email)
    {
        $this->id = (string) $email->getId();
        $this->status = $email->getStatus()->value;
        $this->provider = $email->getProvider();
        $this->providerMessageId = $email->getProviderMessageId();
        $this->retryCount = $email->getRetryCount();
        $this->lastError = $email->getLastError();
        $this->createdAt = $email->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $this->updatedAt = $email->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $this->processedAt = $email->getProcessedAt()?->format(\DateTimeInterface::ATOM);
    }
}
