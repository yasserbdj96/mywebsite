<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

try {
    if (config()) {
        echo json_encode(get_data(db()), $flags);
        exit;
    }
    // Not installed yet: fall back to the old data.json so the page keeps working.
    if (is_file(__DIR__ . '/data.json')) {
        readfile(__DIR__ . '/data.json');
        exit;
    }
    throw new RuntimeException('No data');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load data'], $flags);
}
