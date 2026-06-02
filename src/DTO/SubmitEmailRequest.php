<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class SubmitEmailRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Recipient email is required.')]
        #[Assert\Email(message: 'Invalid recipient email address.')]
        #[Assert\Length(max: 320)]
        public readonly string $recipientEmail = '',

        #[Assert\NotBlank(message: 'Subject is required.')]
        #[Assert\Length(min: 1, max: 998)]
        public readonly string $subject = '',

        #[Assert\NotBlank(message: 'HTML body is required.')]
        public readonly string $htmlBody = '',

        public readonly ?string $textBody = null,

        public readonly array $metadata = [],

        #[Assert\Length(max: 255)]
        public readonly ?string $preferredProvider = null,
    ) {
    }
}
