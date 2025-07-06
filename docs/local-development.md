# Local Development Guide

## Using the Package in Your Symfony Project

### Option 1: Composer Path Repository (Recommended)

Add the package as a local path repository in your main `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/sentry-enhanced-tracing"
        }
    ],
    "require": {
        "amarc-sudo/sentry-enhanced-tracing": "dev-main"
    }
}
```

Then run:
```bash
composer require amarc-sudo/sentry-enhanced-tracing:dev-main
```

### Option 2: Direct Symlink

Create a symlink to your local package:

```bash
# From your project root
ln -s ./packages/sentry-enhanced-tracing vendor/amarc-sudo/sentry-enhanced-tracing
```

### Option 3: Composer Install from Local

```bash
# From your project root
composer config repositories.sentry-enhanced-tracing path ./packages/sentry-enhanced-tracing
composer require amarc-sudo/sentry-enhanced-tracing @dev
```

## Bundle Registration

Add to your `config/bundles.php`:

```php
<?php

return [
    // ... other bundles
    AmarcSudo\SentryEnhancedTracing\SentryEnhancedTracingBundle::class => ['all' => true],
];
```

## Testing the Integration

### 1. Create a Test Controller

```php
<?php
// src/Controller/TestSentryController.php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;

class TestSentryController extends AbstractController
{
    #[Route('/test-sentry', name: 'test_sentry')]
    public function testSentry(
        EntityManagerInterface $em,
        CacheInterface $cache
    ): JsonResponse {
        // This will create database spans under kernel.controller
        $users = $em->getRepository(User::class)->findAll();
        
        // This will create cache spans under kernel.controller
        $cached = $cache->get('test_key', function() {
            return 'cached_value';
        });
        
        // This will create template spans under kernel.response
        return $this->render('test_sentry.html.twig', [
            'users' => $users,
            'cached' => $cached,
        ]);
    }
}
```

### 2. Create a Test Template

```twig
{# templates/test_sentry.html.twig #}
<html>
<head>
    <title>Sentry Test</title>
</head>
<body>
    <h1>Sentry Enhanced Tracing Test</h1>
    <p>Users found: {{ users|length }}</p>
    <p>Cached value: {{ cached }}</p>
</body>
</html>
```

### 3. Test the Endpoint

```bash
# Make a request to see spans in Sentry
curl http://localhost:8000/test-sentry
```

## Expected Sentry Output

You should see in your Sentry dashboard:

```
GET /test-sentry (250ms)
├── Event Listeners Phase: kernel.request (15ms)
│   ├── db.query: SELECT user_id, username FROM users... (8ms)
│   └── cache.get: security:user:context (2ms)
├── Event Listeners Phase: kernel.controller (180ms)
│   ├── db.query: SELECT * FROM users (120ms)
│   ├── cache.get: test_key (5ms)
│   └── cache.set: test_key (3ms)
└── Event Listeners Phase: kernel.response (45ms)
    ├── twig.render: test_sentry.html.twig (40ms)
    └── cache.set: twig:template:hash (2ms)
```

## Development Commands

### Clear Cache
```bash
php bin/console cache:clear
```

### Debug Services
```bash
# Check if services are registered
php bin/console debug:container sentry
php bin/console debug:container AmarcSudo\\SentryEnhancedTracing
```

### Debug Event Listeners
```bash
# Check listener priorities
php bin/console debug:event-dispatcher kernel.request
php bin/console debug:event-dispatcher kernel.response
```

## Debugging Tips

### 1. Enable Debug Mode
```yaml
# config/packages/dev/sentry.yaml
sentry:
    options:
        debug: true
        traces_sample_rate: 1.0
```

### 2. Check Logs
```bash
tail -f var/log/dev.log | grep -i sentry
```

### 3. Verify Configuration
```bash
php bin/console debug:config sentry
php bin/console debug:config sentry_enhanced_tracing
```

### 4. Test Exception Handling
```php
// In your test controller
throw new \Exception('Test Sentry exception handling');
```

## Performance Testing

### Load Testing
```bash
# Use Apache Bench to test performance impact
ab -n 100 -c 10 http://localhost:8000/test-sentry
```

### Memory Usage
```bash
# Check memory usage
php bin/console debug:container --show-private | grep -i memory
```

## Common Issues

### Services Not Loading
- Check `bundles.php` registration
- Verify namespace in `composer.json`
- Clear cache after changes

### No Spans in Sentry
- Check DSN configuration
- Verify `traces_sample_rate` > 0
- Ensure Sentry integrations are enabled

### Priority Conflicts
- Check listener priorities with `debug:event-dispatcher`
- Verify compiler pass is executing
- Check service registration order

## Hot Reload Development

For faster development, use:

```bash
# Watch files for changes
php bin/console cache:clear && php -S localhost:8000 -t public/
```

Make changes to the package and refresh to see updates immediately. 