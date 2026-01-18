<?php
/**
 * Invite Controller
 * 
 * Handles household member invitations.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class InviteController extends BaseController
{
    private AuthService $authService;

    public function __construct(Twig $twig, AuthService $authService, Database $db)
    {
        parent::__construct($twig, $db);
        $this->authService = $authService;
    }

    /**
     * Show invite creation form (for household owner)
     */
    public function showCreate(Request $request, Response $response): Response
    {
        // Only owners can invite
        if (!$this->isOwner()) {
            $this->flash('error', 'Only household owners can invite members.');
            return $this->redirect($response, '/');
        }

        // Get existing pending invites
        $invites = $this->db->fetchAll(
            "SELECT * FROM invite_tokens 
             WHERE household_id = ? AND expires_at > NOW() AND used_at IS NULL
             ORDER BY created_at DESC",
            [$this->householdId()]
        );

        // Get household members
        $members = $this->db->fetchAll(
            "SELECT id, name, email, role, created_at FROM users WHERE household_id = ?",
            [$this->householdId()]
        );

        return $this->render($response, 'invite/create.twig', [
            'invites' => $invites,
            'members' => $members,
        ]);
    }

    /**
     * Create a new invite
     */
    public function createInvite(Request $request, Response $response): Response
    {
        // Only owners can invite
        if (!$this->isOwner()) {
            return $this->json($response, ['error' => 'Unauthorized'], 403);
        }

        $data = (array) $request->getParsedBody();
        $email = trim(strtolower($data['email'] ?? ''));

        // Validate email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Please enter a valid email address.');
            return $this->redirect($response, '/invite');
        }

        // Check if already a member
        if ($this->authService->emailExists($email)) {
            $this->flash('error', 'This email is already registered.');
            return $this->redirect($response, '/invite');
        }

        // Check for existing pending invite
        $existing = $this->db->fetch(
            "SELECT id FROM invite_tokens 
             WHERE household_id = ? AND email = ? AND expires_at > NOW() AND used_at IS NULL",
            [$this->householdId(), $email]
        );

        if ($existing) {
            $this->flash('warning', 'An invite has already been sent to this email.');
            return $this->redirect($response, '/invite');
        }

        // Generate token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Store invite (expires in 7 days)
        $this->db->insert('invite_tokens', [
            'household_id' => $this->householdId(),
            'email' => $email,
            'token_hash' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'created_at' => date('Y-m-d H:i:s'),
            'created_by_user_id' => $this->userId(),
        ]);

        // Build invite URL
        $inviteUrl = rtrim($_ENV['APP_URL'] ?? 'https://fuzzysolution.com/everydollar', '/')
            . '/invite/' . $token;

        $this->flash('success', "Invite created! Share this link with {$email}: {$inviteUrl}");

        return $this->redirect($response, '/invite');
    }

    /**
     * Show invite redemption page (public)
     */
    public function showInvite(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'] ?? '';
        $tokenHash = hash('sha256', $token);

        // Find valid invite
        $invite = $this->db->fetch(
            "SELECT it.*, h.name as household_name 
             FROM invite_tokens it
             JOIN households h ON h.id = it.household_id
             WHERE it.token_hash = ? AND it.expires_at > NOW() AND it.used_at IS NULL",
            [$tokenHash]
        );

        if (!$invite) {
            return $this->render($response, 'invite/invalid.twig', [
                'message' => 'This invite link is invalid or has expired.',
            ]);
        }

        return $this->render($response, 'invite/accept.twig', [
            'invite' => $invite,
            'token' => $token,
        ]);
    }

    /**
     * Accept invite and create account
     */
    public function acceptInvite(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'] ?? '';
        $tokenHash = hash('sha256', $token);

        // Find valid invite
        $invite = $this->db->fetch(
            "SELECT * FROM invite_tokens 
             WHERE token_hash = ? AND expires_at > NOW() AND used_at IS NULL",
            [$tokenHash]
        );

        if (!$invite) {
            return $this->render($response, 'invite/invalid.twig', [
                'message' => 'This invite link is invalid or has expired.',
            ]);
        }

        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $password = $data['password'] ?? '';
        $passwordConfirm = $data['password_confirm'] ?? '';

        // Validate
        $errors = [];

        if (empty($name)) {
            $errors[] = 'Your name is required.';
        }

        if (empty($password)) {
            $errors[] = 'Password is required.';
        } else {
            $passwordErrors = $this->authService->validatePassword($password, $invite['email'], $name);
            $errors = array_merge($errors, $passwordErrors);
        }

        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!empty($errors)) {
            $household = $this->db->fetch("SELECT name FROM households WHERE id = ?", [$invite['household_id']]);

            return $this->render($response, 'invite/accept.twig', [
                'errors' => $errors,
                'invite' => array_merge($invite, ['household_name' => $household['name']]),
                'token' => $token,
                'name' => $name,
            ]);
        }

        $this->db->beginTransaction();

        try {
            // Create user
            $userId = $this->authService->createUser(
                $invite['household_id'],
                $invite['email'],
                $password,
                $name,
                'member'
            );

            // Mark invite as used
            $this->db->update(
                'invite_tokens',
                ['used_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$invite['id']]
            );

            $this->db->commit();

            // Log in the new user
            $user = $this->db->fetch(
                "SELECT u.*, h.name as household_name 
                 FROM users u 
                 JOIN households h ON h.id = u.household_id 
                 WHERE u.id = ?",
                [$userId]
            );

            $this->authService->login($user);

            $this->flash('success', 'Welcome! You have joined the household.');

            return $this->redirect($response, '/budget/' . date('Y-m'));

        } catch (\Exception $e) {
            $this->db->rollback();

            $household = $this->db->fetch("SELECT name FROM households WHERE id = ?", [$invite['household_id']]);

            return $this->render($response, 'invite/accept.twig', [
                'errors' => ['An error occurred. Please try again.'],
                'invite' => array_merge($invite, ['household_name' => $household['name']]),
                'token' => $token,
                'name' => $name,
            ]);
        }
    }
}
