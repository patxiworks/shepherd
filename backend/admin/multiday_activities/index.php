<?php
// Admin > Multi-day Activities: CRUD for the multiday_activities table (see
// migrate/015_multiday_activities.sql) — retreats, courses and camps that
// run across several days at a centre/venue, ported from the standalone
// "Painted Calendar" venue-booking tool. See calendar.php for the painted
// venue/date-range view of the same data.
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/activity_io.php';
require __DIR__ . '/../../includes/multiday_io.php';
require __DIR__ . '/../../includes/multiday_rollforward.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/bulk.php';
$admin = admin_require_role('super');
$isZoneScoped = $admin['role'] === 'zone';

$pdo = pastores_db();
$zoneIds = array_map('intval', $pdo->query('SELECT id FROM zones')->fetchAll(PDO::FETCH_COLUMN));

// A zone-scoped admin may only touch entries of their own zone.
function mday_in_scope(PDO $pdo, int $id, array $admin): bool
{
    if ($admin['role'] !== 'zone') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT zone_id FROM multiday_activities WHERE id = ?');
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn() === (int) $admin['zone_id'];
}

function mday_parse_qs(?string $qs): array
{
    parse_str((string) $qs, $parsed);
    return $parsed;
}

// The filters the list understands (query-string params): dropdowns for
// zone/centre/activity/section/group/priest, a date range (entries running
// at any time within it) and free text (`q`). Only well-formed values are
// kept. `zone` is dropped for a zone admin, who only ever sees their own zone.
function mday_filters(array $src, array $admin, array $zoneIds): array
{
    $filters = [];
    foreach (['zone', 'centre', 'activity', 'section', 'labor', 'priest', 'date_from', 'date_to', 'q'] as $key) {
        $v = trim((string) ($src[$key] ?? ''));
        if ($v === '') {
            continue;
        }
        if ($key === 'zone' && ($admin['role'] === 'zone' || !in_array((int) $v, $zoneIds, true))) {
            continue;
        }
        if (($key === 'date_from' || $key === 'date_to') && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            continue;
        }
        $filters[$key] = mb_substr($v, 0, 200);
    }
    return $filters;
}

// WHERE clause (on `multiday_activities m` joined to `zones z`) for the admin's scope plus the
// filters. Free text: every word must appear in at least one text column.
function mday_where(array $admin, array $filters): array
{
    $where = ['1 = 1'];
    $args = [];
    if ($admin['role'] === 'zone') {
        $where[] = 'm.zone_id = ?';
        $args[] = (int) $admin['zone_id'];
    }
    $columns = ['zone' => 'm.zone_id = ?', 'centre' => 'm.centre = ?', 'activity' => 'm.activity = ?', 'section' => 'm.section = ?',
                'labor' => 'm.labor = ?', 'priest' => 'm.priest = ?'];
    foreach ($columns as $key => $sql) {
        if (isset($filters[$key])) {
            $where[] = $sql;
            $args[] = $filters[$key];
        }
    }
    if (isset($filters['date_from'])) { // still running on/after this date
        $where[] = 'm.end_date >= ?';
        $args[] = $filters['date_from'];
    }
    if (isset($filters['date_to'])) {   // already started on/before this date
        $where[] = 'm.start_date <= ?';
        $args[] = $filters['date_to'];
    }
    if (isset($filters['q'])) {
        foreach (preg_split('/\s+/', $filters['q']) as $word) {
            $like = '%' . addcslashes($word, '\\%_') . '%';
            $where[] = '(z.name LIKE ? OR m.centre LIKE ? OR m.activity LIKE ? OR m.section LIKE ? OR m.labor LIKE ? OR m.priest LIKE ? OR m.description LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like, $like, $like);
        }
    }
    return [implode(' AND ', $where), $args];
}

// The Roll forward form's fields (roll_from, roll_to, roll_zone, roll_free,
// roll_maint, roll_venues), validated. Returns [params, null] or [null, error].
// A zone admin always rolls their own zone; a super admin's blank zone = all.
function mday_roll_params(array $src, array $admin, array $zoneIds): array
{
    $from = (int) ($src['roll_from'] ?? 0);
    $to = (int) ($src['roll_to'] ?? 0);
    if ($from < 2000 || $from > 2100 || $to < 2000 || $to > 2100) {
        return [null, 'Enter a valid source and target year.'];
    }
    if ($from === $to) {
        return [null, 'The target year must differ from the source year.'];
    }
    $zone = $admin['role'] === 'zone' ? (int) $admin['zone_id'] : ((int) ($src['roll_zone'] ?? 0) ?: null);
    if ($zone !== null && !in_array($zone, $zoneIds, true)) {
        return [null, 'Unknown zone.'];
    }
    $venues = array_values(array_filter(array_map('trim', explode(',', (string) ($src['roll_venues'] ?? '')))));
    return [['zone' => $zone, 'from' => $from, 'to' => $to, 'opts' => [
        'free' => !empty($src['roll_free']),
        'maintenance' => max(0, min(365, (int) ($src['roll_maint'] ?? 0))),
        'venues' => $venues,
    ]], null];
}

// 'HH:mm' or 'HH:mm:ss' (or empty) => 'H:i:s', or null.
function mday_time(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('H:i', $value) ?: DateTime::createFromFormat('H:i:s', $value);
    return $d ? $d->format('H:i:s') : null;
}

