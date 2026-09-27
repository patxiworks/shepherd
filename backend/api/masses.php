<?php
// Mirrors the old REMOTE_MASSES_URL contract: a flat JSON object keyed by
// yyyy-MM-dd date, e.g. { "2025-07-26": { "Class": "...", "Mass": "..." } }
// The current frontend fetches this without a zone filter, so ?zone= is
// optional here and, when omitted, returns global (zone_id IS NULL) rows
// plus every zone's rows merged together — matching the previous
// single-shared-sheet behaviour. Pass ?zone= to scope to one zone once the
// frontend/API proxy is updated to do so.

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';

apply_cors();

$pdo = pastores_db();

$zone = $_GET['zone'] ?? null;

if ($zone) {
    $zoneStmt = $pdo->prepare('SELECT id FROM zones WHERE name = ?');
    $zoneStmt->execute([$zone]);
    $zoneRow = $zoneStmt->fetch();
    if (!$zoneRow) {
        json_response(new stdClass());
    }
    $stmt = $pdo->prepare('SELECT mass_date, class, mass FROM masses WHERE zone_id = ? OR zone_id IS NULL');
    $stmt->execute([$zoneRow['id']]);
} else {
    $stmt = $pdo->query('SELECT mass_date, class, mass FROM masses');
}

$result = [];
foreach ($stmt->fetchAll() as $row) {
    $result[$row['mass_date']] = [
        'Class' => $row['class'],
        'Mass' => $row['mass'],
    ];
}

json_response(empty($result) ? new stdClass() : $result);
