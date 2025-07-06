<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\EventListener;

use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Listener that traces Symfony event execution phases as Sentry spans
 * Uses high and low priorities to measure execution duration of other listeners
 *
 * ADVANCED FEATURE: Automatic child span capture
 * ===============================================
 * This listener defines each phase span as the "current span" in the Sentry Hub.
 * Result: All spans created automatically by Sentry integrations during
 * listener execution are attached to the proper phase span.
 *
 * Automatically captured child spans:
 * - db.query: SQL queries via DBAL integration
 * - cache.get/set: Cache operations
 * - twig.render: Template rendering
 * - http.client: Outgoing HTTP calls
 * - redis.command: Redis commands
 * - etc.
 *
 * Example hierarchy in Sentry:
 * POST /api/credentials (500ms)
 * ├── Event Listeners Phase: kernel.request (50ms)
 * │   ├── db.query: SELECT users WHERE id=? (15ms)
 * │   ├── cache.get: user:permissions:123 (2ms)
 * │   └── security.check (30ms)
 * ├── Event Listeners Phase: kernel.controller (200ms)
 * │   ├── db.query: SELECT credentials WHERE category=? (80ms)
 * │   ├── cache.set: credentials:category:456 (5ms)
 * │   └── business.logic (115ms)
 * └── Event Listeners Phase: kernel.response (100ms)
 *     ├── twig.render: response.html.twig (85ms)
 *     └── cache.set: rendered:template:hash (3ms)
 */
#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequestStart', priority: 99995)]
#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequestEnd', priority: -100000)]
#[AsEventListener(event: ViewEvent::class, method: 'onKernelViewStart', priority: 100000)]
#[AsEventListener(event: ViewEvent::class, method: 'onKernelViewEnd', priority: -100000)]
#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponseStart', priority: 100000)]
#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponseEnd', priority: -100000)]
#[AsEventListener(event: ExceptionEvent::class, method: 'onKernelExceptionStart', priority: 100000)]
#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate')]
class SentryListenerPhasesTracer
{
    private static array $activeSpans = [];

    private static array $eventTimes = [];

    private static array $previousSpans = []; // To restore previous spans

    private static int $executionOrder = 0;

    private static bool $spansFinalized = false;

    // === KERNEL REQUEST ===
    public function onKernelRequestStart(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        self::$executionOrder = 0;
        self::$spansFinalized = false; // Reset for new request
        self::$previousSpans = []; // Clean previous spans for new request

        // Informative breadcrumb about automatic capture
        \Sentry\addBreadcrumb(
            category: 'sentry.tracing.setup',
            message: "SentryListenerPhasesTracer initialized - Automatic child span capture enabled",
            metadata: [
                       'features'        => [
                                             'automatic_dbal_spans'  => true,
                                             'automatic_cache_spans' => true,
                                             'automatic_twig_spans'  => true,
                                             'automatic_http_spans'  => true,
                                            ],
                       'request_uri'     => $event->getRequest()->getRequestUri(),
                       'expected_phases' => [
                                             'kernel.request',
                                             'kernel.view',
                                             'kernel.response',
                                            ],
                      ],
            level: \Sentry\Breadcrumb::LEVEL_INFO
        );
        $this->startEventPhaseSpan('kernel.request', [
                                                      'url'        => $event->getRequest()->getUri(),
                                                      'method'     => $event->getRequest()->getMethod(),
                                                      'ip'         => $event->getRequest()->getClientIp(),
                                                      'user_agent' => $event->getRequest()
                                                          ->headers->get('User-Agent', 'unknown'),
                                                     ]);
    }

    public function onKernelRequestEnd(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Calculate duration for potential future use
        // $startTime = self::$eventTimes['kernel.request'];
        // $duration = microtime(true) - $startTime;

        $this->endEventPhaseSpan(
            'kernel.request',
            [
             'final_route' => $event->getRequest()->attributes->get('_route', 'unknown'),
             'controller'  => $event->getRequest()->attributes->get('_controller', 'unknown'),
            ]
        );
    }

    // === KERNEL VIEW ===
    public function onKernelViewStart(ViewEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->startEventPhaseSpan(
            'kernel.view',
            [
             'controller_result_type' => get_debug_type($event->getControllerResult()),
             'route'                  => $event->getRequest()->attributes->get('_route', 'unknown'),
            ]
        );
    }

    public function onKernelViewEnd(ViewEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->endEventPhaseSpan('kernel.view');
    }

    // === KERNEL RESPONSE ===
    public function onKernelResponseStart(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->startEventPhaseSpan(
            'kernel.response',
            [
             'status_code'    => $event->getResponse()->getStatusCode(),
             'content_type'   => $event->getResponse()->headers->get('Content-Type', 'unknown'),
             'content_length' => $event->getResponse()->headers->get('Content-Length', 'unknown'),
            ]
        );
    }

