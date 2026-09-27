<?php
// Admin > Multi-day Activities: CRUD for the multiday_activities table (see
// migrate/015_multiday_activities.sql) — retreats, courses and camps that
// run across several days at a centre/venue, ported from the standalone
// "Painted Calendar" venue-booking tool. See calendar.php for the painted
// venue/date-range view of the same data.
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/flash.php';
$admin = admin_require_role('super', 'zone');
$isZoneScoped = $admin['role'] === 'zone';

$pdo = pastores_db();

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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
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
        $centre = trim($_POST['centre'] ?? '') ?: null;
        $activity = trim($_POST['activity'] ?? '') ?: null;
        $section = trim($_POST['section'] ?? '') ?: null;
        $labor = trim($_POST['labor'] ?? '') ?: null;
        $startDate = trim($_POST['start_date'] ?? '') ?: null;
        $startTime = mday_time($_POST['start_time'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '') ?: null;
        $endTime = mday_time($_POST['end_time'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;

        $existing = null;
        if ($id !== '') {
            $stmt = $pdo->prepare('SELECT * FROM multiday_activities WHERE id = ?');
            $stmt->execute([(int) $id]);
            $existing = $stmt->fetch() ?: null;
        }
        $zoneCheck = $pdo->prepare('SELECT COUNT(*) FROM zones WHERE id = ?');
        $zoneCheck->execute([$zoneId]);
        $zoneExists = (bool) $zoneCheck->fetchColumn();
        $zoneCentres = array_column(
            array_filter(mday_centres($pdo, $isZoneScoped ? (int) $admin['zone_id'] : null), fn($c) => (int) $c['zone_id'] === $zoneId),
            'name'
        );

        if (!$zoneExists) {
            flash('error', 'Zone is required.');
        } elseif ($centre === null) {
            flash('error', 'Centre is required.');
        } elseif ($startDate === null || $endDate === null) {
            flash('error', 'Start and end date are required.');
        } elseif ($endDate < $startDate || ($endDate === $startDate && $startTime !== null && $endTime !== null && $endTime < $startTime)) {
            flash('error', 'The end must not be before the start.');
        } elseif ($id !== '' && ($existing === null || !mday_in_scope($pdo, (int) $id, $admin))) {
            flash('error', 'You do not have access to that entry.');
        } elseif (!in_array($centre, $zoneCentres, true) && !($existing && $existing['centre'] === $centre && (int) $existing['zone_id'] === $zoneId)) {
            flash('error', "\"$centre\" is not a centre of that zone. Add it under Centres first.");
        } else {
            if ($id !== '') {
                $pdo->prepare('UPDATE multiday_activities SET zone_id=?, centre=?, activity=?, section=?, labor=?, start_date=?, start_time=?, end_date=?, end_time=?, description=? WHERE id=?')
                    ->execute([$zoneId, $centre, $activity, $section, $labor, $startDate, $startTime, $endDate, $endTime, $description, (int) $id]);
                flash('success', 'Entry updated.');
            } else {
                $pdo->prepare('INSERT INTO multiday_activities (zone_id, centre, activity, section, labor, start_date, start_time, end_date, end_time, description) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$zoneId, $centre, $activity, $section, $labor, $startDate, $startTime, $endDate, $endTime, $description]);
                flash('success', 'Entry created.');
            }
        }
    }
    header('Location: /admin/multiday_activities/index.php');
    exit;
}

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
    $entries = $pdo->prepare(
        'SELECT m.*, z.name AS zone_name FROM multiday_activities m JOIN zones z ON z.id = m.zone_id
         WHERE m.zone_id = ? ORDER BY m.start_date DESC LIMIT 300'
    );
    $entries->execute([$admin['zone_id']]);
    $entries = $entries->fetchAll();
} else {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
    $entries = $pdo->query(
        'SELECT m.*, z.name AS zone_name FROM multiday_activities m JOIN zones z ON z.id = m.zone_id ORDER BY m.start_date DESC LIMIT 300'
    )->fetchAll();
}

// Centres, keyed for the zone => name dropdown sync in JS below.
function mday_centres(PDO $pdo, ?int $zoneId = null): array
{
    $stmt = $pdo->prepare('SELECT zone_id, name FROM centres' . ($zoneId !== null ? ' WHERE zone_id = ?' : '') . ' ORDER BY name');
    $stmt->execute($zoneId !== null ? [$zoneId] : []);
    return $stmt->fetchAll();
}

$allCentres = mday_centres($pdo, $isZoneScoped ? (int) $admin['zone_id'] : null);
$formZoneId = (int) ($editing['zone_id'] ?? ($zones[0]['id'] ?? 0));
$formCentres = array_column(array_filter($allCentres, fn($r) => (int) $r['zone_id'] === $formZoneId), 'name');
$activityOptions = $pdo->query("SELECT name FROM activity_types WHERE is_multiday = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$sectionOptions = lookup_names($pdo, 'sections');
$laborOptions = lookup_names($pdo, 'labors');

$toTimeInput = fn(?string $t) => $t ? substr($t, 0, 5) : '';
$showDate = fn(string $d) => date('d/m/Y', strtotime($d));
$showTime = fn(?string $t) => $t ? date('H:i', strtotime($t)) : '—';

$pageTitle = 'Multi-day Activities — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Multi-day Activities</h1>
<nav class="tabs">
  <a class="active" href="/admin/multiday_activities/index.php">List</a>
  <a href="/admin/multiday_activities/calendar.php">Calendar</a>
</nav>

<div class="card" data-modal data-add-label="New entry"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit entry' : 'New entry' ?></h2>
  <form method="post">
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
    <label for="description">Description</label>
    <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create entry' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/multiday_activities/index.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<table>
  <thead><tr><th>Zone</th><th>Centre</th><th>Activity</th><th>Section</th><th>Group</th><th>Start</th><th>End</th><th>Description</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($entries as $m): ?>
    <tr>
      <td><?= e($m['zone_name']) ?></td>
      <td><?= e($m['centre']) ?></td>
      <td><?= e($m['activity']) ?></td>
      <td><?= e($m['section']) ?></td>
      <td><?= e($m['labor']) ?></td>
      <td data-sort="<?= e($m['start_date']) ?>"><?= e($showDate($m['start_date'])) ?><?= $m['start_time'] ? ' ' . e($showTime($m['start_time'])) : '' ?></td>
      <td data-sort="<?= e($m['end_date']) ?>"><?= e($showDate($m['end_date'])) ?><?= $m['end_time'] ? ' ' . e($showTime($m['end_time'])) : '' ?></td>
      <td><?= e($m['description']) ?></td>
      <td class="actions">
        <a href="/admin/multiday_activities/index.php?edit=<?= (int) $m['id'] ?>">Edit</a>
        <form class="inline" method="post" onsubmit="return confirm('Delete this entry?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <a href="#" onclick="this.closest('form').requestSubmit(); return false;" style="color:#E91E63;">Delete</a>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$entries): ?>
    <tr><td colspan="9" style="color:#888;">No multi-day activities recorded.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<script>
(function () {
  var centres = <?= json_encode($allCentres) ?>; // {zone_id, name}
  var zoneSel = document.getElementById('zone_id');
  var centreSel = document.getElementById('centre');
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
    });
  }
  // Suggest the end date from the start when the end is empty or now earlier.
  startDate.addEventListener('change', function () {
    if (!endDate.value || endDate.value < startDate.value) endDate.value = startDate.value;
  });
})();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
