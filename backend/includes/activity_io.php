<?php
// Import/export helpers for the admin Activities page: reading CSV / XLSX /
// Google Sheets into rows, parsing dates and times, and writing the CSV
// export. No Composer dependencies (XLSX needs PHP's zip extension).

// Columns of the export, and the ones the importer understands. Order
// doesn't matter on import: columns are found by their header name.
const ACTIVITY_IO_COLUMNS = ['zone', 'section', 'week', 'day', 'duration', 'date', 'priest', 'activity', 'description', 'labor', 'centre', 'from', 'to'];

const ACTIVITY_IO_MAX_BYTES = 5 * 1024 * 1024;
const ACTIVITY_IO_MAX_ROWS = 10000; // blank rows count too

class ImportException extends RuntimeException
{
}

// "Activity Date " -> 'date', "Centre"/"Center" -> 'centre', ... or null for
// a column we don't use.
function import_header_key(string $header, array $columns = ACTIVITY_IO_COLUMNS): ?string
{
    $h = strtolower(preg_replace('/[^a-z0-9]+/i', '', preg_replace('/^\xEF\xBB\xBF/', '', $header)));
    $aliases = [
        'activitydate' => 'date',
        'labour' => 'labor',
        'center' => 'centre',
        'fromtime' => 'from', 'start' => 'from', 'starttime' => 'from',
        'totime' => 'to', 'end' => 'to', 'endtime' => 'to',
    ];
    $h = $aliases[$h] ?? $h;
    return in_array($h, $columns, true) ? $h : null;
}

// ---------------------------------------------------------------------
// Reading files -> list of rows (each a list of strings). Row 0 is the
// header. Empty trailing cells are kept so positions line up.
// ---------------------------------------------------------------------

function csv_rows_from_string(string $content): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252'); // typical Excel "CSV" on Windows
    }
    // Comma, semicolon (European Excel) or tab, judged from the header line.
    $firstLine = strtok($content, "\r\n") ?: '';
    $best = ',';
    $bestCount = -1;
    foreach ([',', ';', "\t"] as $d) {
        $c = substr_count($firstLine, $d);
        if ($c > $bestCount) {
            $best = $d;
            $bestCount = $c;
        }
    }
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $content);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, $best, '"', '')) !== false) {
        $rows[] = array_map(fn($v) => (string) $v, $row);
        if (count($rows) > ACTIVITY_IO_MAX_ROWS + 1) {
            break;
        }
    }
    fclose($stream);
    return $rows;
}

