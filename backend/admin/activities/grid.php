<?php
// Admin > Activities > Grid: one date, centres down the side and every regular
// (non-multi-day) activity type across the top. A cell holds the activities of
// that centre + type on the date; clicking it opens a small editor to set the
// priest, times, group and note of each one (add several for e.g. two Masses),
// or clear the cell. Reads and writes the same `activities` rows as the List tab;
// the List tab's flags (no priest, absent, bilocation, mass limit, duplicate,
// priest in a multi-day activity) are shown as a coloured corner on the cell.
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/liturgical_day.php';
require __DIR__ . '/../../includes/absences.php';
require __DIR__ . '/../../includes/activity_duplicates.php';
require __DIR__ . '/../../includes/activity_bilocation.php';
require __DIR__ . '/../../includes/activity_mass_limit.php';
require __DIR__ . '/../../includes/activity_multiday_busy.php';
$admin = admin_require_role('super', 'zone', 'centre');

$pdo = pastores_db();
mass_limit($pdo);
$scopeCentreName = $admin['role'] === 'centre' ? admin_centre_name($pdo, $admin) : null;
$weekStart = get_week_start($pdo);

// ── Zone ──────────────────────────────────────────────────────────────────
if ($admin['role'] === 'super') {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
    $zoneId = (int) ($_GET['zone'] ?? $_POST['zone_id'] ?? 0);
    if (!in_array($zoneId, array_map(fn($z) => (int) $z['id'], $zones), true)) {
        $zoneId = (int) ($zones[0]['id'] ?? 0);
    }
} else {
    $zoneId = (int) $admin['zone_id'];
}

