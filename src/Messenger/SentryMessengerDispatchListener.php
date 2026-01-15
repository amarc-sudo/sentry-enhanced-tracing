<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Messenger;

use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryTraceStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryUserStamp;
use AmarcSudo\SentryEnhancedTracing\User\EnhancedUserInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\DispatchEvent;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adds Sentry tracing/user stamps on dispatch and records queue.publish spans.
 */
final class SentryMessengerDispatchListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly MessengerMessageMetadataExtractor $metadataExtractor,
        private readonly Security $security,
        private readonly bool $propagateUser,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DispatchEvent::class => 'onDispatch',
            SendMessageToTransportsEvent::class => 'onSend',
        ];
    }

    /**
     * Prepare envelope before dispatch: trace, uuid, user stamps.
     */
    public function onDispatch(DispatchEvent $event): void
    {
        $envelope = $event->getEnvelope();

        $envelope = $this->ensureTraceStamp($envelope);
        $envelope = $this->ensureUuidStamp($envelope);
        if ($this->propagateUser) {
            $envelope = $this->ensureUserStamp($envelope);
        }

        $event->setEnvelope($envelope);
    }

    /**
     * Create queue.publish span, enrich metadata, inject trace headers and ids.
     */
    public function onSend(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $metadata = $this->metadataExtractor->extractPublishMetadata($envelope);

        $transportNames = method_exists($event, 'getTransportNames') ? $event->getTransportNames() : [];
        if ($transportNames !== []) {
            $metadata['messaging.destination.name'] = implode(',', $transportNames);
        }
        if (!isset($metadata['messaging.destination.name'])) {
            $class = $envelope->getMessage()::class;
            $metadata['messaging.destination.name'] = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
        }
        if (!isset($metadata['messaging.destination.name'])) {
            $busStamp = $envelope->last(\Symfony\Component\Messenger\Stamp\BusNameStamp::class);
            if ($busStamp instanceof \Symfony\Component\Messenger\Stamp\BusNameStamp) {
                $metadata['messaging.destination.name'] = $busStamp->getBusName();
            }
        }

        if (!isset($metadata['messaging.message.id'])) {
            $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
            $metadata['messaging.message.id'] = $uuid;
            $envelope = $envelope->with(new \AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryMessageIdStamp($uuid));
        }

        $metadata['messaging.destination.name'] ??= 'default';

        if (isset($metadata['messaging.destination.name'])) {
            $envelope = $envelope->with(
                new \AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryDestinationStamp(
                    (string) $metadata['messaging.destination.name']
                )
            );
            $event->setEnvelope($envelope);
        }

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan() ?? $hub->getTransaction();
        if ($parentSpan === null) {
            return;
        }

        $spanContext = SpanContext::make()
            ->setOp('queue.publish')
            ->setDescription($envelope->getMessage()::class);
        $span = $parentSpan->startChild($spanContext);
        $hub->setSpan($span);

        $traceparent = method_exists($span, 'toTraceparent')
            ? $span->toTraceparent()
            : sprintf('%s-%s-1', $span->getTraceId(), $span->getSpanId());
        $baggage = \Sentry\getBaggage();

        $envelope = $envelope->with(
            new SentryTraceStamp(
                traceparent: $traceparent,
                baggage: $baggage,
                publishedAt: microtime(true),
                bodySize: $metadata['messaging.message.body.size'] ?? null,
            )
        );
        $event->setEnvelope($envelope);

        $span->setData($metadata);
        $span->setStatus(SpanStatus::ok());
        $span->finish();

        $hub->setSpan($parentSpan);
    }

    /**
     * Attach a trace stamp derived from current transaction if missing.
     */
    private function ensureTraceStamp(Envelope $envelope): Envelope
    {
        if ($this->metadataExtractor->getTraceStamp($envelope) instanceof SentryTraceStamp) {
            return $envelope;
        }

        $hub = SentrySdk::getCurrentHub();
        $transaction = $hub->getTransaction();
        if ($transaction === null) {
            return $envelope;
        }

        $traceparent = method_exists($transaction, 'toTraceparent')
            ? $transaction->toTraceparent()
            : sprintf('%s-%s-1', $transaction->getTraceId(), $transaction->getSpanId());
        $baggage = \Sentry\getBaggage();
        $bodySize = $this->metadataExtractor->extractPublishMetadata($envelope)['messaging.message.body.size'] ?? null;

        return $envelope->with(
            new SentryTraceStamp(
                traceparent: $traceparent,
                baggage: $baggage,
                publishedAt: microtime(true),
                bodySize: $bodySize !== null ? (int) $bodySize : null,
            )
        );
    }

    /**
     * Attach user information when available on the current security context.
     */
    private function ensureUserStamp(Envelope $envelope): Envelope
    {
        if ($this->metadataExtractor->getUserStamp($envelope) instanceof SentryUserStamp) {
            return $envelope;
        }

        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
            return $envelope;
        }

        $username = null;
        $email = null;

        if ($user instanceof EnhancedUserInterface) {
            $username = trim((string) ($user->getEnhancedFirstname() ?? '') . ' ' . (string) ($user->getEnhancedLastname() ?? '')) ?: null;
            $email = $user->getEnhancedEmail();
        }

        return $envelope->with(
            new SentryUserStamp(
                userId: $user->getUserIdentifier(),
                username: $username ?? $user->getUserIdentifier(),
                email: $email,
            )
        );
    }

    /**
     * Generate a UUID stamp when no message id is present yet.
     */
    private function ensureUuidStamp(Envelope $envelope): Envelope
    {
        $hasId = $this->metadataExtractor->extractPublishMetadata($envelope)['messaging.message.id'] ?? null;
        if ($hasId !== null) {
            return $envelope;
        }

        if (!class_exists(\Symfony\Component\Messenger\Stamp\UuidStamp::class)) {
            return $envelope;
        }

        return $envelope->with(new \Symfony\Component\Messenger\Stamp\UuidStamp(\Symfony\Component\Uid\Uuid::v4()->toRfc4122()));
    }
}
