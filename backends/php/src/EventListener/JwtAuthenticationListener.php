<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\JwtService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * JWT Authentication Event Listener.
 *
 * This listener intercepts all incoming requests and validates JWT tokens
 * from the Authorization header. Protected endpoints require a valid JWT token.
 */
class JwtAuthenticationListener
{
    /**
     * List of routes that don't require JWT authentication.
     */
    private const array PUBLIC_ROUTES = [
        // renew auth token route, you need chech auth data from bitrix24 and get new token
        '/api/getToken',
        // health check route from monitoring
        '/api/health',
        // install application route, you need check auth data from bitrix24 and install application
        '/api/install',
        // server side application lifecycle events from bitrix24
        '/api/app-events',
        // server side events from bitrix24
        '/api/custom-b24-events',
    ];

    public function __construct(
        private readonly JwtService $jwtService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle request event and validate JWT token.
     */
    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        $request = $requestEvent->getRequest();
        $path = $request->getPathInfo();

        // Skip authentication for public routes
        if ($this->isPublicRoute($path)) {
            $this->logger->debug('JwtAuthenticationListener: Public route, skipping authentication', [
                'path' => $path,
            ]);

            return;
        }

        // Extract token from Authorization header
        $authHeader = $request->headers->get('Authorization');

        if (null === $authHeader) {
            $this->logger->warning('JwtAuthenticationListener: Missing Authorization header', [
                'path' => $path,
            ]);

            $requestEvent->setResponse(new JsonResponse([
                'error' => 'Missing Authorization header',
                'message' => 'Please provide a valid JWT token in the Authorization header',
            ], 401));

            return;
        }

        // Parse Bearer token
        if (!str_starts_with($authHeader, 'Bearer ')) {
            $this->logger->warning('JwtAuthenticationListener: Invalid Authorization header format', [
                'path' => $path,
                'header' => $authHeader,
            ]);

            $requestEvent->setResponse(new JsonResponse([
                'error' => 'Invalid Authorization header format',
                'message' => 'Authorization header must start with "Bearer "',
            ], 401));

            return;
        }

        $token = substr($authHeader, 7); // Remove "Bearer " prefix

        // Validate token
        if (!$this->jwtService->validateToken($token)) {
            $this->logger->warning('JwtAuthenticationListener: Invalid or expired token', [
                'path' => $path,
            ]);

            $requestEvent->setResponse(new JsonResponse([
                'error' => 'Invalid or expired token',
                'message' => 'Please obtain a new token via POST /api/getToken',
            ], 401));

            return;
        }

        // Decode token and store payload in request attributes
        $payload = $this->jwtService->decodeToken($token);

        if (null !== $payload) {
            $request->attributes->set('jwt_payload', $payload);
            $request->attributes->set('jwt_domain', $payload['domain'] ?? null);
            $request->attributes->set('jwt_member_id', $payload['member_id'] ?? null);

            $this->logger->debug('JwtAuthenticationListener: Token validated successfully', [
                'path' => $path,
                'domain' => $payload['domain'] ?? 'unknown',
                'member_id' => $payload['member_id'] ?? null,
            ]);
        }
    }

    /**
     * Check if route is public (doesn't require authentication).
     *
     * @param string $path Request path
     *
     * @return bool True if route is public
     */
    private function isPublicRoute(string $path): bool
    {
        foreach (self::PUBLIC_ROUTES as $publicRoute) {
            if (str_starts_with($path, $publicRoute)) {
                return true;
            }
        }

        return false;
    }
}
