<?php
/**
 * Rate Limiter Service
 * 
 * MySQL-backed rate limiting for login attempts.
 * Tracks attempts by IP address and by account (email).
 */

declare(strict_types=1);

namespace App\Services;

class RateLimiter
{
    private Database $db;
    private array $config;

    public function __construct(Database $db, array $config)
    {
        $this->db = $db;
        $this->config = $config['security']['rate_limit'] ?? [
            'login_attempts_per_ip' => 5,
            'login_attempts_per_account' => 3,
            'lockout_minutes' => 15,
        ];
    }

    /**
     * Check if an IP address is rate limited for login
     */
    public function isIpLimited(string $ip): bool
    {
        $minutes = $this->config['lockout_minutes'];
        $maxAttempts = $this->config['login_attempts_per_ip'];

        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM login_attempts 
             WHERE ip_address = ? 
             AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
             AND success = 0",
            [$ip, $minutes]
        );

        return $count >= $maxAttempts;
    }

    /**
     * Check if an account is rate limited for login
     */
    public function isAccountLimited(string $email): bool
    {
        $minutes = $this->config['lockout_minutes'];
        $maxAttempts = $this->config['login_attempts_per_account'];

        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM login_attempts 
             WHERE email = ? 
             AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
             AND success = 0",
            [strtolower($email), $minutes]
        );

        return $count >= $maxAttempts;
    }

    /**
     * Get remaining lockout time in seconds
     */
    public function getRemainingLockoutSeconds(string $ip, string $email): int
    {
        $minutes = $this->config['lockout_minutes'];

        // Get the most recent failed attempt for either IP or email
        $lastAttempt = $this->db->fetchColumn(
            "SELECT MAX(attempted_at) FROM login_attempts 
             WHERE (ip_address = ? OR email = ?)
             AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
             AND success = 0",
            [$ip, strtolower($email), $minutes]
        );

        if (!$lastAttempt) {
            return 0;
        }

        $lastAttemptTime = strtotime($lastAttempt);
        $lockoutEnds = $lastAttemptTime + ($minutes * 60);
        $remaining = $lockoutEnds - time();

        return max(0, $remaining);
    }

    /**
     * Record a login attempt
     */
    public function recordAttempt(string $ip, string $email, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip_address' => $ip,
            'email' => strtolower($email),
            'success' => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);

        // Clean up old attempts periodically (1% chance per request)
        if (random_int(1, 100) === 1) {
            $this->cleanupOldAttempts();
        }
    }

    /**
     * Clear failed attempts after successful login
     */
    public function clearFailedAttempts(string $ip, string $email): void
    {
        $this->db->execute(
            "DELETE FROM login_attempts 
             WHERE (ip_address = ? OR email = ?) AND success = 0",
            [$ip, strtolower($email)]
        );
    }

    /**
     * Remove attempts older than lockout period
     */
    private function cleanupOldAttempts(): void
    {
        $minutes = $this->config['lockout_minutes'] * 2; // Keep some buffer

        $this->db->execute(
            "DELETE FROM login_attempts 
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        );
    }
}
