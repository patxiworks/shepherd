<?php
// The section colours set in Admin > Sections, for the Next.js app:
//   GET -> { "sections": { "sf": "#FFB74D", "sv": "#64B5F6", ... } }
// Sections without a colour are left out (the app uses its default).

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';

apply_cors();

$pdo = pastores_db();
$sections = [];
foreach ($pdo->query('SELECT name, colour FROM sections WHERE colour IS NOT NULL ORDER BY name') as $s) {
    $sections[$s['name']] = $s['colour'];
}

json_response(['sections' => (object) $sections]);
