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

// Replaces, for every date in $from..$to (yyyy-mm-dd) that has matching
// source rows, the zone's activities on that date with those rows. Dates
// with no matching source row are left alone. $centre (a centre-scoped
// admin's centre) limits both the source rows used and the activities
// replaced to that centre. Runs in one transaction.
//
// Afterwards, every date in the range that is class A in the
// liturgical_calendar table gets a Med and a Ben activity for each centre of
// the zone (only $centre for a centre-scoped admin), unless that centre
// already has one on that date. These rows have only the zone, date, centre,
// activity and the fields the admin form always derives (week, day, weekday,
// section) filled in; priest, labor, times and description stay blank.
//
// Vigil: on each date in the range that is the Thursday before the first Friday
// of a month and has activities in the zone, every centre without a Vigil gets
// one (see vigil.php; only $centre for a centre-scoped admin), whether or not
// the date has source rows.
//
// Identical source rows (same date, centre, activity, priest and times, as in
// activity_duplicates.php) are only added once; the extra ones are skipped and
// reported.
//
// Returns ['dates' => dates filled from source, 'deleted' => activities
// replaced, 'inserted' => activities added from source, 'skipped' => identical
// source rows not added, 'skipped_list' => up to 10 of them as text,
// 'vigil_added' => Vigil rows added, 'vigil_dates' => dates that got some,
// 'class_a_dates' =>
// class A dates in the range, 'class_a_added' => Med/Ben rows added,
// 'calendar' => false if the liturgical_calendar table doesn't exist].
function apply_source_to_activities(PDO $pdo, int $zoneId, string $from, string $to, string $weekStart, ?string $centre = null): array
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

    $sql = 'SELECT week, day, centre, activity, section, labor, from_time, to_time, duration, priest, description
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
    $insert = $pdo->prepare(
        'INSERT INTO activities (zone_id, week, day, weekday, activity_date, centre, activity, section, labor, from_time, to_time, duration, priest, description)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
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
        $stmt = $pdo->prepare('SELECT name, section FROM centres WHERE zone_id = ?' . ($centre !== null ? ' AND name = ?' : '') . ' ORDER BY name');
        $stmt->execute($centre !== null ? [$zoneId, $centre] : [$zoneId]);
        $zoneCentres = $stmt->fetchAll();
    }
    $existing = $pdo->prepare("SELECT centre, activity FROM activities WHERE zone_id = ? AND activity_date = ? AND activity IN ('Med', 'Ben')");
    $insertBlank = $pdo->prepare(
        'INSERT INTO activities (zone_id, week, day, weekday, activity_date, centre, activity, section) VALUES (?,?,?,?,?,?,?,?)'
    );

    $result = ['dates' => 0, 'deleted' => 0, 'inserted' => 0, 'skipped' => 0, 'skipped_list' => [], 'vigil_added' => 0, 'vigil_dates' => 0, 'class_a_dates' => count($classA), 'class_a_added' => 0, 'calendar' => $calendar];
    $pdo->beginTransaction();
    try {
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $parts = date_parts($date, $weekStart);
            $rows = $bySlot[$parts['week'] . '-' . $parts['weekday']] ?? [];
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
                        $r['section'], $r['labor'], $r['from_time'], $r['to_time'], $r['duration'], $r['priest'], $r['description'],
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
                            $insertBlank->execute([$zoneId, $parts['week'], $parts['day'], $parts['weekday'], $date, $c['name'], $activity, $c['section']]);
                            $result['class_a_added']++;
                        }
                    }
                }
            }

            $vigil = add_vigil_activities($pdo, $zoneId, $date, $weekStart, $centre);
            if ($vigil) {
                $result['vigil_added'] += $vigil;
                $result['vigil_dates']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if ($result['dates'] || $result['class_a_added'] || $result['vigil_added']) {
        touch_zone($pdo, $zoneId);
    }
    return $result;
}
