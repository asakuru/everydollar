<?php
/**
 * CSRF Protection Middleware
 * 
 * Implements synchronizer token pattern for CSRF protection.
 * Validates tokens on state-changing requests (POST, PUT, DELETE, PATCH).
 */

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CsrfMiddleware implements MiddlewareInterface
{
    private array $config;
    private const TOKEN_LENGTH = 32;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Ensure CSRF token exists in session
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->generateToken();
        }

        // Check token on state-changing requests
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            if (!$this->validateRequest($request)) {
                return $this->forbiddenResponse();
            }
        }

        return $handler->handle($request);
    }

    /**
     * Generate a cryptographically secure random token
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    /**
     * Validate CSRF token from request
     */
    private function validateRequest(ServerRequestInterface $request): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        // Check form field first
        $parsedBody = $request->getParsedBody();
        $submittedToken = $parsedBody['_csrf'] ?? null;

        // Fall back to header (for AJAX requests)
        if ($submittedToken === null) {
            $submittedToken = $request->getHeaderLine('X-CSRF-Token');
        }

        if (empty($submittedToken) || empty($sessionToken)) {
            return false;
        }

        // Use timing-safe comparison
        return hash_equals($sessionToken, $submittedToken);
    }

    /**
     * Return 403 Forbidden response
     */
    private function forbiddenResponse(): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'error' => 'CSRF token validation failed. Please refresh the page and try again.'
        ]));

        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Regenerate CSRF token (call after sensitive actions)
     */
    public static function regenerateToken(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    /**
     * Get current CSRF token
     */
    public static function getToken(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }
}
