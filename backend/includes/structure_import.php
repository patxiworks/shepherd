<?php
// Import for the tables under the admin "Structure" menu: zones, centres,
// sections, labors and activity types. The rows come from the same readers as
// the Activities/Source importers (CSV, XLSX, Google Sheets: see
// activity_io.php). Import only ever ADDS: a row whose key already exists is
// skipped and nothing existing is changed, so importing a file twice is safe.

// table => [label, columns, what the columns mean]
const STRUCTURE_IMPORT_TABLES = [
    'zones' => ['Zones', ['name'], 'name'],
    'centres' => ['Centres', ['zone', 'name', 'section'], 'zone (must already exist), name, section (optional; must already exist in Sections)'],
    'sections' => ['Sections', ['name'], 'name'],
    'labors' => ['Labors', ['name'], 'name'],
    'activity_types' => ['Activity types', ['name', 'is_multiday'], 'name, multi-day (optional: yes/no, 1/0)'],
];

// A header cell -> the column it stands for in $table, or null. "Centre" in a
// Centres file is the centre's name, "Zone" in a Zones file is the name, etc.
function structure_import_header(string $header, string $table): ?string
{
    $h = strtolower(preg_replace('/[^a-z0-9]+/i', '', preg_replace('/^\xEF\xBB\xBF/', '', $header)));
    $columns = STRUCTURE_IMPORT_TABLES[$table][1];
    if ($table === 'centres' && in_array($h, ['zone', 'zonename'], true)) {
        return 'zone';
    }
    if ($table === 'centres' && in_array($h, ['section', 'sectionname'], true)) {
        return 'section';
    }
    $nameAliases = ['name', 'zone', 'zonename', 'section', 'sectionname', 'labor', 'labour', 'laborname', 'activity', 'activitytype', 'type', 'centre', 'center', 'centrename', 'centername'];
    if (in_array($h, $nameAliases, true)) {
        return 'name';
    }
    if (in_array($h, ['multiday', 'ismultiday', 'multidayprogramme'], true)) {
        return in_array('is_multiday', $columns, true) ? 'is_multiday' : null;
    }
    return null;
}

// Imports $rows (row 0 = header) into $table. Returns
// ['added' => n, 'skipped' => n (already there), 'rejected' => n, 'errors' => [message, ...]].
function import_structure(PDO $pdo, string $table, array $rows): array
{
    if (!isset(STRUCTURE_IMPORT_TABLES[$table])) {
        throw new ImportException('Unknown table.');
    }
    if (!$rows) {
        throw new ImportException('That file is empty.');
    }
    [$label, , $help] = STRUCTURE_IMPORT_TABLES[$table];
    $header = array_shift($rows);
    $col = [];
    foreach ($header as $i => $h) {
        $key = structure_import_header((string) $h, $table);
        if ($key !== null && !isset($col[$key])) {
            $col[$key] = $i;
        }
    }
    if (!isset($col['name']) || ($table === 'centres' && !isset($col['zone']))) {
        throw new ImportException("The first row must be a header row. Expected columns for $label: $help.");
    }
    if (count($rows) > ACTIVITY_IO_MAX_ROWS) {
        throw new ImportException('Too many rows (limit ' . ACTIVITY_IO_MAX_ROWS . ' per import).');
    }

    $maxLen = ['zones' => 100, 'centres' => 150, 'sections' => 20, 'labors' => 30, 'activity_types' => 255][$table];
    $cell = function (array $row, string $key) use ($col): string {
        return isset($col[$key]) ? trim(import_text((string) ($row[$col[$key]] ?? ''))) : '';
    };
    $lower = fn(string $s) => mb_strtolower($s, 'UTF-8');

    // Existing keys, compared case-insensitively as MySQL's collation does.
    $existing = [];
    $zoneIds = [];
    $sectionNames = [];
    if ($table === 'centres') {
        foreach ($pdo->query('SELECT id, name FROM zones') as $z) {
            $zoneIds[$lower($z['name'])] = (int) $z['id'];
        }
        foreach ($pdo->query('SELECT name FROM sections') as $s) {
            $sectionNames[$lower($s['name'])] = $s['name'];
        }
        foreach ($pdo->query('SELECT zone_id, name FROM centres') as $c) {
            $existing[$c['zone_id'] . '|' . $lower($c['name'])] = true;
        }
    } else {
        foreach ($pdo->query("SELECT name FROM $table") as $r) {
            $existing[$lower($r['name'])] = true;
        }
    }
    $hasMultiday = $table === 'activity_types' && (bool) $pdo->query("SHOW COLUMNS FROM activity_types LIKE 'is_multiday'")->fetchColumn();

    $result = ['added' => 0, 'skipped' => 0, 'rejected' => 0, 'errors' => []];
    $reject = function (int $line, string $msg) use (&$result) {
        $result['rejected']++;
        if (count($result['errors']) < 25) {
            $result['errors'][] = "Row $line: $msg";
        }
    };

    $pdo->beginTransaction();
    try {
        foreach ($rows as $n => $row) {
            $line = $n + 2; // 1-based, after the header
            if (!array_filter($row, fn($v) => trim((string) $v) !== '')) {
                continue; // blank row
            }
            $name = $cell($row, 'name');
            if ($name === '') {
                $reject($line, 'the name is empty.');
                continue;
            }
            if (mb_strlen($name, 'UTF-8') > $maxLen) {
                $reject($line, "\"$name\" is longer than $maxLen characters.");
                continue;
            }

            if ($table === 'centres') {
                $zoneName = $cell($row, 'zone');
                $zoneId = $zoneIds[$lower($zoneName)] ?? null;
                if ($zoneId === null) {
                    $reject($line, $zoneName === '' ? 'the zone is empty.' : "zone \"$zoneName\" does not exist (add it under Zones first).");
                    continue;
                }
                $section = $cell($row, 'section');
                if ($section !== '') {
                    if (!isset($sectionNames[$lower($section)])) {
                        $reject($line, "section \"$section\" does not exist (add it under Sections first).");
                        continue;
                    }
                    $section = $sectionNames[$lower($section)];
                }
                $key = $zoneId . '|' . $lower($name);
                if (isset($existing[$key])) {
                    $result['skipped']++;
                    continue;
                }
                $pdo->prepare('INSERT INTO centres (zone_id, name, section) VALUES (?, ?, ?)')->execute([$zoneId, $name, $section ?: null]);
            } else {
                $key = $lower($name);
                if (isset($existing[$key])) {
                    $result['skipped']++;
                    continue;
                }
                if ($hasMultiday) {
                    $multi = in_array($lower($cell($row, 'is_multiday')), ['1', 'yes', 'y', 'true', 'x'], true) ? 1 : 0;
                    $pdo->prepare('INSERT INTO activity_types (name, is_multiday) VALUES (?, ?)')->execute([$name, $multi]);
                } else {
                    $pdo->prepare("INSERT INTO $table (name) VALUES (?)")->execute([$name]);
                }
            }
            $existing[$key] = true;
            $result['added']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $result;
}
