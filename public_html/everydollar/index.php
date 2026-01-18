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

// ROOT_DIR: Use repository path for production, calculated path for development
if (is_dir('/home/ravenscv/repositories/everydollar')) {
    define('ROOT_DIR', '/home/ravenscv/repositories/everydollar');
} else {
    define('ROOT_DIR', dirname(__DIR__, 2));
}

// Composer autoloader
require ROOT_DIR . '/vendor/autoload.php';

// Load configuration - check multiple locations
$configPaths = [
    '/home/ravenscv/config/everydollar/config.php',  // Production config
    dirname(ROOT_DIR) . '/config/everydollar/config.php',
    ROOT_DIR . '/config.php',  // Development fallback
];

$configPath = null;
foreach ($configPaths as $path) {
    if (file_exists($path)) {
        $configPath = $path;
        break;
    }
}

if (!$configPath) {
    http_response_code(500);
    die('Configuration file not found. Please create config.php at /home/ravenscv/config/everydollar/config.php');
}

$config = require $configPath;

// Bootstrap and run the application
$app = require ROOT_DIR . '/src/bootstrap.php';
$app->run();
