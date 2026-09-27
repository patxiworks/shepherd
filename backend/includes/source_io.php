<?php
// Import/export and validation for the admin Source page. Reuses the file
// readers and value parsers from activity_io.php (require that first).
//
// A source row has no date: it says which week of the month and which day of
// the week it is (`week` + `day`). Like activities, its centre / priest /
// activity / labor / section are stored as text, but they must be entries of
// the centres, priests, activity_types, labors and sections tables (see
// source_resolve()), so source can't drift away from those lists.

const SOURCE_IO_COLUMNS = ['zone', 'week', 'day', 'centre', 'activity', 'section', 'labor', 'priest', 'from', 'to', 'duration', 'mfrequency', 'description'];

// Header names the importer understands: the columns above, plus `unit`,
// which the pastoral spreadsheet uses for the zone.
const SOURCE_IO_IMPORT_COLUMNS = [...SOURCE_IO_COLUMNS, 'unit'];

// The lookup data source rows are checked against (names matched
// case-insensitively; the stored spelling wins).
function source_lookups(PDO $pdo): array
{
    $lc = fn(string $s) => mb_strtolower($s);
    $lk = ['zones' => [], 'zone_names' => [], 'centres' => [], 'priests' => [], 'types' => [], 'labors' => []];
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
    foreach ([['types', 'activity_types'], ['labors', 'labors']] as [$key, $table]) {
        foreach ($pdo->query("SELECT name FROM $table") as $t) {
            $lk[$key][$lc($t['name'])] = $t['name'];
        }
    }
    return $lk;
}

// Checks a source row's centre, priest, activity and labor against the
// lookup data (see source_lookups()) for its zone. Empty values are fine.
// The section is not entered: it comes from the centre's section.
// Returns [values, problems]: values are the stored spellings (centre,
// activity, labor, priest, section), problems a list of
// [field, text, message] for each value that isn't in its list.
function source_resolve(array $lk, int $zoneId, array $in): array
{
    $values = ['centre' => null, 'activity' => null, 'labor' => null, 'priest' => null, 'section' => null];
    $problems = [];
    $find = function (string $field, ?string $raw, ?array $list, string $where) use (&$values, &$problems) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $hit = $list[mb_strtolower($raw)] ?? null;
        if ($hit === null) {
            $problems[] = [$field, $raw, "unknown $field \"$raw\"$where"];
        }
        return $hit;
    };
    $zoneName = $lk['zone_names'][$zoneId] ?? null;
    $inZone = $zoneName !== null ? " (in $zoneName)" : '';

    if ($hit = $find('centre', $in['centre'] ?? null, $lk['centres'][$zoneId] ?? [], $inZone)) {
        [$values['centre'], $values['section']] = $hit;
    }
    $values['activity'] = $find('activity', $in['activity'] ?? null, $lk['types'], '');
    $values['labor'] = $find('labor', $in['labor'] ?? null, $lk['labors'], '');
    $values['priest'] = $find('priest', $in['priest'] ?? null, $lk['priests'][$zoneId] ?? [], $inZone);
    return [$values, $problems];
}

