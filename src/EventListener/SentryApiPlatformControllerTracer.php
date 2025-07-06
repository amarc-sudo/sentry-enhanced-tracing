<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\EventListener;

use AmarcSudo\SentryEnhancedTracing\Attribute\TraceSentryController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Traces controllers marked with the TraceSentryController attribute.
 *
 * This listener detects controllers or methods annotated with #[TraceSentryController]
 * and automatically measures their PRECISE execution time with Sentry spans.
 *
 * PRECISE TIMING MEASUREMENT:
 * ===========================
 * Uses extreme event priorities to measure ONLY controller execution:
 * - Start: kernel.controller with priority -99999 (just before controller execution)
 * - End: kernel.view with priority 99999 (just after controller execution)
 * - Fallback: kernel.response with priority 99999 (if no view event)
 *
 * This ensures the span captures ONLY the controller business logic,
 * excluding other listeners, view processing, and response processing.
 *
 * Features:
 * - Attribute-based activation (explicit control)
 * - Measures ONLY controller execution time (not listeners)
 * - Captures child spans (DB queries, cache operations, etc.)
 * - Adds detailed metadata to Sentry spans
 * - Works with any controller method signature
 * - Zero configuration required
 * - Automatic cleanup on exceptions
 *
 * Expected Sentry hierarchy:
 * ```
 * POST /api/endpoint (500ms)
 * ├── Event Listeners Phase: kernel.request (50ms)
 * ├── controller.traced: MyController::__invoke (23.6ms) ← PRECISE!
 * │   ├── db.sql.prepare: SELECT ... (0.01ms)
 * │   ├── db.sql.execute: SELECT ... (1.30ms)
 * │   └── db.sql.transaction.commit: COMMIT (1.52ms)
 * ├── Event Listeners Phase: kernel.view (200ms)
 * └── Event Listeners Phase: kernel.response (100ms)
 * ```
 *
 * Usage:
 * ```php
 * #[TraceSentryController]
 * class MyController extends AbstractController
 * {
 *     public function __invoke(Request $request): Response
 *     {
 *         // This execution will be automatically traced with precise timing
 *         return new Response('Hello World');
 *     }
 * }
 * ```
 */
#[AsEventListener(event: ControllerEvent::class, method: 'onKernelController')]
#[AsEventListener(event: ViewEvent::class, method: 'onKernelView')]
#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponse')]
#[AsEventListener(event: ExceptionEvent::class, method: 'onKernelException')]
class SentryApiPlatformControllerTracer
{
    private ?\Sentry\Tracing\Span $activeSpan = null;
    private ?string $controllerName = null;
    private ?\Sentry\Tracing\Span $previousSpan = null;
    private bool $spanFinished = false;

    /**
     * Starts Sentry tracing if controller has TraceSentryController attribute.
     *
     * Uses very low priority (-99999) to execute just BEFORE the controller,
     * ensuring we measure only the controller execution time.
     * 
     * @throws \ReflectionException
     */
    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        $attribute = $this->getTraceSentryAttribute($controller);

        if (!$attribute) {
            return;
        }

        // Store the controller info for later use
        $this->controllerName = $this->getControllerDisplayName($controller);
        $this->spanFinished = false;

        // Get the Sentry hub and save the current span
        $hub = \Sentry\SentrySdk::getCurrentHub();
        $this->previousSpan = $hub->getSpan();

        // Start Sentry span as child of current transaction
        $transaction = $hub->getTransaction();
        if (!$transaction) {
            return;
        }

        $spanContext = \Sentry\Tracing\SpanContext::make()
            ->setOp($attribute->operationName ?? 'controller.traced')
            ->setDescription($attribute->description ?? $this->controllerName);

        $this->activeSpan = $transaction->startChild($spanContext);

        // IMPORTANT: Set this span as the current span in the Sentry Hub
        // This allows all database queries, cache operations, etc. to become
        // children of this controller span automatically
        $hub->setSpan($this->activeSpan);

        // Add metadata to the span
        $this->activeSpan->setData([
            'controller.name' => $this->controllerName,
            'controller.traced_by' => 'TraceSentryController',
            'controller.type' => $this->getControllerType($controller),
            'controller.start_time' => microtime(true),
            ...$attribute->tags
        ]);