// Validates the submitted fields of one entry. Returns [fields, null] ready to
// store, or [null, error message]. $existing is the row being edited (its
// current centre/priest stay valid even if they are no longer on the zone's
// lists), or null for a new entry. Shared by the modal form and inline_save.
function mday_fields(PDO $pdo, array $admin, int $zoneId, ?array $existing, array $in): array
{
    $centre = trim($in['centre'] ?? '') ?: null;
    $priest = trim($in['priest'] ?? '') ?: null;
    $startDate = trim($in['start_date'] ?? '') ?: null;
    $startTime = mday_time($in['start_time'] ?? '');
    $endDate = trim($in['end_date'] ?? '') ?: null;
    $endTime = mday_time($in['end_time'] ?? '');
    $zoneCentres = array_column(
        array_filter(mday_centres($pdo, $admin['role'] === 'zone' ? (int) $admin['zone_id'] : null), fn($c) => (int) $c['zone_id'] === $zoneId),
        'name'
    );
    $zonePriests = array_column(priests_by_zone($pdo, $zoneId), 'name');
    $unchanged = fn(string $col, ?string $value) => $existing && $existing[$col] === $value && (int) $existing['zone_id'] === $zoneId;

    $rollRule = trim($in['roll_rule'] ?? '') ?: ($existing['roll_rule'] ?? 'flexible');
    if (!isset(MDAY_ROLL_RULES[$rollRule])) {
        return [null, 'Invalid roll rule.'];
    }

    if ($centre === null) {
        return [null, 'Centre is required.'];
    }
    if ($startDate === null || $endDate === null) {
        return [null, 'Start and end date are required.'];
    }
    if ($endDate < $startDate || ($endDate === $startDate && $startTime !== null && $endTime !== null && $endTime < $startTime)) {
        return [null, 'The end must not be before the start.'];
    }
    if (!in_array($centre, $zoneCentres, true) && !$unchanged('centre', $centre)) {
        return [null, "\"$centre\" is not a centre of that zone. Add it under Centres first."];
    }
    if ($priest !== null && !in_array($priest, $zonePriests, true) && !$unchanged('priest', $priest)) {
        return [null, "\"$priest\" is not a priest of that zone. Add them under Priests first."];
    }
    return [[
        'centre' => $centre,
        'activity' => trim($in['activity'] ?? '') ?: null,
        'section' => trim($in['section'] ?? '') ?: null,
        'labor' => trim($in['labor'] ?? '') ?: null,
        'priest' => $priest,
        'start_date' => $startDate,
        'start_time' => $startTime,
        'end_date' => $endDate,
        'end_time' => $endTime,
        'description' => trim($in['description'] ?? '') ?: null,
        'roll_rule' => $rollRule,
    ], null];
}

