<?php
// Day optimiser on the admin Activities page: proposes a priest for every
// activity of one date so that none of the clashes the page flags remain.
// Nothing is saved here; the proposal goes to a modal and the admin accepts
// (or rejects) it, see the `optimise` / `optimise_apply` actions.
//
// A priest can take an activity only if (matching the rules of the flags):
//   - he is not absent then                      (absences.php)
//   - he is not in charge of a multi-day activity elsewhere then
//                                                 (activity_multiday_busy.php)
//   - he is not already booked at an overlapping time in a different centre,
//     by an activity that is not being optimised (another zone, or a centre
//     outside a centre admin's scope)           (activity_bilocation.php)
// and, together, the proposal must give
//   - no priest two overlapping activities in different centres, and
//   - no priest more than mass_limit() masses (counting fixed ones)
//                                                 (activity_mass_limit.php)
// Which activities are optimised: those of the zone on the date (a centre
// admin: of their centre). Other activities of the date only constrain.
//
// The search keeps the current priest wherever that is still conflict-free,
// then spreads the rest over the least-loaded eligible priests. If no full
// solution exists, as many activities as possible are assigned and the rest
// are reported as unresolved (priest left empty in the proposal).

require_once __DIR__ . '/absences.php';
require_once __DIR__ . '/activity_multiday_busy.php';
require_once __DIR__ . '/activity_mass_limit.php';

const PASTORES_OPTIMISER_NODE_LIMIT = 200000;

function opt_secs(?string $time): ?int
{
    if ($time === null || $time === '' || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $time, $m)) {
        return null;
    }
    return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
}

function opt_key(?string $priest): string
{
    return mb_strtolower(trim((string) $priest));
}

