<?php
// "Add from source" on the admin Activities page: fills a date range of one
// zone's activities from the source table.
//
// Each date is matched to the source rows with the same week (which
// occurrence of that day of the week it is in the month: days 1-7 = 1,
// 8-14 = 2, ...) and day (its number in the week per the week_start setting),
// e.g. 2026-09-24 is a Thursday, the 4th one of the month, so it takes the
// source rows with week 4 and day 5 (Sunday start). See date_parts().

// Longest date range accepted in one go.
const SOURCE_APPLY_MAX_DAYS = 366;

// The activities that Add from source creates on its own (class A: Med and Ben;
// Vigil dates: Vigil), as opposed to copying them from source rows.
const SOURCE_APPLY_AUTO_ACTIVITIES = ['Med', 'Ben', 'Vigil'];

// The start / end times (and duration) that source gives each centre's Med, Ben
// and Vigil, for the rows Add from source creates by itself. Returns
// centre => activity => ['day' => [weekday number => [pair, ...]], 'all' => [pair, ...]]
// (names lower-cased), where a pair is ['from_time', 'to_time', 'duration', 'n'
// = how many source rows have it). Only source rows with a start time count.
function source_time_lookup(PDO $pdo, int $zoneId, ?string $centre = null): array
{
    $in = "'" . implode("','", SOURCE_APPLY_AUTO_ACTIVITIES) . "'";
    $stmt = $pdo->prepare(
        "SELECT centre, activity, day, from_time, to_time, duration, COUNT(*) AS n FROM source
         WHERE zone_id = ? AND activity IN ($in) AND from_time IS NOT NULL AND centre IS NOT NULL"
        . ($centre !== null ? ' AND centre = ?' : '') . ' GROUP BY centre, activity, day, from_time, to_time, duration'
    );
    $stmt->execute($centre !== null ? [$zoneId, $centre] : [$zoneId]);
    $lookup = [];
    $add = function (array &$list, array $r): void {
        $key = $r['from_time'] . '|' . $r['to_time'] . '|' . $r['duration'];
        if (isset($list[$key])) {
            $list[$key]['n'] += (int) $r['n'];
        } else {
            $list[$key] = ['from_time' => $r['from_time'], 'to_time' => $r['to_time'], 'duration' => $r['duration'], 'n' => (int) $r['n']];
        }
    };
    foreach ($stmt as $r) {
        $slot = &$lookup[mb_strtolower($r['centre'])][mb_strtolower($r['activity'])];
        $slot ??= ['day' => [], 'all' => []];
        $slot['day'][(int) $r['day']] ??= [];
        $add($slot['day'][(int) $r['day']], $r);
        $add($slot['all'], $r);
        unset($slot);
    }
    return $lookup;
}

// The times to give $activity at $centre on a date whose weekday number is
// $weekday: the most common pair among the centre's source rows for that
// activity on the same weekday, else (the activity is at a fixed time, or
// source has nothing for that weekday) the most common pair over all weekdays.
// Ties go to the earlier start. Returns ['from_time', 'to_time', 'duration',
// 'same_day' => bool], or null if source has no times for it.
function source_times_for(array $lookup, string $centre, string $activity, int $weekday): ?array
{
    $slot = $lookup[mb_strtolower($centre)][mb_strtolower($activity)] ?? null;
    if (!$slot) {
        return null;
    }
    foreach ([[$slot['day'][$weekday] ?? [], true], [$slot['all'], false]] as [$pairs, $sameDay]) {
        if ($pairs) {
            usort($pairs, fn($a, $b) => [$b['n'], $a['from_time'], (string) $a['to_time']] <=> [$a['n'], $b['from_time'], (string) $b['to_time']]);
            return ['from_time' => $pairs[0]['from_time'], 'to_time' => $pairs[0]['to_time'], 'duration' => $pairs[0]['duration'], 'same_day' => $sameDay];
        }
    }
    return null;
}

