<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Messenger;

use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryDestinationStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryMessageIdStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryTraceStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryUserStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\UuidStamp;

/**
 * Small helper that extracts queue metadata from Messenger envelopes.
 */
final class MessengerMessageMetadataExtractor
{
    /**
     * @return array<string, mixed>
     */
    public function extractPublishMetadata(Envelope $envelope, ?SentryTraceStamp $traceStamp = null): array
    {
        $traceStamp ??= $this->getTraceStamp($envelope);

        return array_filter([
            'messaging.message.id' => $this->getMessageId($envelope),
            'messaging.destination.name' => $this->getDestinationName($envelope) ?? 'default',
            'messaging.message.body.size' => $traceStamp?->getBodySize() ?? $this->guessMessageSize($envelope->getMessage()),
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function extractProcessMetadata(Envelope $envelope, ?SentryTraceStamp $traceStamp = null): array
    {
        $traceStamp ??= $this->getTraceStamp($envelope);
        $publishedAt = $traceStamp?->getPublishedAt();

        return array_filter([
            'messaging.message.id' => $this->getMessageId($envelope),
            'messaging.destination.name' => $this->getDestinationName($envelope) ?? 'default',
            'messaging.message.body.size' => $traceStamp?->getBodySize() ?? $this->guessMessageSize($envelope->getMessage()),
            'messaging.message.retry.count' => $this->getRetryCount($envelope),
            'messaging.message.receive.latency' => $publishedAt !== null ? (int) round((microtime(true) - $publishedAt) * 1000) : null,
        ], static fn ($value) => $value !== null);
    }

    public function getTraceStamp(Envelope $envelope): ?SentryTraceStamp
    {
        /** @var SentryTraceStamp|null $stamp */
        $stamp = $envelope->last(SentryTraceStamp::class);

        return $stamp;
    }

    public function getUserStamp(Envelope $envelope): ?SentryUserStamp
    {
        /** @var SentryUserStamp|null $stamp */
        $stamp = $envelope->last(SentryUserStamp::class);

        return $stamp;
    }

    /**
     * Resolve message id from custom stamp, UUID stamp or transport stamp.
     */
    private function getMessageId(Envelope $envelope): ?string
    {
        $messageIdStamp = $envelope->last(SentryMessageIdStamp::class);
        if ($messageIdStamp instanceof SentryMessageIdStamp) {
            return $messageIdStamp->getMessageId();
        }

        $uuidStamp = $envelope->last(UuidStamp::class);
        if ($uuidStamp instanceof UuidStamp) {
            return $uuidStamp->getUuid();
        }

        $transportId = $envelope->last(TransportMessageIdStamp::class);
        if ($transportId instanceof TransportMessageIdStamp) {
            $id = $transportId->getId();
            return is_scalar($id) ? (string) $id : null;
        }

        return null;
    }

    /**
     * Resolve destination name with priority: message class, received transport,
     * producer transport, custom stamp, then bus name.
     */
    private function getDestinationName(Envelope $envelope): ?string
    {
        $class = $envelope->getMessage()::class;
        $basename = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
        if ($basename !== '') {
            return $basename;
        }

        $received = $envelope->last(ReceivedStamp::class);
        if ($received instanceof ReceivedStamp) {
            return $received->getTransportName();
        }

        $transportNames = $envelope->last(TransportNamesStamp::class);
        if ($transportNames instanceof TransportNamesStamp) {
            $names = $transportNames->getTransportNames();

            return $names !== [] ? implode(',', $names) : null;
        }

        $destinationStamp = $envelope->last(SentryDestinationStamp::class);
        if ($destinationStamp instanceof SentryDestinationStamp) {
            return $destinationStamp->getDestination();
        }

        $busName = $envelope->last(BusNameStamp::class);
        if ($busName instanceof BusNameStamp) {
            return $busName->getBusName();
        }

        return null;
    }

    private function getRetryCount(Envelope $envelope): ?int
    {
        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        if ($redeliveryStamp instanceof RedeliveryStamp) {
            return $redeliveryStamp->getRetryCount();
        }

        return null;
    }

    private function guessMessageSize(object $message): ?int
    {
        try {
            $serialized = json_encode($message, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $serialized = null;
        }

        if ($serialized === null) {
            try {
                $serialized = serialize($message);
            } catch (\Throwable) {
                return null;
            }
        }

        return strlen($serialized);
    }
}
