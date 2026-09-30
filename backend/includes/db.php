<?php

function pastores_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config.php';
        if (!file_exists($path)) {
            http_response_code(500);
            die(json_encode(['message' => 'Missing config.php — copy config.sample.php to config.php and fill in credentials.']));
        }
        $config = require $path;
    }
    return $config;
}

function pastores_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $db = pastores_config()['db'];
        $dsn = sprintf(
            'mysql:host=%s%s;dbname=%s;charset=%s',
            $db['host'],
            !empty($db['port']) ? ';port=' . $db['port'] : '',
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5, // fail fast on an unreachable DB host instead of hanging into a gateway timeout
        ]);
    }
    return $pdo;
}
