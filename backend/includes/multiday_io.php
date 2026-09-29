<?php
// Import/export for the admin Multi-day Activities page. Reuses the file
// readers and value parsers from activity_io.php (require that first).
//
// A row is one programme: a zone, a centre (venue), an activity, and a
// start/end date with optional times. Centre, activity, section, labor and
// priest are stored as text but must be entries of the centres, activity_types,
// sections, labors and priests tables, so imported data can't drift away from
// those lists (see multiday_lookups()).

const MDAY_IO_COLUMNS = ['zone', 'centre', 'activity', 'section', 'labor', 'priest', 'start_date', 'start_time', 'end_date', 'end_time', 'description', 'roll_rule'];

// "Start Date" -> 'start_date', "Venue" -> 'centre', "Group" -> 'labor', ... or
// null for a column we don't use.
function mday_import_header_key(string $header): ?string
{
    $h = strtolower(preg_replace('/[^a-z0-9]+/i', '', preg_replace('/^\xEF\xBB\xBF/', '', $header)));
    $aliases = [
        'venue' => 'centre', 'center' => 'centre',
        'group' => 'labor', 'labour' => 'labor',
        'start' => 'start_date', 'startdate' => 'start_date', 'from' => 'start_date', 'fromdate' => 'start_date',
        'end' => 'end_date', 'enddate' => 'end_date', 'to' => 'end_date', 'todate' => 'end_date',
        'starttime' => 'start_time', 'fromtime' => 'start_time',
        'endtime' => 'end_time', 'totime' => 'end_time',
        'priestincharge' => 'priest',
        'rollrule' => 'roll_rule', 'rule' => 'roll_rule',
    ];
    $h = $aliases[$h] ?? $h;
    return in_array($h, MDAY_IO_COLUMNS, true) ? $h : null;
}

// The lists a row's values are checked against (names matched
// case-insensitively; the stored spelling wins).
function multiday_lookups(PDO $pdo): array
{
    $lc = fn(string $s) => mb_strtolower($s);
    $lk = ['zones' => [], 'zone_names' => [], 'centres' => [], 'priests' => [], 'types' => [], 'sections' => [], 'labors' => []];
    foreach ($pdo->query('SELECT id, name FROM zones') as $z) {
        $lk['zones'][$lc($z['name'])] = (int) $z['id'];
        $lk['zone_names'][(int) $z['id']] = $z['name'];
    }
    foreach ($pdo->query('SELECT zone_id, name, section FROM centres') as $c) {
        $lk['centres'][(int) $c['zone_id']][$lc($c['name'])] = [$c['name'], $c['section']];
    }
    foreach (priests_by_zone($pdo) as $p) {
        $lk['priests'][(int) $p['zone_id']][$lc($p['name'])] = $p['name'];
    }
    foreach ([['types', 'activity_types'], ['sections', 'sections'], ['labors', 'labors']] as [$key, $table]) {
        foreach ($pdo->query("SELECT name FROM $table") as $t) {
            $lk[$key][$lc($t['name'])] = $t['name'];
        }
    }
    return $lk;
}