function xlsx_column_index(string $cellRef): int
{
    preg_match('/^([A-Z]+)/', $cellRef, $m);
    $n = 0;
    foreach (str_split($m[1] ?? 'A') as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

// Reads the first worksheet of an .xlsx file. Numbers (including dates
// and times, which Excel stores as numbers) come back as their raw numeric
// text; the date/time parsers below understand that.
function xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new ImportException('Reading .xlsx files needs the PHP "zip" extension on this server. Save the sheet as CSV instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new ImportException('That file is not a valid .xlsx workbook.');
    }
    try {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += $zip->statIndex($i)['size'];
        }
        if ($total > 60 * 1024 * 1024) {
            throw new ImportException('That workbook is too large to import.');
        }
        $load = function (string $name) use ($zip) {
            $xml = $zip->getFromName($name);
            if ($xml === false) {
                return null;
            }
            $prev = libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            libxml_use_internal_errors($prev);
            return $doc ?: null;
        };

        $shared = [];
        if (($ss = $load('xl/sharedStrings.xml')) !== null) {
            foreach ($ss->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else {
                    foreach ($si->r as $run) { // rich text: several runs
                        $text .= (string) $run->t;
                    }
                }
                $shared[] = $text;
            }
        }

        // First sheet in the workbook (falls back to the conventional path).
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $load('xl/workbook.xml');
        $rels = $load('xl/_rels/workbook.xml.rels');
        if ($wb !== null && $rels !== null && isset($wb->sheets->sheet[0])) {
            $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = (string) $rel['Target'];
                    $sheetPath = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', $target), '/');
                }
            }
        }
        $sheet = $load($sheetPath);
        if ($sheet === null) {
            throw new ImportException('Could not find a worksheet in that workbook.');
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $r = isset($row['r']) ? (int) $row['r'] - 1 : count($rows);
            $cells = [];
            foreach ($row->c as $c) {
                $i = isset($c['r']) ? xlsx_column_index((string) $c['r']) : count($cells);
                $type = (string) $c['t'];
                if ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string) $c->is->t;
                } elseif ($type === 'b') {
                    $val = (string) $c->v === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $val = (string) $c->v; // n, str, d, e
                }
                $cells[$i] = $val;
            }
            $line = [];
            $max = $cells ? max(array_keys($cells)) : -1;
            for ($k = 0; $k <= $max; $k++) {
                $line[] = $cells[$k] ?? '';
            }
            $rows[$r] = $line;
            if (count($rows) > ACTIVITY_IO_MAX_ROWS + 1) {
                break;
            }
        }
        // Rows may have gaps (blank rows are omitted in the file); fill them
        // so the row numbers we report match what the user sees in Excel.
        $out = [];
        $last = $rows ? max(array_keys($rows)) : -1;
        for ($k = 0; $k <= $last; $k++) {
            $out[] = $rows[$k] ?? [];
        }
        return $out;
    } finally {
        $zip->close();
    }
}

// Turns a Google Sheets link into the CSV export of that sheet and
// downloads it. Only docs.google.com/spreadsheets links are accepted, and
// the URL fetched is one we build ourselves from the sheet id and gid.
function google_sheet_csv(string $link): string
{
    $link = trim($link);
    if (!preg_match('#^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]+)#', $link, $m)) {
        throw new ImportException('That does not look like a Google Sheets link (https://docs.google.com/spreadsheets/d/…).');
    }
    $url = 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv';
    if (preg_match('/[#&?]gid=(\d+)/', $link, $g)) {
        $url .= '&gid=' . $g[1]; // a specific tab; otherwise the first tab
    }

    $body = false;
    $type = '';
    if (function_exists('curl_init')) {
        $tooBig = false;
        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, &$tooBig) {
                $buffer .= $chunk;
                if (strlen($buffer) > ACTIVITY_IO_MAX_BYTES) {
                    $tooBig = true;
                    return 0; // abort
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($tooBig) {
            throw new ImportException('That sheet is too large to import.');
        }
        if ($ok === false || $status >= 400) {
            if (in_array($status, [400, 401, 403, 404], true)) {
                throw new ImportException('Google would not let us read that sheet. Set its sharing to "Anyone with the link can view", or download it as CSV/XLSX and upload the file.');
            }
            throw new ImportException('Could not download the Google Sheet (' . ($status ?: 'no response') . ').');
        }
        $body = $buffer;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1, 'max_redirects' => 5]]);
        $body = @file_get_contents($url, false, $ctx, 0, ACTIVITY_IO_MAX_BYTES + 1);
        if ($body === false) {
            throw new ImportException('Could not download the Google Sheet.');
        }
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $type = $h;
            }
        }
    }

    // A private sheet answers with a Google sign-in page instead of CSV.
    if (stripos($type, 'text/html') !== false || preg_match('/^\s*<(!doctype|html)/i', $body)) {
        throw new ImportException('That sheet is private. Set its sharing to "Anyone with the link can view", or download it as CSV/XLSX and upload the file.');
    }
    return $body;
}

