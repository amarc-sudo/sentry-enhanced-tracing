<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Tests\Messenger;

use AmarcSudo\SentryEnhancedTracing\Messenger\Stamp\SentryTraceStamp;
use PHPUnit\Framework\TestCase;

final class SentryTraceStampTest extends TestCase
{
    public function testStampStoresValues(): void
    {
        $stamp = new SentryTraceStamp('traceparent', 'baggage', 123.45, 512);

        self::assertSame('traceparent', $stamp->getTraceparent());
        self::assertSame('baggage', $stamp->getBaggage());
        self::assertSame(123.45, $stamp->getPublishedAt());
        self::assertSame(512, $stamp->getBodySize());
    }
}
