<?php
/**
 * Session Middleware
 * 
 * Initializes PHP sessions with secure cookie parameters.
 * Must run before any middleware that needs session access.
 */

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SessionMiddleware implements MiddlewareInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            $sessionConfig = $this->config['session'] ?? [];

            // Set session name
            session_name($sessionConfig['name'] ?? 'ed_session');

            // Configure secure cookie parameters
            session_set_cookie_params([
                'lifetime' => $sessionConfig['lifetime'] ?? 0,
                'path' => $sessionConfig['path'] ?? '/everydollar',
                'domain' => $sessionConfig['domain'] ?? '',
                'secure' => $sessionConfig['secure'] ?? true,
                'httponly' => $sessionConfig['httponly'] ?? true,
                'samesite' => $sessionConfig['samesite'] ?? 'Lax',
            ]);

            session_start();

            // Regenerate session ID periodically to prevent fixation
            $this->maybeRegenerateSession();
        }

        return $handler->handle($request);
    }

    /**
     * Regenerate session ID if it's older than 30 minutes
     * Helps prevent session fixation attacks
     */
    private function maybeRegenerateSession(): void
    {
        $regenerateInterval = 1800; // 30 minutes

        if (!isset($_SESSION['_last_regenerate'])) {
            $_SESSION['_last_regenerate'] = time();
            return;
        }

        if (time() - $_SESSION['_last_regenerate'] > $regenerateInterval) {
            session_regenerate_id(true);
            $_SESSION['_last_regenerate'] = time();
        }
    }

    /**
     * Force session regeneration (call after login)
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_last_regenerate'] = time();
        }
    }

    /**
     * Destroy the session completely (call on logout)
     */
    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            // Delete the session cookie
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
        }
    }
}
