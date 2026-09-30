<?php
// A priest who is in charge of a multi-day activity (table `multiday_activities`,
// column `priest`) is not free for other activities while it runs — checked
// against activities the same way as absences (see absences.php), and likewise
// worked out on the fly and only flagged, never blocked.
//
// An activity clashes with a multi-day one when
//   - it has the same priest (matched by name, in any zone),
//   - it is NOT at the multi-day activity's own centre (same zone + centre name;
//     a priest leading a retreat may still say Mass there), and
//   - its time span overlaps the multi-day one's, where the span runs from
//     start_date + start_time to end_date + end_time. The times are freeform and
//     usually blank; a blank start counts as 18:00 (evening arrival) and a blank
//     end as 12:00 (morning departure), as the calendar paints them. Times that
//     aren't HH:MM are treated as blank. A same-day entry that would end before
//     it starts is taken to run to the end of that day.
// The activity's own span is worked out as for absences (no times = whole day).

// False if the multi-day table or its priest column (migration 016) is missing.
function multiday_busy_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        $available = (bool) $pdo->query("SHOW COLUMNS FROM multiday_activities LIKE 'priest'", PDO::FETCH_NUM)->fetchColumn();
    }
    return $available;
}

function multiday_busy_available_safe(PDO $pdo): bool
{
    try {
        return multiday_busy_available($pdo);
    } catch (PDOException $e) {
        return false; // table not created yet
    }
}

// SQL condition: multi-day row `$m` keeps the priest of activity row `$a` busy.
function multiday_busy_overlap_sql(string $a = 'a', string $m = 'md'): string
{
    $time = fn(string $col, string $default) =>
        "IF($m.$col REGEXP '^[0-9]{1,2}:[0-9]{2}', TIME(STR_TO_DATE(SUBSTRING_INDEX(TRIM($m.$col), ' ', 1), '%H:%i')), '$default')";
    $mFrom = "TIMESTAMP($m.start_date, " . $time('start_time', '18:00:00') . ')';
    $mTo = "TIMESTAMP($m.end_date, " . $time('end_time', '12:00:00') . ')';
    $mToFixed = "IF($mTo <= $mFrom, TIMESTAMP($m.end_date, '23:59:59'), $mTo)";
    $from = "TIMESTAMP($a.activity_date, COALESCE($a.from_time, '00:00:00'))";
    $to = "TIMESTAMP($a.activity_date, COALESCE($a.to_time, ADDTIME($a.from_time, '00:01:00'), '23:59:59'))";
    return "$m.priest <> '' AND $m.priest = $a.priest AND NOT ($m.zone_id = $a.zone_id AND $m.centre <=> $a.centre)"
        . " AND $mFrom < $to AND $mToFixed > $from";
}

// SQL condition (for a WHERE clause): the activity `$a` has a priest busy in a multi-day activity.
function activity_in_multiday_sql(string $a = 'a'): string
{
    return 'EXISTS (SELECT 1 FROM multiday_activities md WHERE ' . multiday_busy_overlap_sql($a) . ')';
}

// Extra select-list columns for a query on `activities $a`:
//   multiday_note   "cv | LS" (activity | centre; several separated by ", "), the flag text
//   multiday_detail the same with dates, for the tooltip
// Both NULL when the priest is free.
function activity_in_multiday_select(PDO $pdo, string $a = 'a'): string
{
    if (!multiday_busy_available_safe($pdo)) {
        return ', NULL AS multiday_note, NULL AS multiday_detail';
    }
    $from = ' FROM multiday_activities md WHERE ' . multiday_busy_overlap_sql($a) . ') AS ';
    return ", (SELECT GROUP_CONCAT(CONCAT(md.activity, ' | ', md.centre) ORDER BY md.start_date SEPARATOR ', ')$from" . 'multiday_note'
        . ", (SELECT GROUP_CONCAT(CONCAT(md.activity, ' | ', md.centre, ' (', DATE_FORMAT(md.start_date, '%d/%m'), ' to ', DATE_FORMAT(md.end_date, '%d/%m'), ')') ORDER BY md.start_date SEPARATOR '; ')$from" . 'multiday_detail';
}

// [flag text, detail] for one saved activity, or null if its priest is free.
function activity_in_multiday_note(PDO $pdo, int $activityId): ?array
{
    if (!multiday_busy_available_safe($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT 1' . activity_in_multiday_select($pdo) . ' FROM activities a WHERE a.id = ?');
    $stmt->execute([$activityId]);
    $row = $stmt->fetch();
    return $row && $row['multiday_note'] !== null ? [$row['multiday_note'], $row['multiday_detail']] : null;
}

// Activities of a zone between two dates (inclusive) whose priest is busy in a
// multi-day activity, as ['count' => n, 'priests' => [name => "cv | LS" => ...]].
// $centre limits it to one centre's activities.
function in_multiday_activities_in_range(PDO $pdo, int $zoneId, string $from, string $to, ?string $centre = null): array
{
    $result = ['count' => 0, 'priests' => []];
    if (!multiday_busy_available_safe($pdo)) {
        return $result;
    }
    $stmt = $pdo->prepare(
        'SELECT a.priest, COUNT(*) AS n FROM activities a
         WHERE a.zone_id = ? AND a.activity_date BETWEEN ? AND ?' . ($centre !== null ? ' AND a.centre = ?' : '') . '
           AND ' . activity_in_multiday_sql() . '
         GROUP BY a.priest ORDER BY a.priest'
    );
    $stmt->execute($centre !== null ? [$zoneId, $from, $to, $centre] : [$zoneId, $from, $to]);
    foreach ($stmt as $row) {
        $result['priests'][$row['priest']] = (int) $row['n'];
        $result['count'] += (int) $row['n'];
    }
    return $result;
}
