<?php
// Vigil activities on the admin Activities page. The Thursday before the first
// Friday of each month is a Vigil day: when activities are added (the New
// activity form, or Add from source) and that date has activities in the zone,
// every centre of the zone that has a section and doesn't have a Vigil that day
// gets one (centres without a section never get automatic Med / Ben / Vigil rows).
//
// These rows have the zone, date, centre, activity, the fields the form always
// derives (week, day, weekday, section) and the start / end times source gives
// that centre's Vigil; priest, labor and description are blank, like the
// Med / Ben rows added for class A dates (see source_apply.php).

const PASTORES_VIGIL_ACTIVITY = 'Vigil';

// True if $date (yyyy-mm-dd) is the Thursday before the first Friday of its
// month, i.e. a Thursday whose next day is a Friday in days 1-7. When the 1st
// is a Friday this is the last day of the previous month.
function is_vigil_date(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $d->format('N') !== '4') {
        return false;
    }
    return (int) $d->modify('+1 day')->format('j') <= 7;
}

// For a Vigil date on which the zone has at least one activity, adds a blank
// Vigil for each centre of the zone that has none that day ($centre limits it
// to one centre, for a centre-scoped admin). Returns how many were added.
// Doesn't start a transaction, so it can run inside one.
//
// With $sourceTimes (from source_time_lookup(), as the New activity form and
// Add from source pass) the Vigils get the start / end times (and duration)
// source gives each centre's Vigil, and $stats (added / timed / fallback, see
// source_apply.php) is filled in; without it they stay blank.
function add_vigil_activities(PDO $pdo, int $zoneId, string $date, string $weekStart, ?string $centre = null, ?array $sourceTimes = null, array &$stats = []): int
{
    if (!is_vigil_date($date)) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE zone_id = ? AND activity_date = ?');
    $stmt->execute([$zoneId, $date]);
    if (!$stmt->fetchColumn()) {
        return 0; // the date isn't in the activities list
    }

    $stmt = $pdo->prepare('SELECT name, section FROM centres WHERE zone_id = ? AND section IS NOT NULL AND section <> \'\'' . ($centre !== null ? ' AND name = ?' : '') . ' ORDER BY name');
    $stmt->execute($centre !== null ? [$zoneId, $centre] : [$zoneId]);
    $centres = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT centre FROM activities WHERE zone_id = ? AND activity_date = ? AND activity = ?');
    $stmt->execute([$zoneId, $date, PASTORES_VIGIL_ACTIVITY]);
    $have = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $have[mb_strtolower((string) $name)] = true;
    }

    $parts = date_parts($date, $weekStart);
    $insert = $pdo->prepare(
        'INSERT INTO activities (zone_id, week, day, weekday, activity_date, centre, activity, section, from_time, to_time, duration)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $added = 0;
    $stats = ['added' => 0, 'timed' => 0, 'fallback' => 0];
    foreach ($centres as $c) {
        if (!isset($have[mb_strtolower($c['name'])])) {
            $t = $sourceTimes !== null ? source_times_for($sourceTimes, $c['name'], PASTORES_VIGIL_ACTIVITY, $parts['weekday']) : null;
            $insert->execute([$zoneId, $parts['week'], $parts['day'], $parts['weekday'], $date, $c['name'], PASTORES_VIGIL_ACTIVITY, $c['section'],
                $t['from_time'] ?? null, $t['to_time'] ?? null, $t['duration'] ?? null]);
            $added++;
            $stats['added']++;
            $stats['timed'] += $t ? 1 : 0;
            $stats['fallback'] += $t && !$t['same_day'] ? 1 : 0;
        }
    }
    if ($added) {
        touch_zone($pdo, $zoneId);
    }
    return $added;
}
