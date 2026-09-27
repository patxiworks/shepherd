<?php
// Mass limit on the admin Activities page: a priest should not have more than
// mass_limit() masses on the same day (the max_masses_per_day setting). Like absences.php,
// activity_duplicates.php and activity_conflicts.php nothing is stored and
// nothing is blocked: the masses of a priest who is over the limit are flagged
// (table badge, filter, save warning, Add from source report).
//
//   - a mass is an activity named PASTORES_MASS_ACTIVITY (the "Mass" entry of
//     Admin > Activity types; compared case-insensitively)
//   - the priest is matched by name across zones (one person), so masses in
//     several zones on the same day add up
//   - every mass of that priest on that day is flagged, since there is no way
//     to say which one is "the extra one"; activities that aren't masses are
//     never flagged

const PASTORES_MASS_ACTIVITY = 'Mass';

// The limit is the `max_masses_per_day` setting (Admin > Settings, super admin);
// the default is used until it has been saved, and if the stored value isn't a
// whole number in the allowed range.
const PASTORES_DEFAULT_MAX_MASSES_PER_DAY = 2;
const PASTORES_MAX_MASSES_SETTING_RANGE = [1, 24];

// The maximum number of masses a priest may have in a day. A call with $pdo
// loads the setting (the Activities page does this once, at the top); later
// calls without it return the loaded value, so the SQL builders and the row
// renderer below need no $pdo.
function mass_limit(?PDO $pdo = null): int
{
    static $limit = PASTORES_DEFAULT_MAX_MASSES_PER_DAY;
    if ($pdo !== null) {
        $limit = PASTORES_DEFAULT_MAX_MASSES_PER_DAY;
        $value = get_setting($pdo, 'max_masses_per_day', (string) $limit);
        [$min, $max] = PASTORES_MAX_MASSES_SETTING_RANGE;
        if (ctype_digit($value) && (int) $value >= $min && (int) $value <= $max) {
            $limit = (int) $value;
        }
    }
    return $limit;
}

// SQL: the number of masses the priest of activity row `$a` has on its date
// (a scalar subquery; use it inside a query on `activities $a`).
function activity_mass_total_sql(string $a = 'a', string $m = 'm'): string
{
    return "(SELECT COUNT(*) FROM activities $m WHERE $m.priest = $a.priest AND $m.activity_date = $a.activity_date"
        . " AND $m.activity = '" . PASTORES_MASS_ACTIVITY . "')";
}

// SQL condition: activity `$a` is a mass of a priest with too many that day.
function activity_over_mass_limit_sql(string $a = 'a'): string
{
    return "($a.activity = '" . PASTORES_MASS_ACTIVITY . "' AND $a.priest IS NOT NULL AND $a.activity_date IS NOT NULL AND "
        . activity_mass_total_sql($a) . ' > ' . mass_limit() . ')';
}

// Extra select-list column `mass_count`: for a mass, how many masses its
// priest has that day; 0 for anything else.
function activity_mass_count_select(string $a = 'a'): string
{
    return ", IF($a.activity = '" . PASTORES_MASS_ACTIVITY . "' AND $a.priest IS NOT NULL AND $a.activity_date IS NOT NULL, "
        . activity_mass_total_sql($a) . ', 0) AS mass_count';
}

// mass_count of one saved activity.
function activity_mass_count(PDO $pdo, int $activityId): int
{
    $stmt = $pdo->prepare('SELECT 1' . activity_mass_count_select() . ' FROM activities a WHERE a.id = ?');
    $stmt->execute([$activityId]);
    return (int) $stmt->fetchColumn(1);
}

// Masses of a zone between two dates (inclusive) that belong to a priest over
// the limit: ['count' => number of masses, 'items' => ["Fr. X on 05/11 (4)", ...]].
// $centre limits it to one centre's activities.
function mass_limit_in_range(PDO $pdo, int $zoneId, string $from, string $to, ?string $centre = null): array
{
    $stmt = $pdo->prepare(
        'SELECT a.priest, a.activity_date, COUNT(*) AS n, ' . activity_mass_total_sql() . ' AS total FROM activities a
         WHERE a.zone_id = ? AND a.activity_date BETWEEN ? AND ?' . ($centre !== null ? ' AND a.centre = ?' : '') . '
           AND ' . activity_over_mass_limit_sql() . '
         GROUP BY a.priest, a.activity_date ORDER BY a.activity_date, a.priest'
    );
    $stmt->execute($centre !== null ? [$zoneId, $from, $to, $centre] : [$zoneId, $from, $to]);
    $result = ['count' => 0, 'items' => []];
    foreach ($stmt as $row) {
        $result['count'] += (int) $row['n'];
        $result['items'][] = $row['priest'] . ' on ' . date('d/m', strtotime($row['activity_date'])) . ' (' . (int) $row['total'] . ')';
    }
    return $result;
}
