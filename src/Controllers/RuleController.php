<?php
/**
 * Rule Controller
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use App\Services\AutoCategorizationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class RuleController extends BaseController
{
    private AutoCategorizationService $autoCat;

    public function __construct(Twig $twig, Database $db, AutoCategorizationService $autoCat)
    {
        parent::__construct($twig, $db);
        $this->autoCat = $autoCat;
    }

    public function index(Request $request, Response $response): Response
    {
        $householdId = $this->householdId();
        $rules = $this->autoCat->getRules($householdId);
        $categories = $this->getCategories($householdId);

        return $this->render($response, 'settings/rules.twig', [
            'rules' => $rules,
            'categories' => $categories,
            'active_tab' => 'rules'
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $householdId = $this->householdId();

        $searchTerm = trim($data['search_term'] ?? '');
        $categoryId = (int) ($data['category_id'] ?? 0);
        $matchType = $data['match_type'] ?? 'contains';

        if (empty($searchTerm) || $categoryId <= 0) {
            $this->flash('error', 'Please provide a search term and category.');
            return $this->redirect($response, '/settings/rules');
        }

        $this->autoCat->createRule($householdId, $searchTerm, $categoryId, $matchType);
        $this->flash('success', 'Rule created successfully.');

        return $this->redirect($response, '/settings/rules');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $ruleId = (int) $args['id'];
        $householdId = $this->householdId();

        $this->autoCat->deleteRule($householdId, $ruleId);
        $this->flash('success', 'Rule deleted.');

        return $this->redirect($response, '/settings/rules');
    }

    private function getCategories(int $householdId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.name, cg.name as group_name
             FROM categories c
             JOIN category_groups cg ON cg.id = c.category_group_id
             WHERE cg.household_id = ? AND c.archived = 0
             ORDER BY cg.sort_order, c.sort_order",
            [$householdId]
        );
    }
}
