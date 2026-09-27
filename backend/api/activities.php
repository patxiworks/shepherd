<?php
// Mirrors the old REMOTE_ACTIVITIES_URL (Google Apps Script) contract used
// by src/app/api/collections/route.ts in the Next.js app:
//   GET ?zone=&section=&centre=            -> JSON array of activities
//   GET ?zone=&action=lastupdate            -> { "last_update": "<ISO>" }

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';

apply_cors();

$pdo = pastores_db();

$zone = $_GET['zone'] ?? null;
$section = $_GET['section'] ?? null;
$centre = $_GET['centre'] ?? null;
$action = $_GET['action'] ?? null;

if (!$zone) {
    json_response(['message' => 'zone is required'], 400);
}

$zoneStmt = $pdo->prepare('SELECT id, last_update FROM zones WHERE name = ?');
$zoneStmt->execute([$zone]);
$zoneRow = $zoneStmt->fetch();

if (!$zoneRow) {
    // Match prior behaviour of returning an empty result set for unknown zones
    if ($action === 'lastupdate') {
        json_response(['last_update' => null]);
    }
    json_response([]);
}

if ($action === 'lastupdate') {
    $lastUpdate = $zoneRow['last_update'];
    json_response([
        'last_update' => $lastUpdate ? gmdate('Y-m-d\TH:i:s.000\Z', strtotime($lastUpdate)) : null,
    ]);
}

$sql = 'SELECT unit, week, day, weekday, activity_date, centre, activity, section, labor,
               from_time, to_time, duration, mfrequency, priest, description
        FROM activities WHERE zone_id = ?';
$params = [$zoneRow['id']];

if ($section) {
    $sql .= ' AND section = ?';
    $params[] = $section;
}
if ($centre) {
    $sql .= ' AND centre = ?';
    $params[] = $centre;
}
$sql .= ' ORDER BY activity_date, from_time';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$activities = array_map(function (array $row): array {
    return [
        'unit' => $row['unit'],
        'week' => $row['week'] !== null ? (int) $row['week'] : null,
        'day' => $row['day'],
        'weekday' => $row['weekday'] !== null ? (int) $row['weekday'] : null,
        'date' => date_to_iso($row['activity_date']),
        'centre' => $row['centre'],
        'activity' => $row['activity'],
        'section' => $row['section'],
        'labor' => $row['labor'],
        'from' => time_to_sheets_iso($row['from_time']),
        'to' => time_to_sheets_iso($row['to_time']),
        'duration' => time_to_sheets_iso($row['duration']),
        'mfrequency' => $row['mfrequency'] !== null ? (int) $row['mfrequency'] : null,
        'priest' => $row['priest'],
        'description' => $row['description'],
    ];
}, $stmt->fetchAll());

json_response($activities);
