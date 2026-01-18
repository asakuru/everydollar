<?php
/**
 * Security Headers Middleware
 * 
 * Adds security headers to all responses.
 * Some headers are also set in .htaccess for static files.
 */

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Prevent clickjacking
        $response = $response->withHeader('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing (also in .htaccess for static files)
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');

        // XSS Protection (legacy, but still useful for older browsers)
        $response = $response->withHeader('X-XSS-Protection', '1; mode=block');

        // Referrer Policy (also in .htaccess)
        $response = $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Content Security Policy
        // Strict CSP for server-rendered templates
        $csp = $this->buildCspHeader();
        $response = $response->withHeader('Content-Security-Policy', $csp);

        // Permissions Policy (restrict browser features)
        $response = $response->withHeader(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=()'
        );

        // HSTS - Only enable if configured and HTTPS confirmed working
        $security = $this->config['security'] ?? [];
        if (!empty($security['hsts_enabled'])) {
            $maxAge = $security['hsts_max_age'] ?? 31536000;
            $response = $response->withHeader(
                'Strict-Transport-Security',
                "max-age={$maxAge}; includeSubDomains"
            );
        }

        return $response;
    }

    /**
     * Build Content Security Policy header
     */
    private function buildCspHeader(): string
    {
        $basePath = $this->config['app']['base_path'] ?? '/everydollar';

        $directives = [
            // Only allow resources from same origin
            "default-src 'self'",

            // Allow inline styles for Twig templates (can be tightened with nonces later)
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",

            // Fonts from Google Fonts
            "font-src 'self' https://fonts.gstatic.com",

            // No inline scripts (strict)
            "script-src 'self'",

            // Images from same origin only
            "img-src 'self' data:",

            // Forms can only submit to same origin
            "form-action 'self'",

            // No embedding in frames
            "frame-ancestors 'none'",

            // Base URI restriction
            "base-uri 'self'",

            // Block mixed content
            "block-all-mixed-content",

            // Upgrade insecure requests
            "upgrade-insecure-requests",
        ];

        return implode('; ', $directives);
    }
}
