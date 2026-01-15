<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries a generated message id when no transport id is available.
 */
final class SentryMessageIdStamp implements StampInterface
{
    public function __construct(private readonly string $messageId)
    {
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }
}
