<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\DependencyInjection\Compiler;

use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\SentryBundle\EventListener\TracingRequestListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Compiler pass that adjusts Sentry listener priorities to ensure proper execution order
 *
 * This pass modifies the priority of various Sentry listeners to guarantee they execute
 * at the right moments during the request lifecycle. High priorities (99999-99997) are
 * assigned to ensure Sentry listeners capture data before or after other application
 * listeners, providing comprehensive tracing and error monitoring.
 *
 * Modified listeners:
 * - RequestListener: Captures request context and user data
 * - ErrorListener: Handles exceptions and error reporting
 * - TracingRequestListener: Manages distributed tracing spans
 * - MessengerListener: Monitors message queue failures
 *
 * Priority order ensures:
 * 1. Error and Request listeners get highest priority (99999)
 * 2. Tracing listeners execute early (99998)
 * 3. Secondary request handling (99997)
 * 4. Custom application listeners execute after Sentry setup
 */
final class ChangeSentryListenerPriorityPass implements CompilerPassInterface
{
    /**
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    public function process(ContainerBuilder $container): void
    {
        $this->updateListenerPriority(
            $container,
            RequestListener::class,
            [
             KernelEvents::REQUEST    => 99999,
             KernelEvents::CONTROLLER => 99999,
            ]
        );

        $this->updateListenerPriority(
            $container,
            ErrorListener::class,
            [KernelEvents::EXCEPTION => 99999]
        );

        $this->updateListenerPriority(
            $container,
            TracingRequestListener::class,
            [
             KernelEvents::REQUEST        => 99998,
             KernelEvents::FINISH_REQUEST => 99998,
            ]
        );

        $this->updateListenerPriority(
            $container,
            RequestListener::class,
            [
             KernelEvents::REQUEST    => 99997,
             KernelEvents::CONTROLLER => 99997,
            ]
        );

        $this->updateListenerPriority(
            $container,
            MessengerListener::class,
            ['messenger.message_failed' => 99999]
        );
    }

    /**
     * Updates the priority of event listeners for a specific service.
     *
     * @param ContainerBuilder $container Service container
     * @param string $listenerClass FQCN of the listener service
     * @param array<string, int> $eventPriorities Array mapping event names to their new priorities
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    private function updateListenerPriority(
        ContainerBuilder $container,
        string $listenerClass,
        array $eventPriorities
    ): void {
        if (!$container->hasDefinition($listenerClass)) {
            return;
        }

        $definition = $container->getDefinition($listenerClass);
        $tags = $definition->getTags();

        if (!isset($tags['kernel.event_listener'])) {
            return;
        }

        foreach ($tags['kernel.event_listener'] as &$tag) {
            if (isset($eventPriorities[$tag['event']])) {
                $tag['priority'] = $eventPriorities[$tag['event']];
            }
        }

        unset($tag);
        $definition->setTags($tags);
    }
}
