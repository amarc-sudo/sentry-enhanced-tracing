<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Propagates minimal user context with Messenger messages.
 */
final class SentryUserStamp implements StampInterface
{
    public function __construct(
        private readonly string $userId,
        private readonly ?string $username = null,
        private readonly ?string $email = null
    ) {
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }
}
