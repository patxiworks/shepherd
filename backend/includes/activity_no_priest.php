<?php
// Activities with no priest assigned, on the admin Activities page. There
// must be a priest for every activity, so this is only flagged, not blocked
// (unlike an activity that duplicates another one) — some activities (e.g. a
// placeholder Med/Ben or Vigil row added automatically, see source_apply.php
// and vigil.php) legitimately have no priest yet until someone fills it in.

// SQL condition: activity row `$a` has no priest.
function activity_priest_missing_sql(string $a = 'a'): string
{
    return "$a.priest IS NULL";
}

// Number of activities of a zone between two dates (inclusive) with no
// priest. $centre limits it to one centre's activities.
function missing_priest_activities_in_range(PDO $pdo, int $zoneId, string $from, string $to, ?string $centre = null): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM activities a
         WHERE a.zone_id = ? AND a.activity_date BETWEEN ? AND ?' . ($centre !== null ? ' AND a.centre = ?' : '') . '
           AND ' . activity_priest_missing_sql()
    );
    $stmt->execute($centre !== null ? [$zoneId, $from, $to, $centre] : [$zoneId, $from, $to]);
    return (int) $stmt->fetchColumn();
}