// Everything the solver needs about one date of one zone.
function day_model(PDO $pdo, int $zoneId, string $date, ?string $scopeCentre): array
{
    $stmt = $pdo->prepare(
        "SELECT id, zone_id, centre, activity, from_time, to_time, priest FROM activities
         WHERE activity_date = ? AND (zone_id = ? OR (priest IS NOT NULL AND priest <> ''))
         ORDER BY from_time, centre, id"
    );
    $stmt->execute([$date, $zoneId]);
    $items = [];
    $fixed = [];
    foreach ($stmt as $r) {
        $r['id'] = (int) $r['id'];
        $r['zone_id'] = (int) $r['zone_id'];
        $movable = $r['zone_id'] === $zoneId && ($scopeCentre === null || $r['centre'] === $scopeCentre);
        if ($movable) {
            $items[] = $r;
        } elseif (!empty($r['priest'])) {
            $fixed[] = $r;
        }
    }
    $n = count($items);

    // Candidate priests: the zone's, plus whoever an activity already has.
    $names = [];
    foreach (priests_by_zone($pdo, $zoneId) as $p) {
        $names[opt_key($p['name'])] = $p['name'];
    }
    $zoneNames = $names;
    foreach ($items as $it) {
        if (!empty($it['priest'])) {
            $names[opt_key($it['priest'])] = $names[opt_key($it['priest'])] ?? $it['priest'];
        }
    }

    // Absences and multi-day activities touching the date.
    $dayStart = strtotime($date . ' 00:00:00');
    $absences = [];
    if (absences_available($pdo)) {
        $s = $pdo->prepare('SELECT priest, start_at, end_at, activity FROM absences WHERE start_at < ? AND end_at > ?');
        $s->execute([date('Y-m-d H:i:s', $dayStart + 86400), date('Y-m-d H:i:s', $dayStart)]);
        foreach ($s as $r) {
            $absences[opt_key($r['priest'])][] = [strtotime($r['start_at']), strtotime($r['end_at']), 'absent' . ($r['activity'] ? ' (' . $r['activity'] . ')' : '')];
        }
    }
    $multi = [];
    if (multiday_busy_available_safe($pdo)) {
        $s = $pdo->prepare("SELECT zone_id, centre, priest, activity, start_date, start_time, end_date, end_time FROM multiday_activities
                            WHERE priest <> '' AND start_date <= ? AND end_date >= ?");
        $s->execute([$date, $date]);
        foreach ($s as $r) {
            $time = fn($v, $default) => ($t = opt_secs(explode(' ', trim((string) $v))[0])) !== null ? $t : $default;
            $from = strtotime($r['start_date'] . ' 00:00:00') + $time($r['start_time'], 18 * 3600);
            $to = strtotime($r['end_date'] . ' 00:00:00') + $time($r['end_time'], 12 * 3600);
            if ($to <= $from) {
                $to = strtotime($r['end_date'] . ' 23:59:59');
            }
            $multi[opt_key($r['priest'])][] = [$from, $to, (int) $r['zone_id'], $r['centre'], 'in charge of ' . $r['activity'] . ' | ' . $r['centre']];
        }
    }

    // Time spans: [absence/multi-day span], [bilocation span or null].
    $span = function (array $a) use ($dayStart): array {
        $f = opt_secs($a['from_time']);
        $t = opt_secs($a['to_time']);
        $from = $dayStart + ($f ?? 0);
        $to = $t !== null ? $dayStart + $t : ($f !== null ? $dayStart + $f + 60 : $dayStart + 86399);
        return [$from, $to];
    };
    $bi = function (array $a): ?array {
        $f = opt_secs($a['from_time']);
        if ($f === null) {
            return null;
        }
        $t = opt_secs($a['to_time']);
        return [$f, $t !== null ? $t : $f + 60];
    };
    $sameCentre = fn(array $a, array $b) => $a['zone_id'] === $b['zone_id'] && ($a['centre'] ?? null) === ($b['centre'] ?? null);

    // Why a priest cannot take an item at all (independent of the others), or null.
    $reasons = [];
    $pairs = array_fill(0, $n, []);
    for ($i = 0; $i < $n; $i++) {
        [$from, $to] = $span($items[$i]);
        $bi1 = $bi($items[$i]);
        foreach ($names as $k => $name) {
            $why = null;
            foreach ($absences[$k] ?? [] as [$s, $e, $label]) {
                if ($s < $to && $e > $from) {
                    $why = $label;
                    break;
                }
            }
            if ($why === null) {
                foreach ($multi[$k] ?? [] as [$s, $e, $mz, $mc, $label]) {
                    if (!($mz === $items[$i]['zone_id'] && $mc === $items[$i]['centre']) && $s < $to && $e > $from) {
                        $why = $label;
                        break;
                    }
                }
            }
            if ($why === null && $bi1) {
                foreach ($fixed as $f) {
                    $b = $bi($f);
                    if (opt_key($f['priest']) === $k && $b && !$sameCentre($items[$i], $f) && $b[0] < $bi1[1] && $b[1] > $bi1[0]) {
                        $why = 'already at ' . $f['centre'] . ' ' . substr((string) $f['from_time'], 0, 5);
                        break;
                    }
                }
            }
            if ($why !== null) {
                $reasons[$i][$k] = $why;
            }
        }
        for ($j = 0; $j < $n; $j++) {
            $b = $bi($items[$j]);
            if ($j !== $i && $bi1 && $b && !$sameCentre($items[$i], $items[$j]) && $b[0] < $bi1[1] && $b[1] > $bi1[0]) {
                $pairs[$i][] = $j;
            }
        }
    }

    $fixedMasses = [];
    foreach ($fixed as $f) {
        if (strcasecmp((string) $f['activity'], PASTORES_MASS_ACTIVITY) === 0) {
            $fixedMasses[opt_key($f['priest'])] = ($fixedMasses[opt_key($f['priest'])] ?? 0) + 1;
        }
    }
    $fixedLoad = [];
    foreach ($fixed as $f) {
        $fixedLoad[opt_key($f['priest'])] = ($fixedLoad[opt_key($f['priest'])] ?? 0) + 1;
    }
    $isMass = array_map(fn($it) => strcasecmp((string) $it['activity'], PASTORES_MASS_ACTIVITY) === 0, $items);

    return compact('items', 'names', 'zoneNames', 'reasons', 'pairs', 'fixedMasses', 'fixedLoad', 'isMass', 'absences', 'fixed');
}

// Reasons (strings) each item is in conflict under $assign (index => priest name or null).
function day_conflicts(array $m, array $assign): array
{
    $out = [];
    $masses = [];
    foreach ($assign as $i => $p) {
        if ($p !== null && $p !== '' && $m['isMass'][$i]) {
            $masses[opt_key($p)] = ($masses[opt_key($p)] ?? 0) + 1;
        }
    }
    foreach ($assign as $i => $p) {
        $why = [];
        if ($p === null || $p === '') {
            $why[] = 'no priest';
        } else {
            $k = opt_key($p);
            if (isset($m['reasons'][$i][$k])) {
                $why[] = $m['reasons'][$i][$k];
            }
            foreach ($m['pairs'][$i] as $j) {
                if (opt_key($assign[$j]) === $k) {
                    $why[] = 'bilocation';
                    break;
                }
            }
            if ($m['isMass'][$i] && ($masses[$k] ?? 0) + ($m['fixedMasses'][$k] ?? 0) > mass_limit()) {
                $why[] = 'over the mass limit';
            }
        }
        if ($why) {
            $out[$i] = $why;
        }
    }
    return $out;
}

// The proposal for the date: rows with the current and proposed priest and
// the priests that could take each activity.
function optimise_day(PDO $pdo, int $zoneId, string $date, ?string $scopeCentre): array
{
    $m = day_model($pdo, $zoneId, $date, $scopeCentre);
    $n = count($m['items']);
    $limit = mass_limit();

    $domain = [];
    foreach ($m['items'] as $i => $it) {
        $domain[$i] = [];
        foreach ($m['names'] as $k => $name) {
            if (isset($m['reasons'][$i][$k])) {
                continue;
            }
            // Only the zone's priests, and whoever the activity already has.
            if (isset($m['zoneNames'][$k]) || opt_key($it['priest']) === $k) {
                $domain[$i][$k] = $name;
            }
        }
    }

    $current = array_map(fn($it) => $it['priest'] ?: null, $m['items']);
    $before = day_conflicts($m, $current);

    // Variables with at least one possible priest, most constrained first.
    $vars = array_values(array_filter(range(0, max(0, $n - 1)), fn($i) => $n > 0 && $domain[$i]));
    usort($vars, fn($a, $b) => [count($domain[$a]), -count($m['pairs'][$a]), $a] <=> [count($domain[$b]), -count($m['pairs'][$b]), $b]);

    $solve = function (bool $allowSkip) use ($m, $domain, $vars, $current, $limit): ?array {
        $assign = array_fill(0, count($m['items']), null);
        $load = $m['fixedLoad'];
        $massCount = $m['fixedMasses'];
        $nodes = 0;
        $dfs = function (int $idx) use (&$dfs, &$assign, &$load, &$massCount, &$nodes, $m, $domain, $vars, $current, $limit, $allowSkip): bool {
            if ($idx === count($vars)) {
                return true;
            }
            if (++$nodes > PASTORES_OPTIMISER_NODE_LIMIT) {
                return false;
            }
            $i = $vars[$idx];
            $cands = $domain[$i];
            $cur = opt_key($current[$i]);
            uksort($cands, function ($a, $b) use ($cur, $load) {
                return [$a === $cur ? 0 : 1, $load[$a] ?? 0, $a] <=> [$b === $cur ? 0 : 1, $load[$b] ?? 0, $b];
            });
            foreach ($cands as $k => $name) {
                $clash = false;
                foreach ($m['pairs'][$i] as $j) {
                    if ($assign[$j] !== null && opt_key($assign[$j]) === $k) {
                        $clash = true;
                        break;
                    }
                }
                if ($clash || ($m['isMass'][$i] && ($massCount[$k] ?? 0) + 1 > $limit)) {
                    continue;
                }
                $assign[$i] = $name;
                $load[$k] = ($load[$k] ?? 0) + 1;
                if ($m['isMass'][$i]) {
                    $massCount[$k] = ($massCount[$k] ?? 0) + 1;
                }
                if ($dfs($idx + 1)) {
                    return true;
                }
                $assign[$i] = null;
                $load[$k]--;
                if ($m['isMass'][$i]) {
                    $massCount[$k]--;
                }
            }
            if ($allowSkip) {
                return $dfs($idx + 1); // leave this one unassigned
            }
            return false;
        };
        return $dfs(0) ? $assign : null;
    };
    $proposed = $solve(false) ?? $solve(true) ?? array_fill(0, $n, null);

    $after = day_conflicts($m, $proposed);
    $absent = [];
    foreach ($m['absences'] as $k => $list) {
        if (isset($m['zoneNames'][$k])) {
            $absent[$m['zoneNames'][$k]] = implode('; ', array_unique(array_map(fn($a) => $a[2], $list)));
        }
    }
    $rows = [];
    foreach ($m['items'] as $i => $it) {
        $rows[] = [
            'id' => $it['id'],
            'centre' => $it['centre'],
            'activity' => $it['activity'],
            'from' => $it['from_time'] ? substr($it['from_time'], 0, 5) : '',
            'to' => $it['to_time'] ? substr($it['to_time'], 0, 5) : '',
            'mass' => $m['isMass'][$i],
            'current' => $current[$i],
            'before' => $before[$i] ?? [],
            'proposed' => $proposed[$i],
            'options' => array_values($domain[$i]),
            'pairs' => $m['pairs'][$i],
        ];
    }
    return [
        'rows' => $rows,
        'fixed_masses' => (object) $m['fixedMasses'],
        // For the modal's priest summary: absentees (name => text) and the activities that stay as they are.
        'absent' => (object) $absent,
        'fixed' => array_map(fn($f) => ['priest' => $f['priest'], 'from' => $f['from_time'] ? substr($f['from_time'], 0, 5) : '', 'activity' => $f['activity'], 'centre' => $f['centre']], $m['fixed']),
        'mass_limit' => $limit,
        'conflicts_before' => count($before),
        'conflicts_after' => count($after),
        'unresolved' => count(array_filter($proposed, fn($p) => $p === null)),
        'changed' => count(array_filter(array_keys($proposed), fn($i) => opt_key($proposed[$i]) !== opt_key($current[$i]))),
    ];
}

// Number of the date's activities that are in conflict as they are stored now.
function day_conflict_count(PDO $pdo, int $zoneId, string $date, ?string $scopeCentre): int
{
    $m = day_model($pdo, $zoneId, $date, $scopeCentre);
    return count(day_conflicts($m, array_map(fn($it) => $it['priest'] ?: null, $m['items'])));
}

// Saves the accepted priests ([activity id => priest name or '']). Only the
// priest column of this zone's activities on $date is touched (and, for a
// centre admin, only their centre's). Returns [changed count, error or null].
function apply_day_assignments(PDO $pdo, int $zoneId, string $date, ?string $scopeCentre, array $assign): array
{
    $allowed = [];
    foreach (priests_by_zone($pdo, $zoneId) as $p) {
        $allowed[opt_key($p['name'])] = $p['name'];
    }
    $changed = 0;
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare('SELECT zone_id, activity_date, centre, priest FROM activities WHERE id = ?');
        $upd = $pdo->prepare('UPDATE activities SET priest = ? WHERE id = ?');
        foreach ($assign as $id => $priest) {
            $sel->execute([(int) $id]);
            $row = $sel->fetch();
            if (!$row || (int) $row['zone_id'] !== $zoneId || $row['activity_date'] !== $date || ($scopeCentre !== null && $row['centre'] !== $scopeCentre)) {
                throw new InvalidArgumentException('An activity in the proposal is not one of that day\'s activities.');
            }
            $priest = trim((string) $priest);
            if (opt_key($priest) === opt_key($row['priest'])) {
                continue;
            }
            if ($priest !== '' && !isset($allowed[opt_key($priest)])) {
                throw new InvalidArgumentException("$priest is not one of this zone's priests.");
            }
            $upd->execute([$priest !== '' ? $allowed[opt_key($priest)] : null, (int) $id]);
            $changed++;
        }
        $pdo->commit();
    } catch (InvalidArgumentException $ex) {
        $pdo->rollBack();
        return [0, $ex->getMessage()];
    }
    if ($changed) {
        touch_zone($pdo, $zoneId);
    }
    return [$changed, null];
}
