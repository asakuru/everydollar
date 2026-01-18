<?php
/**
 * Authentication Middleware
 * 
 * Protects routes that require a logged-in user.
 * Redirects unauthenticated requests to login page.
 */

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if user is logged in
        if (!$this->isAuthenticated()) {
            return $this->redirectToLogin($request);
        }

        // Add user data to request attributes for controllers
        $request = $request->withAttribute('user_id', $_SESSION['user_id'] ?? null);
        $request = $request->withAttribute('household_id', $_SESSION['household_id'] ?? null);
        $request = $request->withAttribute('user_role', $_SESSION['user_role'] ?? null);
        $request = $request->withAttribute('user_name', $_SESSION['user_name'] ?? null);

        return $handler->handle($request);
    }

    /**
     * Check if user is authenticated
     */
    private function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id'])
            && isset($_SESSION['household_id'])
            && !empty($_SESSION['user_id'])
            && !empty($_SESSION['household_id']);
    }

    /**
     * Redirect to login page
     */
    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        $response = new Response();

        // Store intended destination for redirect after login
        $uri = $request->getUri();
        $intendedPath = $uri->getPath();
        if ($uri->getQuery()) {
            $intendedPath .= '?' . $uri->getQuery();
        }
        $_SESSION['intended_url'] = $intendedPath;

        return $response
            ->withHeader('Location', BASE_PATH . '/login')
            ->withStatus(302);
    }

    /**
     * Get the authenticated user's ID
     */
    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get the authenticated user's household ID
     */
    public static function householdId(): ?int
    {
        return $_SESSION['household_id'] ?? null;
    }

    /**
     * Check if user has a specific role
     */
    public static function hasRole(string $role): bool
    {
        return ($_SESSION['user_role'] ?? '') === $role;
    }

    /**
     * Check if user is household owner
     */
    public static function isOwner(): bool
    {
        return self::hasRole('owner');
    }
}
