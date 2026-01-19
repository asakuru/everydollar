<?php
/**
 * Cache Buster
 * Touching files to force OPcache invalidation.
 */
header('Content-Type: text/plain');

$files = [
    'public_html/everydollar/index.php',
    'src/routes.php',
    'src/bootstrap.php'
];

$baseDir = '/home/ravenscv/repositories/everydollar';

echo "Touching files to force reload...\n\n";

foreach ($files as $file) {
    $path = $baseDir . '/' . $file;
    if (file_exists($path)) {
        if (touch($path)) {
            echo "[OK] Touched $path\n";
        } else {
            echo "[ERR] Could not touch $path (Permission denied?)\n";
        }
    } else {
        echo "[MISSING] $path\n";
    }
}

if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "\n[OK] opcache_reset() called successfully.\n";
    } else {
        echo "\n[WARN] opcache_reset() returned false.\n";
    }
}

echo "\nDone. Please wait 10 seconds then try again.";
