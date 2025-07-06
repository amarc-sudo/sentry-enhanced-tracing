<?php

// Example: Basic usage of Sentry Enhanced Tracing Bundle

// 1. Install the bundle
// composer require amarc-sudo/sentry-enhanced-tracing

// 2. Register in bundles.php
// AmarcSudo\SentryEnhancedTracing\SentryEnhancedTracingBundle::class => ['all' => true],

// 3. Configure Sentry (config/packages/sentry.yaml)
/*
sentry:
    dsn: '%env(SENTRY_DSN)%'
    options:
        traces_sample_rate: 1.0
        max_breadcrumbs: 100
        attach_stacktrace: true
*/

// 4. Create a controller that will generate spans
namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;

class ExampleController extends AbstractController
{
    #[Route('/api/example', name: 'api_example')]
    public function example(
        EntityManagerInterface $em,
        CacheInterface $cache
    ): JsonResponse {
        // Database operations - will appear as spans under kernel.controller
        $users = $em->createQuery('SELECT u FROM App\Entity\User u')
            ->getResult();
        
        // Cache operations - will appear as spans under kernel.controller
        $result = $cache->get('example_key', function() {
            return ['data' => 'cached_value', 'timestamp' => time()];
        });
        
        // Template rendering - will appear as spans under kernel.response
        return $this->json([
            'users_count' => count($users),
            'cached_data' => $result,
            'timestamp' => time()
        ]);
    }
}

// 5. What you'll see in Sentry:
/*
POST /api/example (150ms)
├── Event Listeners Phase: kernel.request (20ms)
│   ├── db.query: SELECT user_context... (5ms)
│   └── cache.get: security:user:123 (2ms)
├── Event Listeners Phase: kernel.controller (100ms)
│   ├── db.query: SELECT u FROM App\Entity\User u (80ms)
│   ├── cache.get: example_key (5ms)
│   └── cache.set: example_key (3ms)
└── Event Listeners Phase: kernel.response (25ms)
    ├── serializer.normalize: JsonResponse (20ms)
    └── cache.set: response:cache:hash (2ms)
*/

// 6. Advanced: Custom spans within your business logic
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

class BusinessService
{
    public function processData(array $data): array
    {
        // This span will be a child of whatever event phase is currently active
        $hub = SentrySdk::getCurrentHub();
        $span = $hub->getSpan();
        
        if ($span) {
            $spanContext = new SpanContext();
            $spanContext->setOp('business.process');
            $spanContext->setDescription('Processing business data');
            
            $childSpan = $span->startChild($spanContext);
            
            try {
                // Your business logic here
                $result = $this->heavyComputation($data);
                
                $childSpan->setData([
                    'input_count' => count($data),
                    'output_count' => count($result),
                    'processing_time' => microtime(true) - $childSpan->getStartTimestamp(),
                ]);
                
                return $result;
            } finally {
                $childSpan->finish();
            }
        }
        
        return $this->heavyComputation($data);
    }
    
    private function heavyComputation(array $data): array
    {
        // Simulate heavy computation
        usleep(100000); // 100ms
        return array_map('strtoupper', $data);
    }
}

// 7. Result: Your custom spans will appear hierarchically:
/*
├── Event Listeners Phase: kernel.controller (200ms)
│   ├── db.query: SELECT ... (50ms)
│   ├── business.process: Processing business data (100ms)
│   │   └── cache.get: computation:cache:key (5ms)
│   └── cache.set: result:cache (3ms)
*/ 