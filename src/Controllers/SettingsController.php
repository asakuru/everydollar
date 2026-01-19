<?php
/**
 * Settings Controller
 * 
 * Handles user settings and security configuration.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SettingsController extends BaseController
{
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * Show security settings (2FA placeholder)
     */
    public function security(Request $request, Response $response): Response
    {
        return $this->render($response, 'settings/security.twig', [
            'totp_enabled' => false, // Will be populated from user record when implemented
            'active_tab' => 'security',
        ]);
    }
}