// One table row. Cells with data-field are editable in place (see the script
// below); data-value (and data-date / data-time for the start and end cells)
// is the raw value the editor starts from.
function mday_row_html(array $m, string $qs = ''): string
{
    $showDate = fn(string $d) => date('d/m/Y', strtotime($d));
    $when = fn(string $d, ?string $t) => e($showDate($d)) . ($t ? ' ' . e(date('H:i', strtotime($t))) : '');
    $cell = fn(string $field, string $type, ?string $raw) =>
        '<td data-field="' . $field . '" data-type="' . $type . '" data-value="' . e($raw ?? '') . '">' . e($raw ?? '') . '</td>';
    $range = fn(string $field, string $date, ?string $time) =>
        '<td data-field="' . $field . '" data-type="datetime" data-date="' . e($date) . '" data-time="' . e($time ? substr($time, 0, 5) : '')
        . '" data-sort="' . e($date) . '">' . $when($date, $time) . '</td>';
    $id = (int) $m['id'];
    ob_start();
    ?>
<tr data-id="<?= $id ?>" data-zone="<?= (int) $m['zone_id'] ?>">
  <td><?= e($m['zone_name']) ?></td>
  <?= $cell('centre', 'centre', $m['centre']) ?>
  <?= $cell('activity', 'activity', $m['activity']) ?>
  <?= $cell('section', 'section', $m['section']) ?>
  <?= $cell('labor', 'labor', $m['labor']) ?>
  <?= $cell('priest', 'priest', $m['priest']) ?>
  <?= $range('start', $m['start_date'], $m['start_time']) ?>
  <?= $range('end', $m['end_date'], $m['end_time']) ?>
  <?= $cell('description', 'text', $m['description']) ?>
  <td data-field="roll_rule" data-type="roll_rule" data-value="<?= e($m['roll_rule']) ?>"><?= e(MDAY_ROLL_RULES[$m['roll_rule']] ?? $m['roll_rule']) ?></td>
  <td class="actions">
    <?= icon_edit('/admin/multiday_activities/index.php?edit=' . $id . ($qs !== '' ? '&' . $qs : '')) ?>
    <form class="inline" method="post" onsubmit="return confirm('Delete this entry?');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="qs" value="<?= e($qs) ?>">
      <?= icon_delete() ?>
    </form>
  </td>
</tr>
<?php
    return trim(ob_get_clean());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'inline_save') {
        // Spreadsheet-style edit of one row; answers with JSON. Never moves an entry between zones.
        $reply = function (array $data, int $status = 200): void {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data);
            exit;
        };
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM multiday_activities WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch() ?: null;
        if ($existing === null || !mday_in_scope($pdo, $id, $admin)) {
            $reply(['error' => 'You do not have access to that entry.'], 403);
        }
        [$fields, $error] = mday_fields($pdo, $admin, (int) $existing['zone_id'], $existing, $_POST);
        if ($error !== null) {
            $reply(['error' => $error], 422);
        }
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $pdo->prepare("UPDATE multiday_activities SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
        $stmt = $pdo->prepare('SELECT m.*, z.name AS zone_name FROM multiday_activities m JOIN zones z ON z.id = m.zone_id WHERE m.id = ?');
        $stmt->execute([$id]);
        $reply(['html' => mday_row_html($stmt->fetch(), http_build_query(mday_filters(mday_parse_qs($_POST['qs'] ?? ''), $admin, $zoneIds)))]);
    }

    if ($action === 'import') {
        try {
            $link = trim($_POST['sheet_url'] ?? '');
            if (!empty($_FILES['file']['name'])) {
                $rows = uploaded_rows($_FILES['file']);
            } elseif ($link !== '') {
                $rows = csv_rows_from_string(google_sheet_csv($link));
            } else {
                throw new ImportException('Choose a CSV or XLSX file, or paste a Google Sheets link.');
            }
            // Rows with no zone value go into the zone currently filtered on (a zone admin's rows always go into their own zone).
            $_SESSION['import_report'] = import_multiday($pdo, $rows, (int) ($_POST['zone_id'] ?? 0), $isZoneScoped ? (int) $admin['zone_id'] : null);
        } catch (ImportException $ex) {
            flash('error', 'Import failed: ' . $ex->getMessage());
        }
    } elseif ($action === 'bulk_delete') {
        // Either the ticked rows (ids[], see includes/bulk.php) or every entry matching the filters in `qs`
        // (all_matching=1, with the count the admin was shown in `expected`, so a list that changed meanwhile isn't wiped).
        if (!empty($_POST['all_matching'])) {
            [$whereSql, $whereArgs] = mday_where($admin, mday_filters(mday_parse_qs($_POST['qs'] ?? ''), $admin, $zoneIds));
            $count = $pdo->prepare("SELECT COUNT(*) FROM multiday_activities m JOIN zones z ON z.id = m.zone_id WHERE $whereSql");
            $count->execute($whereArgs);
            if ((int) $count->fetchColumn() !== (int) ($_POST['expected'] ?? -1)) {
                flash('error', 'The list changed since you selected it, so nothing was deleted. Please try again.');
            } else {
                $del = $pdo->prepare("DELETE m FROM multiday_activities m JOIN zones z ON z.id = m.zone_id WHERE $whereSql");
                $del->execute($whereArgs);
                flash('success', 'Deleted ' . $del->rowCount() . ' entr' . ($del->rowCount() === 1 ? 'y' : 'ies') . '.');
            }
        } else {
            bulk_run(function (int $id) use ($pdo, $admin): ?string {
                if (!mday_in_scope($pdo, $id, $admin)) {
                    return 'You do not have access to that entry.';
                }
                $pdo->prepare('DELETE FROM multiday_activities WHERE id = ?')->execute([$id]);
                return null;
            }, 'entry', 'entries');
        }
    } elseif ($action === 'roll_apply') {
        [$rp, $error] = mday_roll_params($_POST, $admin, $zoneIds);
        if ($error !== null) {
            flash('error', $error);
        } else {
            $plan = mday_roll_plan($pdo, $rp['zone'], $rp['from'], $rp['to'], $rp['opts']);
            $unplaced = count(array_filter($plan['placeholders'], fn($p) => !empty($p['unplaced'])));
            if (!$plan['shifted'] && count($plan['placeholders']) === $unplaced) {
                flash('error', "Nothing to roll forward into {$rp['to']}" . ($plan['skipped'] ? ' — the entries are already there.' : ' — no entries start in ' . $rp['from'] . '.'));
            } else {
                [$nShifted, $nPlaceholders] = mday_roll_apply($pdo, $plan);
                flash('success', "Rolled {$rp['from']} forward to {$rp['to']}: $nShifted entr" . ($nShifted === 1 ? 'y' : 'ies') . " copied, $nPlaceholders Free/Maintenance placeholder" . ($nPlaceholders === 1 ? '' : 's') . ' added'
                    . ($plan['skipped'] ? ", {$plan['skipped']} already existed (skipped)" : '') . '.'
                    . ($unplaced ? " $unplaced maintenance window" . ($unplaced === 1 ? '' : 's') . ' could not be placed automatically — add them by hand.' : ''));
                // Show the new year afterwards.
                $rollBack = http_build_query(array_filter(['zone' => $rp['zone'], 'date_from' => $rp['to'] . '-01-01', 'date_to' => $rp['to'] . '-12-31']));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        if (mday_in_scope($pdo, $id, $admin)) {
            $pdo->prepare('DELETE FROM multiday_activities WHERE id = ?')->execute([$id]);
            flash('success', 'Entry deleted.');
        } else {
            flash('error', 'You do not have access to that entry.');
        }
    } else {
        $id = $_POST['id'] ?? '';
        $zoneId = $isZoneScoped ? (int) $admin['zone_id'] : (int) ($_POST['zone_id'] ?? 0);

        $existing = null;
        if ($id !== '') {
            $stmt = $pdo->prepare('SELECT * FROM multiday_activities WHERE id = ?');
            $stmt->execute([(int) $id]);
            $existing = $stmt->fetch() ?: null;
        }
        $zoneCheck = $pdo->prepare('SELECT COUNT(*) FROM zones WHERE id = ?');
        $zoneCheck->execute([$zoneId]);

        if (!$zoneCheck->fetchColumn()) {
            flash('error', 'Zone is required.');
        } elseif ($id !== '' && ($existing === null || !mday_in_scope($pdo, (int) $id, $admin))) {
            flash('error', 'You do not have access to that entry.');
        } else {
            [$fields, $error] = mday_fields($pdo, $admin, $zoneId, $existing, $_POST);
            if ($error !== null) {
                flash('error', $error);
            } elseif ($id !== '') {
                $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                $pdo->prepare("UPDATE multiday_activities SET zone_id = ?, $set WHERE id = ?")->execute([$zoneId, ...array_values($fields), (int) $id]);
                flash('success', 'Entry updated.');
            } else {
                $cols = implode(', ', array_keys($fields));
                $pdo->prepare("INSERT INTO multiday_activities (zone_id, $cols) VALUES (" . implode(',', array_fill(0, count($fields) + 1, '?')) . ')')
                    ->execute([$zoneId, ...array_values($fields)]);
                flash('success', 'Entry created.');
            }
        }
    }
    $back = $rollBack ?? http_build_query(mday_filters(mday_parse_qs($_POST['qs'] ?? ''), $admin, $zoneIds));
    header('Location: /admin/multiday_activities/index.php' . ($back !== '' ? '?' . $back : ''));
    exit;
}

$filters = mday_filters($_GET, $admin, $zoneIds);
$filterQs = http_build_query($filters);

// Roll forward: the form's defaults, and the preview once it has been submitted.
$rollScope = $isZoneScoped ? ' WHERE zone_id = ' . (int) $admin['zone_id'] : '';
$latestYear = (int) $pdo->query('SELECT MAX(YEAR(start_date)) FROM multiday_activities' . $rollScope)->fetchColumn() ?: (int) date('Y');
$roll = ['zone' => null, 'from' => $latestYear, 'to' => $latestYear + 1, 'opts' => ['free' => true, 'maintenance' => 14, 'venues' => ['Iroto', 'Iwollo']]];
$rollPreview = null;
$rollError = null;
if (isset($_GET['roll'])) {
    [$rp, $rollError] = mday_roll_params($_GET, $admin, $zoneIds);
    if ($rp !== null) {
        $roll = $rp;
        $rollPreview = mday_roll_plan($pdo, $rp['zone'], $rp['from'], $rp['to'], $rp['opts']);
    } else {
        // shown in the Roll forward card below
    }
}
$clearUrl = '/admin/multiday_activities/index.php';
$filterZone = isset($filters['zone']) ? (int) $filters['zone'] : ($isZoneScoped ? (int) $admin['zone_id'] : 0); // 0 = all zones

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId && mday_in_scope($pdo, $editId, $admin)) {
    $stmt = $pdo->prepare('SELECT * FROM multiday_activities WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

if ($isZoneScoped) {
    $zones = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
    $zones->execute([$admin['zone_id']]);
    $zones = $zones->fetchAll();
} else {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
}

// Table rows: the admin's scope plus the filters, newest start first, capped
// so a huge table can't bring the page down. The CSV export ignores the cap.
const MDAY_ROW_LIMIT = 300;
[$whereSql, $whereArgs] = mday_where($admin, $filters);
$entrySql = "SELECT m.*, z.name AS zone_name FROM multiday_activities m JOIN zones z ON z.id = m.zone_id WHERE $whereSql ORDER BY m.start_date DESC, m.id DESC";
if (isset($_GET['export'])) {
    $stmt = $pdo->prepare($entrySql);
    $stmt->execute($whereArgs);
    export_multiday_csv($stmt, 'multiday-activities-' . date('Y-m-d') . '.csv');
    exit;
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM multiday_activities m JOIN zones z ON z.id = m.zone_id WHERE $whereSql");
$countStmt->execute($whereArgs);
$totalMatching = (int) $countStmt->fetchColumn();
$stmt = $pdo->prepare($entrySql . ' LIMIT ' . MDAY_ROW_LIMIT);
$stmt->execute($whereArgs);
$entries = $stmt->fetchAll();

// Centres, keyed for the zone => name dropdown sync in JS below.
function mday_centres(PDO $pdo, ?int $zoneId = null): array
{
    $stmt = $pdo->prepare('SELECT zone_id, name FROM centres' . ($zoneId !== null ? ' WHERE zone_id = ?' : '') . ' ORDER BY name');
    $stmt->execute($zoneId !== null ? [$zoneId] : []);
    return $stmt->fetchAll();
}

$allCentres = mday_centres($pdo, $isZoneScoped ? (int) $admin['zone_id'] : null);
$formZoneId = (int) ($editing['zone_id'] ?? ($filterZone ?: ($zones[0]['id'] ?? 0)));
$formCentres = array_column(array_filter($allCentres, fn($r) => (int) $r['zone_id'] === $formZoneId), 'name');
$allPriests = priests_by_zone($pdo, $isZoneScoped ? (int) $admin['zone_id'] : null);
$formPriests = array_column(array_filter($allPriests, fn($r) => (int) $r['zone_id'] === $formZoneId), 'name');
$activityOptions = $pdo->query("SELECT name FROM activity_types WHERE is_multiday = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$sectionOptions = lookup_names($pdo, 'sections');
$laborOptions = lookup_names($pdo, 'labors');

$toTimeInput = fn(?string $t) => $t ? substr($t, 0, 5) : '';
$showDateFilter = fn(string $d) => date('d/m/Y', strtotime($d));

// Values that appear in the entries (in the admin's scope, and the filtered
// zone if any), for the filter dropdowns. The columns are free text, so they
// needn't still be in the admin lists.
$distinct = function (string $column) use ($pdo, $admin, $filterZone): array {
    $stmt = $pdo->prepare("SELECT DISTINCT $column FROM multiday_activities WHERE $column IS NOT NULL AND $column <> ''" . ($filterZone ? ' AND zone_id = ?' : '') . " ORDER BY $column");
    $stmt->execute($filterZone ? [$filterZone] : []);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};
$filterCentres = $distinct('centre');
$filterActivities = $distinct('activity');
$filterSections = $distinct('section');
$filterLabors = $distinct('labor');
$filterPriests = $distinct('priest');
$zoneOptions = array_column($zones, 'name', 'id');
$filterLabels = ['zone' => 'Zone', 'centre' => 'Centre', 'activity' => 'Activity', 'section' => 'Section', 'labor' => 'Group', 'priest' => 'Priest',
                 'date_from' => 'Running from', 'date_to' => 'Running until', 'q' => 'Text'];
$exportAllUrl = '/admin/multiday_activities/index.php?export=1';
$importReport = $_SESSION['import_report'] ?? null;
unset($_SESSION['import_report']);

// The filter fields shared by the Filter and Export modals; $p prefixes the
// element ids so the two forms don't clash. The free-text search (`q`) lives
// in the toolbar box and is carried along as a hidden field.
$renderFilterFields = function (string $p) use ($filters, $isZoneScoped, $zoneOptions, $filterCentres, $filterActivities, $filterSections, $filterLabors, $filterPriests) {
?>
    <?php if (!$isZoneScoped): ?>
    <div class="row">
      <div>
        <label for="<?= $p ?>zone">Zone</label>
        <?php render_select($p . 'zone', 'zone', $zoneOptions, $filters['zone'] ?? null, 'Any'); ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="row">
      <div>
        <label for="<?= $p ?>date_from">Running from</label>
        <input type="date" id="<?= $p ?>date_from" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
      </div>
      <div>
        <label for="<?= $p ?>date_to">Running until</label>
        <input type="date" id="<?= $p ?>date_to" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
      </div>
    </div>
    <small class="hint" style="min-height:0;">Matches every entry that runs on at least one day of this range.</small>
    <div class="row">
      <div>
        <label for="<?= $p ?>centre">Centre</label>
        <?php render_select($p . 'centre', 'centre', $filterCentres, $filters['centre'] ?? null, 'Any'); ?>
      </div>
      <div>
        <label for="<?= $p ?>activity">Activity</label>
        <?php render_select($p . 'activity', 'activity', $filterActivities, $filters['activity'] ?? null, 'Any'); ?>
      </div>
      <div>
        <label for="<?= $p ?>priest">Priest</label>
        <?php render_select($p . 'priest', 'priest', $filterPriests, $filters['priest'] ?? null, 'Any'); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="<?= $p ?>section">Section</label>
        <?php render_select($p . 'section', 'section', $filterSections, $filters['section'] ?? null, 'Any'); ?>
      </div>
      <div>
        <label for="<?= $p ?>labor">Group</label>
        <?php render_select($p . 'labor', 'labor', $filterLabors, $filters['labor'] ?? null, 'Any'); ?>
      </div>
    </div>
    <?php if (isset($filters['q'])): ?><input type="hidden" name="q" value="<?= e($filters['q']) ?>"><?php endif; ?>
<?php
};

$pageTitle = 'Multi-day Activities — Pastores Admin';
$pageWide = true; // same width as the Calendar tab, so switching tabs doesn't resize the page
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Multi-day Activities</h1>
<nav class="tabs">
  <a class="active" href="/admin/multiday_activities/index.php">List</a>
  <a href="/admin/multiday_activities/calendar.php">Calendar</a>
  <a href="/admin/multiday_activities/dashboard.php">Dashboard</a>
</nav>

<div class="card" data-modal data-add-label="New entry"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit entry' : 'New entry' ?></h2>
  <form method="post">
    <input type="hidden" name="qs" value="<?= e($filterQs) ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="row">
      <div>
        <label for="zone_id">Zone</label>
        <?php if ($isZoneScoped): ?>
          <input type="text" value="<?= e($zones[0]['name'] ?? '') ?>" disabled>
        <?php else: ?>
          <select id="zone_id" name="zone_id" required>
            <?php foreach ($zones as $zone): ?>
              <option value="<?= (int) $zone['id'] ?>" <?= $formZoneId === (int) $zone['id'] ? 'selected' : '' ?>><?= e($zone['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      <div>
        <label for="centre">Centre / Venue</label>
        <?php render_select('centre', 'centre', $formCentres, $editing['centre'] ?? null, 'Select a centre', ['required' => 'required']); ?>
      </div>
      <div>
        <label for="activity">Activity</label>
        <?php render_select('activity', 'activity', $activityOptions, $editing['activity'] ?? null, 'Select an activity'); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="section">Section</label>
        <?php render_select('section', 'section', $sectionOptions, $editing['section'] ?? null, 'Select a section'); ?>
      </div>
      <div>
        <label for="labor">Group / Labor</label>
        <?php render_select('labor', 'labor', $laborOptions, $editing['labor'] ?? null, 'Select a group'); ?>
      </div>
      <div>
        <label for="priest">Priest in charge</label>
        <?php render_select('priest', 'priest', $formPriests, $editing['priest'] ?? null, 'Select a priest'); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="start_date">Start date</label>
        <input type="date" id="start_date" name="start_date" value="<?= e($editing['start_date'] ?? '') ?>" required>
      </div>
      <div>
        <label for="start_time">Start time</label>
        <input type="time" id="start_time" name="start_time" value="<?= e($toTimeInput($editing['start_time'] ?? null)) ?>">
        <small class="hint">Leave blank for an evening arrival (the usual pattern).</small>
      </div>
      <div>
        <label for="end_date">End date</label>
        <input type="date" id="end_date" name="end_date" value="<?= e($editing['end_date'] ?? '') ?>" required>
      </div>
      <div>
        <label for="end_time">End time</label>
        <input type="time" id="end_time" name="end_time" value="<?= e($toTimeInput($editing['end_time'] ?? null)) ?>">
        <small class="hint">Leave blank for a morning departure (the usual pattern).</small>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="roll_rule">Roll rule</label>
        <select id="roll_rule" name="roll_rule">
          <?php foreach (MDAY_ROLL_RULES as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= ($editing['roll_rule'] ?? 'flexible') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="hint">How the dates move when rolled forward to a new year: the same calendar date, or the nearest same weekday (about 52 weeks later).</small>
      </div>
    </div>
    <label for="description">Description</label>
    <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create entry' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/multiday_activities/index.php<?= $filterQs !== '' ? '?' . e($filterQs) : '' ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="Roll forward" data-button-class="muted"<?= $rollError !== null ? ' data-open' : '' ?>>
  <h2>Roll forward to a new year</h2>
  <form method="get" id="roll-form">
    <input type="hidden" name="roll" value="1">
    <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <p class="hint" style="min-height:0;margin:0 0 4px;">
      Copies every entry that starts in the source year into the target year, moving its dates by its <strong>Roll rule</strong>
      (<strong>Exact date</strong> adds exactly one calendar year; <strong>Stick to weekend</strong> and <strong>Flexible</strong> land on the
      nearest same weekday, roughly 52 weeks later). Length is preserved. <em>Free</em> and <em>Maintenance</em> entries are not copied but
      regenerated as placeholders. Nothing is saved until you apply the preview, the source year is left as it is, and entries already in the
      target year are skipped.
    </p>
    <div class="row">
      <?php if (!$isZoneScoped): ?>
      <div>
        <label for="roll_zone">Zone</label>
        <select id="roll_zone" name="roll_zone">
          <option value="">All zones</option>
          <?php foreach ($zones as $zone): ?>
            <option value="<?= (int) $zone['id'] ?>" <?= (int) ($roll['zone'] ?? $filterZone) === (int) $zone['id'] ? 'selected' : '' ?>><?= e($zone['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label for="roll_from">From year</label>
        <input type="number" id="roll_from" name="roll_from" min="2000" max="2100" value="<?= (int) $roll['from'] ?>" required>
      </div>
      <div>
        <label for="roll_to">To year</label>
        <input type="number" id="roll_to" name="roll_to" min="2000" max="2100" value="<?= (int) $roll['to'] ?>" required>
      </div>
    </div>
    <label style="font-weight:normal;"><input type="checkbox" name="roll_free" value="1" style="width:auto;" <?= $roll['opts']['free'] ? 'checked' : '' ?>>
      Add Free-day placeholders (the day after each <em>ca</em> ends, and at least one free day per month per venue)</label>
    <div class="row">
      <div>
        <label for="roll_maint">Maintenance days per venue</label>
        <input type="number" id="roll_maint" name="roll_maint" min="0" max="365" value="<?= (int) $roll['opts']['maintenance'] ?>">
      </div>
      <div style="flex:2;">
        <label for="roll_venues">Venues that get maintenance</label>
        <input type="text" id="roll_venues" name="roll_venues" value="<?= e(implode(', ', $roll['opts']['venues'])) ?>" placeholder="Centre names, comma-separated">
      </div>
    </div>
    <small class="hint" style="min-height:0;">Maintenance is placed in the largest open gaps of the target year, up to the number of days above (0 or no venues = none).</small>
    <div class="btn-row">
      <button type="submit">Generate preview</button>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="<?= $filters ? 'Filter (' . count($filters) . ')' : 'Filter' ?>" data-button-class="muted" data-align="right">
  <h2>Filter multi-day activities</h2>
  <form method="get">
    <?php $renderFilterFields('f_'); ?>
    <div class="btn-row">
      <button type="submit">Apply filters</button>
      <a class="btn muted" href="<?= e($clearUrl) ?>">Clear filters</a>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="Import" data-button-class="muted">
  <h2>Import multi-day activities</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="qs" value="<?= e($filterQs) ?>">
    <input type="hidden" name="zone_id" value="<?= (int) $filterZone ?>">
    <label for="import_file">CSV or Excel (.xlsx) file</label>
    <input type="file" id="import_file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
    <label for="import_sheet">&hellip;or a Google Sheets link</label>
    <input type="url" id="import_sheet" name="sheet_url" placeholder="https://docs.google.com/spreadsheets/d/…">
    <small class="hint" style="min-height:0;">
      The sheet must be shared as "Anyone with the link can view". The first row must be a header row; columns can be in any order:
      <strong><?= e(implode(', ', MDAY_IO_COLUMNS)) ?></strong>.
    </small>
    <ul class="hint" style="margin:8px 0 0 18px;padding:0;">
      <li>Each row needs a <em>centre</em>, a <em>start_date</em> and an <em>end_date</em> (the end not before the start). The times are optional; leave them blank for an evening arrival and a morning departure.</li>
      <li>The zone comes from the <em>zone</em> column and must already exist in Zones.<?= $isZoneScoped ? ' As a zone admin, all rows go into your own zone.' : ' Empty = the zone currently filtered on.' ?></li>
      <li>Centre and priest (of that zone), activity, section and labor (group) must already exist in the admin lists (spelling and case are matched loosely); rows with unknown values are reported and skipped.</li>
      <li>Rows that already exist are skipped, so importing the same file twice is safe.</li>
    </ul>
    <div class="btn-row">
      <button type="submit">Import</button>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="Export" data-button-class="muted" data-close-on-submit>
  <h2>Export multi-day activities to CSV</h2>
  <form method="get">
    <input type="hidden" name="export" value="1">
    <p style="margin:0 0 4px;">Do you want to filter what gets exported? Leave everything empty to export
      <strong>all</strong> entries<?= $isZoneScoped ? ' of your zone' : '' ?>.</p>
    <?php $renderFilterFields('x_'); ?>
    <div class="btn-row">
      <button type="submit">Export<?= $filters ? ' with these filters' : '' ?></button>
      <a class="btn muted" href="<?= e($exportAllUrl) ?>" data-close-modal>Export all</a>
    </div>
  </form>
</div>

<form method="get" class="mday-search" data-toolbar-item data-before-right>
  <?php foreach ($filters as $k => $v): if ($k !== 'q'): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
  <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search text… (Enter)" aria-label="Search multi-day activities">
</form>

<?php if ($rollError !== null): ?><div class="flash error"><?= e($rollError) ?></div><?php endif; ?>
<?php if ($rollPreview !== null):
    $rp = $roll;
    $unplacedRows = array_filter($rollPreview['placeholders'], fn($p) => !empty($p['unplaced']));
    $placedRows = array_filter($rollPreview['placeholders'], fn($p) => empty($p['unplaced']));
    $nFree = count(array_filter($placedRows, fn($p) => $p['activity'] === MDAY_ROLL_FREE));
    $nMaint = count($placedRows) - $nFree;
    $dmy = fn(string $d) => date('d/m/Y', strtotime($d));
    $rollHidden = ['roll_from' => $rp['from'], 'roll_to' => $rp['to'], 'roll_zone' => $rp['zone'] ?? '', 'roll_maint' => $rp['opts']['maintenance'],
                   'roll_venues' => implode(', ', $rp['opts']['venues'])] + ($rp['opts']['free'] ? ['roll_free' => 1] : []);
?>
<div class="card" id="roll-preview">
  <h2>Roll forward <?= (int) $rp['from'] ?> &rarr; <?= (int) $rp['to'] ?> &mdash; preview</h2>
  <p>
    <strong><?= count($rollPreview['shifted']) ?></strong> entries shifted to <?= (int) $rp['to'] ?> &middot;
    <strong><?= $nFree ?></strong> Free-day placeholders &middot;
    <strong><?= $nMaint ?></strong> Maintenance placeholders
    <?php if ($rollPreview['skipped']): ?>&middot; <strong><?= (int) $rollPreview['skipped'] ?></strong> already in <?= (int) $rp['to'] ?> (skipped)<?php endif; ?>
    <?php if ($unplacedRows): ?>&middot; <strong style="color:#c62828;"><?= count($unplacedRows) ?></strong> <span style="color:#c62828;">could not be auto-placed</span><?php endif; ?>
  </p>
  <p class="hint" style="min-height:0;">Placeholders are not checked against your bookings elsewhere and should be reviewed after applying. Free/Maintenance activity types are created if missing.</p>
  <div style="max-height:420px;overflow:auto;margin-bottom:12px;">
    <table>
      <thead><tr><?php if (!$isZoneScoped): ?><th>Zone</th><?php endif; ?><th>Activity</th><th>Centre</th><th>Group</th><th><?= (int) $rp['from'] ?></th><th><?= (int) $rp['to'] ?></th><th>Rule</th></tr></thead>
      <tbody>
      <?php foreach ($rollPreview['shifted'] as $r): ?>
        <tr>
          <?php if (!$isZoneScoped): ?><td><?= e($r['zone_name']) ?></td><?php endif; ?>
          <td><?= e($r['activity'] ?? '') ?></td><td><?= e($r['centre']) ?></td><td><?= e($r['labor'] ?? '') ?></td>
          <td style="color:#888;"><?= e($dmy($r['source_start'])) ?> &rarr; <?= e($dmy($r['source_end'])) ?></td>
          <td><?= e($dmy($r['start_date'])) ?> &rarr; <?= e($dmy($r['end_date'])) ?></td>
          <td><?= e(MDAY_ROLL_RULES[$r['roll_rule']] ?? $r['roll_rule']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($rollPreview['placeholders'] as $r): ?>
        <tr style="background:<?= !empty($r['unplaced']) ? '#fdecea' : '#f3f8ff' ?>;">
          <?php if (!$isZoneScoped): ?><td><?= e($r['zone_name']) ?></td><?php endif; ?>
          <td><?= e($r['activity']) ?> <small style="color:var(--brand);">auto</small></td><td><?= e($r['centre']) ?></td><td>&mdash;</td>
          <td style="color:#888;">&mdash;</td>
          <?php if (!empty($r['unplaced'])): ?>
            <td colspan="2" style="color:#c62828;"><?= e($r['description']) ?></td>
          <?php else: ?>
            <td><?= e($dmy($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? ' &rarr; ' . e($dmy($r['end_date'])) : '' ?></td><td>&mdash;</td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rollPreview['shifted'] && !$rollPreview['placeholders']): ?>
        <tr><td colspan="7" style="color:#888;">Nothing to roll forward: no entries start in <?= (int) $rp['from'] ?><?= $rollPreview['skipped'] ? '' : ' (in that zone)' ?>, or they are all already in <?= (int) $rp['to'] ?>.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <form method="post" onsubmit="return confirm('Add these entries to <?= (int) $rp['to'] ?>? The <?= (int) $rp['from'] ?> entries are left as they are.');">
    <input type="hidden" name="action" value="roll_apply">
    <?php foreach ($rollHidden as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <div class="btn-row" style="margin-top:0;">
      <button type="submit" <?= $rollPreview['shifted'] || $placedRows ? '' : 'disabled' ?>>Apply — add to <?= (int) $rp['to'] ?></button>
      <a class="btn secondary" href="/admin/multiday_activities/index.php<?= $filterQs !== '' ? '?' . e($filterQs) : '' ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($importReport): ?>
<div class="card">
  <h2>Import finished</h2>
  <p>
    <strong><?= (int) $importReport['imported'] ?></strong> imported &middot;
    <strong><?= (int) $importReport['duplicates'] ?></strong> already existed (skipped) &middot;
    <strong><?= (int) $importReport['error_count'] ?></strong> with problems (not imported)
  </p>
  <?php foreach ($importReport['unknown'] as $field => $values): ?>
    <p class="hint" style="min-height:0;">
      Unknown <?= e($field) ?>: <strong><?= e(implode(', ', $values)) ?></strong>
      &mdash; add <?= count($values) === 1 ? 'it' : 'them' ?> in the admin, then import the file again (rows already imported are skipped).
    </p>
  <?php endforeach; ?>
  <?php if ($importReport['errors']): ?>
    <table>
      <thead><tr><th>Row</th><th>Problem</th></tr></thead>
      <tbody>
      <?php foreach ($importReport['errors'] as [$line, $message]): ?>
        <tr><td><?= (int) $line ?></td><td><?= e($message) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($importReport['error_count'] > count($importReport['errors'])): ?>
      <p class="hint">Showing the first <?= count($importReport['errors']) ?> of <?= (int) $importReport['error_count'] ?> problem rows.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($filters): ?>
<p class="table-hint">
  <strong>Filtered by</strong>
  <?php foreach ($filters as $key => $value): ?>
    &middot; <?= e($filterLabels[$key]) ?>: <?= e($key === 'zone' ? ($zoneOptions[$value] ?? $value) : ($key === 'date_from' || $key === 'date_to' ? $showDateFilter($value) : $value)) ?>
  <?php endforeach; ?>
  &middot; <a href="<?= e($clearUrl) ?>">Clear filters</a>
</p>
<?php endif; ?>
<p class="table-hint">Click a row to edit it in place &middot; click a column heading to sort &middot; showing <?= count($entries) ?> entr<?= count($entries) === 1 ? 'y' : 'ies' ?><?= count($entries) >= MDAY_ROW_LIMIT ? ' (latest ' . MDAY_ROW_LIMIT . ' &mdash; filter to see the rest; export includes all)' : '' ?>.</p>
<script>if (window.pastoresInitToolbar) window.pastoresInitToolbar(); // buttons first, before the long table is parsed</script>
<?= table_zoom_bar() ?>
<table data-bulk-total="<?= $totalMatching ?>" data-bulk-extra="<?= e(json_encode(['qs' => $filterQs])) ?>">
  <thead><tr><th>Zone</th><th>Centre</th><th>Activity</th><th>Section</th><th>Group</th><th>Priest</th><th>Start</th><th>End</th><th>Description</th><th>Roll rule</th><th></th></tr></thead>
  <tbody id="mday-body" data-qs="<?= e($filterQs) ?>">
  <?php foreach ($entries as $m): ?>
    <?= mday_row_html($m, $filterQs) ?>
  <?php endforeach; ?>
  <?php if (!$entries): ?>
    <tr><td colspan="11" style="color:#888;"><?= $filters ? 'No multi-day activities match these filters.' : 'No multi-day activities recorded.' ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
<style>
  tbody#mday-body tr[data-id] { cursor: pointer; }
  /* No padding: the .cell-input below fills the cell edge-to-edge (its own
     padding/border give it breathing room) instead of leaving a gap around
     it, so an editing row's wider fields (selects, longer inputs) don't
     also need the cell — and so the table — any wider than they already do. */
  /* border-collapse resolves a same-width/same-style border conflict (this
     row's border-top vs. the row above's border-bottom, both 1px solid)
     unreliably — not necessarily in favour of the one declared here — so
     these are 2px: strictly wider always wins, unambiguously. */
  tr.editing td { background: #fffbe6; padding: 0; cursor: default; vertical-align: top; border-top: 2px solid #000; border-bottom: 2px solid #000; }
  tr.editing .cell-input { width: 100%; min-width: 96px; height: 40px; padding: 4px 6px; font-size: 12px; border: none; }
  tr.editing td[data-type=datetime] .cell-input { min-width: 118px; margin-bottom: 3px; }
  tr.editing textarea.cell-input { min-height: 54px; min-width: 160px; }
  tr.editing td.actions { white-space: nowrap; padding: 0 8px; }
  tr.editing td.actions button { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; padding: 0; vertical-align: middle; }
  tr.editing td.actions button svg { width: 14px; height: 14px; }
  tr.editing .row-error { display: block; color: #c62828; font-size: 12px; margin-top: 4px; white-space: normal; }
  tr.saved td { background: #e8f5e9; }
  .table-hint { font-size: 12px; color: #666; margin: 0 0 8px; }
  form.mday-search { margin: 0; }
  form.mday-search input { width: 220px; padding: 6px 10px; font-size: 13px; }
</style>
<script>
(function () {
  var centres = <?= json_encode($allCentres) ?>; // {zone_id, name}
  var zoneSel = document.getElementById('zone_id');
  var centreSel = document.getElementById('centre');
  var priests = <?= json_encode($allPriests) ?>; // {zone_id, name}
  var priestSel = document.getElementById('priest');
  var activityTypes = <?= json_encode(array_values($activityOptions)) ?>;
  var sections = <?= json_encode(array_values($sectionOptions)) ?>;
  var labors = <?= json_encode(array_values($laborOptions)) ?>;
  var rollRules = <?= json_encode(array_map(null, array_keys(MDAY_ROLL_RULES), array_values(MDAY_ROLL_RULES))) ?>; // [value, label]
  var startDate = document.getElementById('start_date');
  var endDate = document.getElementById('end_date');

  // Zone change (super admin): list that zone's centres, keeping the choice if still valid.
  if (zoneSel) {
    zoneSel.addEventListener('change', function () {
      var keep = centreSel.value;
      centreSel.innerHTML = '';
      centreSel.add(new Option('Select a centre', ''));
      centres.forEach(function (c) {
        if (c.zone_id == zoneSel.value) centreSel.add(new Option(c.name, c.name, false, c.name === keep));
      });
      var keepPriest = priestSel.value;
      priestSel.innerHTML = '';
      priestSel.add(new Option('Select a priest', ''));
      priests.forEach(function (p) {
        if (p.zone_id == zoneSel.value) priestSel.add(new Option(p.name, p.name, false, p.name === keepPriest));
      });
    });
  }
  // Suggest the end date from the start when the end is empty or now earlier.
  startDate.addEventListener('change', function () {
    if (!endDate.value || endDate.value < startDate.value) endDate.value = startDate.value;
  });

  // ---- Spreadsheet-style row editing --------------------------------
  // Click a row to turn its cells into inputs. Enter (in a single-line
  // field) or Save stores it, Esc or Cancel puts it back. The zone is not
  // editable inline; use the Edit link for that.
  var tbody = document.getElementById('mday-body');
  var editing = null;   // { tr, orig }
  var dirty = false;

  function optionsFor(type, zone, current) {
    var list = type === 'centre' ? centres.filter(function (c) { return c.zone_id == zone; }).map(function (c) { return c.name; })
      : type === 'priest' ? priests.filter(function (p) { return p.zone_id == zone; }).map(function (p) { return p.name; })
      : type === 'activity' ? activityTypes.slice()
      : type === 'section' ? sections.slice()
      : labors.slice();
    if (current && list.indexOf(current) < 0) list.push(current); // keep values that are no longer in the list
    return list;
  }

  function input(type, name, value) {
    var el = document.createElement('input');
    el.type = type; el.name = name; el.value = value; el.className = 'cell-input';
    return el;
  }

  function makeEditor(td, zone) {
    var type = td.getAttribute('data-type'), field = td.getAttribute('data-field'), value = td.getAttribute('data-value') || '';
    td.textContent = '';
    if (type === 'datetime') {
      // two inputs: <field>_date and <field>_time
      td.appendChild(input('date', field + '_date', td.getAttribute('data-date') || ''));
      td.appendChild(input('time', field + '_time', td.getAttribute('data-time') || ''));
      return;
    }
    var el;
    if (type === 'roll_rule') {
      el = document.createElement('select');
      rollRules.forEach(function (r) { el.add(new Option(r[1], r[0])); });
    } else if (type === 'text') {
      el = document.createElement('textarea');
    } else {
      el = document.createElement('select');
      if (type !== 'centre') el.add(new Option('', '')); // a centre is required, so it can't be cleared
      optionsFor(type, zone, value).forEach(function (o) { el.add(new Option(o, o)); });
    }
    el.value = value;
    el.name = field;
    el.className = 'cell-input';
    td.appendChild(el);
  }

  function startEdit(tr, focusTd) {
    var orig = tr.cloneNode(true);
    var zone = tr.getAttribute('data-zone');
    tr.classList.add('editing');
    Array.prototype.forEach.call(tr.querySelectorAll('td[data-field]'), function (td) { makeEditor(td, zone); });
    tr.querySelector('td.actions').innerHTML =
      '<button type="button" class="save" aria-label="Save" title="Save"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></button> ' +
      '<button type="button" class="secondary cancel" aria-label="Cancel" title="Cancel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg></button>' +
      '<span class="row-error"></span>';
    editing = { tr: tr, orig: orig };
    dirty = false;
    var target = (focusTd && focusTd.querySelector('.cell-input')) || tr.querySelector('.cell-input');
    if (target) target.focus();
  }

  function cancelEdit() {
    if (!editing) return;
    editing.tr.replaceWith(editing.orig);
    editing = null;
  }

  function showError(msg) {
    var box = editing && editing.tr.querySelector('.row-error');
    if (box) box.textContent = msg;
  }

  function saveEdit() {
    if (!editing) return;
    var tr = editing.tr;
    var fd = new FormData();
    fd.append('action', 'inline_save');
    fd.append('id', tr.getAttribute('data-id'));
    fd.append('qs', tbody.getAttribute('data-qs') || '');
    Array.prototype.forEach.call(tr.querySelectorAll('[name]'), function (el) { fd.append(el.name, el.value); });
    var buttons = tr.querySelectorAll('td.actions button');
    Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
    showError('');
    fetch('/admin/multiday_activities/index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || res.body.error) throw new Error(res.body.error || 'Save failed.');
        var holder = document.createElement('tbody');
        holder.innerHTML = res.body.html;
        var fresh = holder.firstElementChild;
        tr.replaceWith(fresh);
        editing = null;
        fresh.classList.add('saved');
        setTimeout(function () { fresh.classList.remove('saved'); }, 1200);
      })
      .catch(function (err) {
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
        showError(err.message === 'Failed to fetch' ? 'Could not reach the server.' : err.message);
      });
  }

  tbody.addEventListener('click', function (ev) {
    if (ev.target.closest('button.save')) { saveEdit(); return; }
    if (ev.target.closest('button.cancel')) { cancelEdit(); return; }
    if (ev.target.closest('a, button, input, select, textarea, form')) return;
    var tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    if (editing) {
      if (editing.tr === tr) return;
      if (dirty) { showError('Save or cancel this row before editing another.'); return; }
      cancelEdit();
    }
    startEdit(tr, ev.target.closest('td'));
  });
  tbody.addEventListener('input', function () { if (editing) dirty = true; });
  tbody.addEventListener('change', function (ev) {
    if (!editing) return;
    dirty = true;
    // Same nicety as the form: keep the end from being before the start.
    if (ev.target.name === 'start_date') {
      var end = editing.tr.querySelector('[name=end_date]');
      if (end && (!end.value || end.value < ev.target.value)) end.value = ev.target.value;
    }
  });
  tbody.addEventListener('keydown', function (ev) {
    if (!editing) return;
    if (ev.key === 'Enter' && ev.target.matches('input, select')) { ev.preventDefault(); saveEdit(); }
    if (ev.key === 'Escape') { ev.preventDefault(); cancelEdit(); }
  });
})();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
