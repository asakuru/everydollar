<?php
/**
 * Route Definitions
 * 
 * All application routes are defined here.
 * Routes use the base path /everydollar set in bootstrap.php
 */

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use App\Controllers\AuthController;
use App\Controllers\SetupController;
use App\Controllers\BudgetController;
use App\Controllers\TransactionController;
use App\Controllers\CategoryController;
use App\Controllers\ReportsController;
use App\Controllers\SettingsController;
use App\Controllers\InviteController;
use App\Controllers\ImportController;
use App\Middleware\AuthMiddleware;

/** @var App $app */

// Public Routes (no authentication required)
$app->get('/login', [AuthController::class, 'showLogin'])->setName('login');
$app->post('/login', [AuthController::class, 'login']);
$app->get('/logout', [AuthController::class, 'logout'])->setName('logout');

// Setup route (for initial household creation)
$app->get('/setup', [SetupController::class, 'showSetup'])->setName('setup');
$app->post('/setup', [SetupController::class, 'createHousehold']);

// Invite redemption (public but token-protected)
$app->get('/invite/{token}', [InviteController::class, 'showInvite'])->setName('invite');
$app->post('/invite/{token}', [InviteController::class, 'acceptInvite']);

// Protected Routes (require authentication)
$app->group('', function (RouteCollectorProxy $group) {

    // Dashboard / Home - redirect to current month budget
    $group->get('/', function ($request, $response) {
        $currentMonth = date('Y-m');
        return $response
            ->withHeader('Location', BASE_PATH . '/budget/' . $currentMonth)
            ->withStatus(302);
    })->setName('home');

    // Budget Routes
    $group->get('/budget/{month}', [BudgetController::class, 'show'])->setName('budget');
    $group->post('/budget/{month}/income', [BudgetController::class, 'addIncome']);
    $group->post('/budget/{month}/income/{id}/update', [BudgetController::class, 'updateIncome']);
    $group->post('/budget/{month}/income/{id}/delete', [BudgetController::class, 'deleteIncome']);
    $group->post('/budget/{month}/category/{id}/budget', [BudgetController::class, 'updateBudgetItem']);
    $group->post('/budget/{month}/copy-previous', [BudgetController::class, 'copyPreviousMonth']);

    // Category Management
    $group->get('/category/{id}', [CategoryController::class, 'show'])->setName('category');
    $group->post('/category-group', [CategoryController::class, 'createGroup']);
    $group->post('/category-group/{id}/update', [CategoryController::class, 'updateGroup']);
    $group->post('/category-group/{id}/category', [CategoryController::class, 'createCategory']);
    $group->post('/category/{id}/update', [CategoryController::class, 'updateCategory']);
    $group->post('/category/{id}/archive', [CategoryController::class, 'archiveCategory']);

    // Transaction Routes
    $group->get('/transactions/{month}', [TransactionController::class, 'index'])->setName('transactions');
    $group->get('/transactions/{month}/uncategorized', [TransactionController::class, 'uncategorized'])->setName('transactions.uncategorized');
    $group->post('/transaction', [TransactionController::class, 'create']);
    $group->get('/transaction/{id}/edit', [TransactionController::class, 'edit'])->setName('transaction.edit');
    $group->post('/transaction/{id}/update', [TransactionController::class, 'update']);
    $group->post('/transaction/{id}/delete', [TransactionController::class, 'delete']);
    $group->post('/transaction/{id}/categorize', [TransactionController::class, 'quickCategorize']);

    // Import Routes
    $group->get('/import', [ImportController::class, 'showForm'])->setName('import');
    $group->post('/import/preview', [ImportController::class, 'preview']);
    $group->post('/import/confirm', [ImportController::class, 'import']);

    // Reports
    $group->get('/reports', [ReportsController::class, 'index'])->setName('reports');

    // Settings
    $group->get('/settings/security', [SettingsController::class, 'security'])->setName('settings.security');

    // Invite Management (for household owner)
    $group->get('/invite', [InviteController::class, 'showCreate'])->setName('invite.create');
    $group->post('/invite', [InviteController::class, 'createInvite']);

})->add(new AuthMiddleware());
