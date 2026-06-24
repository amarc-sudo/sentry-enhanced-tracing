<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Messenger;

use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryDestinationStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryMessageIdStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryTraceStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryUserStamp;
use AmarcSudo\SentryEnhancedTracing\User\EnhancedUserInterface;
use Ramsey\Uuid\Uuid;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Records queue.publish spans and attaches Sentry trace/user stamps to outgoing messages.
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
            SendMessageToTransportsEvent::class => 'onSend',
        ];
    }

    /**
     * Create the queue.publish span, enrich metadata and inject trace/user stamps
     * just before the message is handed over to its transport(s).
     */
    public function onSend(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $metadata = $this->metadataExtractor->extractPublishMetadata($envelope);

        // The event knows the real target transports at send time (keyed by name); prefer them.
        $transportNames = array_keys($event->getSenders());
        if ($transportNames !== []) {
            $metadata['messaging.destination.name'] = implode(',', $transportNames);
        }

        if (!isset($metadata['messaging.message.id'])) {
            $uuid = Uuid::uuid4()->toString();
            $metadata['messaging.message.id'] = $uuid;
            $envelope = $envelope->with(new SentryMessageIdStamp($uuid));
        }

        $destination = (string) ($metadata['messaging.destination.name'] ?? 'default');
        $metadata['messaging.destination.name'] = $destination;
        $envelope = $envelope->with(new SentryDestinationStamp($destination));

        if ($this->propagateUser) {
            $envelope = $this->ensureUserStamp($envelope);
        }

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan() ?? $hub->getTransaction();
        if ($parentSpan === null) {
            $event->setEnvelope($envelope);

            return;
        }

        $spanContext = SpanContext::make()
            ->setOp('queue.publish')
            ->setDescription($envelope->getMessage()::class);
        $span = $parentSpan->startChild($spanContext);
        $hub->setSpan($span);

        $envelope = $envelope->with(
            new SentryTraceStamp(
                traceparent: $span->toTraceparent(),
                baggage: \Sentry\getBaggage(),
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
}
