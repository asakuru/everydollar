<?php
/**
 * Setup Controller
 * 
 * Handles initial household setup and user registration.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SetupController extends BaseController
{
    private AuthService $authService;

    public function __construct(Twig $twig, AuthService $authService, Database $db)
    {
        parent::__construct($twig, $db);
        $this->authService = $authService;
    }

    /**
     * Show setup form
     */
    public function showSetup(Request $request, Response $response): Response
    {
        // Already logged in? Redirect to home
        if ($this->userId()) {
            return $this->redirect($response, '/');
        }

        return $this->render($response, 'auth/setup.twig');
    }

    /**
     * Process household creation
     */
    public function createHousehold(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        $householdName = trim($data['household_name'] ?? '');
        $userName = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $passwordConfirm = $data['password_confirm'] ?? '';

        // Validate input
        $errors = $this->validateSetup($householdName, $userName, $email, $password, $passwordConfirm);

        if (!empty($errors)) {
            return $this->render($response, 'auth/setup.twig', [
                'errors' => $errors,
                'household_name' => $householdName,
                'name' => $userName,
                'email' => $email,
            ]);
        }

        $this->db->beginTransaction();

        try {
            // Create household
            $householdId = $this->db->insert('households', [
                'name' => $householdName,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Create owner user
            $userId = $this->authService->createUser(
                $householdId,
                $email,
                $password,
                $userName,
                'owner'
            );

            // Seed default categories
            $this->seedDefaultCategories($householdId);

            $this->db->commit();

            // Log in the new user
            $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
            $user['household_name'] = $householdName;

            $this->authService->login($user);

            $this->flash('success', 'Welcome! Your household has been created.');

            return $this->redirect($response, '/budget/' . date('Y-m'));

        } catch (\Exception $e) {
            $this->db->rollback();

            return $this->render($response, 'auth/setup.twig', [
                'errors' => ['An error occurred. Please try again.'],
                'household_name' => $householdName,
                'name' => $userName,
                'email' => $email,
            ]);
        }
    }

    /**
     * Validate setup form data
     */
    private function validateSetup(
        string $householdName,
        string $userName,
        string $email,
        string $password,
        string $passwordConfirm
    ): array {
        $errors = [];

        // Household name
        if (empty($householdName)) {
            $errors[] = 'Household name is required.';
        } elseif (strlen($householdName) > 100) {
            $errors[] = 'Household name must be 100 characters or less.';
        }

        // User name
        if (empty($userName)) {
            $errors[] = 'Your name is required.';
        } elseif (strlen($userName) > 100) {
            $errors[] = 'Name must be 100 characters or less.';
        }

        // Email
        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif ($this->authService->emailExists($email)) {
            $errors[] = 'This email is already registered.';
        }

        // Password
        if (empty($password)) {
            $errors[] = 'Password is required.';
        } else {
            $passwordErrors = $this->authService->validatePassword($password, $email, $userName);
            $errors = array_merge($errors, $passwordErrors);
        }

        // Password confirmation
        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }

        return $errors;
    }

    /**
     * Seed default categories for a new household
     */
    private function seedDefaultCategories(int $householdId): void
    {
        $categories = [
            'Housing' => ['Mortgage/Rent', 'Property Taxes', 'Home Insurance', 'Maintenance', 'Utilities'],
            'Transportation' => ['Car Payment', 'Car Insurance', 'Gas', 'Maintenance', 'Parking'],
            'Food' => ['Groceries', 'Restaurants', 'Coffee'],
            'Personal' => ['Clothing', 'Personal Care', 'Entertainment', 'Subscriptions'],
            'Health' => ['Health Insurance', 'Doctor', 'Dentist', 'Prescriptions', 'Gym'],
            'Giving' => ['Tithe/Charity', 'Gifts'],
            'Savings' => ['Emergency Fund', 'Retirement', 'Investments'],
            'Debt' => ['Credit Card', 'Student Loans', 'Personal Loan'],
            'Miscellaneous' => ['Pet Care', 'Childcare', 'Education', 'Other'],
        ];

        $sortOrder = 0;
        foreach ($categories as $groupName => $categoryNames) {
            $groupId = $this->db->insert('category_groups', [
                'household_id' => $householdId,
                'name' => $groupName,
                'sort_order' => $sortOrder++,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $catSort = 0;
            foreach ($categoryNames as $catName) {
                $this->db->insert('categories', [
                    'category_group_id' => $groupId,
                    'name' => $catName,
                    'sort_order' => $catSort++,
                    'archived' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
