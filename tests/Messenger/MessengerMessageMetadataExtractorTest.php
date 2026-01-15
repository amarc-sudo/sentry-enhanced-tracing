<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Tests\Messenger;

use AmarcSudo\SentryEnhancedTracing\Messenger\MessengerMessageMetadataExtractor;
use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryTraceStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Stamp\UuidStamp;

final class MessengerMessageMetadataExtractorTest extends TestCase
{
    public function testExtractPublishMetadataUsesStamps(): void
    {
        $envelope = (new Envelope(new \stdClass()))
            ->with(new TransportMessageIdStamp('123'))
            ->with(new TransportNamesStamp(['async']))
            ->with(new SentryTraceStamp('tp', 'bg', 100.0, 256));

        $extractor = new MessengerMessageMetadataExtractor();
        $metadata = $extractor->extractPublishMetadata($envelope);

        self::assertSame('123', $metadata['messaging.message.id']);
        self::assertSame('async', $metadata['messaging.destination.name']);
        self::assertSame(256, $metadata['messaging.message.body.size']);
    }

    public function testFallbacksWhenNoTransportId(): void
    {
        $envelope = (new Envelope(new \stdClass()))
            ->with(new UuidStamp('uuid-1'));

        $extractor = new MessengerMessageMetadataExtractor();
        $metadata = $extractor->extractPublishMetadata($envelope);

        self::assertSame('uuid-1', $metadata['messaging.message.id']);
    }
}
