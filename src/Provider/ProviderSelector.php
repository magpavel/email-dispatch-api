<?php

declare(strict_types=1);

namespace App\Provider;

use App\Entity\Email;
use Psr\Log\LoggerInterface;

final class ProviderSelector implements ProviderSelectorInterface
{
    /** @param EmailProviderInterface[] $providers */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns an ordered list of providers to try for this email.
     * The preferred provider (if valid) comes first; log provider is always the final fallback.
     *
     * @return EmailProviderInterface[]
     */
    public function getOrderedProviders(Email $email): array
    {
        $indexed = [];
        foreach ($this->providers as $provider) {
            $indexed[$provider->getName()] = $provider;
        }

        $preferredName = $email->getPreferredProvider() ?? $this->defaultProvider;
        $ordered = [];

        if (isset($indexed[$preferredName])) {
            $ordered[] = $indexed[$preferredName];
        } else {
            $this->logger->warning('Preferred provider not found, using default', [
                'preferred' => $preferredName,
                'default' => $this->defaultProvider,
            ]);
            if (isset($indexed[$this->defaultProvider])) {
                $ordered[] = $indexed[$this->defaultProvider];
            }
        }

        // Add log provider as the last-resort fallback if it is not already in the list.
        if (isset($indexed[LogEmailProvider::NAME]) && !\in_array($indexed[LogEmailProvider::NAME], $ordered, true)) {
            $ordered[] = $indexed[LogEmailProvider::NAME];
        }

        return $ordered;
    }
}
