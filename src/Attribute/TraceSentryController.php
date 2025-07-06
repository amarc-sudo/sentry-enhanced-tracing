<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\Attribute;

/**
 * Attribute to automatically trace controller execution with Sentry.
 *
 * This attribute can be applied to controller classes or methods to enable
 * automatic Sentry tracing with performance monitoring.
 *
 * Usage:
 * ```php
 * #[TraceSentryController]
 * class MyController extends AbstractController
 * {
 *     public function __invoke(): Response
 *     {
 *         // This controller execution will be automatically traced
 *         return new Response('Hello World');
 *     }
 * }
 * ```
 *
 * Or on specific methods:
 * ```php
 * class MyController extends AbstractController
 * {
 *     #[TraceSentryController]
 *     public function index(): Response
 *     {
 *         // Only this method will be traced
 *         return new Response('Hello World');
 *     }
 * }
 * ```
 *
 * Features:
 * - Measures controller execution time
 * - Captures database queries, cache operations, and other child spans
 * - Adds detailed metadata to Sentry spans
 * - Zero configuration required
 * - Works with dependency injection
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class TraceSentryController
{
    /**
     * @param string|null $operationName Custom operation name for the Sentry span
     * @param string|null $description Custom description for the Sentry span
     * @param array $tags Additional tags to add to the Sentry span
     */
    public function __construct(
        public readonly ?string $operationName = null,
        public readonly ?string $description = null,
        public readonly array $tags = []
    ) {
    }
}
