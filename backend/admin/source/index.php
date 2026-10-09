<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/activity_io.php';
require __DIR__ . '/../../includes/source_io.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/bulk.php';
// The Source table mirrors Activities but is for super admins only, so there
// is no zone/centre scoping here. Its `day` column is the numeric day of the
// week (1 = first day of the week per Admin > Settings; Sunday start: Mon = 2,
// Tue = 3 ...) and there is no separate weekday column. Unlike Activities the
// table lists every zone (Zone column, and a Zone filter), and rows have no
// date: their week (nth occurrence of the day in the month) and day are
// entered by hand.
$admin = admin_require_role('super');

$pdo = pastores_db();

$weekStart = get_week_start($pdo);
$weekdayNames = weekday_names($weekStart);
$dayOptions = array_map(fn($n, $name) => "$n – $name", array_keys($weekdayNames), $weekdayNames);
$dayOptions = array_combine(array_keys($weekdayNames), $dayOptions);

$zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
$zoneIds = array_map(fn($z) => (int) $z['id'], $zones);

function source_exists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM source WHERE id = ?');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

// The filters the Source page understands (query-string params). Only
// well-formed values are kept; anything else is dropped.
function source_filters(array $src, array $weekdayNames, array $zoneIds): array
{
    $filters = [];
    foreach (['zone', 'day', 'centre', 'section', 'priest'] as $key) {
        $v = trim((string) ($src[$key] ?? ''));
        if ($v === '') {
            continue;
        }
        if ($key === 'day' && !isset($weekdayNames[$v])) {
            continue;
        }
        if ($key === 'zone' && !in_array((int) $v, $zoneIds, true)) {
            continue;
        }
        $filters[$key] = $v;
    }
    return $filters;
}

// WHERE clause (on `source a`) for the rows matching the filters.
function source_where(array $filters): array
{
    $where = ['1 = 1'];
    $args = [];
    $columns = ['zone' => 'a.zone_id = ?', 'day' => 'a.day = ?',
                'centre' => 'a.centre = ?', 'section' => 'a.section = ?', 'priest' => 'a.priest = ?'];
    foreach ($filters as $key => $value) {
        $where[] = $columns[$key];
        $args[] = $value;
    }
    return [implode(' AND ', $where), $args];
}

// Filters carried through a POST (hidden `qs` field) so saving/deleting
// returns to the same filtered list.
function source_filters_from_qs(?string $qs, array $weekdayNames, array $zoneIds): array
{
    parse_str((string) $qs, $parsed);
    return source_filters($parsed, $weekdayNames, $zoneIds);
}

