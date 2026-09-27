<?php
// Mirrors the GET handler of the old src/app/api/auth/zone-login/route.ts:
// returns the distinct list of zones for the login dropdown.
//   GET -> { "zones": ["Zone 1", "Zone 2", ...] }

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';

apply_cors();

$pdo = pastores_db();
$stmt = $pdo->query('SELECT name FROM zones ORDER BY name');
$zones = array_column($stmt->fetchAll(), 'name');

json_response(['zones' => $zones]);
