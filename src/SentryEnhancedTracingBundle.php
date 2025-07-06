<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing;

use AmarcSudo\SentryEnhancedTracing\DependencyInjection\Compiler\ChangeSentryListenerPriorityPass;
use AmarcSudo\SentryEnhancedTracing\DependencyInjection\SentryEnhancedTracingExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Enhanced Sentry tracing bundle for Symfony applications
 *
 * Provides:
 * - Automatic child span capture for database, cache, and template operations
 * - Listener phase tracking with performance metrics
 * - Enhanced user context and request tracing
 * - Optimized Sentry listener priorities
 */
class SentryEnhancedTracingBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Add the compiler pass to adjust Sentry listener priorities
        $container->addCompilerPass(new ChangeSentryListenerPriorityPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SentryEnhancedTracingExtension();
    }
}
