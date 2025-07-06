<?php

declare(strict_types=1);

namespace App\Controller;

use AmarcSudo\SentryEnhancedTracing\Attribute\TraceSentryController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Examples of using the TraceSentryController attribute for automatic tracing.
 */

// Example 1: Basic usage on invokable controller
#[TraceSentryController]
class DeleteUserResourceController extends AbstractController
{
    #[Route('/users/{id}/resources', methods: ['DELETE'])]
    public function __invoke(string $id): Response
    {
        // This controller execution will be automatically traced
        // All database queries, cache operations, etc. will be captured as child spans
        
        // Simulate some business logic
        $this->performDatabaseOperation($id);
        $this->clearCache($id);
        
        return new Response('', Response::HTTP_NO_CONTENT);
    }
    
    private function performDatabaseOperation(string $id): void
    {
        // Database operations will be automatically traced as child spans
        // Example: DELETE FROM user_resources WHERE user_id = ?
    }
    
    private function clearCache(string $id): void
    {
        // Cache operations will be automatically traced as child spans
        // Example: cache.delete('user_resources_' . $id)
    }
}

// Example 2: Custom operation name and description
#[TraceSentryController(
    operationName: 'api.user.bulk_update',
    description: 'Bulk update user resources operation'
)]
class BulkUpdateUserResourcesController extends AbstractController
{
    #[Route('/users/bulk-update', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // This will appear in Sentry with custom operation name and description
        $data = json_decode($request->getContent(), true);
        
        foreach ($data['users'] as $userData) {
            $this->updateUserResource($userData);
        }
        
        return new Response('Updated ' . count($data['users']) . ' users');
    }
    
    private function updateUserResource(array $userData): void
    {
        // Each database operation will be traced as a child span
    }
}

// Example 3: Adding custom tags for filtering in Sentry
#[TraceSentryController(
    operationName: 'api.user.analytics',
    description: 'Generate user analytics report',
    tags: [
        'feature' => 'analytics',
        'performance_critical' => true,
        'database_intensive' => true
    ]
)]
class UserAnalyticsController extends AbstractController
{
    #[Route('/users/{id}/analytics', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        // Complex analytics operation - good candidate for tracing
        $userStats = $this->calculateUserStats($id);
        $resourceStats = $this->calculateResourceStats($id);
        $trends = $this->calculateTrends($id);
        
        return $this->json([
            'user_stats' => $userStats,
            'resource_stats' => $resourceStats,
            'trends' => $trends
        ]);
    }
    
    private function calculateUserStats(string $id): array
    {
        // Multiple database queries will be traced
        return [];
    }
    
    private function calculateResourceStats(string $id): array
    {
        // Cache lookups and database queries will be traced
        return [];
    }
    
    private function calculateTrends(string $id): array
    {
        // Complex calculations and potential external API calls
        return [];
    }
}

// Example 4: Traditional controller with method-level tracing
class UserResourceController extends AbstractController
{
    #[Route('/users/{id}/resources', methods: ['GET'])]
    public function list(string $id): Response
    {
        // This method is NOT traced (no attribute)
        return $this->json(['resources' => []]);
    }
    
    #[TraceSentryController(
        operationName: 'api.user.resource.create',
        description: 'Create new user resource'
    )]
    #[Route('/users/{id}/resources', methods: ['POST'])]
    public function create(string $id, Request $request): Response
    {
        // Only THIS method will be traced (has the attribute)
        $data = json_decode($request->getContent(), true);
        
        $this->validateResourceData($data);
        $resource = $this->createResource($id, $data);
        
        return $this->json($resource, Response::HTTP_CREATED);
    }
    
    #[TraceSentryController(
        operationName: 'api.user.resource.update',
        tags: ['crud_operation' => 'update']
    )]
    #[Route('/users/{id}/resources/{resourceId}', methods: ['PUT'])]
    public function update(string $id, string $resourceId, Request $request): Response
    {
        // This method is also traced with custom tags
        $data = json_decode($request->getContent(), true);
        
        $resource = $this->updateResource($resourceId, $data);
        
        return $this->json($resource);
    }
    
    #[Route('/users/{id}/resources/{resourceId}', methods: ['DELETE'])]
    public function delete(string $id, string $resourceId): Response
    {
        // This method is NOT traced (no attribute)
        $this->deleteResource($resourceId);
        
        return new Response('', Response::HTTP_NO_CONTENT);
    }
    
    private function validateResourceData(array $data): void
    {
        // Validation logic - will be included in parent span
    }
    
    private function createResource(string $userId, array $data): array
    {
        // Database operations will be traced as child spans
        return [];
    }
    
    private function updateResource(string $resourceId, array $data): array
    {
        // Database operations will be traced as child spans
        return [];
    }
    
    private function deleteResource(string $resourceId): void
    {
        // Database operations will be traced as child spans
    }
}

/**
 * Benefits of using TraceSentryController attribute:
 * 
 * 1. **Explicit Control**: You decide exactly which controllers/methods to trace
 * 2. **Zero Configuration**: No setup required, just add the attribute
 * 3. **Flexible**: Works with any controller pattern (invokable, traditional methods)
 * 4. **Customizable**: Custom operation names, descriptions, and tags
 * 5. **Performance**: Only traced controllers have overhead
 * 6. **Child Spans**: Automatically captures database queries, cache operations, etc.
 * 7. **Rich Metadata**: Detailed information in Sentry for debugging
 * 8. **No Argument Resolution Issues**: Works with any method signature
 * 
 * What gets traced automatically:
 * - Controller execution time (from kernel.controller to kernel.response)
 * - Database queries (via Doctrine integration)
 * - Cache operations (via Symfony Cache integration)
 * - HTTP client requests (via Symfony HttpClient integration)
 * - Any other Sentry-integrated operations
 * 
 * Sentry span hierarchy:
 * - Main transaction (HTTP request)
 *   - Controller span (created by TraceSentryController)
 *     - Database query span (auto-captured)
 *     - Cache operation span (auto-captured)
 *     - HTTP client span (auto-captured)
 *     - ... other operations
 */ 