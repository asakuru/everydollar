<?php
/**
 * TOTP Service - Placeholder for Future 2FA Implementation
 * 
 * This service provides the structure for implementing TOTP-based
 * two-factor authentication. The actual implementation will require
 * adding a TOTP library (e.g., spomky-labs/otphp).
 * 
 * See README.md for implementation instructions.
 */

declare(strict_types=1);

namespace App\Services;

class TotpService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config['totp'] ?? [];
    }

    /**
     * Generate a new TOTP secret for a user
     * 
     * TODO: Implement using spomky-labs/otphp
     * 
     * @return array{secret: string, qr_uri: string}
     */
    public function generateSecret(string $email): array
    {
        // Placeholder implementation
        throw new \RuntimeException('TOTP not yet implemented. See README for instructions.');

        // Future implementation:
        // $otp = TOTP::create();
        // $otp->setLabel($email);
        // $otp->setIssuer($this->config['issuer'] ?? 'Budget App');
        // 
        // return [
        //     'secret' => $otp->getSecret(),
        //     'qr_uri' => $otp->getProvisioningUri(),
        // ];
    }

    /**
     * Verify a TOTP code
     * 
     * @param string $secret The user's TOTP secret (decrypted)
     * @param string $code The 6-digit code to verify
     * @return bool
     */
    public function verify(string $secret, string $code): bool
    {
        // Placeholder implementation
        throw new \RuntimeException('TOTP not yet implemented. See README for instructions.');

        // Future implementation:
        // $otp = TOTP::create($secret);
        // return $otp->verify($code, null, 1); // Allow 1 period variance
    }

    /**
     * Encrypt TOTP secret for database storage
     * 
     * Uses AES-256-GCM encryption with the configured key.
     */
    public function encryptSecret(string $secret): string
    {
        $key = $this->getEncryptionKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $key);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt TOTP secret from database
     */
    public function decryptSecret(string $encrypted): string
    {
        $key = $this->getEncryptionKey();
        $decoded = base64_decode($encrypted);

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt TOTP secret');
        }

        return $plaintext;
    }

    /**
     * Generate recovery codes
     * 
     * @return array{codes: string[], hashed: string}
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        $hashes = [];

        for ($i = 0; $i < $count; $i++) {
            // Generate readable 8-character codes
            $code = strtoupper(bin2hex(random_bytes(4)));
            $code = substr($code, 0, 4) . '-' . substr($code, 4, 4);

            $codes[] = $code;
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }

        return [
            'codes' => $codes,
            'hashed' => json_encode($hashes),
        ];
    }

    /**
     * Verify a recovery code
     */
    public function verifyRecoveryCode(string $code, string $hashedCodes): ?string
    {
        $hashes = json_decode($hashedCodes, true);
        if (!is_array($hashes)) {
            return null;
        }

        $normalizedCode = strtoupper(str_replace('-', '', $code));
        $formattedCode = substr($normalizedCode, 0, 4) . '-' . substr($normalizedCode, 4, 4);

        foreach ($hashes as $index => $hash) {
            if (password_verify($formattedCode, $hash)) {
                // Remove used code
                unset($hashes[$index]);
                return json_encode(array_values($hashes));
            }
        }

        return null;
    }

    /**
     * Check if 2FA should be enforced for a user
     */
    public function shouldEnforce2FA(array $user): bool
    {
        return !empty($user['totp_enabled']);
    }

    /**
     * Get the encryption key from config
     */
    private function getEncryptionKey(): string
    {
        $keyBase64 = $this->config['encryption_key'] ?? '';

        if (empty($keyBase64)) {
            throw new \RuntimeException('TOTP encryption key not configured');
        }

        $key = base64_decode($keyBase64);

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid TOTP encryption key length');
        }

        return $key;
    }
}
