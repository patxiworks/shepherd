<?php
// One-time CLI migration: pulls current data from the Google Apps Script
// endpoints (same ones src/app/api/collections/route.ts and
// src/app/api/auth/zone-login/route.ts call today) and imports it into the
// new MySQL schema.
//
// Run locally (not on the web server) with:
//   php migrate.php
//
// Review the output carefully, then re-run against a fresh/truncated
// database if anything looks off — this script is idempotent for zones and
// users (keyed by name), but will duplicate activities/masses if run twice
// without truncating first.

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

// Copied from src/app/api/collections/route.ts and
// src/app/api/auth/zone-login/route.ts — update if those have changed.
const REMOTE_ACTIVITIES_URL = 'https://script.google.com/macros/s/AKfycbwRoGXp8hU-e8vlTntOlAwlqofP9mQ3PqTSBy7b4WdbhZyTE9P5M2OSmfIqZn0s0RnN/exec';
const REMOTE_MASSES_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AehSKLiHOCjJ0S2XNOHXVk3AEesA4qe5dMyuZTqCK9wtU-_MRXFZj6000SRLROk0fd9R4DImOeusBE4_pb1i4iRUr8b6ow2cSMAGRk2KNWQZ_uKAhtVq6Jt3wU3GYMSAGCBHvzahEsYHKhlJXaSITrCVq4RAWWanNLDLnGiTt-eJcUzM7qgZWI9WiOtkFN2zYnTvvdy7PI78fW7k4-noDdwTuiWf-sXHO81SpLA6ty-pTpMcjjr7WBDzmO4j8tZRcHPiT4rKyUHpugSodFl_hiFgjxbmLGP8zvCcVyDMI1ogir8Iz-rHt8c&lib=Myn6iEwL8dqLg0i8ztc1Qms6Fh59HncaP';
const REMOTE_USERS_URL = 'https://script.google.com/macros/s/AKfycbwRoGXp8hU-e8vlTntOlAwlqofP9mQ3PqTSBy7b4WdbhZyTE9P5M2OSmfIqZn0s0RnN/exec?action=pass';

function fetch_json(string $url)
{
    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        fwrite(STDERR, "Failed to fetch $url\n");
        exit(1);
    }
    return json_decode($raw, true);
}

// Converts either a plain "yyyy-MM-dd" or a Sheets ISO datetime string to
// "Y-m-d", or null.
function normalize_date(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    if (str_contains($value, 'T')) {
        return substr($value, 0, 10);
    }
    return $value;
}

// Converts a Sheets time-only ISO string ("1899-12-30T06:45:00.000Z") or a
// plain "H:i" string to "H:i:s", or null.
function normalize_time(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    if (str_contains($value, 'T')) {
        [, $time] = explode('T', $value);
        return substr($time, 0, 8);
    }
    if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
        return $value . ':00';
    }
    return $value;
}

$pdo = pastores_db();

echo "Fetching users...\n";
$users = fetch_json(REMOTE_USERS_URL) ?: [];
echo 'Found ' . count($users) . " users.\n";

$zoneIds = [];
$insertZone = $pdo->prepare('INSERT IGNORE INTO zones (name) VALUES (?)');
$selectZone = $pdo->prepare('SELECT id FROM zones WHERE name = ?');
$insertUser = $pdo->prepare(
    'INSERT INTO users (zone_id, name, centre, section, role, passcode_hash) VALUES (?,?,?,?,?,?)'
);
$insertCentre = $pdo->prepare('INSERT IGNORE INTO centres (zone_id, name, section) VALUES (?, ?, ?)');

foreach ($users as $u) {
    $zoneName = $u['zone'] ?? null;
    if (!$zoneName) {
        continue;
    }
    if (!isset($zoneIds[$zoneName])) {
        $insertZone->execute([$zoneName]);
        $selectZone->execute([$zoneName]);
        $zoneIds[$zoneName] = (int) $selectZone->fetchColumn();
    }
    $zoneId = $zoneIds[$zoneName];

    $insertUser->execute([
        $zoneId,
        $u['name'] ?? '',
        $u['centre'] ?? null,
        $u['section'] ?? null,
        $u['role'] ?? null,
        hash_passcode((string) ($u['passcode'] ?? bin2hex(random_bytes(4)))),
    ]);

    if (!empty($u['centre'])) {
        $insertCentre->execute([$zoneId, $u['centre'], $u['section'] ?? null]);
    }
}

echo "Fetching activities per zone...\n";
$insertActivity = $pdo->prepare(
    'INSERT INTO activities (zone_id, unit, week, day, weekday, activity_date, centre, activity, section, labor, from_time, to_time, duration, mfrequency, priest, description)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);

foreach ($zoneIds as $zoneName => $zoneId) {
    $url = REMOTE_ACTIVITIES_URL . '?zone=' . urlencode($zoneName);
    $activities = fetch_json($url) ?: [];
    echo "  $zoneName: " . count($activities) . " activities\n";

    foreach ($activities as $a) {
        $insertActivity->execute([
            $zoneId,
            $a['unit'] ?? null,
            $a['week'] ?? null,
            $a['day'] ?? null,
            $a['weekday'] ?? null,
            normalize_date($a['date'] ?? null),
            $a['centre'] ?? null,
            $a['activity'] ?? null,
            $a['section'] ?? null,
            $a['labor'] ?? null,
            normalize_time($a['from'] ?? null),
            normalize_time($a['to'] ?? null),
            normalize_time($a['duration'] ?? null),
            $a['mfrequency'] ?? null,
            $a['priest'] ?? null,
            $a['description'] ?? null,
        ]);

        if (!empty($a['centre'])) {
            $insertCentre->execute([$zoneId, $a['centre'], $a['section'] ?? null]);
        }
    }
}

echo "Fetching masses (global)...\n";
$masses = fetch_json(REMOTE_MASSES_URL) ?: [];
$insertMass = $pdo->prepare('INSERT IGNORE INTO masses (zone_id, mass_date, class, mass) VALUES (NULL, ?, ?, ?)');
foreach ($masses as $date => $entry) {
    $insertMass->execute([
        normalize_date($date),
        $entry['Class'] ?? null,
        $entry['Mass'] ?? null,
    ]);
}
echo 'Imported ' . count($masses) . " mass entries.\n";

foreach ($zoneIds as $zoneName => $zoneId) {
    touch_zone($pdo, $zoneId);
}

echo "Done. Review the data in the admin panel before pointing the Next.js app at this database.\n";