// Rows from an uploaded file ($_FILES entry).
function uploaded_rows(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ImportException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
            ? 'That file is too large.' : 'The file could not be uploaded.');
    }
    if ($file['size'] > ACTIVITY_IO_MAX_BYTES) {
        throw new ImportException('That file is larger than 5 MB.');
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($ext === 'xls') {
        throw new ImportException('Old .xls files are not supported. Save the sheet as .xlsx or CSV.');
    }
    $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 4);
    if ($ext === 'xlsx' || $head === "PK\x03\x04") {
        return xlsx_rows($file['tmp_name']);
    }
    return csv_rows_from_string((string) file_get_contents($file['tmp_name']));
}

// ---------------------------------------------------------------------
// Value parsers. Each returns the normalised value, null for an empty
// cell, or false for something that can't be understood.
// ---------------------------------------------------------------------

// 'Y-m-d'. Accepts 2026-09-20, 20/09/2026 (day first), 20.9.26, 20 Sep 2026,
// ISO timestamps, and Excel date serials.
function parse_import_date(string $v)
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $y = $m = $d = null;
    if (is_numeric($v) && $v >= 20000 && $v <= 80000) {
        return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400)); // Excel serial (1900 system)
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ].*)?$/', $v, $p)) {
        [, $y, $m, $d] = $p;
    } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', $v, $p)) {
        [, $d, $m, $y] = $p;
        if (strlen($y) === 2) {
            $y = 2000 + (int) $y;
        }
    } else {
        foreach (['j M Y', 'j F Y', 'M j, Y', 'F j, Y'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $v);
            if ($dt && $dt->format($fmt) === $v) {
                return $dt->format('Y-m-d');
            }
        }
        return false;
    }
    return checkdate((int) $m, (int) $d, (int) $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : false;
}

// 'HH:MM:SS'. Accepts 6:45, 06:45:00, 6:45 PM, 6pm, the old Sheets
// timestamp 1899-12-30T06:45:00.000Z (clock time taken literally), and
// Excel's fraction-of-a-day numbers.
function parse_import_time(string $v)
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/[T ](\d{1,2}):(\d{2})(?::(\d{2}))?/', $v, $p) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
        return sprintf('%02d:%02d:%02d', $p[1], $p[2], $p[3] ?? 0);
    }
    if (is_numeric($v)) {
        $n = (float) $v;
        if ($n < 0 || ($n >= 1 && $n < 20000)) {
            return false; // "45" is not a time
        }
        $secs = (int) round(($n - floor($n)) * 86400) % 86400;
        return sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?$/', $v, $p)) {
        [$h, $mi, $s] = [(int) $p[1], (int) $p[2], (int) ($p[3] ?? 0)];
        $ampm = strtolower($p[4] ?? '');
    } elseif (preg_match('/^(\d{1,2})\s*([AaPp][Mm])$/', $v, $p)) {
        [$h, $mi, $s] = [(int) $p[1], 0, 0];
        $ampm = strtolower($p[2]);
    } else {
        return false;
    }
    if ($ampm !== '') {
        if ($h < 1 || $h > 12) {
            return false;
        }
        $h = ($h % 12) + ($ampm === 'pm' ? 12 : 0);
    }
    return ($h < 24 && $mi < 60 && $s < 60) ? sprintf('%02d:%02d:%02d', $h, $mi, $s) : false;
}

// Undo the leading apostrophe the export adds to text that would otherwise
// be read as a spreadsheet formula.
function import_text(string $v): string
{
    $v = trim($v);
    return preg_match("/^'[=+\\-@]/", $v) ? substr($v, 1) : $v;
}

function export_text(?string $v): string
{
    $v = (string) $v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}

// ---------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------

