<?php
// Import for the tables under the admin "Structure" menu: zones, centres,
// sections and activity types. The rows come from the same readers as
// the Activities/Source importers (CSV, XLSX, Google Sheets: see
// activity_io.php). Import only ever ADDS: a row whose key already exists is
// skipped and nothing existing is changed, so importing a file twice is safe.

// table => [label, columns, what the columns mean]
const STRUCTURE_IMPORT_TABLES = [
    'zones' => ['Zones', ['name'], 'name'],
    'centres' => ['Centres', ['zone', 'name', 'full_name', 'section', 'labores'], 'zone (must already exist), name, full name (optional), section (optional; must already exist in Sections), labores (optional; the labor texts the centre runs, separated by commas, e.g. "sm agd, sm club, sg"; each must already exist in Labores. An existing centre gets the missing ones added)'],
    'sections' => ['Sections', ['name'], 'name'],
    'labores' => ['Labores', ['name', 'sub'], 'labor, sub-labor (optional: leave it empty for a labor that has none). A labor is created when it is missing, one row per sub-labor, e.g. "sm | agd"'],
    'activity_types' => ['Activities', ['name', 'is_multiday'], 'name, multi-day (optional: yes/no, 1/0)'],
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
    if ($table === 'centres' && in_array($h, ['fullname', 'centrefullname', 'centerfullname'], true)) {
        return 'full_name';
    }
    if ($table === 'centres' && in_array($h, ['labores', 'labors', 'labours', 'labor', 'labour'], true)) {
        return 'labores';
    }
    if ($table === 'labores' && in_array($h, ['sub', 'sublabor', 'sublabour', 'sublabores', 'sublabors', 'sublaborname'], true)) {
        return 'sub';
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

    $maxLen = ['zones' => 100, 'centres' => 150, 'sections' => 20, 'labores' => 30, 'activity_types' => 255][$table];
    $cell = function (array $row, string $key) use ($col): string {
        return isset($col[$key]) ? trim(import_text((string) ($row[$col[$key]] ?? ''))) : '';
    };
    $lower = fn(string $s) => mb_strtolower($s, 'UTF-8');

    // Existing keys, compared case-insensitively as MySQL's collation does.
    $existing = [];
    $centreIds = [];  // zone_id|lc name => centre id
    $laborIds = [];   // lc labor text => labores id (see below)
    $topLabor = [];
    $topIds = [];     // lc labor name => id (labores import)
    $zoneIds = [];
    $sectionNames = [];
    if ($table === 'centres') {
        foreach ($pdo->query('SELECT id, name FROM zones') as $z) {
            $zoneIds[$lower($z['name'])] = (int) $z['id'];
        }
        foreach ($pdo->query('SELECT name FROM sections') as $s) {
            $sectionNames[$lower($s['name'])] = $s['name'];
        }
        foreach ($pdo->query('SELECT id, zone_id, name FROM centres') as $c) {
            $existing[$c['zone_id'] . '|' . $lower($c['name'])] = true;
            $centreIds[$c['zone_id'] . '|' . $lower($c['name'])] = (int) $c['id'];
        }
        // "sm agd" / "sg" (a labor without sub-labores) => the labores id a centre is given.
        foreach ($pdo->query('SELECT l.id, l.name, p.name AS parent FROM labores l LEFT JOIN labores p ON p.id = l.parent_id') as $l) {
            if ($l['parent'] !== null) {
                $laborIds[$lower(labor_text($l['parent'], $l['name']))] = (int) $l['id'];
            } else {
                $topLabor[(int) $l['id']] = $l['name'];
            }
        }
        $withSubs = array_flip(array_map('intval', $pdo->query('SELECT DISTINCT parent_id FROM labores WHERE parent_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($topLabor as $lid => $lname) {
            if (!isset($withSubs[$lid])) {
                $laborIds[$lower($lname)] = $lid;
            }
        }
    } elseif ($table === 'labores') {
        foreach ($pdo->query('SELECT id, name, parent_id FROM labores') as $l) {
            if ($l['parent_id'] === null) {
                $topIds[$lower($l['name'])] = (int) $l['id'];
            } else {
                $existing[$l['parent_id'] . '|' . $lower($l['name'])] = true;
            }
        }
    } else {
        foreach ($pdo->query("SELECT name FROM $table") as $r) {
            $existing[$lower($r['name'])] = true;
        }
    }
    $hasMultiday = $table === 'activity_types' && (bool) $pdo->query("SHOW COLUMNS FROM activity_types LIKE 'is_multiday'")->fetchColumn();

    $result = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => 0, 'errors' => []];
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
                $fullName = $cell($row, 'full_name');
                if (mb_strlen($fullName, 'UTF-8') > 255) {
                    $reject($line, 'the full name is longer than 255 characters.');
                    continue;
                }
                // The labores column: labor texts separated by commas / semicolons / new lines.
                $wanted = [];
                $badLabor = null;
                foreach (preg_split('/[,;\n]+/', $cell($row, 'labores')) as $text) {
                    $text = trim(preg_replace('/\s+/', ' ', $text));
                    if ($text === '') {
                        continue;
                    }
                    if (!isset($laborIds[$lower($text)])) {
                        $badLabor = $text;
                        break;
                    }
                    $wanted[$laborIds[$lower($text)]] = true;
                }
                if ($badLabor !== null) {
                    $reject($line, "labor \"$badLabor\" does not exist (add it under Labores first; a labor that has sub-labores needs one, e.g. \"sm agd\").");
                    continue;
                }
                $key = $zoneId . '|' . $lower($name);
                if (isset($existing[$key])) {
                    // Existing centre: nothing is changed except that missing labores are added.
                    $gained = 0;
                    $link = $pdo->prepare('INSERT IGNORE INTO centre_labores (centre_id, labor_id) VALUES (?, ?)');
                    foreach (array_keys($wanted) as $lid) {
                        $link->execute([$centreIds[$key], $lid]);
                        $gained += $link->rowCount();
                    }
                    $gained ? $result['updated']++ : $result['skipped']++;
                    continue;
                }
                $pdo->prepare('INSERT INTO centres (zone_id, name, full_name, section) VALUES (?, ?, ?, ?)')->execute([$zoneId, $name, $fullName ?: null, $section ?: null]);
                $centreIds[$key] = (int) $pdo->lastInsertId();
                $link = $pdo->prepare('INSERT INTO centre_labores (centre_id, labor_id) VALUES (?, ?)');
                foreach (array_keys($wanted) as $lid) {
                    $link->execute([$centreIds[$key], $lid]);
                }
            } elseif ($table === 'labores') {
                // One row per sub-labor ("sm | agd"); an empty sub-labor is a labor without any.
                $sub = $cell($row, 'sub');
                if (mb_strlen($sub, 'UTF-8') > 30) {
                    $reject($line, "\"$sub\" is longer than 30 characters.");
                    continue;
                }
                $made = 0;
                $topId = $topIds[$lower($name)] ?? null;
                if ($topId === null) {
                    $pdo->prepare('INSERT INTO labores (name, parent_id) VALUES (?, NULL)')->execute([$name]);
                    $topId = (int) $pdo->lastInsertId();
                    $topIds[$lower($name)] = $topId;
                    $made++;
                }
                if ($sub !== '' && !isset($existing[$topId . '|' . $lower($sub)])) {
                    $pdo->prepare('INSERT INTO labores (name, parent_id) VALUES (?, ?)')->execute([$sub, $topId]);
                    $existing[$topId . '|' . $lower($sub)] = true;
                    // The labor now has sub-labores, so a centre can no longer have it on its own.
                    $pdo->prepare('DELETE FROM centre_labores WHERE labor_id = ?')->execute([$topId]);
                    $made++;
                }
                $made ? $result['added'] += $made : $result['skipped']++;
                continue;
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
