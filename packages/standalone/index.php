<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Desiderata\Standalone\Router;

$path = $_GET['desiderata_path'] ?? '';
if ($path === '' && isset($_SERVER['PATH_INFO'])) {
    $path = ltrim((string) $_SERVER['PATH_INFO'], '/');
}
if ($path === '' && !empty($_SERVER['REQUEST_URI'])) {
    $uri = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    if (preg_match('#/v1(?:/(.*))?$#', $uri, $m)) {
        $path = $m[1] ?? '';
    }
}

$router = new Router($config, $pdo);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
