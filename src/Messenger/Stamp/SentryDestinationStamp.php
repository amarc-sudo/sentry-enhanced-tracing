<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Persists the destination/transport name for queue monitoring across hops.
 */
final class SentryDestinationStamp implements StampInterface
{
    public function __construct(private readonly string $destination)
    {
    }

    public function getDestination(): string
    {
        return $this->destination;
    }
}
