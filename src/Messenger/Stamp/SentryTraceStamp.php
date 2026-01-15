<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries Sentry tracing headers and publish metadata across Messenger hops.
 */
final class SentryTraceStamp implements StampInterface
{
    public function __construct(
        private readonly string $traceparent,
        private readonly ?string $baggage,
        private readonly float $publishedAt,
        private readonly ?int $bodySize
    ) {
    }

    public function getTraceparent(): string
    {
        return $this->traceparent;
    }

    public function getBaggage(): ?string
    {
        return $this->baggage;
    }

    public function getPublishedAt(): float
    {
        return $this->publishedAt;
    }

    public function getBodySize(): ?int
    {
        return $this->bodySize;
    }
}