// Imports parsed rows into multiday_activities. Returns a report:
//   imported, duplicates (already there, skipped), errors [[row, message]],
//   error_count, unknown [field => [values]] (values that aren't in the admin
//   lists yet).
// $defaultZoneId is used for rows with no zone value (0 = none). A zone admin
// passes their zone as $scopeZoneId: every row goes there, and a row naming
// another zone is refused. Rows with a problem are reported and skipped; the
// valid ones are still imported, and re-running the same file is safe (which is
// why a blank section stays blank rather than taking the centre's).
function import_multiday(PDO $pdo, array $rows, int $defaultZoneId, ?int $scopeZoneId = null): array
{
    if (!$rows) {
        throw new ImportException('That file is empty.');
    }
    $header = array_shift($rows);
    $col = [];
    foreach ($header as $i => $h) {
        $key = mday_import_header_key((string) $h);
        if ($key !== null && !isset($col[$key])) {
            $col[$key] = $i;
        }
    }
    if (!isset($col['centre']) || !isset($col['start_date']) || !isset($col['end_date'])) {
        throw new ImportException('The first row must be a header row with "centre", "start_date" and "end_date" columns. Expected columns: ' . implode(', ', MDAY_IO_COLUMNS) . '.');
    }
    if (count($rows) > ACTIVITY_IO_MAX_ROWS) {
        throw new ImportException('Too many rows (limit ' . ACTIVITY_IO_MAX_ROWS . ' per import).');
    }

    $lk = multiday_lookups($pdo);
    $dupe = $pdo->prepare(
        'SELECT 1 FROM multiday_activities WHERE zone_id = ? AND centre <=> ? AND activity <=> ? AND section <=> ? AND labor <=> ? AND priest <=> ?
           AND start_date = ? AND start_time <=> ? AND end_date = ? AND end_time <=> ? AND description <=> ? LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO multiday_activities (zone_id, centre, activity, section, labor, priest, start_date, start_time, end_date, end_time, description, roll_rule)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $report = ['imported' => 0, 'duplicates' => 0, 'errors' => [], 'error_count' => 0, 'unknown' => []];
    $seen = [];
    $get = fn(array $row, string $f): string => isset($col[$f]) ? import_text((string) ($row[$col[$f]] ?? '')) : '';
    $lc = fn(string $s) => mb_strtolower($s);

    $pdo->beginTransaction();
    try {
        foreach ($rows as $n => $row) {
            $line = $n + 2; // spreadsheet row number (header is row 1)
            if (trim(implode('', $row)) === '') {
                continue;
            }
            $problems = [];

            // zone
            $zoneName = $get($row, 'zone');
            $zoneId = $scopeZoneId ?: $defaultZoneId;
            if ($zoneName !== '') {
                $named = $lk['zones'][$lc($zoneName)] ?? 0;
                if (!$named) {
                    $report['unknown']['zone'][$zoneName] = true;
                    $problems[] = "unknown zone \"$zoneName\"";
                    $zoneId = 0;
                } elseif ($scopeZoneId && $named !== $scopeZoneId) {
                    $problems[] = "zone \"$zoneName\" is not your zone";
                    $zoneId = 0;
                } else {
                    $zoneId = $named;
                }
            } elseif (!$zoneId) {
                $problems[] = 'zone is required (no zone in the row)';
            }
            $where = $zoneId ? ' (in ' . $lk['zone_names'][$zoneId] . ')' : '';

            // centre (required), activity, section, labor, priest: must be in the admin lists
            $find = function (string $field, string $raw, ?array $list, string $suffix = '') use (&$problems, &$report, $lc) {
                if ($raw === '') {
                    return null;
                }
                $hit = ($list ?? [])[$lc($raw)] ?? null;
                if ($hit === null) {
                    $problems[] = "unknown $field \"$raw\"$suffix";
                    $report['unknown'][$field][$raw . $suffix] = true;
                }
                return $hit;
            };
            $centre = null;
            $centreRaw = $get($row, 'centre');
            if ($centreRaw === '') {
                $problems[] = 'centre is required';
            } elseif ($zoneId && ($hit = $find('centre', $centreRaw, $lk['centres'][$zoneId] ?? [], $where))) {
                $centre = $hit[0];
            }
            $activity = $find('activity', $get($row, 'activity'), $lk['types']);
            $labor = $find('labor', $get($row, 'labor'), $lk['labors']);
            $priest = $zoneId ? $find('priest', $get($row, 'priest'), $lk['priests'][$zoneId] ?? [], $where) : null;
            $section = $find('section', $get($row, 'section'), $lk['sections']);

            // dates and times
            $startDate = parse_import_date($get($row, 'start_date'));
            $endDate = parse_import_date($get($row, 'end_date'));
            $startTime = parse_import_time($get($row, 'start_time'));
            $endTime = parse_import_time($get($row, 'end_time'));
            if ($startDate === null) {
                $problems[] = 'start_date is required';
            } elseif ($startDate === false) {
                $problems[] = 'unrecognised start_date "' . $get($row, 'start_date') . '"';
            }
            if ($endDate === null) {
                $problems[] = 'end_date is required';
            } elseif ($endDate === false) {
                $problems[] = 'unrecognised end_date "' . $get($row, 'end_date') . '"';
            }
            if ($startTime === false) {
                $problems[] = 'unrecognised start_time "' . $get($row, 'start_time') . '"';
            }
            if ($endTime === false) {
                $problems[] = 'unrecognised end_time "' . $get($row, 'end_time') . '"';
            }
            if (is_string($startDate) && is_string($endDate) && ($endDate < $startDate
                || ($endDate === $startDate && is_string($startTime) && is_string($endTime) && $endTime < $startTime))) {
                $problems[] = 'the end is before the start';
            }
            $description = $get($row, 'description') ?: null;
            // roll_rule (how the entry moves on "Roll forward"): exact / weekend / flexible; blank = flexible.
            $rollRule = 'flexible';
            $ruleRaw = $get($row, 'roll_rule');
            if ($ruleRaw !== '') {
                $norm = preg_replace('/[^a-z]/', '', strtolower($ruleRaw));
                $ruleMap = ['exact' => 'exact', 'exactdate' => 'exact', 'weekend' => 'weekend', 'sticktoweekend' => 'weekend', 'flexible' => 'flexible'];
                if (isset($ruleMap[$norm])) {
                    $rollRule = $ruleMap[$norm];
                } else {
                    $problems[] = "unknown roll_rule \"$ruleRaw\" (use exact, weekend or flexible)";
                }
            }

            if ($problems) {
                $report['error_count']++;
                if (count($report['errors']) < 200) {
                    $report['errors'][] = [$line, implode('; ', $problems)];
                }
                continue;
            }

            $key = [$zoneId, $centre, $activity, $section, $labor, $priest, $startDate, $startTime ?: null, $endDate, $endTime ?: null, $description];
            $dupe->execute($key);
            $keyStr = implode('|', array_map('strval', $key));
            if (isset($seen[$keyStr]) || $dupe->fetchColumn()) {
                $report['duplicates']++;
                continue;
            }
            $seen[$keyStr] = true;
            $insert->execute([...$key, $rollRule]);
            $report['imported']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    foreach ($report['unknown'] as $field => $values) {
        $report['unknown'][$field] = array_keys($values);
    }
    return $report;
}

// Streams multi-day rows (each with a zone_name) as CSV with the import columns.
function export_multiday_csv(iterable $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
    fputcsv($out, MDAY_IO_COLUMNS, ',', '"', '');
    $hm = fn(?string $t) => $t ? substr($t, 0, 5) : '';
    foreach ($rows as $m) {
        fputcsv($out, [
            export_text($m['zone_name']), export_text($m['centre']), export_text($m['activity']), export_text($m['section']),
            export_text($m['labor']), export_text($m['priest']), $m['start_date'], $hm($m['start_time']), $m['end_date'], $hm($m['end_time']),
            export_text($m['description']), $m['roll_rule'],
        ], ',', '"', '');
    }
    fclose($out);
}
