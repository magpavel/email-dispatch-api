<?php

declare(strict_types=1);

namespace App\Provider;

use App\Entity\Email;

interface EmailProviderInterface
{
    public function getName(): string;

    public function send(Email $email): ProviderResult;
}