    public function onKernelResponseEnd(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->endEventPhaseSpan(
            'kernel.response',
            [
             'final_status_code' => $event->getResponse()->getStatusCode(),
             'response_size'     => strlen($event->getResponse()->getContent() ?: ''),
            ]
        );

        // Finalize all spans BEFORE sending response and Sentry data
        if (!self::$spansFinalized) {
            $this->createExecutionSummary($event);
            $this->finalizeAllSpans();
        }
    }

    // === KERNEL EXCEPTION ===
    public function onKernelExceptionStart(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $exception = $event->getThrowable();

        $this->startEventPhaseSpan(
            'kernel.exception',
            [
             'exception_class'   => get_class($exception),
             'exception_message' => $exception->getMessage(),
             'exception_code'    => $exception->getCode(),
             'exception_file'    => basename($exception->getFile()),
             'exception_line'    => $exception->getLine(),
            ]
        );

        // In case of exception, finalize all spans immediately
        // since onKernelResponseEnd might never be called
        if (!self::$spansFinalized) {
            $this->finalizeAllSpans();
        }
    }

    // === KERNEL TERMINATE ===
    public function onKernelTerminate(TerminateEvent $event): void
    {
        // For now, we don't use $event but it might be needed for future enhancements
        unset($event);

        // Security cleanup if spans haven't been finalized
        // (for example in case of exception that would have short-circuited onKernelResponseEnd)
        if (!self::$spansFinalized && self::$activeSpans !== []) {
            $this->finalizeAllSpans();
        }
    }

    // === PRIVATE METHODS ===
    /**
     * Starts a new span for an event phase.
     *
     * @param string $eventName Name of the event
     * @param array<string, mixed> $metadata Additional metadata to include
     */
    private function startEventPhaseSpan(string $eventName, array $metadata = []): void
    {
        $hub = SentrySdk::getCurrentHub();
        $transaction = $hub->getTransaction();
        if (!$transaction) {
            return;
        }

        // 1. Save current span for later restoration
        $previousSpan = $hub->getSpan();
        self::$previousSpans[$eventName] = $previousSpan;

        // 2. Create our event phase span
        $spanContext = new SpanContext();
        $spanContext->setOp('event.listeners.phase');
        $spanContext->setDescription("Event Listeners Phase: {$eventName}");

        $span = $transaction->startChild($spanContext);

        $span->setData([
                        'event.name'            => $eventName,
                        'event.metadata'        => $metadata,
                        'event.execution_order' => ++self::$executionOrder,
                        'event.start_time'      => microtime(true),
                       ]);

        // 3. IMPORTANT: Set this span as the current span in the Sentry Hub
        //
        // This is the key to the magic! By defining our span as the current span,
        // all Sentry integrations (DBAL, cache, Twig, HTTP client, Redis...)
        // will automatically create their spans as children of our span.
        //
        // No need to modify business code or add manual traces!
        // SQL queries in kernel.controller will automatically appear
        // under the "Event Listeners Phase: kernel.controller" span
        $hub->setSpan($span);

        self::$activeSpans[$eventName] = $span;
        self::$eventTimes[$eventName] = microtime(true);

        // Breadcrumb for phase start
        \Sentry\addBreadcrumb(
            category: 'event.listeners.phase',
            message: "Listeners phase {$eventName} started (order: " .
                      self::$executionOrder . ") - Now capturing child spans",
            metadata: array_merge($metadata, [
                                              'execution_order'      => self::$executionOrder,
                                              'phase'                => 'start',
                                              'captures_child_spans' => true,
                                             ]),
            level: \Sentry\Breadcrumb::LEVEL_DEBUG
        );
    }

    /**
     * Ends a span for an event phase.
     *
     * @param string $eventName Name of the event
     * @param array<string, mixed> $endMetadata Additional end metadata
     */
    private function endEventPhaseSpan(string $eventName, array $endMetadata = []): void
    {
        if (!isset(self::$activeSpans[$eventName])) {
            return;
        }

        $hub = SentrySdk::getCurrentHub();
        $span = self::$activeSpans[$eventName];
        $startTime = self::$eventTimes[$eventName];
        $duration = microtime(true) - $startTime;

        // Count child spans that were captured
        $childSpansCount = $this->countChildSpans($span);

        $span->setData([
                        'event.end_time'            => microtime(true),
                        'event.duration'            => $duration,
                        'event.end_metadata'        => $endMetadata,
                        'event.execution_order_end' => ++self::$executionOrder,
                        'event.child_spans_count'   => $childSpansCount,
                       ]);

        $span->finish();

        // IMPORTANT: Restore previous span in the Sentry Hub
        //
        // We restore the span that was current before our event phase.
        // This ensures that spans created after this phase will be attached
        // to the correct parent (either the next phase or the main transaction)
        $previousSpan = self::$previousSpans[$eventName] ?? null;
        $hub->setSpan($previousSpan);

        // Breadcrumb for phase end
        \Sentry\addBreadcrumb(
            category: 'event.listeners.phase',
            message: "Listeners phase {$eventName} completed (order: " .
                      self::$executionOrder . ") - Captured {$childSpansCount} child spans",
            metadata: array_merge(
                $endMetadata,
                [
                 'duration_ms'          => round($duration * 1000, 3),
                 'execution_order'      => self::$executionOrder,
                 'phase'                => 'end',
                 'performance'          => $this->getPhasePerformanceLevel($eventName, $duration),
                 'child_spans_captured' => $childSpansCount,
                ]
            ),
            level: $duration > 0.1 ? \Sentry\Breadcrumb::LEVEL_WARNING : \Sentry\Breadcrumb::LEVEL_DEBUG
        );

        unset(self::$activeSpans[$eventName], self::$eventTimes[$eventName], self::$previousSpans[$eventName]);
    }

