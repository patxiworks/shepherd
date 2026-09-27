<?php
// Duplicate activities on the admin Activities page. Like absences (see
// absences.php) nothing is stored: it is worked out whenever it is needed.
// New activities that would duplicate an existing one are not added (the New
// activity form, and Add from source, see source_apply.php); old data and
// edits can still produce duplicates, which are flagged in the table.
// Different from a *conflict* (activity_conflicts.php): the same priest at
// overlapping but not identical times.
//
// Two activities are duplicates when they are in the same zone, on the same
// date, and have the same centre, activity, priest, from time and to time
// (empty matches empty; description and labor are not compared). Every copy is
// flagged, not just the later ones. Activities without a date are never
// flagged.

// True if an activity identical to $fields (as built by activity_fields():
// date, centre, activity, priest, from/to time) already exists in the zone.
function identical_activity_exists(PDO $pdo, int $zoneId, array $fields): bool
{
    if (empty($fields['activity_date'])) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM activities WHERE zone_id = ? AND activity_date = ? AND centre <=> ? AND activity <=> ?
           AND priest <=> ? AND from_time <=> ? AND to_time <=> ?'
    );
    $stmt->execute([$zoneId, $fields['activity_date'], $fields['centre'], $fields['activity'], $fields['priest'], $fields['from_time'], $fields['to_time']]);
    return (bool) $stmt->fetchColumn();
}

// SQL condition: activity row `$d` duplicates activity row `$a` (a different row).
function activity_duplicate_sql(string $a = 'a', string $d = 'd'): string
{
    return "$d.id <> $a.id AND $d.zone_id = $a.zone_id AND $d.activity_date = $a.activity_date"
        . " AND $d.centre <=> $a.centre AND $d.activity <=> $a.activity AND $d.priest <=> $a.priest"
        . " AND $d.from_time <=> $a.from_time AND $d.to_time <=> $a.to_time";
}

// SQL condition (for a WHERE clause): the activity `$a` has a duplicate.
function activity_has_duplicate_sql(string $a = 'a'): string
{
    return 'EXISTS (SELECT 1 FROM activities d WHERE ' . activity_duplicate_sql($a) . ')';
}

// Extra select-list column `duplicate_count` for a query on `activities $a`:
// how many other identical activities there are (0 if none).
function activity_duplicate_select(string $a = 'a'): string
{
    return ', (SELECT COUNT(*) FROM activities d WHERE ' . activity_duplicate_sql($a) . ') AS duplicate_count';
}

// How many other identical activities one saved activity has.
function activity_duplicate_count(PDO $pdo, int $activityId): int
{
    $stmt = $pdo->prepare('SELECT 1' . activity_duplicate_select() . ' FROM activities a WHERE a.id = ?');
    $stmt->execute([$activityId]);
    return (int) $stmt->fetchColumn(1);
}

// Number of activities of a zone between two dates (inclusive) that have a
// duplicate. $centre limits it to one centre's activities.
function duplicate_activities_in_range(PDO $pdo, int $zoneId, string $from, string $to, ?string $centre = null): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM activities a
         WHERE a.zone_id = ? AND a.activity_date BETWEEN ? AND ?' . ($centre !== null ? ' AND a.centre = ?' : '') . '
           AND ' . activity_has_duplicate_sql()
    );
    $stmt->execute($centre !== null ? [$zoneId, $from, $to, $centre] : [$zoneId, $from, $to]);
    return (int) $stmt->fetchColumn();
}
