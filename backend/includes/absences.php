<?php
// Priests' absences (table `absences`, see admin/absences/) checked against
// activities. Nothing is stored on the activity: whether a priest is absent
// is worked out whenever it is needed, so editing or deleting an absence
// takes effect straight away.
//
// An activity clashes with an absence when they have the same priest (matched
// by name, whichever zone the absence was recorded in) and the activity's
// time span overlaps the absence:
//   - from = the activity's date + from_time (00:00 if there is none)
//   - to   = the activity's date + to_time, else from_time + 1 minute, else
//            the end of the day (an activity without times is a whole-day one)
//   - overlap = absence.start_at < to AND absence.end_at > from
// Touching at a boundary (absence ends when the activity starts) is no clash.

// False if migration 012 hasn't been applied to this database.
function absences_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        $available = (bool) $pdo->query("SHOW TABLES LIKE 'absences'")->fetchColumn();
    }
    return $available;
}

// SQL condition: absence row `$ab` overlaps activity row `$a`. The only
// definition of "clash"; everything below builds on it.
function absence_overlap_sql(string $a = 'a', string $ab = 'ab'): string
{
    $from = "TIMESTAMP($a.activity_date, COALESCE($a.from_time, '00:00:00'))";
    $to = "TIMESTAMP($a.activity_date, COALESCE($a.to_time, ADDTIME($a.from_time, '00:01:00'), '23:59:59'))";
    return "$ab.priest = $a.priest AND $ab.start_at < $to AND $ab.end_at > $from";
}

// SQL condition (for a WHERE clause): the activity `$a` has an absent priest.
function activity_absent_sql(string $a = 'a'): string
{
    return 'EXISTS (SELECT 1 FROM absences ab WHERE ' . absence_overlap_sql($a) . ')';
}

// Extra select-list column `absent_note` for a query on `activities $a`: a
// text such as "01/10 09:00 to 03/10 17:30: Retreat" for each clashing
// absence, or NULL when the priest is available.
function activity_absent_select(PDO $pdo, string $a = 'a'): string
{
    if (!absences_available($pdo)) {
        return ', NULL AS absent_note';
    }
    return ", (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(ab.start_at, '%d/%m %H:%i'), ' to ', DATE_FORMAT(ab.end_at, '%d/%m %H:%i'),
                IF(COALESCE(ab.activity, '') = '', '', CONCAT(': ', ab.activity))) ORDER BY ab.start_at SEPARATOR '; ')
              FROM absences ab WHERE " . absence_overlap_sql($a) . ') AS absent_note';
}

// The absence text for one saved activity, or null if its priest is available.
function activity_absent_note(PDO $pdo, int $activityId): ?string
{
    $stmt = $pdo->prepare('SELECT 1' . activity_absent_select($pdo) . ' FROM activities a WHERE a.id = ?');
    $stmt->execute([$activityId]);
    $row = $stmt->fetch();
    return $row && $row['absent_note'] !== null ? $row['absent_note'] : null;
}

// Activities of a zone between two dates (inclusive) whose priest is absent,
// as ['count' => n, 'priests' => [name => activities]]. $centre limits it to
// one centre's activities.
function absent_activities_in_range(PDO $pdo, int $zoneId, string $from, string $to, ?string $centre = null): array
{
    $result = ['count' => 0, 'priests' => []];
    if (!absences_available($pdo)) {
        return $result;
    }
    $stmt = $pdo->prepare(
        'SELECT a.priest, COUNT(*) AS n FROM activities a
         WHERE a.zone_id = ? AND a.activity_date BETWEEN ? AND ?' . ($centre !== null ? ' AND a.centre = ?' : '') . '
           AND ' . activity_absent_sql() . '
         GROUP BY a.priest ORDER BY a.priest'
    );
    $stmt->execute($centre !== null ? [$zoneId, $from, $to, $centre] : [$zoneId, $from, $to]);
    foreach ($stmt as $row) {
        $result['priests'][$row['priest']] = (int) $row['n'];
        $result['count'] += (int) $row['n'];
    }
    return $result;
}
