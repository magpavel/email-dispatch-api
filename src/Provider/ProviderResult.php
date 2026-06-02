<?php

declare(strict_types=1);

namespace App\Provider;

final readonly class ProviderResult
{
    /**
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public ?string $errorMessage = null,
        public array $rawResponse = [],
    ) {
    }

    /** @param array<string, mixed> $rawResponse */
    public static function success(?string $providerMessageId = null, array $rawResponse = []): self
    {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            rawResponse: $rawResponse,
        );
    }

    /** @param array<string, mixed> $rawResponse */
    public static function failure(string $errorMessage, array $rawResponse = []): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse,
        );
    }
}
