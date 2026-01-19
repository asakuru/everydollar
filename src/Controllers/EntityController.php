<?php
/**
 * Entity Controller
 * 
 * Handles entity (Personal/LLC) switching and management.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class EntityController extends BaseController
{
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * Get current entity from session or default to first personal entity
     */
    public static function getCurrentEntityId(): ?int
    {
        return $_SESSION['current_entity_id'] ?? null;
    }

    /**
     * Set current entity in session
     */
    public static function setCurrentEntityId(int $entityId): void
    {
        $_SESSION['current_entity_id'] = $entityId;
    }

    /**
     * Switch to a different entity
     */
    public function switch(Request $request, Response $response, array $args): Response
    {
        $entityId = (int) $args['id'];
        $householdId = $this->householdId();

        // Verify entity belongs to household
        $entity = $this->db->fetch(
            "SELECT id FROM entities WHERE id = ? AND household_id = ?",
            [$entityId, $householdId]
        );

        if (!$entity) {
            $this->flash('error', 'Entity not found.');
            return $this->redirect($response, '/');
        }

        self::setCurrentEntityId($entityId);
        $this->flash('success', "Switched to {$entity['name']}.");

        // Redirect to budget for current month
        $month = date('Y-m');
        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * List all entities for household
     */
    public function index(Request $request, Response $response): Response
    {
        $householdId = $this->householdId();

        $entities = $this->db->fetchAll(
            "SELECT e.*, 
                    (SELECT COUNT(*) FROM accounts a WHERE a.entity_id = e.id) as account_count,
                    (SELECT COUNT(*) FROM transactions t WHERE t.entity_id = e.id) as transaction_count
             FROM entities e
             WHERE e.household_id = ?
             ORDER BY e.type ASC, e.name ASC",
            [$householdId]
        );

        return $this->render($response, 'entities/index.twig', [
            'entities' => $entities,
        ]);
    }

    /**
     * Show create entity form
     */
    public function showCreate(Request $request, Response $response): Response
    {
        return $this->render($response, 'entities/create.twig');
    }

    /**
     * Create a new entity (e.g., LLC)
     */
    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $householdId = $this->householdId();

        $name = trim($data['name'] ?? '');
        $type = $data['type'] ?? 'personal';
        $taxRate = (float) ($data['tax_rate'] ?? 25.00);

        // Validation
        if (empty($name)) {
            $this->flash('error', 'Entity name is required.');
            return $this->redirect($response, '/entities/create');
        }

        if (!in_array($type, ['personal', 'business'])) {
            $type = 'personal';
        }

        // Create entity
        $entityId = $this->db->insert('entities', [
            'household_id' => $householdId,
            'name' => $name,
            'type' => $type,
            'tax_rate_percent' => $taxRate,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // If business type, create default LLC categories
        if ($type === 'business') {
            $this->createDefaultLLCCategories($entityId);
        }

        $this->flash('success', "Entity '{$name}' created successfully.");
        return $this->redirect($response, '/entities');
    }

    /**
     * Show edit form
     */
    public function showEdit(Request $request, Response $response, array $args): Response
    {
        $entityId = (int) $args['id'];
        $householdId = $this->householdId();

        $entity = $this->db->fetch(
            "SELECT * FROM entities WHERE id = ? AND household_id = ?",
            [$entityId, $householdId]
        );

        if (!$entity) {
            $this->flash('error', 'Entity not found.');
            return $this->redirect($response, '/entities');
        }

        return $this->render($response, 'entities/edit.twig', [
            'entity' => $entity,
        ]);
    }

    /**
     * Update entity
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $entityId = (int) $args['id'];
        $householdId = $this->householdId();
        $data = (array) $request->getParsedBody();

        // Verify ownership
        $entity = $this->db->fetch(
            "SELECT * FROM entities WHERE id = ? AND household_id = ?",
            [$entityId, $householdId]
        );

        if (!$entity) {
            $this->flash('error', 'Entity not found.');
            return $this->redirect($response, '/entities');
        }

        $name = trim($data['name'] ?? '');
        $taxRate = (float) ($data['tax_rate'] ?? $entity['tax_rate_percent']);

        if (empty($name)) {
            $this->flash('error', 'Entity name is required.');
            return $this->redirect($response, "/entities/{$entityId}/edit");
        }

        $this->db->update('entities', $entityId, [
            'name' => $name,
            'tax_rate_percent' => $taxRate,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', 'Entity updated.');
        return $this->redirect($response, '/entities');
    }

    /**
     * Create default LLC category groups and categories
     */
    private function createDefaultLLCCategories(int $entityId): void
    {
        $groups = [
            'Revenue' => [
                'Client Income',
                'Contract Work',
                'Product Sales',
                'Other Revenue',
            ],
            'Operating Expenses' => [
                'Software & Subscriptions',
                'Equipment',
                'Office Supplies',
                'Professional Services',
                'Marketing & Advertising',
            ],
            'Owner & Payroll' => [
                'Owner Draw',
                'Contractor Payments',
                'Payroll',
                'Payroll Taxes',
            ],
            'Taxes & Fees' => [
                'Federal Tax Payments',
                'State Tax Payments',
                'Business Licenses',
                'Bank Fees',
            ],
        ];

        $sortOrder = 0;
        foreach ($groups as $groupName => $categories) {
            $groupId = $this->db->insert('category_groups', [
                'household_id' => $this->householdId(),
                'entity_id' => $entityId,
                'name' => $groupName,
                'sort_order' => $sortOrder++,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $catSortOrder = 0;
            foreach ($categories as $catName) {
                $this->db->insert('categories', [
                    'category_group_id' => $groupId,
                    'name' => $catName,
                    'sort_order' => $catSortOrder++,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
