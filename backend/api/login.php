<?php
// Mirrors the POST handler of the old src/app/api/auth/zone-login/route.ts,
// but does the credential match in PHP against hashed passcodes instead of
// Next.js fetching every user and comparing plaintext passcodes.
//   POST { "zone": "...", "passcode": "..." }
//     -> { "success": true, "user": { zone, name, centre, section, role, last_update } }
//     -> { "success": false, "message": "..." } (401)
//
// Note: the response intentionally omits the passcode field present in the
// old ZoneUser payload — nothing in the frontend reads user.passcode after
// login, so this is safe to drop and avoids echoing credentials back.

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

apply_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['message' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$zone = $body['zone'] ?? null;
$passcode = $body['passcode'] ?? null;

if (!$zone || !$passcode) {
    json_response(['message' => 'Zone and passcode are required'], 400);
}

$pdo = pastores_db();

$stmt = $pdo->prepare(
    'SELECT u.name, u.centre, u.section, u.passcode_hash, u.role, z.name AS zone_name, z.last_update
     FROM users u
     JOIN zones z ON z.id = u.zone_id
     WHERE z.name = ?'
);
$stmt->execute([$zone]);

$match = null;
foreach ($stmt->fetchAll() as $row) {
    if (verify_passcode($passcode, $row['passcode_hash'])) {
        $match = $row;
        break;
    }
}

if (!$match) {
    json_response(['success' => false, 'message' => 'Invalid zone or passcode'], 401);
}

json_response([
    'success' => true,
    'user' => [
        'zone' => $match['zone_name'],
        'name' => $match['name'],
        'centre' => $match['centre'],
        'section' => $match['section'],
        'role' => $match['role'],
        'last_update' => $match['last_update']
            ? gmdate('Y-m-d\TH:i:s.000\Z', strtotime($match['last_update']))
            : null,
    ],
]);
