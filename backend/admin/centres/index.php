<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/flash.php';
admin_require_role('super');

$pdo = pastores_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        // Source rows must only use centres that exist, so a centre they use can't go.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM source s JOIN centres c ON c.zone_id = s.zone_id AND c.name = s.centre WHERE c.id = ?');
        $stmt->execute([(int) $_POST['id']]);
        if ($inSource = (int) $stmt->fetchColumn()) {
            flash('error', "That centre is used by $inSource row" . ($inSource === 1 ? '' : 's') . ' in Source, so it can\'t be deleted. Change or remove those source rows first.');
        } else {
            $pdo->prepare('DELETE FROM centres WHERE id = ?')->execute([(int) $_POST['id']]);
            flash('success', 'Centre deleted.');
        }
    } else {
        $name = trim($_POST['name'] ?? '');
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        $section = trim($_POST['section'] ?? '') ?: null;

        if ($name === '' || !$zoneId) {
            flash('error', 'Name and zone are required.');
        } else {
            $id = $_POST['id'] ?? '';
            if ($id !== '') {
                $stmt = $pdo->prepare('SELECT zone_id, name FROM centres WHERE id = ?');
                $stmt->execute([(int) $id]);
                $old = $stmt->fetch();
                // Source rows follow the centre's name and section. Moving the
                // centre to another zone would leave them with priests of the
                // wrong zone, so that is refused while source uses the centre.
                if ($old && (int) $old['zone_id'] !== $zoneId) {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM source WHERE zone_id = ? AND centre = ?');
                    $stmt->execute([$old['zone_id'], $old['name']]);
                    if ($n = (int) $stmt->fetchColumn()) {
                        flash('error', "That centre is used by $n row" . ($n === 1 ? '' : 's') . ' in Source, so it can\'t be moved to another zone. Change or remove those source rows first.');
                        header('Location: /admin/centres/index.php');
                        exit;
                    }
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE centres SET name = ?, zone_id = ?, section = ? WHERE id = ?')
                    ->execute([$name, $zoneId, $section, (int) $id]);
                if ($old) {
                    $pdo->prepare('UPDATE source SET centre = ?, section = ? WHERE zone_id = ? AND centre = ?')
                        ->execute([$name, $section, $old['zone_id'], $old['name']]);
                }
                $pdo->commit();
                // An activity's section comes from its centre, so keep the
                // activities currently pointing at this centre in step.
                if ($old) {
                    $pdo->prepare('UPDATE activities SET section = ? WHERE zone_id = ? AND centre = ? AND NOT (section <=> ?)')
                        ->execute([$section, $old['zone_id'], $old['name'], $section]);
                    touch_zone($pdo, (int) $old['zone_id']);
                }
                flash('success', 'Centre updated.');
            } else {
                $pdo->prepare('INSERT INTO centres (name, zone_id, section) VALUES (?, ?, ?)')
                    ->execute([$name, $zoneId, $section]);
                flash('success', 'Centre created.');
            }
        }
    }
    header('Location: /admin/centres/index.php');
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM centres WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

$zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
$centres = $pdo->query(
    'SELECT c.*, z.name AS zone_name FROM centres c JOIN zones z ON z.id = c.zone_id ORDER BY z.name, c.name'
)->fetchAll();

$pageTitle = 'Centres — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Centres</h1>

<div class="card" data-modal data-add-label="New centre"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit centre' : 'New centre' ?></h2>
  <form method="post">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="row">
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
      </div>
      <div>
        <label for="zone_id">Zone</label>
        <select id="zone_id" name="zone_id" required>
          <option value="">Select a zone</option>
          <?php foreach ($zones as $zone): ?>
            <option value="<?= (int) $zone['id'] ?>" <?= (($editing['zone_id'] ?? null) == $zone['id']) ? 'selected' : '' ?>>
              <?= e($zone['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="section">Section</label>
        <input type="text" id="section" name="section" list="sections" value="<?= e($editing['section'] ?? '') ?>" placeholder="e.g. sf, sv, c-m">
      </div>
    </div>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create centre' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/centres/index.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php render_datalist('sections', lookup_names($pdo, 'sections')); ?>

<table>
  <thead><tr><th>Name</th><th>Zone</th><th>Section</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($centres as $centre): ?>
    <tr>
      <td><?= e($centre['name']) ?></td>
      <td><?= e($centre['zone_name']) ?></td>
      <td><?= e($centre['section']) ?></td>
      <td class="actions">
        <a href="/admin/centres/index.php?edit=<?= (int) $centre['id'] ?>">Edit</a>
        <form class="inline" method="post" onsubmit="return confirm('Delete this centre?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $centre['id'] ?>">
          <a href="#" onclick="this.closest('form').requestSubmit(); return false;" style="color:#E91E63;">Delete</a>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
