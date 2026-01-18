<?php
/**
 * Authentication Controller
 * 
 * Handles login, logout, and authentication flows.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController extends BaseController
{
    private AuthService $authService;

    public function __construct(Twig $twig, AuthService $authService)
    {
        parent::__construct($twig);
        $this->authService = $authService;
    }

    /**
     * Show login form
     */
    public function showLogin(Request $request, Response $response): Response
    {
        // Already logged in? Redirect to home
        if ($this->userId()) {
            return $this->redirect($response, '/');
        }

        return $this->render($response, 'auth/login.twig');
    }

    /**
     * Process login form
     */
    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $ip = $this->getClientIp();

        // Validate input
        $errors = [];
        if (empty($email)) {
            $errors[] = 'Email is required.';
        }
        if (empty($password)) {
            $errors[] = 'Password is required.';
        }

        if (!empty($errors)) {
            return $this->render($response, 'auth/login.twig', [
                'errors' => $errors,
                'email' => $email,
            ]);
        }

        // Check rate limiting
        if ($this->authService->isRateLimited($ip, $email)) {
            $remaining = $this->authService->getRemainingLockout($ip, $email);
            $minutes = ceil($remaining / 60);

            return $this->render($response, 'auth/login.twig', [
                'errors' => ["Too many login attempts. Please try again in {$minutes} minute(s)."],
                'email' => $email,
            ]);
        }

        // Attempt authentication
        $user = $this->authService->attempt($email, $password, $ip);

        if (!$user) {
            // Generic error message (don't reveal whether email exists)
            return $this->render($response, 'auth/login.twig', [
                'errors' => ['Invalid email or password.'],
                'email' => $email,
            ]);
        }

        // Success - log user in
        $this->authService->login($user);

        // Redirect to intended URL or home
        $intendedUrl = $_SESSION['intended_url'] ?? null;
        unset($_SESSION['intended_url']);

        if ($intendedUrl && str_starts_with($intendedUrl, BASE_PATH)) {
            return $response
                ->withHeader('Location', $intendedUrl)
                ->withStatus(302);
        }

        return $this->redirect($response, '/');
    }

    /**
     * Log user out
     */
    public function logout(Request $request, Response $response): Response
    {
        $this->authService->logout();

        $this->flash('success', 'You have been logged out.');

        return $this->redirect($response, '/login');
    }
}