// Replaces (when $overwrite; otherwise only fills dates that have no activities yet), for every date in $from..$to (yyyy-mm-dd) that has matching
// source rows, the zone's activities on that date with those rows. Dates
// with no matching source row are left alone. $centre (a centre-scoped
// admin's centre) limits both the source rows used and the activities
// replaced to that centre. Runs in one transaction.
//
// Afterwards, every date in the range that is class A in the
// liturgical_calendar table gets a Med and a Ben activity for each centre of
// the zone that has a section (only $centre for a centre-scoped admin), unless that centre
// already has one on that date. These rows have the zone, date, centre,
// activity, the fields the admin form always derives (week, day, weekday,
// section) and the start / end times (and duration) that source gives that
// centre's Med / Ben (see source_times_for()); priest, labor and description
// stay blank, and so do the times if source has none for the centre.
//
// Vigil: on each date in the range that is the Thursday before the first Friday
// of a month and has activities in the zone, every centre without a Vigil gets
// one (see vigil.php; only $centre for a centre-scoped admin), whether or not
// the date has source rows, with the times source gives that centre's Vigil.
//
// Identical source rows (same date, centre, activity, priest and times, as in
// activity_duplicates.php) are only added once; the extra ones are skipped and
// reported.
//
// Returns ['dates' => dates filled from source, 'kept_dates' => dates with source rows left alone because they already had activities (no overwrite), 'deleted' => activities
// replaced, 'inserted' => activities added from source, 'skipped' => identical
// source rows not added, 'skipped_list' => up to 10 of them as text,
// 'vigil_added' => Vigil rows added, 'vigil_dates' => dates that got some,
// 'class_a_dates' =>
// class A dates in the range, 'class_a_added' => Med/Ben rows added,
// 'auto_added' => class A + Vigil rows added, 'auto_timed' => how many of them
// got times from source, 'auto_fallback' => of those, how many took the
// centre's usual times because source has none for that weekday,
// 'calendar' => false if the liturgical_calendar table doesn't exist].
function apply_source_to_activities(PDO $pdo, int $zoneId, string $from, string $to, string $weekStart, ?string $centre = null, bool $overwrite = true): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
        throw new InvalidArgumentException('Choose a valid start date and end date.');
    }
    if ($end < $start) {
        throw new InvalidArgumentException('The end date must not be before the start date.');
    }
    if ($start->diff($end)->days + 1 > SOURCE_APPLY_MAX_DAYS) {
        throw new InvalidArgumentException('Choose a range of at most ' . SOURCE_APPLY_MAX_DAYS . ' days.');
    }

    $sql = 'SELECT week, day, centre, activity, section, labor, from_time, to_time, duration, priest, alt_priest, description
            FROM source WHERE zone_id = ?';
    $args = [$zoneId];
    if ($centre !== null) {
        $sql .= ' AND centre = ?';
        $args[] = $centre;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY from_time, id');
    $stmt->execute($args);
    $bySlot = []; // "week-day" => source rows
    foreach ($stmt as $row) {
        $bySlot[$row['week'] . '-' . $row['day']][] = $row;
    }

    $deleteSql = 'DELETE FROM activities WHERE zone_id = ? AND activity_date = ?' . ($centre !== null ? ' AND centre = ?' : '');
    $delete = $pdo->prepare($deleteSql);
    $hasAny = $pdo->prepare('SELECT 1 FROM activities WHERE zone_id = ? AND activity_date = ?' . ($centre !== null ? ' AND centre = ?' : '') . ' LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO activities (zone_id, week, day, weekday, activity_date, centre, activity, section, labor, from_time, to_time, duration, priest, alt_priest, description)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    // Class A dates in the range, and the centres that get the Med / Ben rows.
    $classA = [];
    $calendar = true;
    try {
        $stmt = $pdo->prepare("SELECT cal_date FROM liturgical_calendar WHERE class = 'A' AND cal_date BETWEEN ? AND ?");
        $stmt->execute([$from, $to]);
        $classA = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') { // 42S02: table doesn't exist (migration 009 not applied)
            throw $e;
        }
        $calendar = false;
    }
    $zoneCentres = [];
    if ($classA) {
        $stmt = $pdo->prepare('SELECT name, section FROM centres WHERE zone_id = ? AND section IS NOT NULL AND section <> \'\'' . ($centre !== null ? ' AND name = ?' : '') . ' ORDER BY name');
        $stmt->execute($centre !== null ? [$zoneId, $centre] : [$zoneId]);
        $zoneCentres = $stmt->fetchAll();
    }
    $existing = $pdo->prepare("SELECT centre, activity FROM activities WHERE zone_id = ? AND activity_date = ? AND activity IN ('Med', 'Ben')");
    $insertAuto = $pdo->prepare(
        'INSERT INTO activities (zone_id, week, day, weekday, activity_date, centre, activity, section, from_time, to_time, duration)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $times = source_time_lookup($pdo, $zoneId, $centre);
    $autoStats = ['added' => 0, 'timed' => 0, 'fallback' => 0];

    $result = ['dates' => 0, 'deleted' => 0, 'kept_dates' => 0, 'inserted' => 0, 'skipped' => 0, 'skipped_list' => [], 'vigil_added' => 0, 'vigil_dates' => 0, 'class_a_dates' => count($classA), 'class_a_added' => 0, 'auto_added' => 0, 'auto_timed' => 0, 'auto_fallback' => 0, 'calendar' => $calendar];
    $pdo->beginTransaction();
    try {
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $parts = date_parts($date, $weekStart);
            $rows = $bySlot[$parts['week'] . '-' . $parts['weekday']] ?? [];
            if ($rows && !$overwrite) {
                // Not overwriting: a date that already has activities keeps them.
                $hasAny->execute($centre !== null ? [$zoneId, $date, $centre] : [$zoneId, $date]);
                if ($hasAny->fetchColumn()) {
                    $rows = [];
                    $result['kept_dates']++;
                }
            }
            if ($rows) {
                $delete->execute($centre !== null ? [$zoneId, $date, $centre] : [$zoneId, $date]);
                $result['deleted'] += $delete->rowCount();
                $seen = [];
                foreach ($rows as $r) {
                    $key = implode('|', array_map(fn($v) => mb_strtolower((string) $v), [$r['centre'], $r['activity'], $r['priest'], $r['from_time'], $r['to_time']]));
                    if (isset($seen[$key])) {
                        $result['skipped']++;
                        if (count($result['skipped_list']) < 10) {
                            $result['skipped_list'][] = trim("{$r['activity']} at {$r['centre']} " . substr((string) $r['from_time'], 0, 5)
                                . ($r['priest'] ? " ({$r['priest']})" : '') . " on $date");
                        }
                        continue;
                    }
                    $seen[$key] = true;
                    $insert->execute([
                        $zoneId, $parts['week'], $parts['day'], $parts['weekday'], $date, $r['centre'], $r['activity'],
                        $r['section'], $r['labor'], $r['from_time'], $r['to_time'], $r['duration'], $r['priest'], $r['alt_priest'], $r['description'],
                    ]);
                    $result['inserted']++;
                }
                $result['dates']++;
            }

            if (isset($classA[$date])) {
                // Med / Ben for every centre that doesn't have them yet (this
                // sees the rows just inserted from source).
                $existing->execute([$zoneId, $date]);
                $have = [];
                foreach ($existing as $e) {
                    $have[mb_strtolower((string) $e['centre']) . '|' . mb_strtolower($e['activity'])] = true;
                }
                foreach ($zoneCentres as $c) {
                    foreach (['Med', 'Ben'] as $activity) {
                        if (!isset($have[mb_strtolower($c['name']) . '|' . mb_strtolower($activity)])) {
                            $t = source_times_for($times, $c['name'], $activity, $parts['weekday']);
                            $insertAuto->execute([$zoneId, $parts['week'], $parts['day'], $parts['weekday'], $date, $c['name'], $activity, $c['section'],
                                $t['from_time'] ?? null, $t['to_time'] ?? null, $t['duration'] ?? null]);
                            $result['class_a_added']++;
                            $autoStats['added']++;
                            $autoStats['timed'] += $t ? 1 : 0;
                            $autoStats['fallback'] += $t && !$t['same_day'] ? 1 : 0;
                        }
                    }
                }
            }

            $vigilStats = ['added' => 0, 'timed' => 0, 'fallback' => 0];
            $vigil = add_vigil_activities($pdo, $zoneId, $date, $weekStart, $centre, $times, $vigilStats);
            foreach ($autoStats as $k => $_) {
                $autoStats[$k] += $vigilStats[$k];
            }
            if ($vigil) {
                $result['vigil_added'] += $vigil;
                $result['vigil_dates']++;
            }
        }
        $pdo->commit();
        $result['auto_added'] = $result['class_a_added'] + $result['vigil_added'];
        $result['auto_timed'] = $autoStats['timed'];
        $result['auto_fallback'] = $autoStats['fallback'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if ($result['dates'] || $result['class_a_added'] || $result['vigil_added']) {
        touch_zone($pdo, $zoneId);
    }
    return $result;
}
