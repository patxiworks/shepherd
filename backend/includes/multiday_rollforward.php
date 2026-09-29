<?php
// "Roll forward to a new year" for multiday_activities (List tab), ported from
// the standalone Painted Calendar tool. Every fixed-length entry that starts in
// the source year is copied into the target year, its dates moved by its
// roll_rule; Free / Maintenance placeholders are then generated instead of being
// copied. Unlike the original, which replaced its whole log, this only ADDS rows:
// the source year is left alone, and entries already present in the target year
// are skipped, so a roll can be previewed and re-run safely.
//
// The plan is computed by mday_roll_plan() (pure read); the preview page shows
// it and "Apply" recomputes it and inserts (mday_roll_apply()).

const MDAY_ROLL_RULES = ['exact' => 'Exact date', 'weekend' => 'Stick to weekend', 'flexible' => 'Flexible'];
const MDAY_ROLL_FREE = 'Free';
const MDAY_ROLL_MAINTENANCE = 'Maintenance';
// A placeholder occupies whole days: from the morning slot of its first day to
// the evening slot of its last (the original's M..E), so it gets explicit times.
const MDAY_ROLL_START_TIME = '00:00:00';
const MDAY_ROLL_END_TIME = '23:59:00';

function mday_roll_is_placeholder(?string $activity): bool
{
    $a = strtolower(trim((string) $activity));
    return $a === 'free' || $a === 'maintenance';
}

// The date in $targetYear on the same weekday as $original, nearest to the
// same month/day (searching forward before backward, up to a week each way).
function mday_roll_nearest_weekday(DateTimeImmutable $original, int $targetYear): DateTimeImmutable
{
    $literal = $original->setDate($targetYear, (int) $original->format('n'), (int) $original->format('j'));
    $weekday = $original->format('w');
    for ($offset = 0; $offset <= 7; $offset++) {
        $forward = $literal->modify("+$offset days");
        if ($forward->format('w') === $weekday) return $forward;
        $backward = $literal->modify("-$offset days");
        if ($backward->format('w') === $weekday) return $backward;
    }
    return $literal;
}

function mday_roll_shift(DateTimeImmutable $original, string $rule, int $targetYear): DateTimeImmutable
{
    if ($rule === 'exact') {
        return $original->setDate($targetYear, (int) $original->format('n'), (int) $original->format('j'));
    }
    return mday_roll_nearest_weekday($original, $targetYear); // 'weekend' and 'flexible' behave alike
}

function mday_roll_key(array $r): string
{
    return implode('|', [$r['zone_id'], $r['centre'], $r['activity'], $r['section'], $r['labor'], $r['priest'],
        $r['start_date'], $r['start_time'] ? substr($r['start_time'], 0, 5) : '', $r['end_date'], $r['end_time'] ? substr($r['end_time'], 0, 5) : '', $r['description']]);
}

