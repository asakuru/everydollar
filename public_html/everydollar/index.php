<?php
/**
 * Front Controller - Entry point for all requests
 * 
 * This file bootstraps the Slim 4 application and handles all HTTP requests.
 * The .htaccess file rewrites all non-file requests to this file.
 */

declare(strict_types=1);

// Define the base path constant for the application
define('BASE_PATH', '/everydollar');
define('ROOT_DIR', dirname(__DIR__, 2));

// Composer autoloader
require ROOT_DIR . '/vendor/autoload.php';

// Load configuration
$configPath = dirname(ROOT_DIR) . '/config/everydollar/config.php';
if (!file_exists($configPath)) {
    // Fallback to local config for development
    $configPath = ROOT_DIR . '/config.php';
}

if (!file_exists($configPath)) {
    http_response_code(500);
    die('Configuration file not found. Please create config.php');
}

$config = require $configPath;

// Bootstrap and run the application
$app = require ROOT_DIR . '/src/bootstrap.php';
$app->run();
