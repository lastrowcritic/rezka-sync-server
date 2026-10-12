<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/WatchRecord.php';
require dirname(__DIR__) . '/app/WatchSyncApi.php';

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'The sync service is not configured.']);
    exit;
}

$config = require $configPath;
if (!is_array($config)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'The sync service is not configured.']);
    exit;
}

$required = ['db_host', 'db_name', 'db_user', 'db_password', 'session_pepper'];
foreach ($required as $key) {
    if (!is_string($config[$key] ?? null) || $config[$key] === '') {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'The sync service is not configured.']);
        exit;
    }
}

try {
    $database = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_EMULATE_PREPARES => false],
    );
    (new WatchSyncApi($database, $config['session_pepper'], ($config['allow_registration'] ?? false) === true))->handle(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'));
} catch (Throwable $error) {
    error_log('Watch sync bootstrap failed: ' . $error::class);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'The sync service could not complete this request.']);
}