// Turns submitted form values into the source columns. Week (1-6) and day
// (1-7) are taken as submitted. Centre, activity, labor and priest must be
// entries of their lists (the zone's own centres/priests) or the same as the
// row already has (so a legacy value doesn't block editing other cells); the
// section comes from the centre. Throws InvalidArgumentException with a
// message for the admin when something isn't valid.
function source_fields(PDO $pdo, int $zoneId, ?int $id, array $in): array
{
    $week = (int) ($in['week'] ?? 0);
    $day = (int) ($in['day'] ?? 0);
    $week = ($week >= 1 && $week <= 6) ? $week : null;
    $day = ($day >= 1 && $day <= 7) ? $day : null;

    $current = [];
    if ($id) {
        $stmt = $pdo->prepare('SELECT zone_id, centre, activity, labor, priest, alt_priest FROM source WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch() ?: [];
    }
    $sameZone = $current && (int) $current['zone_id'] === $zoneId;
    $want = [];
    foreach (['centre', 'activity', 'labor', 'priest', 'alt_priest'] as $f) {
        $want[$f] = trim($in[$f] ?? '');
    }
    [$v, $problems] = source_resolve(source_lookups($pdo), $zoneId, $want);
    $legacyCentre = false;
    foreach ($problems as [$field, $text, $message]) {
        if ($sameZone && (string) $current[$field] === $text) {
            $v[$field] = $text; // unchanged legacy value: keep it
            $legacyCentre = $legacyCentre || $field === 'centre';
            continue;
        }
        throw new InvalidArgumentException(ucfirst($message) . '. Add it under Admin > ' . ['centre' => 'Centres', 'activity' => 'Activity types', 'labor' => 'Labors', 'priest' => 'Priests', 'alt_priest' => 'Priests'][$field] . ' first.');
    }
    if ($legacyCentre) {
        // Centre isn't in the centres list (legacy row kept above): leave the section as it was.
        $stmt = $pdo->prepare('SELECT section FROM source WHERE id = ?');
        $stmt->execute([$id]);
        $v['section'] = $stmt->fetchColumn() ?: null;
    }

    return [
        'week' => $week,
        'day' => $day,
        'centre' => $v['centre'],
        'activity' => $v['activity'],
        'section' => $v['section'],
        'labor' => $v['labor'],
        'from_time' => ($in['from_time'] ?? '') ?: null,
        'to_time' => ($in['to_time'] ?? '') ?: null,
        'duration' => ($in['duration'] ?? '') ?: null,
        'priest' => $v['priest'],
        'alt_priest' => $v['alt_priest'],
        'description' => trim($in['description'] ?? '') ?: null,
    ];
}

// One table row. Cells with data-field are editable in place (see the
// script below); data-value is the raw value the editor starts from and
// the section is recalculated from the centre while editing. $a needs zone_name.
function source_row_html(array $a, string $qs = '', array $weekdayNames = []): string
{
    $t = fn($v) => e(substr((string) ($v ?? ''), 0, 5));
    $cell = fn(string $field, string $type, ?string $raw, string $text, string $extra = '') =>
        '<td data-field="' . $field . '" data-type="' . $type . '" data-value="' . e($raw ?? '') . '"' . $extra . '>' . $text . '</td>';
    $id = (int) $a['id'];
    $zone = (int) $a['zone_id'];
    ob_start();
    ?>
<tr data-id="<?= $id ?>" data-zone="<?= $zone ?>">
  <td><?= e($a['zone_name']) ?></td>
  <?= $cell('day', 'day', (string) $a['day'], e((string) $a['day']), ' title="' . e($weekdayNames[$a['day']] ?? '') . '"') ?>
  <?= $cell('week', 'week', (string) $a['week'], e((string) $a['week'])) ?>
  <?= $cell('centre', 'centre', $a['centre'], e($a['centre'])) ?>
  <td data-derived="section"><?= e($a['section']) ?></td>
  <?= $cell('activity', 'activity', $a['activity'], e($a['activity'])) ?>
  <?= $cell('labor', 'labor', $a['labor'], e($a['labor'])) ?>
  <?= $cell('priest', 'priest', $a['priest'], e($a['priest'])) ?>
  <?= $cell('alt_priest', 'priest', $a['alt_priest'], e($a['alt_priest'])) ?>
  <?= $cell('from_time', 'time', $a['from_time'] ? substr($a['from_time'], 0, 5) : '', $t($a['from_time'])) ?>
  <?= $cell('to_time', 'time', $a['to_time'] ? substr($a['to_time'], 0, 5) : '', $t($a['to_time'])) ?>
  <?= $cell('duration', 'time', $a['duration'] ? substr($a['duration'], 0, 5) : '', $t($a['duration'])) ?>
  <?= $cell('description', 'text', $a['description'], e($a['description']), ' class="desc" title="' . e($a['description']) . '"') ?>
  <td class="actions">
    <?= icon_edit('/admin/source/index.php?edit=' . $id . ($qs !== '' ? '&' . $qs : '')) ?>
    <form class="inline" method="post" onsubmit="return confirm('Delete this source row?');">
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
        // Spreadsheet-style edit of one row; answers with JSON.
        $reply = function (array $data, int $status = 200): void {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data);
            exit;
        };
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT zone_id FROM source WHERE id = ?');
        $stmt->execute([$id]);
        $zoneId = $stmt->fetchColumn(); // an inline edit never moves a row between zones
        if (!$id || $zoneId === false) {
            $reply(['error' => 'That source row no longer exists.'], 404);
        }
        try {
            $fields = source_fields($pdo, (int) $zoneId, $id, $_POST);
        } catch (InvalidArgumentException $ex) {
            $reply(['error' => $ex->getMessage()], 422);
        }
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $pdo->prepare("UPDATE source SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
        $stmt = $pdo->prepare('SELECT a.*, z.name AS zone_name FROM source a JOIN zones z ON z.id = a.zone_id WHERE a.id = ?');
        $stmt->execute([$id]);
        $reply(['html' => source_row_html($stmt->fetch(), http_build_query(source_filters_from_qs($_POST['qs'] ?? '', $weekdayNames, $zoneIds)), $weekdayNames)]);
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
            // Rows with no zone/unit value go into the zone currently filtered on, if any.
            $_SESSION['import_report'] = import_source($pdo, $rows, (int) ($_POST['zone_id'] ?? 0));
        } catch (ImportException $ex) {
            flash('error', 'Import failed: ' . $ex->getMessage());
        }
    } elseif ($action === 'bulk_delete') {
        if (!empty($_POST['all_matching'])) {
            [$whereSql, $whereArgs] = source_where(source_filters_from_qs($_POST['qs'] ?? '', $weekdayNames, $zoneIds));
            bulk_delete_matching($pdo, 'source', $whereSql, $whereArgs, 'source row', 'source rows');
        } else {
            bulk_run(function (int $id) use ($pdo): ?string {
                if (!source_exists($pdo, $id)) {
                    return 'That source row no longer exists.';
                }
                $pdo->prepare('DELETE FROM source WHERE id = ?')->execute([$id]);
                return null;
            }, 'source row', 'source rows');
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        if (source_exists($pdo, $id)) {
            $pdo->prepare('DELETE FROM source WHERE id = ?')->execute([$id]);
            flash('success', 'Source row deleted.');
        } else {
            flash('error', 'That source row no longer exists.');
        }
    } else {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        $id = $_POST['id'] ?? '';
        try {
            $fields = source_fields($pdo, $zoneId, $id !== '' ? (int) $id : null, $_POST);
        } catch (InvalidArgumentException $ex) {
            $fields = null;
            flash('error', $ex->getMessage());
        }

        if ($fields === null) {
            // error already flashed
        } elseif (!$zoneId) {
            flash('error', 'Zone is required.');
        } elseif ($id !== '' && !source_exists($pdo, (int) $id)) {
            flash('error', 'That source row no longer exists.');
        } else {
            if ($id !== '') {
                $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                $pdo->prepare("UPDATE source SET zone_id = ?, $set WHERE id = ?")
                    ->execute([$zoneId, ...array_values($fields), (int) $id]);
                flash('success', 'Source row updated.');
            } else {
                $cols = implode(', ', array_merge(['zone_id'], array_keys($fields)));
                $placeholders = implode(', ', array_fill(0, count($fields) + 1, '?'));
                $pdo->prepare("INSERT INTO source ($cols) VALUES ($placeholders)")
                    ->execute([$zoneId, ...array_values($fields)]);
                flash('success', 'Source row created.');
            }
        }
    }
    $query = source_filters_from_qs($_POST['qs'] ?? '', $weekdayNames, $zoneIds);
    header('Location: /admin/source/index.php' . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM source WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$filters = source_filters($_GET, $weekdayNames, $zoneIds);
$filterQs = http_build_query($filters);
$filterZone = isset($filters['zone']) ? (int) $filters['zone'] : null; // null = all zones

// Dropdown data. Centres and priests are filtered client-side by the selected
// zone (the form's Zone can be changed); the server renders the initial
// zone's list (the filtered or edited zone, if any) so the form is right
// before any JS runs.
$allCentres = $pdo->query('SELECT zone_id, name, section FROM centres ORDER BY name')->fetchAll();
$allPriests = priests_by_zone($pdo); // home zone or also serving there
$formZoneId = (int) ($editing['zone_id'] ?? $filterZone ?? 0);
$inFormZone = fn(array $rows) => array_values(array_filter($rows, fn($r) => (int) $r['zone_id'] === $formZoneId));
$formCentres = array_column($inFormZone($allCentres), 'name');
$formPriests = array_column($inFormZone($allPriests), 'name');
$laborOptions = lookup_names($pdo, 'labors');
$sectionOptions = lookup_names($pdo, 'sections');
$activityOptions = lookup_names($pdo, 'activity_types');

// Initial hint under the Centre field (the script below keeps it current
// as the form changes).
$currentCentre = $editing['centre'] ?? null;
$currentSection = null;
foreach ($allCentres as $c) {
    if ($c['name'] === $currentCentre && (int) $c['zone_id'] === $formZoneId) {
        $currentSection = $c['section'];
    }
}
$sectionHint = $currentCentre ? 'Section: ' . ($currentSection ?: '—') : '';

// Table rows: every source row matching the filters (all zones unless
// filtered), zone by zone in calendar order. Capped so a huge table can't
// bring the page down.
const SOURCE_ROW_LIMIT = 5000;
[$whereSql, $whereArgs] = source_where($filters);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM source a WHERE $whereSql");
$countStmt->execute($whereArgs);
$totalMatching = (int) $countStmt->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT a.*, z.name AS zone_name FROM source a JOIN zones z ON z.id = a.zone_id WHERE $whereSql
     ORDER BY z.name, a.week, a.day, a.from_time, a.id LIMIT " . SOURCE_ROW_LIMIT
);
$stmt->execute($whereArgs);
$activities = $stmt->fetchAll();

// CSV export of everything the current view is filtered to (not just the
// rows the table shows).
if (isset($_GET['export'])) {
    $stmt = $pdo->prepare(
        "SELECT a.*, z.name AS zone_name FROM source a JOIN zones z ON z.id = a.zone_id WHERE $whereSql ORDER BY z.name, a.week, a.day, a.from_time, a.id"
    );
    $stmt->execute($whereArgs);
    export_source_csv($stmt, 'source-' . date('Y-m-d') . '.csv');
    exit;
}
$filterLabels = ['zone' => 'Zone', 'day' => 'Day', 'centre' => 'Centre', 'section' => 'Section', 'priest' => 'Priest'];
$zoneOptions = array_column($zones, 'name', 'id');
$exportAllUrl = '/admin/source/index.php?export=1';
$importReport = $_SESSION['import_report'] ?? null;
unset($_SESSION['import_report']);
$clearUrl = '/admin/source/index.php';

// Centres and priests that appear in the source rows (source keeps them as
// free text, so they needn't be in the admin lists), for the filter dropdowns.
$distinct = function (string $column) use ($pdo, $filterZone): array {
    $stmt = $pdo->prepare("SELECT DISTINCT $column FROM source WHERE $column IS NOT NULL AND $column <> ''" . ($filterZone ? ' AND zone_id = ?' : '') . " ORDER BY $column");
    $stmt->execute($filterZone ? [$filterZone] : []);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};
$filterCentres = $distinct('centre');
$filterPriests = $distinct('priest');

// The zone/day/centre/section/priest fields shared by the Filter and
// Export modals; $p prefixes the element ids so the two forms don't clash.
$renderFilterFields = function (string $p) use ($filters, $dayOptions, $zoneOptions, $filterCentres, $filterPriests, $sectionOptions) {
?>
    <div class="row">
      <div>
        <label for="<?= $p ?>zone">Zone</label>
        <?php render_select($p . 'zone', 'zone', $zoneOptions, $filters['zone'] ?? null, 'Any'); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="<?= $p ?>day">Day</label>
        <?php render_select($p . 'day', 'day', $dayOptions, $filters['day'] ?? null, 'Any'); ?>
      </div>
      <div>
        <label for="<?= $p ?>centre">Centre</label>
        <?php render_select($p . 'centre', 'centre', $filterCentres, $filters['centre'] ?? null, 'Any'); ?>
      </div>
      <div>
        <label for="<?= $p ?>section">Section</label>
        <?php render_select($p . 'section', 'section', $sectionOptions, $filters['section'] ?? null, 'Any'); ?>
      </div>
      <div>
        <label for="<?= $p ?>priest">Priest</label>
        <?php render_select($p . 'priest', 'priest', $filterPriests, $filters['priest'] ?? null, 'Any'); ?>
      </div>
    </div>
<?php
};

$pageTitle = 'Source — Pastores Admin';
$pageWide = true; // the table has many columns
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Source</h1>

<div class="card" data-modal data-add-label="New source row"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit source row' : 'New source row' ?></h2>
  <form method="post">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <input type="hidden" name="qs" value="<?= e($filterQs) ?>">
    <div class="row">
      <div>
        <label for="zone_id">Zone</label>
        <select id="zone_id" name="zone_id" required>
          <option value="">Select a zone</option>
          <?php foreach ($zones as $zone): ?>
            <option value="<?= (int) $zone['id'] ?>" <?= (($editing['zone_id'] ?? $filterZone) == $zone['id']) ? 'selected' : '' ?>>
              <?= e($zone['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="week">Week</label>
        <?php render_select('week', 'week', ['1' => '1st', '2' => '2nd', '3' => '3rd', '4' => '4th', '5' => '5th'], isset($editing['week']) ? (string) $editing['week'] : null, 'Select a week'); ?>
        <small class="hint">Which occurrence of the day in the month.</small>
      </div>
      <div>
        <label for="day">Day</label>
        <?php render_select('day', 'day', $dayOptions, isset($editing['day']) ? (string) $editing['day'] : null, 'Select a day'); ?>
        <small class="hint">Number of the day in the week (per Settings).</small>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="centre">Centre</label>
        <?php render_select('centre', 'centre', $formCentres, $editing['centre'] ?? null, 'Select a centre'); ?>
        <small class="hint" id="section_hint"><?= e($sectionHint) ?></small>
      </div>
      <div>
        <label for="activity">Activity</label>
        <?php render_select('activity', 'activity', $activityOptions, $editing['activity'] ?? null, 'Select an activity'); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="labor">Labor</label>
        <?php render_select('labor', 'labor', $laborOptions, $editing['labor'] ?? null, 'Select a labor'); ?>
      </div>
      <div>
        <label for="priest">Priest</label>
        <?php render_select('priest', 'priest', $formPriests, $editing['priest'] ?? null, 'Select a priest'); ?>
      </div>
      <div>
        <label for="alt_priest">Alternate priest</label>
        <?php render_select('alt_priest', 'alt_priest', $formPriests, $editing['alt_priest'] ?? null, 'Select a priest'); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="from_time">From</label>
        <input type="time" id="from_time" name="from_time" value="<?= e($editing['from_time'] ?? '') ?>">
      </div>
      <div>
        <label for="to_time">To</label>
        <input type="time" id="to_time" name="to_time" value="<?= e($editing['to_time'] ?? '') ?>">
      </div>
      <div>
        <label for="duration">Duration</label>
        <input type="time" id="duration" name="duration" value="<?= e($editing['duration'] ?? '') ?>">
      </div>
    </div>
    <label for="description">Description</label>
    <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create source row' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/source/index.php<?= $filterQs !== '' ? '?' . e($filterQs) : '' ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="<?= $filters ? 'Filter (' . count($filters) . ')' : 'Filter' ?>" data-button-class="muted" data-align="right">
  <h2>Filter source</h2>
  <form method="get">
    <?php $renderFilterFields('f_'); ?>
    <div class="btn-row">
      <button type="submit">Apply filters</button>
      <a class="btn muted" href="<?= e($clearUrl) ?>">Clear filters</a>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="Import" data-button-class="muted">
  <h2>Import into source</h2>
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
      <strong><?= e(implode(', ', SOURCE_IO_COLUMNS)) ?></strong>.
    </small>
    <ul class="hint" style="margin:8px 0 0 18px;padding:0;">
      <li>Each row needs a <em>week</em> (1&ndash;5, which occurrence of the day in the month) and a <em>day</em> (1&ndash;7, the number in the week per Settings).</li>
      <li>The zone comes from the <em>zone</em> column, else the <em>unit</em> column, and must already exist in Zones. Empty = the zone currently filtered on.</li>
      <li>Centre (of that zone), priest (of that zone), activity and labor must already exist in the admin lists (spelling and case are matched loosely); rows with unknown values are reported and skipped. The optional <em>alt_priest</em> (alternate / substitute priest) is checked the same way. The section is the centre's. Other columns are ignored.</li>
      <li>Rows that already exist are skipped, so importing the same file twice is safe.</li>
    </ul>
    <div class="btn-row">
      <button type="submit">Import</button>
    </div>
  </form>
</div>
<div class="card" data-modal data-add-label="Export" data-button-class="muted" data-close-on-submit>
  <h2>Export source to CSV</h2>
  <form method="get">
    <input type="hidden" name="export" value="1">
    <p style="margin:0 0 4px;">Do you want to filter what gets exported? Leave everything empty to export
      <strong>all</strong> source rows.</p>
    <?php $renderFilterFields('x_'); ?>
    <div class="btn-row">
      <button type="submit">Export<?= $filters ? ' with these filters' : '' ?></button>
      <a class="btn muted" href="<?= e($exportAllUrl) ?>" data-close-modal>Export all</a>
    </div>
  </form>
</div>

<?php if ($importReport): ?>
<div class="card">
  <h2>Import finished</h2>
  <p>
    <strong><?= (int) $importReport['imported'] ?></strong> imported &middot;
    <strong><?= (int) $importReport['duplicates'] ?></strong> already existed (skipped) &middot;
    <strong><?= (int) $importReport['error_count'] ?></strong> with problems (not imported)
  </p>
  <?php if (!empty($importReport['cleared_durations'])): ?>
    <p class="hint" style="min-height:0;">
      <?= (int) $importReport['cleared_durations'] ?> imported row<?= $importReport['cleared_durations'] === 1 ? ' had' : 's had' ?> a negative or invalid duration (e.g. -0:15:00, #VALUE!); the duration was left empty.
    </p>
  <?php endif; ?>
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
    &middot; <?= e($filterLabels[$key]) ?>: <?= e($key === 'day' ? $dayOptions[$value] : ($key === 'zone' ? $zoneOptions[$value] : $value)) ?>
  <?php endforeach; ?>
  &middot; <a href="<?= e($clearUrl) ?>">Clear filters</a>
</p>
<?php endif; ?>
<p class="table-hint">Click a row to edit it in place &middot; click a column heading to sort &middot; showing <?= count($activities) ?> source row<?= count($activities) === 1 ? '' : 's' ?><?= count($activities) >= SOURCE_ROW_LIMIT ? ' (limit ' . SOURCE_ROW_LIMIT . ' &mdash; filter to see the rest)' : '' ?>.</p>
<script>if (window.pastoresInitToolbar) window.pastoresInitToolbar(); // buttons first, before the long table is parsed</script>
<div class="table-wrap">
<table data-bulk-total="<?= $totalMatching ?>" data-bulk-extra="<?= e(json_encode(['qs' => $filterQs])) ?>">
  <thead><tr>
    <th>Zone</th><th>Day</th><th>Wk</th><th>Centre</th><th>Section</th><th>Activity</th>
    <th>Labor</th><th>Priest</th><th>Alternate priest</th><th>From</th><th>To</th><th>Duration</th><th>Description</th><th></th>
  </tr></thead>
  <tbody id="source-body" data-qs="<?= e($filterQs) ?>">
  <?php foreach ($activities as $a): ?>
    <?= source_row_html($a, $filterQs, $weekdayNames) ?>

  <?php endforeach; ?>
  <?php if (!$activities): ?>
    <tr><td colspan="15" style="text-align:center;color:#888;"><?= $filters ? 'No source rows match these filters.' : 'No source rows yet.' ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<style>
  main table td { vertical-align: middle; }
  tbody tr[data-id] { cursor: pointer; }
  td[data-type=time], td[data-type=day], td[data-type=week], td[data-derived] { white-space: nowrap; }
  td.desc { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  /* No padding: the .cell-input below fills the cell edge-to-edge (its own
     padding/border give it breathing room) instead of leaving a gap around
     it, so an editing row's wider fields (selects, longer inputs) don't
     also need the cell — and so the table — any wider than they already do. */
  /* border-collapse resolves a same-width/same-style border conflict (this
     row's border-top vs. the row above's border-bottom, both 1px solid)
     unreliably — not necessarily in favour of the one declared here — so
     these are 2px: strictly wider always wins, unambiguously. */
  tr.editing td { background: #fffbe6; padding: 0; cursor: default; border-top: 2px solid #000; border-bottom: 2px solid #000; }
  tr.editing td.desc { max-width: none; overflow: visible; }
  tr.editing .cell-input { width: 100%; min-width: 96px; height: 40px; padding: 4px 6px; font-size: 12px; border: none; }
  tr.editing td[data-type=time] .cell-input { min-width: 84px; }
  tr.editing td[data-type=day] .cell-input, tr.editing td[data-type=week] .cell-input { min-width: 72px; }
  tr.editing td.actions { white-space: nowrap; padding: 0 8px; }
  tr.editing td.actions button { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; padding: 0; vertical-align: middle; }
  tr.editing td.actions button svg { width: 14px; height: 14px; }
  tr.editing .row-error { display: block; color: #c62828; font-size: 12px; margin-top: 4px; white-space: normal; }
  tr.saved td { background: #e8f5e9; }
  .table-hint { font-size: 12px; color: #666; margin: 0 0 8px; }
</style>
<script>
(function () {
  var centres = <?= json_encode($allCentres) ?>;           // {zone_id, name, section}
  var priests = <?= json_encode($allPriests) ?>;           // {zone_id, name}
  var labors = <?= json_encode($laborOptions) ?>;
  var activityTypes = <?= json_encode($activityOptions) ?>;
  var weekdayNames = <?= json_encode($weekdayNames) ?>;    // {1: 'Sunday', ...} per the week start setting

  function sectionOf(zone, name) {
    var c = centres.filter(function (c) { return c.zone_id == zone && c.name === name; })[0];
    return (c && c.section) || '';
  }

  // ---- Form (modal) -------------------------------------------------
  var zoneSel = document.getElementById('zone_id');
  var centreSel = document.getElementById('centre');
  var priestSel = document.getElementById('priest');
  var altPriestSel = document.getElementById('alt_priest');
  var sectionHint = document.getElementById('section_hint');

  function formZone() { return zoneSel ? parseInt(zoneSel.value, 10) : <?= (int) $formZoneId ?>; }

  function showSectionHint() {
    var name = centreSel.value;
    sectionHint.textContent = name ? 'Section: ' + (sectionOf(formZone(), name) || '—') : '';
  }
  // Refill a <select> with the current zone's entries, keeping the current
  // choice if it's still available.
  function refill(sel, rows, placeholder) {
    var keep = sel.value;
    sel.innerHTML = '';
    sel.add(new Option(placeholder, ''));
    rows.forEach(function (r) {
      if (r.zone_id == formZone()) sel.add(new Option(r.name, r.name, false, r.name === keep));
    });
  }

  centreSel.addEventListener('change', showSectionHint);
  if (zoneSel) {
    zoneSel.addEventListener('change', function () {
      if (centreSel) refill(centreSel, centres, 'Select a centre');
      refill(priestSel, priests, 'Select a priest');
      refill(altPriestSel, priests, 'Select a priest');
      showSectionHint();
    });
  }

  // ---- Spreadsheet-style row editing --------------------------------
  // Click a row to turn its cells into inputs. Enter or Save stores it,
  // Esc or Cancel puts it back. Day/week/section update as you type
  // because they are derived; they aren't editable themselves.
  var tbody = document.getElementById('source-body');
  var editing = null;   // { tr, orig }
  var dirty = false;
  var url = '/admin/source/index.php';

  function optionsFor(type, zone, current) {
    var list = type === 'centre' ? centres.filter(function (c) { return c.zone_id == zone; }).map(function (c) { return c.name; })
      : type === 'priest' ? priests.filter(function (p) { return p.zone_id == zone; }).map(function (p) { return p.name; })
      : type === 'activity' ? activityTypes.slice()
      : labors.slice();
    if (current && list.indexOf(current) < 0) list.push(current); // keep values that are no longer in the list
    return list;
  }

  function makeEditor(td, zone) {
    var type = td.getAttribute('data-type'), value = td.getAttribute('data-value') || '', el;
    if (type === 'day' || type === 'week') {
      el = document.createElement('select');
      el.add(new Option('', ''));
      if (type === 'day') {
        Object.keys(weekdayNames).forEach(function (n) { el.add(new Option(n + ' ' + weekdayNames[n].slice(0, 3), n)); });
      } else {
        [1, 2, 3, 4, 5].forEach(function (n) { el.add(new Option(String(n), String(n))); });
      }
    } else if (type === 'centre' || type === 'priest' || type === 'labor' || type === 'activity') {
      el = document.createElement('select');
      el.add(new Option('', ''));
      optionsFor(type, zone, value).forEach(function (o) { el.add(new Option(o, o)); });
    } else {
      el = document.createElement('input');
      el.type = type;
    }
    el.value = value;
    el.name = td.getAttribute('data-field');
    el.className = 'cell-input';
    td.textContent = '';
    td.appendChild(el);
  }

  function updateDerived(tr) {
    // The section follows the centre when it is one of the centres in the list.
    var centre = tr.querySelector('[name=centre]');
    var section = centre ? sectionOf(tr.getAttribute('data-zone'), centre.value) : '';
    if (section) tr.querySelector('[data-derived=section]').textContent = section;
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
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
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
  tbody.addEventListener('input', function (ev) { if (editing) { dirty = true; updateDerived(editing.tr); } });
  tbody.addEventListener('change', function (ev) { if (editing) { dirty = true; updateDerived(editing.tr); } });
  tbody.addEventListener('keydown', function (ev) {
    if (!editing) return;
    if (ev.key === 'Enter' && ev.target.matches('input, select')) { ev.preventDefault(); saveEdit(); }
    if (ev.key === 'Escape') { ev.preventDefault(); cancelEdit(); }
  });
})();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
