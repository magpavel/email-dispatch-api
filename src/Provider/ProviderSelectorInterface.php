<?php

declare(strict_types=1);

namespace App\Provider;

use App\Entity\Email;

interface ProviderSelectorInterface
{
    /** @return EmailProviderInterface[] */
    public function getOrderedProviders(Email $email): array;
}
