<?php

declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_readable($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 'config_missing',
        'message' => 'Copiez config.example.php vers config.php.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @var array<string,mixed> $config */
$config = require $configFile;

require_once dirname(__DIR__) . '/lib/shared/Meta.php';
require_once dirname(__DIR__) . '/lib/shared/OpenLibrary.php';
require_once dirname(__DIR__) . '/lib/shared/Zotero.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/RateLimit.php';
require_once __DIR__ . '/Router.php';

use Desiderata\Standalone\Auth;
use Desiderata\Standalone\Schema;

$dsn = (string) ($config['db']['dsn'] ?? '');
$user = (string) ($config['db']['user'] ?? '');
$pass = (string) ($config['db']['pass'] ?? '');

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 'db_error', 'message' => 'Connexion base impossible.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$prefix = (string) ($config['db']['prefix'] ?? 'desiderata_');
Schema::migrate($pdo, $prefix);
Auth::bootstrapOwner($pdo, $prefix, $config['bootstrap_user'] ?? null);
