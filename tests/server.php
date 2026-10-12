<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/WatchRecord.php';
require dirname(__DIR__) . '/app/WatchSyncApi.php';

$databasePath = getenv('REZKA_TEST_DB');
if (!is_string($databasePath) || $databasePath === '') {
    http_response_code(500);
    exit;
}

$database = new PDO('sqlite:' . $databasePath);
// The tests close sign-ups by creating this file.
(new WatchSyncApi($database, 'test-only-session-pepper', !is_file($databasePath . '.closed')))->handle('/sync/public');
