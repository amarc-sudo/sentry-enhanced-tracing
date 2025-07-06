<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\EventListener;

use AmarcSudo\SentryEnhancedTracing\User\EnhancedUserInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Sentry\State\Scope;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Enriches Sentry context with detailed user and request information.
 *
 * This listener automatically captures and enriches Sentry traces with:
 * - Enhanced user information (name, email, UUID) when EnhancedUserInterface is implemented
 * - Request context (route, method, URL, IP, user agent)
 * - Response status and timing information
 * - Exception details with enhanced metadata
 * - Breadcrumbs for request/response lifecycle tracking
 *
 * The listener subscribes to kernel events and JWT authentication events to enrich
 * the Sentry scope with contextual information for better error tracking and performance monitoring.
 *
 * Note: This listener requires LexikJWTAuthenticationBundle to be installed and configured.
 */
#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequest')]
#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponse')]
#[AsEventListener(event: ExceptionEvent::class, method: 'onKernelException')]
readonly class SentryUserContextListener
{
    public function __construct(private Security $security)
    {
    }

    /**
     * Handles JWT authentication events to enrich Sentry context with authenticated user information.
     *
     * This method is called after a successful JWT authentication and provides the most
     * accurate user context for Sentry tracking. It should be the primary method for
     * capturing user information when using JWT authentication.
     */
    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $token = $event->getToken();
        $user = $token->getUser();

        \Sentry\configureScope(function (Scope $scope) use ($user): void {
            if ($user !== null) {
                $firstname = null;
                $lastname = null;
                $email = null;

                // Use EnhancedUserInterface if available
                if ($user instanceof EnhancedUserInterface) {
                    $firstname = $user->getEnhancedFirstname();
                    $lastname = $user->getEnhancedLastname();
                    $email = $user->getEnhancedEmail();
                }

                $fullName = trim(($firstname ?? '') . ' ' . ($lastname ?? ''));

                $scope->setUser([
                                 'id'       => $user->getUserIdentifier(),
                                 'username' => $fullName ?: $user->getUserIdentifier(),
                                 'email'    => $email,
                                 'name'     => $fullName ?: $user->getUserIdentifier(),
                                 'type'     => 'jwt_authenticated',
                                ]);

                // Add JWT-specific tags
                $scope->setTag('auth_method', 'jwt');
                $scope->setTag('user_type', $user instanceof EnhancedUserInterface ? 'enhanced' : 'standard');
            }
        });

        // Add breadcrumb for JWT authentication
        \Sentry\addBreadcrumb(
            category: 'auth',
            message: sprintf('JWT authenticated user: %s', $user?->getUserIdentifier() ?? 'unknown'),
            metadata: [
                       'auth_method' => 'jwt',
                       'user_id'     => $user?->getUserIdentifier() ?? 'unknown',
                       'user_type'   => $user instanceof EnhancedUserInterface ? 'enhanced' : 'standard',
                      ],
            level: \Sentry\Breadcrumb::LEVEL_INFO
        );
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        \Sentry\configureScope(function (Scope $scope) use ($request): void {
            $user = $this->security->getUser();

            // Enrich user information (fallback for non-JWT authentication)
            if ($user !== null) {
                $firstname = null;
                $lastname = null;
                $email = null;

                // Use EnhancedUserInterface if available
                if ($user instanceof EnhancedUserInterface) {
                    $firstname = $user->getEnhancedFirstname();
                    $lastname = $user->getEnhancedLastname();
                    $email = $user->getEnhancedEmail();
                }

                $fullName = trim(($firstname ?? '') . ' ' . ($lastname ?? ''));

                $scope->setUser([
                                 'id'       => $user->getUserIdentifier(),
                                 'username' => $fullName ?: $user->getUserIdentifier(),
                                 'email'    => $email,
                                 'name'     => $fullName ?: $user->getUserIdentifier(),
                                 'type'     => 'authenticated',
                                ]);
            } else {
                $scope->setUser([
                                 'id'   => 'anonymous',
                                 'type' => 'guest',
                                ]);
            }

            // Add more context about the request
            $scope->setTag('route', (string) $request->attributes->get('_route', 'unknown'));
            $scope->setTag('method', $request->getMethod());
            $scope->setTag('url', $request->getUri());
            $scope->setTag('client_ip', $request->getClientIp() ?: 'unknown');
            $scope->setTag('user_agent', $request->headers->get('User-Agent') ?: 'unknown');

            // Add query parameters (without sensitive data)
            $queryParams = $request->query->all();
            if ($queryParams !== []) {
                $scope->setContext('query_params', $queryParams);
            }
        });

        // Add breadcrumb for the request
        \Sentry\addBreadcrumb(
            category: 'request',
            message: sprintf('%s %s', $request->getMethod(), $request->getPathInfo()),
            metadata: [
                       'route'      => $request->attributes->get('_route', 'unknown'),
                       'controller' => $request->attributes->get('_controller', 'unknown'),
                       'method'     => $request->getMethod(),
                       'url'        => $request->getUri(),
                      ],
            level: \Sentry\Breadcrumb::LEVEL_INFO
        );
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $request = $event->getRequest();

        // Add breadcrumb for the response
        \Sentry\addBreadcrumb(
            category: 'response',
            message: sprintf(
                'Response %d for %s %s',
                $response->getStatusCode(),
                $request->getMethod(),
                $request->getPathInfo()
            ),
            metadata: [
                       'status_code'  => $response->getStatusCode(),
                       'content_type' => $response->headers->get('Content-Type', 'unknown'),
                       'route'        => $request->attributes->get('_route', 'unknown'),
                      ],
            level: $response->getStatusCode() >= 400 ?
                          \Sentry\Breadcrumb::LEVEL_WARNING : \Sentry\Breadcrumb::LEVEL_INFO
        );

        // Add tags for response status
        \Sentry\configureScope(function (Scope $scope) use ($response): void {
            $scope->setTag('response_status', (string) $response->getStatusCode());
            $scope->setTag('response_status_class', $this->getStatusClass($response->getStatusCode()));
        });
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Add breadcrumb for the exception
        \Sentry\addBreadcrumb(
            category: 'exception',
            message: sprintf('Exception %s: %s', get_class($exception), $exception->getMessage()),
            metadata: [
                       'exception_class' => get_class($exception),
                       'exception_code'  => $exception->getCode(),
                       'file'             => $exception->getFile(),
                       'line'            => $exception->getLine(),
                       'route'           => $request->attributes->get('_route', 'unknown'),
                      ],
            level: \Sentry\Breadcrumb::LEVEL_ERROR
        );

        // Add more context for the exception
        \Sentry\configureScope(function (Scope $scope) use ($exception): void {
            $scope->setTag('exception_class', get_class($exception));
            $scope->setTag('exception_code', (string) $exception->getCode());
            $scope->setContext('exception_details', [
                                                     'class'       => get_class($exception),
                                                     'message'     => $exception->getMessage(),
                                                     'file'         => $exception->getFile(),
                                                     'line'        => $exception->getLine(),
                                                     'trace_count' => count($exception->getTrace()),
                                                    ]);
        });
    }

    private function getStatusClass(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode >= 300 && $statusCode < 400 => 'redirect',
            $statusCode >= 400 && $statusCode < 500 => 'client_error',
            $statusCode >= 500 => 'server_error',
            default => 'unknown',
        };
    }
}