// Imports parsed rows into activities. Returns a report:
//   imported, duplicates (already there, skipped), errors [[row, message]],
//   error_count, unknown [field => [values]] (lookup values that aren't in
//   the database yet), zones [ids touched].
// Valid rows are inserted even if others fail; rerunning the same file is
// safe because rows already present are skipped.
function import_activities(PDO $pdo, array $rows, array $admin, ?string $scopeCentreName, int $defaultZoneId, string $weekStart): array
{
    if (!$rows) {
        throw new ImportException('That file is empty.');
    }
    $header = array_shift($rows);
    $col = []; // field => column index
    foreach ($header as $i => $h) {
        $key = import_header_key((string) $h);
        if ($key !== null && !isset($col[$key])) {
            $col[$key] = $i;
        }
    }
    if (!isset($col['date'])) {
        throw new ImportException('The first row must be a header row with at least a "date" column. Expected columns: ' . implode(', ', ACTIVITY_IO_COLUMNS) . '.');
    }
    if (count($rows) > ACTIVITY_IO_MAX_ROWS) {
        throw new ImportException('Too many rows (limit ' . ACTIVITY_IO_MAX_ROWS . ' per import).');
    }

    // Lookups (case-insensitive; the stored spelling wins).
    $zoneIds = [];
    $zoneNameOf = [];
    foreach ($pdo->query('SELECT id, name FROM zones') as $z) {
        $zoneIds[mb_strtolower($z['name'])] = (int) $z['id'];
        $zoneNameOf[(int) $z['id']] = $z['name'];
    }
    $centres = [];  // zone_id => lc name => [name, section]
    foreach ($pdo->query('SELECT zone_id, name, section FROM centres') as $c) {
        $centres[(int) $c['zone_id']][mb_strtolower($c['name'])] = [$c['name'], $c['section']];
    }
    $priests = [];  // zone_id => lc name => name
    foreach (priests_by_zone($pdo) as $p) {
        $priests[(int) $p['zone_id']][mb_strtolower($p['name'])] = $p['name'];
    }
    $global = fn(string $table) => array_column(
        array_map(fn($n) => [mb_strtolower($n), $n], $pdo->query("SELECT name FROM $table")->fetchAll(PDO::FETCH_COLUMN)), 1, 0
    );
    $labors = $global('labors');
    $types = $global('activity_types');
    $sections = $global('sections');

    $dupe = $pdo->prepare(
        'SELECT 1 FROM activities WHERE zone_id = ? AND activity_date = ? AND centre <=> ? AND activity <=> ? AND from_time <=> ? AND to_time <=> ? LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO activities (zone_id, week, day, weekday, activity_date, centre, activity, section, labor, from_time, to_time, duration, priest, description)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $report = ['imported' => 0, 'duplicates' => 0, 'errors' => [], 'error_count' => 0, 'unknown' => [], 'zones' => []];
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
            $unknown = function (string $field, string $value) use (&$report, &$problems) {
                $report['unknown'][$field][$value] = true;
                $problems[] = "unknown $field \"$value\"";
            };

            // zone
            $zoneName = $get($row, 'zone');
            if ($admin['role'] !== 'super') {
                $zoneId = (int) $admin['zone_id'];
                if ($zoneName !== '' && (($zoneIds[mb_strtolower($zoneName)] ?? 0) !== $zoneId)) {
                    $problems[] = "zone \"$zoneName\" is not your zone";
                }
            } elseif ($zoneName === '') {
                $zoneId = $defaultZoneId;
            } elseif (isset($zoneIds[mb_strtolower($zoneName)])) {
                $zoneId = $zoneIds[mb_strtolower($zoneName)];
            } else {
                $zoneId = 0;
                $unknown('zone', $zoneName);
            }

            if (!$zoneId && !$problems) {
                $problems[] = 'zone is required';
            }

            // date
            $date = parse_import_date($get($row, 'date'));
            if ($date === null) {
                $problems[] = 'date is required';
            } elseif ($date === false) {
                $problems[] = 'unrecognised date "' . $get($row, 'date') . '" (use yyyy-mm-dd or dd/mm/yyyy)';
            }

            // centre (and the section that comes with it)
            $centre = null;
            $section = null;
            $centreName = $get($row, 'centre');
            if ($admin['role'] === 'centre') {
                if ($centreName !== '' && mb_strtolower($centreName) !== mb_strtolower((string) $scopeCentreName)) {
                    $problems[] = "centre \"$centreName\" is not your centre";
                }
                $centreName = (string) $scopeCentreName;
            }
            if ($centreName !== '' && $zoneId) {
                if (isset($centres[$zoneId][mb_strtolower($centreName)])) {
                    [$centre, $section] = $centres[$zoneId][mb_strtolower($centreName)];
                } else {
                    $unknown('centre', $centreName);
                }
            }
            $sectionName = $get($row, 'section');
            if ($section === null && $sectionName !== '') {
                if (isset($sections[mb_strtolower($sectionName)])) {
                    $section = $sections[mb_strtolower($sectionName)];
                } else {
                    $unknown('section', $sectionName);
                }
            }

            // lookups
            $activity = $labor = $priest = null;
            if (($v = $get($row, 'activity')) !== '') {
                isset($types[mb_strtolower($v)]) ? $activity = $types[mb_strtolower($v)] : $unknown('activity', $v);
            }
            if (($v = $get($row, 'labor')) !== '') {
                isset($labors[mb_strtolower($v)]) ? $labor = $labors[mb_strtolower($v)] : $unknown('labor', $v);
            }
            if (($v = $get($row, 'priest')) !== '') {
                if (isset($priests[$zoneId][mb_strtolower($v)])) {
                    $priest = $priests[$zoneId][mb_strtolower($v)];
                } else {
                    $unknown('priest', $v . ($zoneId && isset($zoneNameOf[$zoneId]) ? ' (in ' . $zoneNameOf[$zoneId] . ')' : ''));
                }
            }

            // times
            $times = [];
            foreach (['from', 'to', 'duration'] as $f) {
                $t = parse_import_time($get($row, $f));
                if ($t === false) {
                    $problems[] = "unrecognised $f time \"" . $get($row, $f) . '"';
                }
                $times[$f] = $t ?: null;
            }

            if ($problems) {
                $report['error_count']++;
                if (count($report['errors']) < 200) {
                    $report['errors'][] = [$line, implode('; ', $problems)];
                }
                continue;
            }

            $key = implode('|', [$zoneId, $date, $centre, $activity, $times['from'], $times['to']]);
            $dupe->execute([$zoneId, $date, $centre, $activity, $times['from'], $times['to']]);
            if (isset($seen[$key]) || $dupe->fetchColumn()) {
                $report['duplicates']++;
                continue;
            }
            $seen[$key] = true;

            $parts = date_parts($date, $weekStart);
            $insert->execute([
                $zoneId, $parts['week'], $parts['day'], $parts['weekday'], $date, $centre, $activity, $section, $labor,
                $times['from'], $times['to'], $times['duration'], $priest, $get($row, 'description') ?: null,
            ]);
            $report['imported']++;
            $report['zones'][$zoneId] = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    foreach (array_keys($report['zones']) as $zid) {
        touch_zone($pdo, (int) $zid);
    }
    foreach ($report['unknown'] as $field => $values) {
        $report['unknown'][$field] = array_keys($values);
    }
    return $report;
}

// ---------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------

// Streams activity rows (each with a zone_name) as CSV with the import columns.
function export_activities_csv(iterable $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
    fputcsv($out, ACTIVITY_IO_COLUMNS, ',', '"', '');
    $hm = fn(?string $t) => $t ? substr($t, 0, 5) : '';
    foreach ($rows as $a) {
        fputcsv($out, [
            export_text($a['zone_name']), export_text($a['section']), $a['week'], $a['day'], $hm($a['duration']),
            $a['activity_date'], export_text($a['priest']), export_text($a['activity']), export_text($a['description']),
            export_text($a['labor']), export_text($a['centre']), $hm($a['from_time']), $hm($a['to_time']),
        ], ',', '"', '');
    }
    fclose($out);
}
