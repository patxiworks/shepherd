<?php
// Priest bilocation on the admin Activities page: the same priest booked on
// the same date with overlapping times in *different centres* (he can't be
// in two places at once). Like absences.php and activity_duplicates.php
// nothing is stored; it is worked out when needed.
//
// Overlapping activities of the same priest in the same centre are not
// bilocation (e.g. a Mass and a Confession in one church), and an exact
// duplicate (activity_duplicates.php) is one of those, so it is never
// bilocation either.
//
//   - the priest is matched by name, across zones (one person, one entry)
//   - a centre is identified by zone + name, so same-named centres in
//     different zones count as different centres
//   - both activities need a from time; an activity without times is not
//     compared, since we don't know when it happens
//   - an activity runs from from_time to to_time, or for one minute if it has
//     no to_time
//   - overlap = other.from < this.to AND other.to > this.from, so 10:00-11:00
//     and 11:00-12:00 do not overlap

// SQL condition: activity row `$c` bilocates with activity row `$a`.
function activity_bilocation_sql(string $a = 'a', string $c = 'c'): string
{
    return "$c.id <> $a.id AND $c.priest = $a.priest AND $c.activity_date = $a.activity_date"
        . " AND $a.from_time IS NOT NULL AND $c.from_time IS NOT NULL"
        . " AND $c.from_time < COALESCE($a.to_time, ADDTIME($a.from_time, '00:01:00'))"
        . " AND COALESCE($c.to_time, ADDTIME($c.from_time, '00:01:00')) > $a.from_time"
        . " AND NOT ($c.zone_id = $a.zone_id AND $c.centre <=> $a.centre)";
}

// SQL condition (for a WHERE clause): the activity `$a` is a bilocation.
function activity_has_bilocation_sql(string $a = 'a'): string
{
    return 'EXISTS (SELECT 1 FROM activities c WHERE ' . activity_bilocation_sql($a) . ')';
}

// Extra select-list column `bilocation_note`: the other activities as
// "Mass at Centre 10:30-11:30" (plus " (Zone name)" when it is in another
// zone), or NULL when there are none.
function activity_bilocation_select(string $a = 'a'): string
{
    return ", (SELECT GROUP_CONCAT(CONCAT(COALESCE(c.activity, 'activity'), ' at ', COALESCE(c.centre, '?'), ' ', TIME_FORMAT(c.from_time, '%H:%i'),
                IF(c.to_time IS NULL, '', CONCAT('-', TIME_FORMAT(c.to_time, '%H:%i'))), IF(c.zone_id = $a.zone_id, '', CONCAT(' (', (SELECT z.name FROM zones z WHERE z.id = c.zone_id), ')')))
                ORDER BY c.from_time SEPARATOR '; ')
              FROM activities c WHERE " . activity_bilocation_sql($a) . ') AS bilocation_note';
}

// The bilocation of one saved activity as text, or null if there is none.
function activity_bilocation_note(PDO $pdo, int $activityId): ?string
{
    $stmt = $pdo->prepare('SELECT 1' . activity_bilocation_select() . ' FROM activities a WHERE a.id = ?');
    $stmt->execute([$activityId]);
    $note = $stmt->fetchColumn(1);
    return $note !== false && $note !== null ? $note : null;
}

// Number of activities of a zone between two dates (inclusive) that are a
// bilocation. $centre limits it to one centre's activities.
function bilocation_activities_in_range(PDO $pdo, int $zoneId, string $from, string $to, ?string $centre = null): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM activities a
         WHERE a.zone_id = ? AND a.activity_date BETWEEN ? AND ?' . ($centre !== null ? ' AND a.centre = ?' : '') . '
           AND ' . activity_has_bilocation_sql()
    );
    $stmt->execute($centre !== null ? [$zoneId, $from, $to, $centre] : [$zoneId, $from, $to]);
    return (int) $stmt->fetchColumn();
}
