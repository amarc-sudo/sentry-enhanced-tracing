<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Messenger;

use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryTraceStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryDestinationStamp;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryUserStamp;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Envelope;

/**
 * Starts queue.process transactions on workers and enriches them with queue metadata.
 */
final class SentryMessengerWorkerListener implements EventSubscriberInterface
{
    /**
     * @var array<string, \Sentry\Tracing\Transaction>
     */
    private array $transactions = [];

    public function __construct(private readonly MessengerMessageMetadataExtractor $metadataExtractor)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        $traceStamp = $this->metadataExtractor->getTraceStamp($envelope);
        $context = $traceStamp instanceof SentryTraceStamp
            ? \Sentry\continueTrace($traceStamp->getTraceparent(), $traceStamp->getBaggage() ?? '')
            : TransactionContext::make();

        $context->setOp('queue.process');
        $context->setName($envelope->getMessage()::class);

        $transaction = \Sentry\startTransaction($context);
        SentrySdk::getCurrentHub()->setSpan($transaction);

        $this->propagateUser($envelope);

        $this->transactions[$this->getTransactionKey($envelope)] = $transaction;
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $transaction = $this->transactions[$this->getTransactionKey($envelope)] ?? null;
        if ($transaction === null) {
            return;
        }

        $metadata = $this->metadataExtractor->extractProcessMetadata($envelope);
        $metadata = $this->ensureDestinationName($metadata, $event);
        $this->enrichTransaction($transaction, $metadata);
        $transaction->setStatus(SpanStatus::ok());
        $transaction->finish();

        SentrySdk::getCurrentHub()->setSpan(null);
        $this->flushClient();

        unset($this->transactions[$this->getTransactionKey($envelope)]);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $transaction = $this->transactions[$this->getTransactionKey($envelope)] ?? null;
        if ($transaction === null) {
            return;
        }

        $metadata = $this->metadataExtractor->extractProcessMetadata($envelope);
        $metadata = $this->ensureDestinationName($metadata, $event);
        $this->enrichTransaction($transaction, $metadata);
        $transaction->setStatus(SpanStatus::internalError());

        SentrySdk::getCurrentHub()->captureException($event->getThrowable());

        $transaction->finish();

        SentrySdk::getCurrentHub()->setSpan(null);
        $this->flushClient();

        unset($this->transactions[$this->getTransactionKey($envelope)]);
    }

    private function propagateUser(Envelope $envelope): void
    {
        $userStamp = $this->metadataExtractor->getUserStamp($envelope);
        if (!$userStamp instanceof SentryUserStamp) {
            return;
        }

        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($userStamp): void {
            $scope->setUser([
                'id' => $userStamp->getUserId(),
                'username' => $userStamp->getUsername(),
                'email' => $userStamp->getEmail(),
                'type' => 'messenger_propagated',
            ]);
        });
    }

    private function getTransactionKey(Envelope $envelope): string
    {
        $messageId = $this->metadataExtractor->extractPublishMetadata($envelope)['messaging.message.id'] ?? null;
        if (is_string($messageId) && $messageId !== '') {
            return $messageId;
        }

        return spl_object_hash($envelope);
    }

    private function flushClient(): void
    {
        $client = SentrySdk::getCurrentHub()->getClient();
        if ($client !== null) {
            $client->flush(5_000);
        }
    }

    /**
     * Add queue-related data and tags for Sentry Queue UI aggregation.
     *
     * @param array<string, mixed> $metadata
     */
    private function enrichTransaction(\Sentry\Tracing\Transaction $transaction, array $metadata): void
    {
        $transaction->setData($metadata);
    }

    /**
     * Ensure destination name is set; some transports might not add ReceivedStamp.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function ensureDestinationName(array $metadata, WorkerMessageHandledEvent|WorkerMessageFailedEvent $event): array
    {
        if (!isset($metadata['messaging.destination.name'])) {
            $stamp = $event->getEnvelope()->last(SentryDestinationStamp::class);
            if ($stamp instanceof SentryDestinationStamp) {
                $metadata['messaging.destination.name'] = $stamp->getDestination();
            }
        }

        if (!isset($metadata['messaging.destination.name'])) {
            $receiverName = $event->getReceiverName();
            if ($receiverName !== '') {
                $metadata['messaging.destination.name'] = $receiverName;
            }
        }

        return $metadata;
    }
}
