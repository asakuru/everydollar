<?php
/**
 * Authentication Service
 * 
 * Handles user authentication, password hashing, and session management.
 * Implements OWASP password security recommendations.
 */

declare(strict_types=1);

namespace App\Services;

use App\Middleware\SessionMiddleware;

class AuthService
{
    private Database $db;
    private RateLimiter $rateLimiter;
    private array $config;

    // Common passwords list (top 100 most common)
    private const COMMON_PASSWORDS = [
        'password',
        '123456',
        '12345678',
        'qwerty',
        'abc123',
        'monkey',
        '1234567',
        'letmein',
        'trustno1',
        'dragon',
        'baseball',
        'iloveyou',
        'master',
        'sunshine',
        'ashley',
        'bailey',
        'shadow',
        '123123',
        '654321',
        'superman',
        'qazwsx',
        'michael',
        'football',
        'password1',
        'password123',
        'batman',
        'login',
        'adminadmin',
        'welcome',
        'hello',
        'charlie',
        'donald',
        'loveme',
        'jennifer',
        'jordan',
        'joshua',
        'maggie',
        'michelle',
        'nicole',
        'tigger',
        '1234',
        '12345',
        '123456789',
        '0987654321',
        'qwertyuiop',
        'asdfghjkl',
        'zxcvbnm',
        'admin',
        'guest',
        'user',
        'test',
        'demo',
        'access',
        'secret',
        'database',
        'mysql',
    ];

    public function __construct(Database $db, RateLimiter $rateLimiter, array $config)
    {
        $this->db = $db;
        $this->rateLimiter = $rateLimiter;
        $this->config = $config;
    }

    /**
     * Attempt to authenticate a user
     * Returns user data on success, null on failure
     */
    public function attempt(string $email, string $password, string $ip): ?array
    {
        $email = strtolower(trim($email));

        // Check rate limits
        if ($this->rateLimiter->isIpLimited($ip) || $this->rateLimiter->isAccountLimited($email)) {
            return null;
        }

        // Find user by email
        $user = $this->db->fetch(
            "SELECT u.*, h.name as household_name 
             FROM users u 
             JOIN households h ON u.household_id = h.id 
             WHERE LOWER(u.email) = ?",
            [$email]
        );

        // Always check password hash even if user doesn't exist (timing attack prevention)
        $dummyHash = password_hash('dummy', PASSWORD_DEFAULT);
        $hashToCheck = $user['password_hash'] ?? $dummyHash;

        if (!password_verify($password, $hashToCheck) || $user === null) {
            $this->rateLimiter->recordAttempt($ip, $email, false);
            return null;
        }

        // Success - clear failed attempts and record success
        $this->rateLimiter->recordAttempt($ip, $email, true);
        $this->rateLimiter->clearFailedAttempts($ip, $email);

        // Check if password needs rehashing
        if (password_needs_rehash($user['password_hash'], $this->getHashAlgorithm(), $this->getHashOptions())) {
            $this->updatePasswordHash($user['id'], $password);
        }

        return $user;
    }

    /**
     * Log user into session
     */
    public function login(array $user): void
    {
        // Regenerate session ID on login
        SessionMiddleware::regenerate();

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['household_id'] = $user['household_id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['logged_in_at'] = time();
    }

    /**
     * Log user out
     */
    public function logout(): void
    {
        SessionMiddleware::destroy();
    }

    /**
     * Hash a password using Argon2id (preferred) or bcrypt
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, $this->getHashAlgorithm(), $this->getHashOptions());
    }

    /**
     * Validate password meets requirements
     * Returns array of error messages (empty if valid)
     */
    public function validatePassword(string $password, string $email = '', string $name = ''): array
    {
        $errors = [];
        $config = $this->config['security']['password'] ?? [];
        $minLength = $config['min_length'] ?? 12;

        // Check minimum length
        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long.";
        }

        // Check against common passwords
        if ($config['check_common'] ?? true) {
            if (in_array(strtolower($password), self::COMMON_PASSWORDS, true)) {
                $errors[] = "This password is too common. Please choose a stronger password.";
            }
        }

        // Check if password contains email or name
        $lowerPassword = strtolower($password);
        if ($email && str_contains($lowerPassword, strtolower(explode('@', $email)[0]))) {
            $errors[] = "Password should not contain your email address.";
        }
        if ($name && strlen($name) >= 3 && str_contains($lowerPassword, strtolower($name))) {
            $errors[] = "Password should not contain your name.";
        }

        return $errors;
    }

    /**
     * Create a new user
     */
    public function createUser(int $householdId, string $email, string $password, string $name, string $role = 'member'): int
    {
        return $this->db->insert('users', [
            'household_id' => $householdId,
            'email' => strtolower(trim($email)),
            'password_hash' => $this->hashPassword($password),
            'name' => trim($name),
            'role' => $role,
            'totp_enabled' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Check if email is already registered
     */
    public function emailExists(string $email): bool
    {
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE LOWER(email) = ?",
            [strtolower(trim($email))]
        );
        return $count > 0;
    }

    /**
     * Get remaining lockout time if rate limited
     */
    public function getRemainingLockout(string $ip, string $email): int
    {
        return $this->rateLimiter->getRemainingLockoutSeconds($ip, strtolower($email));
    }

    /**
     * Check if request is rate limited
     */
    public function isRateLimited(string $ip, string $email): bool
    {
        return $this->rateLimiter->isIpLimited($ip) || $this->rateLimiter->isAccountLimited(strtolower($email));
    }

    /**
     * Update password hash for a user
     */
    private function updatePasswordHash(int $userId, string $password): void
    {
        $this->db->update('users', [
            'password_hash' => $this->hashPassword($password),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$userId]);
    }

    /**
     * Get the password hash algorithm
     */
    private function getHashAlgorithm(): string|int
    {
        // Prefer Argon2id if available (PHP 7.3+)
        if (defined('PASSWORD_ARGON2ID')) {
            return PASSWORD_ARGON2ID;
        }
        if (defined('PASSWORD_ARGON2I')) {
            return PASSWORD_ARGON2I;
        }
        return PASSWORD_BCRYPT;
    }

    /**
     * Get password hash options
     */
    private function getHashOptions(): array
    {
        if (defined('PASSWORD_ARGON2ID') || defined('PASSWORD_ARGON2I')) {
            return [
                'memory_cost' => 65536, // 64 MB
                'time_cost' => 4,
                'threads' => 1,
            ];
        }
        return [
            'cost' => 12,
        ];
    }
}
