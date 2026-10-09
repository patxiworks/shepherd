<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/bulk.php';
$admin = admin_require_role('super', 'zone');
$isZoneScoped = $admin['role'] === 'zone';

$pdo = pastores_db();

// A zone-scoped admin may only touch absences of their own zone.
function absence_in_scope(PDO $pdo, int $absenceId, array $admin): bool
{
    if ($admin['role'] !== 'zone') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT zone_id FROM absences WHERE id = ?');
    $stmt->execute([$absenceId]);
    return (int) $stmt->fetchColumn() === (int) $admin['zone_id'];
}

// 'yyyy-MM-ddTHH:mm' (datetime-local) or 'yyyy-MM-dd HH:mm[:ss]' => 'Y-m-d H:i:s', or null.
function absence_datetime(?string $value): ?string
{
    $d = DateTime::createFromFormat('Y-m-d\TH:i', (string) $value)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s', (string) $value)
        ?: DateTime::createFromFormat('Y-m-d H:i:s', (string) $value);
    return $d ? $d->format('Y-m-d H:i:s') : null;
}

$deleteOne = function (int $id) use ($pdo, $admin): ?string {
    if (!absence_in_scope($pdo, $id, $admin)) {
        return 'You do not have access to that entry.';
    }
    $pdo->prepare('DELETE FROM absences WHERE id = ?')->execute([$id]);
    return null;
};
$bulkWhere = $isZoneScoped ? ['a.zone_id = ?', [(int) $admin['zone_id']]] : ['1 = 1', []];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_delete') {
        if (!empty($_POST['all_matching'])) {
            bulk_delete_matching($pdo, 'absences', $bulkWhere[0], $bulkWhere[1], 'absence', 'absences');
        } else {
            bulk_run($deleteOne, 'absence', 'absences');
        }
    } elseif ($action === 'delete') {
        $err = $deleteOne((int) $_POST['id']);
        flash($err === null ? 'success' : 'error', $err ?? 'Absence deleted.');
    } else {
        $id = $_POST['id'] ?? '';
        $zoneId = $isZoneScoped ? (int) $admin['zone_id'] : (int) ($_POST['zone_id'] ?? 0);
        $priest = trim($_POST['priest'] ?? '');
        $startAt = absence_datetime($_POST['start_at'] ?? '');
        $endAt = absence_datetime($_POST['end_at'] ?? '');
        $activity = trim($_POST['activity'] ?? '') ?: null;
        $description = trim($_POST['description'] ?? '') ?: null;

        $existing = null;
        if ($id !== '') {
            $stmt = $pdo->prepare('SELECT * FROM absences WHERE id = ?');
            $stmt->execute([(int) $id]);
            $existing = $stmt->fetch() ?: null;
        }
        $zoneNames = $pdo->prepare('SELECT COUNT(*) FROM zones WHERE id = ?');
        $zoneNames->execute([$zoneId]);
        $zoneExists = (bool) $zoneNames->fetchColumn();
        $zonePriests = array_column(priests_by_zone($pdo, $zoneId), 'name');

        if (!$zoneExists) {
            flash('error', 'Zone is required.');
        } elseif ($priest === '') {
            flash('error', 'Priest is required.');
        } elseif ($startAt === null || $endAt === null) {
            flash('error', 'Start and end date and time are required.');
        } elseif ($endAt < $startAt) {
            flash('error', 'The end must not be before the start.');
        } elseif ($id !== '' && ($existing === null || !absence_in_scope($pdo, (int) $id, $admin))) {
            flash('error', 'You do not have access to that entry.');
        } elseif (!in_array($priest, $zonePriests, true) && !($existing && $existing['priest'] === $priest && (int) $existing['zone_id'] === $zoneId)) {
            flash('error', "\"$priest\" is not a priest of that zone. Add them under Priests first.");
        } else {
            if ($id !== '') {
                $pdo->prepare('UPDATE absences SET zone_id=?, priest=?, start_at=?, end_at=?, activity=?, description=? WHERE id=?')
                    ->execute([$zoneId, $priest, $startAt, $endAt, $activity, $description, (int) $id]);
                flash('success', 'Absence updated.');
            } else {
                $pdo->prepare('INSERT INTO absences (zone_id, priest, start_at, end_at, activity, description) VALUES (?,?,?,?,?,?)')
                    ->execute([$zoneId, $priest, $startAt, $endAt, $activity, $description]);
                flash('success', 'Absence created.');
            }
        }
    }
    header('Location: /admin/absences/index.php');
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId && absence_in_scope($pdo, $editId, $admin)) {
    $stmt = $pdo->prepare('SELECT * FROM absences WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

if ($isZoneScoped) {
    $zones = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
    $zones->execute([$admin['zone_id']]);
    $zones = $zones->fetchAll();
    $absences = $pdo->prepare(
        'SELECT a.*, z.name AS zone_name FROM absences a JOIN zones z ON z.id = a.zone_id
         WHERE a.zone_id = ? ORDER BY a.start_at DESC LIMIT 300'
    );
    $absences->execute([$admin['zone_id']]);
    $absences = $absences->fetchAll();
} else {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
    $absences = $pdo->query(
        'SELECT a.*, z.name AS zone_name FROM absences a JOIN zones z ON z.id = a.zone_id ORDER BY a.start_at DESC LIMIT 300'
    )->fetchAll();
}

// Priests are filtered client-side by the selected zone (super admins can
// switch zones in the form); the server renders the initial zone's list.
$allPriests = priests_by_zone($pdo, $isZoneScoped ? (int) $admin['zone_id'] : null);
$formZoneId = (int) ($editing['zone_id'] ?? ($zones[0]['id'] ?? 0));
$formPriests = array_column(array_filter($allPriests, fn($r) => (int) $r['zone_id'] === $formZoneId), 'name');
$activityOptions = lookup_names($pdo, 'activity_types');

$toInput = fn(?string $dt) => $dt ? str_replace(' ', 'T', substr($dt, 0, 16)) : '';
$show = fn(string $dt) => date('d/m/Y H:i', strtotime($dt));

$bulkCount = $pdo->prepare("SELECT COUNT(*) FROM absences a WHERE {$bulkWhere[0]}");
$bulkCount->execute($bulkWhere[1]);
$bulkTotal = (int) $bulkCount->fetchColumn();

$pageTitle = 'Absences — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Absences</h1>

<div class="card" data-modal data-add-label="New absence"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit absence' : 'New absence' ?></h2>
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
        <label for="priest">Priest</label>
        <?php render_select('priest', 'priest', $formPriests, $editing['priest'] ?? null, 'Select a priest', ['required' => 'required']); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="start_at">Start date and time</label>
        <input type="datetime-local" id="start_at" name="start_at" value="<?= e($toInput($editing['start_at'] ?? null)) ?>" required>
      </div>
      <div>
        <label for="end_at">End date and time</label>
        <input type="datetime-local" id="end_at" name="end_at" value="<?= e($toInput($editing['end_at'] ?? null)) ?>" required>
      </div>
      <div>
        <label for="activity">Activity</label>
        <input type="text" id="activity" name="activity" list="activity_list" value="<?= e($editing['activity'] ?? '') ?>" autocomplete="off">
        <?php render_datalist('activity_list', $activityOptions); ?>
      </div>
    </div>
    <label for="description">Description</label>
    <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create absence' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/absences/index.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<script>if (window.pastoresInitToolbar) window.pastoresInitToolbar(); // buttons first, before the long table is parsed</script>
<table data-bulk-total="<?= $bulkTotal ?>">
  <thead><tr><th>Zone</th><th>Priest</th><th>Start</th><th>End</th><th>Activity</th><th>Description</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($absences as $a): ?>
    <tr>
      <td><?= e($a['zone_name']) ?></td>
      <td><?= e($a['priest']) ?></td>
      <td data-sort="<?= e($a['start_at']) ?>"><?= e($show($a['start_at'])) ?></td>
      <td data-sort="<?= e($a['end_at']) ?>"><?= e($show($a['end_at'])) ?></td>
      <td><?= e($a['activity']) ?></td>
      <td><?= e($a['description']) ?></td>
      <td class="actions">
        <?= icon_edit('/admin/absences/index.php?edit=' . (int) $a['id']) ?>
        <form class="inline" method="post" onsubmit="return confirm('Delete this absence?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <?= icon_delete() ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$absences): ?>
    <tr><td colspan="7" style="color:#888;">No absences recorded.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<script>
(function () {
  var priests = <?= json_encode($allPriests) ?>; // {zone_id, name}
  var zoneSel = document.getElementById('zone_id');
  var priestSel = document.getElementById('priest');
  var start = document.getElementById('start_at');
  var end = document.getElementById('end_at');

  // Zone change (super admin): list that zone's priests, keeping the choice if still valid.
  if (zoneSel) {
    zoneSel.addEventListener('change', function () {
      var keep = priestSel.value;
      priestSel.innerHTML = '';
      priestSel.add(new Option('Select a priest', ''));
      priests.forEach(function (p) {
        if (p.zone_id == zoneSel.value) priestSel.add(new Option(p.name, p.name, false, p.name === keep));
      });
    });
  }
  // Suggest the end from the start when the end is empty or now earlier.
  start.addEventListener('change', function () {
    if (!end.value || end.value < start.value) end.value = start.value;
  });
})();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
