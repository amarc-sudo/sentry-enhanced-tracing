# Configuration Guide

## Basic Setup

### 1. Install the Bundle

```bash
composer require amarc-sudo/sentry-enhanced-tracing
```

### 2. Enable in Symfony

Add to `config/bundles.php`:

```php
<?php

return [
    // ... other bundles
    AmarcSudo\SentryEnhancedTracing\SentryEnhancedTracingBundle::class => ['all' => true],
];
```

### 3. Configure Sentry (Required)

Make sure you have Sentry properly configured:

```yaml
# config/packages/sentry.yaml
sentry:
    dsn: '%env(SENTRY_DSN)%'
    options:
        # Enhanced tracing options
        traces_sample_rate: 1.0
        profiles_sample_rate: 1.0
        max_breadcrumbs: 100
        attach_stacktrace: true
        send_default_pii: true
        max_request_body_size: 'medium'
        context_lines: 7
        integrations:
            - 'Sentry\Integration\RequestIntegration'
            - 'Sentry\Integration\TransactionIntegration'
            - 'Sentry\Integration\FrameContextifierIntegration'
```

### 4. Configure Monolog (Recommended)

For breadcrumbs and enhanced logging:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        sentry_breadcrumbs:
            type: sentry
            level: info
            hub_id: Sentry\State\HubInterface
            fill_extra_context: true
            
        sentry_events:
            type: sentry
            level: warning
            hub_id: Sentry\State\HubInterface
```

## Advanced Configuration

### Custom Event Phases

You can customize which event phases are tracked:

```yaml
# config/packages/sentry_enhanced_tracing.yaml
sentry_enhanced_tracing:
    tracked_events:
        - 'kernel.request'
        - 'kernel.controller'  
        - 'kernel.view'
        - 'kernel.response'
        - 'kernel.exception'
```

### Performance Thresholds

Customize performance categorization:

```yaml
sentry_enhanced_tracing:
    performance_thresholds:
        kernel.request: 0.05    # 50ms
        kernel.controller: 0.1  # 100ms
        kernel.view: 0.02       # 20ms
        kernel.response: 0.05   # 50ms
```

### User Context Settings

Configure user context capture:

```yaml
sentry_enhanced_tracing:
    user_context:
        capture_user_id: true
        capture_username: true
        capture_email: false    # PII - be careful!
        capture_ip: true
        capture_user_agent: true
```

## Environment Variables

Set these in your `.env` file:

```env
# Required
SENTRY_DSN=https://your-sentry-dsn@sentry.io/project-id

# Optional - for enhanced tracing
SENTRY_TRACES_SAMPLE_RATE=1.0
SENTRY_PROFILES_SAMPLE_RATE=1.0
SENTRY_SEND_DEFAULT_PII=false
```

## Verification

After installation, you should see in your Sentry dashboard:

1. **Transactions** with event phase spans
2. **Database queries** nested under appropriate phases
3. **Cache operations** as child spans
4. **Template rendering** performance
5. **Rich breadcrumbs** with execution metrics

## Troubleshooting

### No Spans Appearing

1. Check Sentry configuration is correct
2. Verify `traces_sample_rate` is > 0
3. Ensure bundle is enabled in `bundles.php`
4. Check logs for any errors

### Performance Issues

1. Reduce `traces_sample_rate` in production
2. Adjust `max_breadcrumbs` if needed
3. Consider disabling in high-traffic endpoints

### Missing Child Spans

1. Ensure Sentry integrations are enabled
2. Check that DBAL/Cache are properly configured
3. Verify listener priorities are not conflicting

## Best Practices

1. **Production**: Use sampling (`traces_sample_rate: 0.1`)
2. **Development**: Use full tracing (`traces_sample_rate: 1.0`)
3. **Security**: Be careful with PII data capture
4. **Performance**: Monitor overhead in high-traffic apps 