// Returns:
//   shifted      rows to insert (with source_start/source_end for the preview) — new, not already present
//   skipped      how many shifted rows already exist in the target year
//   placeholders rows to insert (activity Free/Maintenance); 'unplaced' rows are warnings only
// $opts: free (bool: Free-day placeholders), maintenance (int min days per venue, 0 = none),
//        venues (list of centre names that get maintenance).
function mday_roll_plan(PDO $pdo, ?int $zoneId, int $from, int $to, array $opts): array
{
    $scopeArgs = $zoneId !== null ? [$zoneId] : [];

    $src = $pdo->prepare('SELECT m.*, z.name AS zone_name FROM multiday_activities m JOIN zones z ON z.id = m.zone_id
                          WHERE YEAR(m.start_date) = ?' . ($zoneId !== null ? ' AND m.zone_id = ?' : '') . ' ORDER BY z.name, m.centre, m.start_date, m.id');
    $src->execute([$from, ...$scopeArgs]);

    $exist = $pdo->prepare('SELECT * FROM multiday_activities WHERE start_date <= ? AND end_date >= ?' . ($zoneId !== null ? ' AND zone_id = ?' : ''));
    $exist->execute(["$to-12-31", "$to-01-01", ...$scopeArgs]);
    $existingKeys = [];
    $occupied = [];        // "zone|centre" => set of 'Y-m-d' already booked in the target year
    $existingFreeMonths = [];
    $existingMaintDays = [];
    $markDays = function (string $key, string $s, string $e) use (&$occupied): void {
        for ($d = new DateTimeImmutable($s), $end = new DateTimeImmutable($e); $d <= $end; $d = $d->modify('+1 day')) {
            $occupied[$key][$d->format('Y-m-d')] = true;
        }
    };
    foreach ($exist->fetchAll() as $r) {
        $existingKeys[mday_roll_key($r)] = true;
        $key = $r['zone_id'] . '|' . $r['centre'];
        $markDays($key, $r['start_date'], $r['end_date']);
        $a = strtolower(trim((string) $r['activity']));
        if ($a === 'free' && (int) substr($r['start_date'], 0, 4) === $to) {
            $existingFreeMonths[$key][(int) substr($r['start_date'], 5, 2)] = true;
        }
        if ($a === 'maintenance') {
            $end = new DateTimeImmutable(min($r['end_date'], "$to-12-31"));
            for ($d = new DateTimeImmutable(max($r['start_date'], "$to-01-01")); $d <= $end; $d = $d->modify('+1 day')) {
                $existingMaintDays[$key] = ($existingMaintDays[$key] ?? 0) + 1;
            }
        }
    }

    $shifted = [];
    $skipped = 0;
    $venueKeys = []; // "zone|centre" => [zone_id, centre, zone_name] for every venue that has a rolled entry
    foreach ($src->fetchAll() as $m) {
        if (mday_roll_is_placeholder($m['activity'])) continue; // regenerated below
        $start = new DateTimeImmutable($m['start_date']);
        $length = (int) $start->diff(new DateTimeImmutable($m['end_date']))->days;
        $newStart = mday_roll_shift($start, $m['roll_rule'], $to);
        $newEnd = $newStart->modify("+$length days");
        $row = [
            'zone_id' => (int) $m['zone_id'], 'zone_name' => $m['zone_name'], 'centre' => $m['centre'], 'activity' => $m['activity'],
            'section' => $m['section'], 'labor' => $m['labor'], 'priest' => $m['priest'],
            'start_date' => $newStart->format('Y-m-d'), 'start_time' => $m['start_time'],
            'end_date' => $newEnd->format('Y-m-d'), 'end_time' => $m['end_time'],
            'description' => $m['description'], 'roll_rule' => $m['roll_rule'],
            'source_start' => $m['start_date'], 'source_end' => $m['end_date'],
        ];
        $key = $row['zone_id'] . '|' . $row['centre'];
        $venueKeys[$key] = [$row['zone_id'], $row['centre'], $row['zone_name']];
        if (isset($existingKeys[mday_roll_key($row)])) {
            $skipped++;
            continue;
        }
        $markDays($key, $row['start_date'], $row['end_date']);
        $shifted[] = $row;
    }

    $placeholders = [];
    $isFree = fn(string $key, DateTimeImmutable $d) => !isset($occupied[$key][$d->format('Y-m-d')]);
    $add = function (array $venue, string $activity, DateTimeImmutable $s, DateTimeImmutable $e, string $desc, bool $unplaced = false) use (&$placeholders, $markDays): void {
        [$zid, $centre, $zoneName] = $venue;
        $placeholders[] = [
            'zone_id' => $zid, 'zone_name' => $zoneName, 'centre' => $centre, 'activity' => $activity, 'section' => null, 'labor' => null, 'priest' => null,
            'start_date' => $s->format('Y-m-d'), 'start_time' => MDAY_ROLL_START_TIME, 'end_date' => $e->format('Y-m-d'), 'end_time' => MDAY_ROLL_END_TIME,
            'description' => $desc, 'roll_rule' => 'flexible', 'unplaced' => $unplaced,
        ];
        if (!$unplaced) $markDays($zid . '|' . $centre, $s->format('Y-m-d'), $e->format('Y-m-d'));
    };

    if (!empty($opts['free'])) {
        // Rule 1: a Free day after every 'ca' entry ends, if that day is open (in the target
        // year, or the first 3 days of the next — a 'ca' ending right at New Year).
        foreach ($shifted as $r) {
            if (strtolower(trim((string) $r['activity'])) !== 'ca') continue;
            $free = (new DateTimeImmutable($r['end_date']))->modify('+1 day');
            $inYear = (int) $free->format('Y') === $to;
            $spill = (int) $free->format('Y') === $to + 1 && $free->format('n') === '1' && (int) $free->format('j') <= 3;
            if (($inYear || $spill) && $isFree($r['zone_id'] . '|' . $r['centre'], $free)) {
                $add([$r['zone_id'], $r['centre'], $r['zone_name']], MDAY_ROLL_FREE, $free, $free, 'Auto-placed: day after ' . $r['activity'] . '-' . $r['labor'] . ' ends');
            }
        }
        // Rule 2: at least one Free day in every month, for each venue that has entries.
        foreach ($venueKeys as $key => $venue) {
            for ($mo = 1; $mo <= 12; $mo++) {
                $has = isset($existingFreeMonths[$key][$mo]);
                foreach ($placeholders as $p) {
                    if ($p['activity'] === MDAY_ROLL_FREE && $p['zone_id'] . '|' . $p['centre'] === $key
                        && (int) substr($p['start_date'], 0, 4) === $to && (int) substr($p['start_date'], 5, 2) === $mo) {
                        $has = true;
                    }
                }
                if ($has) continue;
                $days = (int) date('t', strtotime(sprintf('%04d-%02d-01', $to, $mo)));
                for ($d = 1; $d <= $days; $d++) {
                    $c = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $to, $mo, $d));
                    if ($isFree($key, $c)) {
                        $add($venue, MDAY_ROLL_FREE, $c, $c, 'Auto-placed: monthly minimum free day');
                        break;
                    }
                }
            }
        }
    }

    // Rule 3: at least N Maintenance days a year for the chosen venues, greedily placed in the
    // largest open gaps (does not try to optimise placement).
    $wanted = array_map('mb_strtolower', $opts['venues'] ?? []);
    if (($opts['maintenance'] ?? 0) > 0 && $wanted) {
        foreach ($venueKeys as $key => $venue) {
            if (!in_array(mb_strtolower((string) $venue[1]), $wanted, true)) continue;
            $needed = (int) $opts['maintenance'] - ($existingMaintDays[$key] ?? 0);
            if ($needed <= 0) continue;
            $runs = [];
            $runStart = null;
            $yearEnd = new DateTimeImmutable("$to-12-31");
            for ($d = new DateTimeImmutable("$to-01-01"); $d <= $yearEnd; $d = $d->modify('+1 day')) {
                $free = $isFree($key, $d);
                if ($free && $runStart === null) $runStart = $d;
                if (!$free && $runStart !== null) { $runs[] = [$runStart, $d->modify('-1 day')]; $runStart = null; }
            }
            if ($runStart !== null) $runs[] = [$runStart, $yearEnd];
            usort($runs, fn($a, $b) => $b[0]->diff($b[1])->days <=> $a[0]->diff($a[1])->days); // largest gap first
            foreach ($runs as [$rs, $re]) {
                if ($needed <= 0) break;
                $take = min($needed, (int) $rs->diff($re)->days + 1);
                $add($venue, MDAY_ROLL_MAINTENANCE, $rs, $rs->modify('+' . ($take - 1) . ' days'), "Auto-placed: {$take}d toward {$opts['maintenance']}-day minimum maintenance window");
                $needed -= $take;
            }
            if ($needed > 0) {
                $add($venue, MDAY_ROLL_MAINTENANCE, new DateTimeImmutable("$to-01-01"), new DateTimeImmutable("$to-01-01"),
                    "Could not auto-place $needed of {$opts['maintenance']} required maintenance days — no open gap found. Add manually.", true);
            }
        }
    }

    return ['shifted' => $shifted, 'skipped' => $skipped, 'placeholders' => $placeholders];
}

// Inserts a plan's shifted entries and (placed) placeholders in one transaction,
// creating the Free / Maintenance activity types on first use. Returns
// [shifted inserted, placeholders inserted].
function mday_roll_apply(PDO $pdo, array $plan): array
{
    $insert = $pdo->prepare(
        'INSERT INTO multiday_activities (zone_id, centre, activity, section, labor, priest, start_date, start_time, end_date, end_time, description, roll_rule)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $placed = array_values(array_filter($plan['placeholders'], fn($p) => empty($p['unplaced'])));
    $pdo->beginTransaction();
    try {
        foreach (array_unique(array_column($placed, 'activity')) as $type) {
            $pdo->prepare('INSERT IGNORE INTO activity_types (name, is_multiday) VALUES (?, 1)')->execute([$type]);
        }
        foreach (array_merge($plan['shifted'], $placed) as $r) {
            $insert->execute([$r['zone_id'], $r['centre'], $r['activity'], $r['section'], $r['labor'], $r['priest'],
                $r['start_date'], $r['start_time'], $r['end_date'], $r['end_time'], $r['description'], $r['roll_rule']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [count($plan['shifted']), count($placed)];
}