// Centres of the zone (a centre admin: only theirs) and the regular activity types.
$centreStmt = $pdo->prepare('SELECT name, section FROM centres WHERE zone_id = ?' . ($scopeCentreName !== null ? ' AND name = ?' : '') . ' ORDER BY name');
$centreStmt->execute($scopeCentreName !== null ? [$zoneId, $scopeCentreName] : [$zoneId]);
$centres = $centreStmt->fetchAll();
$types = $pdo->query('SELECT name FROM activity_types WHERE is_multiday = 0 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$priestOptions = array_values(array_unique(array_column(priests_by_zone($pdo, $zoneId), 'name')));
$laborOptions = lookup_names($pdo, 'labors');

$dateIn = (string) ($_GET['date'] ?? $_POST['date'] ?? '');
$date = date_parts($dateIn) ? $dateIn : date('Y-m-d');

// The grid's rows for the date, with the List tab's flag columns.
function agrid_load(PDO $pdo, int $zoneId, string $date, ?string $scopeCentreName): array
{
    $sql = 'SELECT a.*' . activity_absent_select($pdo) . activity_in_multiday_select($pdo) . activity_duplicate_select() . activity_bilocation_select() . activity_mass_count_select()
        . ' FROM activities a WHERE a.zone_id = ? AND a.activity_date = ?' . ($scopeCentreName !== null ? ' AND a.centre = ?' : '') . ' ORDER BY a.from_time, a.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scopeCentreName !== null ? [$zoneId, $date, $scopeCentreName] : [$zoneId, $date]);
    $by = [];
    foreach ($stmt->fetchAll() as $a) {
        $by[$a['centre'] . "\0" . $a['activity']][] = $a;
    }
    return $by;
}

// Duration is to − from, as everywhere else.
function agrid_duration(?string $from, ?string $to): ?string
{
    if ($from && $to && preg_match('/^(\d\d):(\d\d)/', $from, $f) && preg_match('/^(\d\d):(\d\d)/', $to, $t)) {
        $mins = ($t[1] * 60 + $t[2]) - ($f[1] * 60 + $f[2]);
        if ($mins > 0) {
            return sprintf('%02d:%02d:00', intdiv($mins, 60), $mins % 60);
        }
    }
    return null;
}

function agrid_time(?string $t): string
{
    return $t ? substr($t, 0, 5) : '';
}

// One cell's HTML (also the JSON the editor starts from, in data-entries).
function agrid_cell(array $acts): string
{
    $entries = [];
    $html = '';
    $flag = '';
    foreach ($acts as $i => $a) {
        $cls = '';
        $tip = [];
        if (!empty($a['absent_note'])) { $cls = 'f-absent'; $tip[] = 'Priest absent: ' . $a['absent_note']; }
        elseif (!empty($a['multiday_note'])) { $cls = 'f-multi'; $tip[] = 'Priest in a multi-day activity'; }
        elseif (!empty($a['bilocation_note'])) { $cls = 'f-bilo'; $tip[] = 'Bilocation: ' . $a['bilocation_note']; }
        elseif ((int) $a['mass_count'] > mass_limit()) { $cls = 'f-mass'; $tip[] = $a['priest'] . ' has ' . (int) $a['mass_count'] . ' masses (maximum ' . mass_limit() . ')'; }
        elseif ((int) $a['duplicate_count'] > 0) { $cls = 'f-dup'; $tip[] = 'Duplicate activity'; }
        elseif ($a['priest'] === null || $a['priest'] === '') { $cls = 'f-none'; $tip[] = 'No priest assigned'; }
        $time = agrid_time($a['from_time']) . ($a['to_time'] ? '–' . agrid_time($a['to_time']) : '');
        $html .= '<div class="g-entry ' . $cls . '" draggable="true" data-i="' . $i . '" title="' . e(implode(' · ', $tip)) . '">'
            . '<span class="g-priest">' . ($a['priest'] !== null && $a['priest'] !== '' ? e($a['priest']) : '<em>no priest</em>') . '</span>'
            . ($time !== '' ? '<span class="g-time">' . e($time) . '</span>' : '')
            . ($a['description'] ? '<span class="g-note">' . e($a['description']) . '</span>' : '')
            . '</div>';
        $entries[] = ['id' => (int) $a['id'], 'priest' => $a['priest'] ?? '', 'from_time' => agrid_time($a['from_time']), 'to_time' => agrid_time($a['to_time']),
            'labor' => $a['labor'] ?? '', 'description' => $a['description'] ?? ''];
    }
    return $html === '' ? '' : $html . '<script type="application/json" class="g-data">' . json_encode($entries, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}

// ── Save one cell ─────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'cell_save') {
    $reply = function (array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    };
    $centre = (string) ($_POST['centre'] ?? '');
    $activity = (string) ($_POST['activity'] ?? '');
    $centreRow = null;
    foreach ($centres as $c) {
        if ($c['name'] === $centre) $centreRow = $c;
    }
    if (!$zoneId || !$centreRow || !in_array($activity, $types, true) || !date_parts($date)) {
        $reply(['error' => 'That cell is not available to you.'], 403);
    }
    $entries = json_decode((string) ($_POST['entries'] ?? '[]'), true);
    if (!is_array($entries) || count($entries) > 20) {
        $reply(['error' => 'Bad request.'], 400);
    }
    $parts = date_parts($date, $weekStart);
    $existing = $pdo->prepare('SELECT id FROM activities WHERE zone_id = ? AND activity_date = ? AND centre = ? AND activity = ?');
    $existing->execute([$zoneId, $date, $centre, $activity]);
    $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
    $time = fn($v) => preg_match('/^\d\d:\d\d(:\d\d)?$/', (string) $v) ? substr((string) $v, 0, 5) . ':00' : null;

    $pdo->beginTransaction();
    try {
        $keep = [];
        foreach ($entries as $en) {
            $priest = trim((string) ($en['priest'] ?? '')) ?: null;
            if ($priest !== null && !in_array($priest, $priestOptions, true)) {
                throw new InvalidArgumentException("$priest is not one of this zone's priests.");
            }
            $labor = trim((string) ($en['labor'] ?? '')) ?: null;
            if ($labor !== null && !in_array($labor, $laborOptions, true)) {
                throw new InvalidArgumentException("Unknown group: $labor.");
            }
            $from = $time($en['from_time'] ?? '');
            $to = $time($en['to_time'] ?? '');
            $vals = [$priest, $from, $to, agrid_duration($from, $to), $labor, trim((string) ($en['description'] ?? '')) ?: null];
            $id = (int) ($en['id'] ?? 0);
            if ($id) {
                if (!in_array($id, $existingIds, true)) {
                    throw new InvalidArgumentException('An activity in this cell changed meanwhile; reload the page.');
                }
                $pdo->prepare('UPDATE activities SET priest = ?, from_time = ?, to_time = ?, duration = ?, labor = ?, description = ? WHERE id = ?')
                    ->execute([...$vals, $id]);
                $keep[] = $id;
            } else {
                $pdo->prepare('INSERT INTO activities (zone_id, week, day, weekday, activity_date, centre, activity, section, priest, from_time, to_time, duration, labor, description)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$zoneId, $parts['week'], $parts['day'], $parts['weekday'], $date, $centre, $activity, $centreRow['section'], ...$vals]);
            }
        }
        foreach (array_diff($existingIds, $keep) as $gone) {
            $pdo->prepare('DELETE FROM activities WHERE id = ?')->execute([$gone]);
        }
        $pdo->commit();
    } catch (InvalidArgumentException $ex) {
        $pdo->rollBack();
        $reply(['error' => $ex->getMessage()], 422);
    }
    touch_zone($pdo, $zoneId);
    $reply(['ok' => true]);
}

$celebration = liturgical_day_text($pdo, $date);
$byCell = agrid_load($pdo, $zoneId, $date, $scopeCentreName);
$total = array_sum(array_map('count', $byCell));
$prev = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
$next = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
$zq = $admin['role'] === 'super' && $zoneId ? '&zone=' . $zoneId : '';
$zq1 = $zq !== '' ? '?zone=' . $zoneId : '';

$pageTitle = 'Activities Grid — Pastores Admin';
$pageWide = true;
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Activities</h1>
<nav class="tabs">
  <a href="/admin/activities/index.php<?= $zq1 ?>">List</a>
  <a href="/admin/activities/calendar.php<?= $zq1 ?>">Calendar</a>
  <a class="active" href="/admin/activities/grid.php">Grid</a>
  <a href="/admin/activities/dashboard.php<?= $zq1 ?>">Dashboard</a>
</nav>

<style>
  .g-bar { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
  .g-bar a.btn { padding: 6px 12px; }
  .g-bar .g-title { font-size: 18px; font-weight: 600; }
  .g-entry[draggable=true] { cursor: grab; }
  .g-scroll { overflow: auto; max-height: 75vh; border: 1px solid var(--tint-border); border-radius: 6px; background: #fff; }
  table.g-table { border-collapse: separate; border-spacing: 0; width: max-content; min-width: 100%; }
  .g-table th, .g-table td { border-right: 1px solid var(--tint-border); border-bottom: 1px solid var(--tint-border); padding: 4px 6px; vertical-align: top; background: #fff; }
  .g-table thead th { position: sticky; top: 0; z-index: 2; min-width: 130px; max-width: 170px; font-size: 12px; text-align: center; white-space: normal; background: var(--tint-1); color: var(--brand-dark); }
  .g-table th.g-centre { position: sticky; top: auto; left: 0; z-index: 1; min-width: 160px; text-align: left; font-size: 13px; background: var(--tint-2); color: var(--brand-dark); }
  .g-table thead th.g-corner { top: 0; left: 0; z-index: 3; background: var(--tint-2); }
  .g-table tbody tr:hover td { background: var(--tint-stripe); }
  .g-table td.g-cell { cursor: pointer; height: 44px; min-width: 130px; }
  .g-table td.g-cell:hover { outline: 2px solid var(--brand); outline-offset: -2px; }
  .g-table td.g-cell:empty::after { content: '+'; color: #d0c6e6; font-size: 16px; }
  .g-entry { font-size: 12px; line-height: 1.3; padding: 2px 5px; margin-bottom: 3px; border-radius: 3px; border-left: 4px solid #4a9a55; background: #e6f4e8; }
  .g-entry .g-time { display: block; color: #666; font-size: 11px; }
  .g-entry .g-note { display: block; color: #666; font-size: 11px; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }
  .g-entry.f-none { background: #eee; border-color: #888; }
  .g-entry.f-absent { background: #fbd5d5; border-color: #c0392b; }
  .g-entry.f-multi { background: #cdeeee; border-color: #1b8f8f; }
  .g-entry.f-bilo { background: #ffe6b3; border-color: #d08a00; }
  .g-entry.f-mass { background: #e3d3f7; border-color: #7a3fc0; }
  .g-entry.f-dup { background: #d3e6fb; border-color: #3a7bc8; }
  .g-lit { font-size: 14px; color: #666; margin-top: 2px; }
  .g-daybox { margin: 0 4px; }
  .g-palette { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 8px; font-size: 12px; }
  .g-pal-title { color: #666; }
  .g-chip { background: #fff; border: 1px solid #bbb; border-radius: 12px; padding: 2px 10px; cursor: grab; user-select: none; }
  .g-chip:hover { border-color: var(--brand); }
  td.g-cell.g-over { outline: 2px dashed var(--brand); outline-offset: -2px; background: var(--tint-stripe); }
  .g-legend { display: flex; flex-wrap: wrap; gap: 10px; font-size: 12px; margin: 8px 0; color: #555; }
  .g-legend span { padding: 1px 8px; border-radius: 3px; border-left: 4px solid; }
  dialog.g-dlg { border: 0; border-radius: 8px; padding: 0; width: min(720px, 94vw); box-shadow: 0 10px 40px rgba(0,0,0,.3); }
  dialog.g-dlg::backdrop { background: rgba(0,0,0,.4); }
  .g-dlg form { padding: 16px 18px; }
  .g-dlg h2 { margin: 0 0 2px; font-size: 17px; }
  .g-dlg .sub { color: #666; font-size: 12px; margin-bottom: 10px; }
  .g-erow { display: grid; grid-template-columns: 1.6fr .8fr .8fr 1fr 28px; gap: 6px; align-items: end; margin-bottom: 4px; }
  .g-erow label { font-size: 11px; color: #666; margin: 0; }
  .g-erow input, .g-erow select { width: 100%; box-sizing: border-box; }
  .g-erow .g-desc { grid-column: 1 / 5; }
  .g-erow .g-del { grid-row: 1; grid-column: 5; align-self: end; border: 0; background: none; color: #b00; font-size: 20px; cursor: pointer; }
  .g-erow + .g-erow { border-top: 1px dashed #ddd; padding-top: 8px; margin-top: 4px; }
  .g-actions { display: flex; gap: 8px; margin-top: 12px; align-items: center; }
  .g-err { color: #b00; font-size: 12px; margin-right: auto; }
  @media (max-width: 640px) { .g-erow { grid-template-columns: 1fr 1fr 28px; } .g-erow .g-desc { grid-column: 1 / 3; } .g-erow .g-del { grid-column: 3; } }
</style>

<div class="card">
  <form method="get" class="g-bar">
    <?php if ($admin['role'] === 'super'): ?>
    <div>
      <label for="zone">Zone</label>
      <select id="zone" name="zone" onchange="this.form.submit()">
        <?php foreach ($zones as $zone): ?>
          <option value="<?= (int) $zone['id'] ?>" <?= $zoneId === (int) $zone['id'] ? 'selected' : '' ?>><?= e($zone['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <a class="btn" href="?date=<?= e($prev) . $zq ?>" title="Previous day">‹</a>
    <div>
      <input type="date" id="date" aria-label="Date" name="date" value="<?= e($date) ?>" style="width:160px;" onchange="this.form.submit()">
    </div>
    <a class="btn" href="?date=<?= e($next) . $zq ?>" title="Next day">›</a>
    <a class="btn" href="?date=<?= date('Y-m-d') . $zq ?>">Today</a>
    <div class="g-daybox">
      <div class="g-title"><?= e((new DateTimeImmutable($date))->format('l j F Y')) ?></div>
      <?php if ($celebration !== ''): ?><div class="g-lit"><?= e($celebration) ?></div><?php endif; ?>
    </div>
    <label style="margin-left:auto;font-weight:normal;"><input type="checkbox" id="g-hide-empty"> Hide empty rows &amp; columns</label>
  </form>
</div>


<?php if ($centres && $types): ?>
<div class="g-palette" id="g-palette"><span class="g-pal-title">Drag a priest onto a cell:</span>
  <?php foreach ($priestOptions as $pn): ?><span class="g-chip" draggable="true" data-priest="<?= e($pn) ?>"><?= e($pn) ?></span><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="g-legend">
  <span style="background:#e6f4e8;border-color:#4a9a55">Assigned</span>
  <span style="background:#eee;border-color:#888">No priest</span>
  <span style="background:#fbd5d5;border-color:#c0392b">Priest absent</span>
  <span style="background:#cdeeee;border-color:#1b8f8f">Priest in multi-day</span>
  <span style="background:#ffe6b3;border-color:#d08a00">Bilocation</span>
  <span style="background:#e3d3f7;border-color:#7a3fc0">Over mass limit</span>
  <span style="background:#d3e6fb;border-color:#3a7bc8">Duplicate</span>
  <span style="margin-left:auto;border:0;"><?= $total ?> activit<?= $total === 1 ? 'y' : 'ies' ?> on this day · click a cell to edit</span>
</div>

<?php if (!$centres || !$types): ?>
  <div class="card">This zone has no centres, or there are no regular activity types yet.</div>
<?php else: ?>
<div id="g-wrap" class="g-scroll">
  <table class="g-table" id="g-table" data-no-sort data-no-wrap>
    <thead>
      <tr>
        <th class="g-centre g-corner">Centre</th>
        <?php foreach ($types as $t): ?><th data-type="<?= e($t) ?>"><?= e($t) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($centres as $c): ?>
      <tr data-centre="<?= e($c['name']) ?>">
        <th class="g-centre"><?= e($c['name']) ?></th>
        <?php foreach ($types as $t): ?>
          <td class="g-cell"><?= agrid_cell($byCell[$c['name'] . "\0" . $t] ?? []) ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<dialog class="g-dlg" id="g-dlg">
  <form id="g-form">
    <h2 id="g-h"></h2>
    <div class="sub" id="g-sub"></div>
    <div id="g-rows"></div>
    <button type="button" class="btn" id="g-add" style="margin-top:6px;">+ Add another</button>
    <div class="g-actions">
      <span class="g-err" id="g-err"></span>
      <button type="button" class="btn" id="g-cancel">Cancel</button>
      <button type="submit" class="btn primary">Save</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  var priests = <?= json_encode($priestOptions) ?>, labors = <?= json_encode($laborOptions) ?>;
  var zoneId = <?= (int) $zoneId ?>, date = <?= json_encode($date) ?>;
  var dlg = document.getElementById('g-dlg'), form = document.getElementById('g-form'), rows = document.getElementById('g-rows'), err = document.getElementById('g-err');
  var cur = null;

  function opts(list, val, blank) {
    var h = '<option value="">' + blank + '</option>';
    if (val && list.indexOf(val) < 0) list = list.concat([val]);
    list.forEach(function (o) { h += '<option' + (o === val ? ' selected' : '') + '>' + o.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</option>'; });
    return h;
  }
  function addRow(en) {
    en = en || { id: 0, priest: '', from_time: '', to_time: '', labor: '', description: '' };
    var d = document.createElement('div');
    d.className = 'g-erow';
    d.dataset.id = en.id || 0;
    d.innerHTML = '<div><label>Priest</label><select class="r-priest">' + opts(priests, en.priest, '— none —') + '</select></div>'
      + '<div><label>From</label><input type="time" class="r-from"></div>'
      + '<div><label>To</label><input type="time" class="r-to"></div>'
      + '<div><label>Group</label><select class="r-labor">' + opts(labors, en.labor, '—') + '</select></div>'
      + '<button type="button" class="g-del" title="Remove">×</button>'
      + '<div class="g-desc"><label>Note</label><input type="text" class="r-desc" maxlength="500"></div>';
    d.querySelector('.r-from').value = en.from_time || '';
    d.querySelector('.r-to').value = en.to_time || '';
    d.querySelector('.r-desc').value = en.description || '';
    d.querySelector('.g-del').addEventListener('click', function () { d.remove(); });
    rows.appendChild(d);
  }
  document.getElementById('g-table').addEventListener('click', function (ev) {
    var td = ev.target.closest('td.g-cell');
    if (!td) return;
    cur = cellInfo(td);
    document.getElementById('g-h').textContent = cur.type + ' — ' + cur.centre;
    document.getElementById('g-sub').textContent = new Date(date + 'T00:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + '. Remove every line and save to clear the cell.';
    rows.innerHTML = '';
    err.textContent = '';
    var data = td.querySelector('.g-data');
    var list = data ? JSON.parse(data.textContent) : [];
    (list.length ? list : [null]).forEach(addRow);
    dlg.showModal();
  });
  document.getElementById('g-add').addEventListener('click', function () { addRow(); });
  document.getElementById('g-cancel').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
  // Drag a priest chip onto a cell: fills the first entry without a priest, else adds a new entry.
  var dragged = null;
  document.getElementById('g-palette').addEventListener('dragstart', function (ev) {
    var c = ev.target.closest('.g-chip');
    if (!c) return;
    dragged = { priest: c.dataset.priest };
    ev.dataTransfer.setData('text/plain', c.dataset.priest);
    ev.dataTransfer.effectAllowed = 'copy';
  });
  var tbl = document.getElementById('g-table');
  // Drag an entry onto another cell to move it there (hold Alt/Option to copy it).
  tbl.addEventListener('dragstart', function (ev) {
    var en = ev.target.closest('.g-entry');
    if (!en) return;
    var td = en.closest('td.g-cell');
    dragged = { from: td, idx: +en.dataset.i };
    ev.dataTransfer.setData('text/plain', en.textContent);
    ev.dataTransfer.effectAllowed = 'copyMove';
  });
  function cellList(td) { var d = td.querySelector('.g-data'); return d ? JSON.parse(d.textContent) : []; }
  tbl.addEventListener('dragover', function (ev) {
    var td = ev.target.closest('td.g-cell');
    if (!td || dragged === null) return;
    ev.preventDefault();
    ev.dataTransfer.dropEffect = dragged.from && !ev.altKey ? 'move' : 'copy';
    Array.prototype.forEach.call(tbl.querySelectorAll('.g-over'), function (x) { if (x !== td) x.classList.remove('g-over'); });
    td.classList.add('g-over');
  });
  tbl.addEventListener('dragleave', function (ev) {
    var td = ev.target.closest('td.g-cell');
    if (td && !td.contains(ev.relatedTarget)) td.classList.remove('g-over');
  });
  tbl.addEventListener('drop', function (ev) {
    var td = ev.target.closest('td.g-cell');
    if (!td || dragged === null) return;
    ev.preventDefault();
    td.classList.remove('g-over');
    var d = dragged;
    dragged = null;
    var info = cellInfo(td), list = cellList(td);
    if (d.from) {
      if (d.from === td) return;
      var src = cellList(d.from), moved = src[d.idx];
      if (!moved) return;
      list.push({ id: 0, priest: moved.priest, from_time: moved.from_time, to_time: moved.to_time, labor: moved.labor, description: moved.description });
      var done = send(info, list, !ev.altKey);
      if (!ev.altKey) {
        // Target first, so a failure never loses the entry; then drop it from the source.
        done = done.then(function () { src.splice(d.idx, 1); return send(cellInfo(d.from), src); });
      }
      done.catch(function (e) { alert(e.message); });
      return;
    }
    var blank = list.filter(function (e) { return !e.priest; })[0];
    if (blank) blank.priest = d.priest;
    else list.push({ id: 0, priest: d.priest, from_time: '', to_time: '', labor: '', description: '' });
    send(info, list).catch(function (e) { alert(e.message); });
  });
  document.addEventListener('dragend', function () { dragged = null; Array.prototype.forEach.call(document.querySelectorAll('.g-over'), function (x) { x.classList.remove('g-over'); }); });

  function send(info, entries, skipRefresh) {
    var body = new URLSearchParams({ action: 'cell_save', zone_id: zoneId, date: date, centre: info.centre, activity: info.type, entries: JSON.stringify(entries) });
    return fetch(location.pathname, { method: 'POST', body: body, headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Save failed'); return j; }); })
      .then(function (j) { return skipRefresh ? j : refresh(); });
  }
  function cellInfo(td) {
    return { centre: td.parentNode.dataset.centre, type: document.getElementById('g-table').tHead.rows[0].cells[td.cellIndex].dataset.type };
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var entries = Array.prototype.map.call(rows.children, function (d) {
      return { id: +d.dataset.id, priest: d.querySelector('.r-priest').value, from_time: d.querySelector('.r-from').value,
        to_time: d.querySelector('.r-to').value, labor: d.querySelector('.r-labor').value, description: d.querySelector('.r-desc').value };
    });
    send(cur, entries)
      .then(function () { dlg.close(); })
      .catch(function (e) { err.textContent = e.message; });
  });

  // Reload only the table after a save (other cells' flags may have changed).
  function refresh() {
    return fetch(location.href).then(function (r) { return r.text(); }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var fresh = doc.getElementById('g-table');
      if (fresh) { document.getElementById('g-table').innerHTML = fresh.innerHTML; applyHide(); }
      var lg = doc.querySelector('.g-legend span:last-child'), mine = document.querySelector('.g-legend span:last-child');
      if (lg && mine) mine.textContent = lg.textContent;
    });
  }

  var hide = document.getElementById('g-hide-empty');
  function applyHide() {
    var t = document.getElementById('g-table'), on = hide.checked;
    try { localStorage.setItem('gridHideEmpty', on ? '1' : ''); } catch (e) {}
    var ncols = t.tHead.rows[0].cells.length, used = [];
    Array.prototype.forEach.call(t.tBodies[0].rows, function (tr) {
      var any = false;
      for (var i = 1; i < ncols; i++) if (tr.cells[i].querySelector('.g-entry')) { any = true; used[i] = true; }
      tr.style.display = on && !any ? 'none' : '';
    });
    for (var i = 1; i < ncols; i++) {
      var show = !on || used[i];
      t.tHead.rows[0].cells[i].style.display = show ? '' : 'none';
      Array.prototype.forEach.call(t.tBodies[0].rows, function (tr) { tr.cells[i].style.display = show ? '' : 'none'; });
    }
  }
  try { hide.checked = localStorage.getItem('gridHideEmpty') === '1'; } catch (e) {}
  hide.addEventListener('change', applyHide);
  applyHide();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
