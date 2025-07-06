<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Extension for SentryEnhancedTracing bundle.
 *
 * This extension loads the bundle's service configuration and registers
 * the enhanced tracing listeners with the Symfony dependency injection container.
 */
class SentryEnhancedTracingExtension extends Extension
{
    /**
     * Loads the bundle configuration.
     *
     * @param array<array-key, array<array-key, mixed>> $configs Configuration arrays
     * @param ContainerBuilder $container Service container
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        // For now, we don't process configuration, but it might be needed in the future
        unset($configs);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );

        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'sentry_enhanced_tracing';
    }
}