        // Add breadcrumb
        \Sentry\addBreadcrumb(
            category: 'controller.traced',
            message: 'Starting traced controller: ' . $this->controllerName,
            metadata: [
                'controller_name' => $this->controllerName,
                'operation_name' => $attribute->operationName ?? 'controller.traced',
                'custom_tags' => $attribute->tags,
            ],
            level: \Sentry\Breadcrumb::LEVEL_INFO
        );
    }

    /**
     * Finishes Sentry tracing when view processing starts (right after controller execution).
     * This provides the most precise measurement of controller execution time.
     */
    public function onKernelView(ViewEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->activeSpan || $this->spanFinished) {
            return;
        }

        $this->finishControllerSpan('view', [
            'controller_result_type' => get_debug_type($event->getControllerResult()),
            'view_triggered' => true,
        ]);
    }

    /**
     * Finishes Sentry tracing when response is ready (fallback if no view event).
     * Uses high priority to execute just after controller execution.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->activeSpan || $this->spanFinished) {
            return;
        }

        $response = $event->getResponse();
        $this->finishControllerSpan('response', [
            'response_status' => $response->getStatusCode(),
            'response_content_type' => $response->headers->get('Content-Type'),
            'view_triggered' => false,
        ]);
    }

    /**
     * Handles exceptions and ensures span cleanup.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->activeSpan || $this->spanFinished) {
            return;
        }

        $exception = $event->getThrowable();
        $this->finishControllerSpan('exception', [
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
        ], 'error');
    }

    /**
     * Finishes the controller span with precise timing and metadata.
     */
    private function finishControllerSpan(string $trigger, array $endMetadata = [], string $status = 'success'): void
    {
        if ($this->spanFinished || !$this->activeSpan) {
            return;
        }

        // Calculate execution duration
        $startTime = $this->activeSpan->getData()['controller.start_time'] ?? null;
        $duration = $startTime ? microtime(true) - $startTime : null;

        // Add end metadata
        $this->activeSpan->setData([
            'controller.execution_status' => $status,
            'controller.end_time' => microtime(true),
            'controller.duration' => $duration,
            'controller.end_trigger' => $trigger,
            ...$endMetadata,
        ]);

        // Finish the span
        $this->activeSpan->finish();
        $this->spanFinished = true;

        // IMPORTANT: Restore the previous span in the Sentry Hub
        $hub = \Sentry\SentrySdk::getCurrentHub();
        $hub->setSpan($this->previousSpan);

        // Add completion breadcrumb
        $breadcrumbLevel = $status === 'error' ? \Sentry\Breadcrumb::LEVEL_ERROR : \Sentry\Breadcrumb::LEVEL_INFO;
        $message = $status === 'error' ?
            'Controller threw exception: ' . $this->controllerName :
            'Completed traced controller: ' . $this->controllerName;

        \Sentry\addBreadcrumb(
            category: 'controller.traced',
            message: $message,
            metadata: [
                'controller_name' => $this->controllerName,
                'execution_status' => $status,
                'duration_ms' => $duration ? round($duration * 1000, 2) : null,
                'end_trigger' => $trigger,
                'child_spans_captured' => true,
                ...$endMetadata,
            ],
            level: $breadcrumbLevel
        );

        // Clean up
        $this->activeSpan = null;
        $this->controllerName = null;
        $this->previousSpan = null;
        $this->spanFinished = false;
    }

    /**
     * Gets the TraceSentryController attribute from a controller.
     * 
     * @throws \ReflectionException
     */
    private function getTraceSentryAttribute(mixed $controller): ?TraceSentryController
    {
        // Handle different controller formats
        if (is_array($controller) && count($controller) === 2) {
            // Controller is [object, method] format
            [$controllerObject, $methodName] = $controller;

            if (!is_object($controllerObject)) {
                return null;
            }

            // Check method attribute first
            try {
                $reflectionMethod = new \ReflectionMethod($controllerObject, $methodName);
                $methodAttributes = $reflectionMethod->getAttributes(TraceSentryController::class);

                if (!empty($methodAttributes)) {
                    return $methodAttributes[0]->newInstance();
                }
            } catch (\ReflectionException) {
                // Method doesn't exist, continue to class check
            }

            // Check class attribute
            $reflectionClass = new \ReflectionClass($controllerObject);
        } elseif (is_object($controller)) {
            // Controller is invokable object
            $reflectionClass = new \ReflectionClass($controller);

            // Check __invoke method attribute first
            if ($reflectionClass->hasMethod('__invoke')) {
                $reflectionMethod = $reflectionClass->getMethod('__invoke');
                $methodAttributes = $reflectionMethod->getAttributes(TraceSentryController::class);

                if (!empty($methodAttributes)) {
                    return $methodAttributes[0]->newInstance();
                }
            }
        } else {
            // Controller is string or other format - not supported
            return null;
        }

        // Check class attribute
        $classAttributes = $reflectionClass->getAttributes(TraceSentryController::class);

        if (!empty($classAttributes)) {
            return $classAttributes[0]->newInstance();
        }

        return null;
    }

    /**
     * Gets a display name for the controller.
     */
    private function getControllerDisplayName(mixed $controller): string
    {
        if (is_array($controller) && count($controller) === 2) {
            [$controllerObject, $methodName] = $controller;
            $className = is_object($controllerObject) ? get_class($controllerObject) : (string)$controllerObject;
            $shortName = substr($className, strrpos($className, '\\') + 1);
            return $shortName . '::' . $methodName;
        }

        if (is_object($controller)) {
            $className = get_class($controller);
            $shortName = substr($className, strrpos($className, '\\') + 1);
            return $shortName . '::__invoke';
        }

        return (string)$controller;
    }

    /**
     * Gets the controller type for metadata.
     */
    private function getControllerType(mixed $controller): string
    {
        if (is_array($controller)) {
            return 'action_method';
        }

        if (is_object($controller)) {
            return 'invokable_object';
        }

        return 'other';
    }
}
