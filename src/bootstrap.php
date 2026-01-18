<?php
/**
 * Application Bootstrap
 * 
 * Creates and configures the Slim 4 application with all middleware,
 * routes, and dependency injection container.
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use App\Middleware\SessionMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\AuthMiddleware;
use App\Services\Database;
use App\Services\AuthService;
use App\Services\RateLimiter;
use App\Services\TotpService;
use App\Services\CsvParserService;

// Build DI Container
$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    // Configuration
    'config' => function () use ($config) {
        return $config;
    },

        // Logger
    Logger::class => function (ContainerInterface $c) {
        $config = $c->get('config');
        $logger = new Logger('app');

        $logPath = $config['logging']['path'] ?? ROOT_DIR . '/storage/logs/app.log';
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $level = match ($config['logging']['level'] ?? 'warning') {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Warning,
        };

        $logger->pushHandler(new StreamHandler($logPath, $level));
        return $logger;
    },

    // Database (PDO)
    PDO::class => function (ContainerInterface $c) {
        $config = $c->get('config')['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    },

        // Database Service
    Database::class => function (ContainerInterface $c) {
        return new Database($c->get(PDO::class));
    },

        // Auth Service
    AuthService::class => function (ContainerInterface $c) {
        return new AuthService(
            $c->get(Database::class),
            $c->get(RateLimiter::class),
            $c->get('config')
        );
    },

        // Rate Limiter
    RateLimiter::class => function (ContainerInterface $c) {
        return new RateLimiter(
            $c->get(Database::class),
            $c->get('config')
        );
    },

        // TOTP Service (placeholder for 2FA)
    TotpService::class => function (ContainerInterface $c) {
        return new TotpService($c->get('config'));
    },

        // CSV Parser Service
    CsvParserService::class => function (ContainerInterface $c) {
        return new CsvParserService();
    },

        // Twig
    Twig::class => function (ContainerInterface $c) {
        $config = $c->get('config');
        $twig = Twig::create(ROOT_DIR . '/templates', [
            'cache' => $config['twig']['cache'] ?? false,
            'debug' => $config['twig']['debug'] ?? false,
            'auto_reload' => $config['twig']['auto_reload'] ?? true,
        ]);

        // Ensure cache directory exists
        $cacheDir = $config['twig']['cache'] ?? ROOT_DIR . '/storage/cache/twig';
        if ($cacheDir && !is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Add global functions
        $basePath = $config['app']['base_path'] ?? '/everydollar';

        $twig->getEnvironment()->addFunction(
            new \Twig\TwigFunction('base_path', function (string $path = '') use ($basePath) {
                return rtrim($basePath, '/') . '/' . ltrim($path, '/');
            })
        );

        $twig->getEnvironment()->addFunction(
            new \Twig\TwigFunction('asset', function (string $path) use ($basePath) {
                return rtrim($basePath, '/') . '/assets/' . ltrim($path, '/');
            })
        );

        // Add CSRF token function
        $twig->getEnvironment()->addFunction(
            new \Twig\TwigFunction('csrf_field', function () {
            $token = $_SESSION['csrf_token'] ?? '';
            return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        }, ['is_safe' => ['html']])
        );

        $twig->getEnvironment()->addFunction(
            new \Twig\TwigFunction('csrf_token', function () {
                return $_SESSION['csrf_token'] ?? '';
            })
        );

        // Add global variables
        $twig->getEnvironment()->addGlobal('base_path', $basePath);
        $twig->getEnvironment()->addGlobal('app_name', $config['app']['name'] ?? 'Budget App');

        return $twig;
    },
]);

$container = $containerBuilder->build();

// Create Slim App with PHP-DI
AppFactory::setContainer($container);
$app = AppFactory::create();

// Set base path for subdirectory deployment
$app->setBasePath(BASE_PATH);

// Add Error Middleware (should be last middleware added, first to execute)
$errorMiddleware = $app->addErrorMiddleware(
    $config['app']['debug'] ?? false,
    true,
    true,
    $container->get(Logger::class)
);

// Add custom error handler for production
if (!($config['app']['debug'] ?? false)) {
    $errorMiddleware->setDefaultErrorHandler(function (\Psr\Http\Message\ServerRequestInterface $request, \Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) use ($container) {
        $logger = $container->get(Logger::class);
        $logger->error($exception->getMessage(), [
            'trace' => $exception->getTraceAsString()
        ]);

        $response = new \Slim\Psr7\Response();
        $twig = $container->get(Twig::class);

        $response->getBody()->write(
            $twig->fetch('errors/500.twig', [
                'message' => 'An unexpected error occurred.'
            ])
        );

        return $response->withStatus(500);
    });
}

// Add Twig Middleware
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

// Add CSRF Middleware
$app->add(new CsrfMiddleware($container->get('config')));

// Add Session Middleware (runs before CSRF)
$app->add(new SessionMiddleware($container->get('config')));

// Add Security Headers Middleware
$app->add(new SecurityHeadersMiddleware($container->get('config')));

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Add Routing Middleware
$app->addRoutingMiddleware();

// Load Routes
require ROOT_DIR . '/src/routes.php';

return $app;