// Imports parsed rows into `source`. Same report shape as import_activities()
// ($report['unknown'] holds the zones, centres, priests, activities and
// labors that aren't in the admin lists; cleared_durations counts imported
// rows whose invalid duration was left empty). The zone comes from the `zone`
// column, else the `unit` column (which the pastoral spreadsheet uses for
// it), else $defaultZoneId. `week` (1-6) and `day` (1-7) are required. Rows
// with a problem are reported and skipped; the valid ones are still imported.
function import_source(PDO $pdo, array $rows, int $defaultZoneId): array
{
    if (!$rows) {
        throw new ImportException('That file is empty.');
    }
    $header = array_shift($rows);
    $col = [];
    foreach ($header as $i => $h) {
        $key = import_header_key((string) $h, SOURCE_IO_IMPORT_COLUMNS);
        if ($key !== null && !isset($col[$key])) {
            $col[$key] = $i;
        }
    }
    if (!isset($col['week']) || !isset($col['day'])) {
        throw new ImportException('The first row must be a header row with "week" and "day" columns. Expected columns: ' . implode(', ', SOURCE_IO_COLUMNS) . '.');
    }
    if (count($rows) > ACTIVITY_IO_MAX_ROWS) {
        throw new ImportException('Too many rows (limit ' . ACTIVITY_IO_MAX_ROWS . ' per import).');
    }

    $lk = source_lookups($pdo);

    $dupe = $pdo->prepare(
        'SELECT 1 FROM source WHERE zone_id = ? AND week <=> ? AND day <=> ? AND centre <=> ? AND activity <=> ?
           AND from_time <=> ? AND to_time <=> ? AND priest <=> ? AND description <=> ? LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO source (zone_id, week, day, centre, activity, section, labor, from_time, to_time, duration, mfrequency, priest, description)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $report = ['imported' => 0, 'duplicates' => 0, 'errors' => [], 'error_count' => 0, 'unknown' => [], 'zones' => [], 'cleared_durations' => 0];
    $seen = [];
    $get = fn(array $row, string $f): string => isset($col[$f]) ? import_text((string) ($row[$col[$f]] ?? '')) : '';

    $pdo->beginTransaction();
    try {
        foreach ($rows as $n => $row) {
            $line = $n + 2; // spreadsheet row number (header is row 1)
            if (trim(implode('', $row)) === '') {
                continue;
            }
            $problems = [];

            // zone
            $zoneName = $get($row, 'zone') ?: $get($row, 'unit');
            if ($zoneName === '') {
                $zoneId = $defaultZoneId;
                if (!$zoneId) {
                    $problems[] = 'zone is required (no zone or unit in the row)';
                }
            } elseif (isset($lk['zones'][mb_strtolower($zoneName)])) {
                $zoneId = $lk['zones'][mb_strtolower($zoneName)];
            } else {
                $zoneId = 0;
                $report['unknown']['zone'][$zoneName] = true;
                $problems[] = "unknown zone \"$zoneName\"";
            }

            // week and day
            $week = $get($row, 'week');
            $day = $get($row, 'day');
            if (!ctype_digit($week) || $week < 1 || $week > 6) {
                $problems[] = $week === '' ? 'week is required' : "week \"$week\" must be 1-6";
            }
            if (!ctype_digit($day) || $day < 1 || $day > 7) {
                $problems[] = $day === '' ? 'day is required' : "day \"$day\" must be 1-7";
            }
            $week = (int) $week;
            $day = (int) $day;

            // centre, activity, labor, priest: must be in the admin lists
            // (the section is the centre's, so a `section` column is ignored)
            $values = ['centre' => null, 'activity' => null, 'labor' => null, 'priest' => null, 'section' => null];
            if ($zoneId) {
                [$values, $lookupProblems] = source_resolve($lk, $zoneId, [
                    'centre' => $get($row, 'centre'), 'activity' => $get($row, 'activity'),
                    'labor' => $get($row, 'labor'), 'priest' => $get($row, 'priest'),
                ]);
                foreach ($lookupProblems as [$field, $text, $message]) {
                    $report['unknown'][$field][$text . ($zoneId && in_array($field, ['centre', 'priest'], true) ? ' (in ' . $lk['zone_names'][$zoneId] . ')' : '')] = true;
                    $problems[] = $message;
                }
            }

            // times
            // A negative duration or a spreadsheet error such as #VALUE! (what the
            // sheet's formula gives when "to" is empty or earlier than "from")
            // is meaningless: the row is kept, with the duration left empty.
            $times = [];
            $rawDuration = $get($row, 'duration');
            $badDuration = $rawDuration !== '' && ($rawDuration[0] === '-' || $rawDuration[0] === '#');
            foreach (['from', 'to', 'duration'] as $f) {
                if ($f === 'duration' && $badDuration) {
                    $times[$f] = null;
                    continue;
                }
                $t = parse_import_time($get($row, $f));
                if ($t === false) {
                    $problems[] = "unrecognised $f time \"" . $get($row, $f) . '"';
                }
                $times[$f] = $t ?: null;
            }

            // frequency (e.g. 1 or 0.33)
            $freq = $get($row, 'mfrequency');
            if ($freq === '') {
                $freq = null;
            } elseif (!is_numeric($freq) || $freq < 0 || $freq > 99) {
                $problems[] = "unrecognised frequency \"$freq\"";
            }
            $description = $get($row, 'description') ?: null;

            if ($problems) {
                $report['error_count']++;
                if (count($report['errors']) < 200) {
                    $report['errors'][] = [$line, implode('; ', $problems)];
                }
                continue;
            }

            $cleared = $badDuration;
            $key = [$zoneId, $week, $day, $values['centre'], $values['activity'], $times['from'], $times['to'], $values['priest'], $description];
            $dupe->execute($key);
            $keyStr = implode('|', array_map('strval', $key));
            if (isset($seen[$keyStr]) || $dupe->fetchColumn()) {
                $report['duplicates']++;
                continue;
            }
            $seen[$keyStr] = true;

            $insert->execute([
                $zoneId, $week, $day, $values['centre'], $values['activity'], $values['section'], $values['labor'],
                $times['from'], $times['to'], $times['duration'], $freq, $values['priest'], $description,
            ]);
            $report['imported']++;
            $report['cleared_durations'] += $cleared ? 1 : 0;
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

// Streams source rows (each with a zone_name) as CSV with the import columns.
// `day` is the weekday number as stored.
function export_source_csv(iterable $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
    fputcsv($out, SOURCE_IO_COLUMNS, ',', '"', '');
    $hm = fn(?string $t) => $t ? substr($t, 0, 5) : '';
    foreach ($rows as $a) {
        fputcsv($out, [
            export_text($a['zone_name']), $a['week'], $a['day'],
            export_text($a['centre']), export_text($a['activity']), export_text($a['section']), export_text($a['labor']), export_text($a['priest']),
            $hm($a['from_time']), $hm($a['to_time']), $hm($a['duration']), $a['mfrequency'], export_text($a['description']),
        ], ',', '"', '');
    }
    fclose($out);
}
