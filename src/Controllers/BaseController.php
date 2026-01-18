<?php
/**
 * Base Controller
 * 
 * Provides common functionality for all controllers.
 */

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Views\Twig;

abstract class BaseController
{
    protected Twig $twig;

    public function __construct(Twig $twig)
    {
        $this->twig = $twig;
    }

    /**
     * Render a Twig template
     */
    protected function render(Response $response, string $template, array $data = []): Response
    {
        // Add common data
        $data['user'] = $this->getAuthUser();
        $data['flash'] = $this->getFlash();

        return $this->twig->render($response, $template, $data);
    }

    /**
     * Redirect to a path
     */
    protected function redirect(Response $response, string $path, int $status = 302): Response
    {
        // Ensure path starts with base path
        if (!str_starts_with($path, BASE_PATH)) {
            $path = BASE_PATH . '/' . ltrim($path, '/');
        }

        return $response
            ->withHeader('Location', $path)
            ->withStatus($status);
    }

    /**
     * Return JSON response
     */
    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Get authenticated user data
     */
    protected function getAuthUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        return [
            'id' => $_SESSION['user_id'],
            'household_id' => $_SESSION['household_id'],
            'role' => $_SESSION['user_role'],
            'name' => $_SESSION['user_name'],
            'email' => $_SESSION['user_email'] ?? null,
        ];
    }

    /**
     * Get user ID from session
     */
    protected function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get household ID from session
     */
    protected function householdId(): ?int
    {
        return $_SESSION['household_id'] ?? null;
    }

    /**
     * Check if current user is owner
     */
    protected function isOwner(): bool
    {
        return ($_SESSION['user_role'] ?? '') === 'owner';
    }

    /**
     * Set flash message
     */
    protected function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * Get and clear flash message
     */
    protected function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }

    /**
     * Get client IP address
     */
    protected function getClientIp(): string
    {
        // Check for proxied IP
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For can contain multiple IPs
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }
}
