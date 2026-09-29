<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/bulk.php';
$admin = admin_require_role('super', 'zone');
$isZoneScoped = $admin['role'] === 'zone';

$pdo = pastores_db();

// A zone-scoped admin may only touch entries in their own zone — never
// global (zone_id IS NULL) entries, which apply to every zone.
function mass_in_scope(PDO $pdo, int $massId, array $admin): bool
{
    if ($admin['role'] !== 'zone') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT zone_id FROM masses WHERE id = ?');
    $stmt->execute([$massId]);
    return (int) $stmt->fetchColumn() === (int) $admin['zone_id'];
}

$deleteOne = function (int $id) use ($pdo, $admin): ?string {
    if (!mass_in_scope($pdo, $id, $admin)) {
        return 'You do not have access to that entry.';
    }
    $stmt = $pdo->prepare('SELECT zone_id FROM masses WHERE id = ?');
    $stmt->execute([$id]);
    $zoneId = $stmt->fetchColumn();
    $pdo->prepare('DELETE FROM masses WHERE id = ?')->execute([$id]);
    if ($zoneId) {
        touch_zone($pdo, (int) $zoneId);
    }
    return null;
};
// What "select all" covers: everything for a super admin, only their own zone's entries (never the global ones) for a zone admin.
$bulkWhere = $isZoneScoped ? ['a.zone_id = ?', [(int) $admin['zone_id']]] : ['1 = 1', []];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_delete') {
        if (!empty($_POST['all_matching'])) {
            bulk_delete_matching($pdo, 'masses', $bulkWhere[0], $bulkWhere[1], 'mass entry', 'mass entries', function () use ($pdo, $isZoneScoped, $admin) {
                if ($isZoneScoped) {
                    touch_zone($pdo, (int) $admin['zone_id']);
                } else {
                    foreach ($pdo->query('SELECT id FROM zones')->fetchAll(PDO::FETCH_COLUMN) as $z) {
                        touch_zone($pdo, (int) $z);
                    }
                }
            });
        } else {
            bulk_run($deleteOne, 'mass entry', 'mass entries');
        }
    } elseif ($action === 'delete') {
        $err = $deleteOne((int) $_POST['id']);
        flash($err === null ? 'success' : 'error', $err ?? 'Mass entry deleted.');
    } else {
        $zoneId = $isZoneScoped ? (int) $admin['zone_id'] : ($_POST['zone_id'] !== '' ? (int) $_POST['zone_id'] : null);
        $massDate = $_POST['mass_date'] ?? '';
        $class = trim($_POST['class'] ?? '') ?: null;
        $mass = trim($_POST['mass'] ?? '') ?: null;
        $id = $_POST['id'] ?? '';

        if ($massDate === '') {
            flash('error', 'Date is required.');
        } elseif ($id !== '' && !mass_in_scope($pdo, (int) $id, $admin)) {
            flash('error', 'You do not have access to that entry.');
        } else {
            if ($id !== '') {
                $pdo->prepare('UPDATE masses SET zone_id=?, mass_date=?, class=?, mass=? WHERE id=?')
                    ->execute([$zoneId, $massDate, $class, $mass, (int) $id]);
                flash('success', 'Mass entry updated.');
            } else {
                $pdo->prepare('INSERT INTO masses (zone_id, mass_date, class, mass) VALUES (?,?,?,?)')
                    ->execute([$zoneId, $massDate, $class, $mass]);
                flash('success', 'Mass entry created.');
            }
            if ($zoneId) {
                touch_zone($pdo, $zoneId);
            }
        }
    }
    header('Location: /admin/masses/index.php');
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId && mass_in_scope($pdo, $editId, $admin)) {
    $stmt = $pdo->prepare('SELECT * FROM masses WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

if ($isZoneScoped) {
    $zones = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
    $zones->execute([$admin['zone_id']]);
    $zones = $zones->fetchAll();
    $masses = $pdo->prepare(
        'SELECT m.*, z.name AS zone_name FROM masses m LEFT JOIN zones z ON z.id = m.zone_id
         WHERE m.zone_id = ? OR m.zone_id IS NULL ORDER BY m.mass_date DESC LIMIT 300'
    );
    $masses->execute([$admin['zone_id']]);
    $masses = $masses->fetchAll();
} else {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
    $masses = $pdo->query(
        'SELECT m.*, z.name AS zone_name FROM masses m LEFT JOIN zones z ON z.id = m.zone_id ORDER BY m.mass_date DESC LIMIT 300'
    )->fetchAll();
}

$bulkCount = $pdo->prepare("SELECT COUNT(*) FROM masses a WHERE {$bulkWhere[0]}");
$bulkCount->execute($bulkWhere[1]);
$bulkTotal = (int) $bulkCount->fetchColumn();

$pageTitle = 'Masses — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Masses</h1>

<div class="card" data-modal data-add-label="New mass entry"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit mass entry' : 'New mass entry' ?></h2>
  <form method="post">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="row">
      <div>
        <label for="mass_date">Date</label>
        <input type="date" id="mass_date" name="mass_date" value="<?= e($editing['mass_date'] ?? '') ?>" required>
      </div>
      <div>
        <?php if ($isZoneScoped): ?>
          <label for="zone_id">Zone</label>
          <input type="text" value="<?= e($zones[0]['name'] ?? '') ?>" disabled>
        <?php else: ?>
          <label for="zone_id">Zone (optional — leave blank for global)</label>
          <select id="zone_id" name="zone_id">
            <option value="">Global (all zones)</option>
            <?php foreach ($zones as $zone): ?>
              <option value="<?= (int) $zone['id'] ?>" <?= (($editing['zone_id'] ?? null) == $zone['id']) ? 'selected' : '' ?>>
                <?= e($zone['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      <div>
        <label for="class">Class</label>
        <input type="text" id="class" name="class" value="<?= e($editing['class'] ?? '') ?>">
      </div>
      <div>
        <label for="mass">Mass</label>
        <input type="text" id="mass" name="mass" value="<?= e($editing['mass'] ?? '') ?>">
      </div>
    </div>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create entry' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/masses/index.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<table data-bulk-total="<?= $bulkTotal ?>">
  <thead><tr><th>Date</th><th>Zone</th><th>Class</th><th>Mass</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($masses as $m): ?>
    <tr>
      <td><?= e($m['mass_date']) ?></td>
      <td><?= e($m['zone_name'] ?? 'Global') ?></td>
      <td><?= e($m['class']) ?></td>
      <td><?= e($m['mass']) ?></td>
      <td class="actions">
        <?php if (!$isZoneScoped || $m['zone_id'] !== null): ?>
          <?= icon_edit('/admin/masses/index.php?edit=' . (int) $m['id']) ?>
          <form class="inline" method="post" onsubmit="return confirm('Delete this entry?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <?= icon_delete() ?>
          </form>
        <?php else: ?>
          <span style="color:#aaa; font-size:12px;">Global — read only</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