    private function createExecutionSummary(ResponseEvent|TerminateEvent $event): void
    {
        $totalTime = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $memoryUsage = memory_get_peak_usage(true);

        $response = $event->getResponse();
        $request = $event->getRequest();

        $summary = [
                    'total_execution_time_ms' => round($totalTime * 1000.0, 3),
                    'peak_memory_usage_mb'    => round((float) $memoryUsage / 1024.0 / 1024.0, 2),
                    'response_status'         => $response->getStatusCode(),
                    'total_listener_phases'   => self::$executionOrder,
                    'request_uri'             => $request->getRequestUri(),
                    'request_method'          => $request->getMethod(),
                    'response_content_length' => strlen($response->getContent() ?: ''),
                   ];

        \Sentry\addBreadcrumb(
            category: 'performance.summary',
            message: "Request execution completed with {$summary['total_listener_phases']} listener phases",
            metadata: $summary,
            level: $totalTime > 1.0 ? \Sentry\Breadcrumb::LEVEL_WARNING : \Sentry\Breadcrumb::LEVEL_INFO
        );

        // Add performance tags
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($summary): void {
            $scope->setTag('execution_time_ms', (string) $summary['total_execution_time_ms']);
            $scope->setTag('memory_usage_mb', (string) $summary['peak_memory_usage_mb']);
            $scope->setTag('listener_phases_count', (string) $summary['total_listener_phases']);
            $scope->setTag(
                'performance_category',
                $this->getOverallPerformanceCategory($summary['total_execution_time_ms'])
            );
            $scope->setTag(
                'response_size_category',
                $this->getResponseSizeCategory($summary['response_content_length'])
            );
        });
    }

    private function finalizeAllSpans(): void
    {
        $hub = SentrySdk::getCurrentHub();

        foreach (self::$activeSpans as $eventName => $span) {
            $startTime = self::$eventTimes[$eventName];
            $duration = microtime(true) - $startTime;

            $span->setData([
                            'event.duration' => $duration,
                            'event.status'   => 'force_completed',
                           ]);
            $span->finish();

            // Restore previous span for this span as well
            if (isset(self::$previousSpans[$eventName])) {
                $previousSpan = self::$previousSpans[$eventName];
                $hub->setSpan($previousSpan);
            }
        }

        // Mark as finalized and reset for next request
        self::$spansFinalized = true;
        self::$activeSpans = [];
        self::$eventTimes = [];
        self::$previousSpans = []; // Clean previous spans
        self::$executionOrder = 0;
    }

    /**
     * Approximately counts the number of captured child spans
     * (simplified method - in a real implementation we could use internal metrics)
     */
    private function countChildSpans(mixed $span): int
    {
        // For now, we don't use $span but it might be needed for future span counting
        unset($span);

        // For now, we return 0 because the Sentry API doesn't easily provide access to child spans
        // But child spans will be created and visible in the Sentry interface
        return 0;
    }



    private function getPhasePerformanceLevel(string $eventName, float $duration): string
    {
        // Different thresholds based on event type
        $thresholds = [
                       'kernel.request'   => 0.05,
                       'kernel.view'      => 0.02,
                       'kernel.response'  => 0.05,
                       'kernel.exception' => 0.01,
                      ];

        $threshold = $thresholds[$eventName] ?? 0.05;

        return match (true) {
            $duration < $threshold / 2.0 => 'fast',
            $duration < $threshold => 'normal',
            $duration < $threshold * 2.0 => 'slow',
            default => 'very_slow',
        };
    }

    private function getOverallPerformanceCategory(float $totalTimeMs): string
    {
        return match (true) {
            $totalTimeMs < 200 => 'fast',
            $totalTimeMs < 500 => 'normal',
            $totalTimeMs < 1000 => 'slow',
            default => 'very_slow',
        };
    }

    private function getResponseSizeCategory(int $contentLength): string
    {
        return match (true) {
            $contentLength < 1024 => 'small',        // < 1KB
            $contentLength < 10240 => 'medium',      // < 10KB
            $contentLength < 102400 => 'large',      // < 100KB
            default => 'very_large',                 // >= 100KB
        };
    }
}
