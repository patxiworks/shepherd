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
        $pdo->prepare('DELETE FROM zones WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('success', 'Zone deleted.');
    } else {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('error', 'Zone name is required.');
        } else {
            $id = $_POST['id'] ?? '';
            if ($id !== '') {
                $pdo->prepare('UPDATE zones SET name = ? WHERE id = ?')->execute([$name, (int) $id]);
                flash('success', 'Zone updated.');
            } else {
                $pdo->prepare('INSERT INTO zones (name) VALUES (?)')->execute([$name]);
                flash('success', 'Zone created.');
            }
        }
    }
    header('Location: /admin/zones/index.php');
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

$zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();

$pageTitle = 'Zones — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Zones</h1>

<div class="card" data-modal data-add-label="New zone"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit zone' : 'New zone' ?></h2>
  <form method="post">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create zone' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/zones/index.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<table>
  <thead><tr><th>Name</th><th>Last update</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($zones as $zone): ?>
    <tr>
      <td><?= e($zone['name']) ?></td>
      <td><?= e($zone['last_update']) ?></td>
      <td class="actions">
        <a href="/admin/zones/index.php?edit=<?= (int) $zone['id'] ?>">Edit</a>
        <form class="inline" method="post" onsubmit="return confirm('Delete this zone and everything linked to it?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $zone['id'] ?>">
          <a href="#" onclick="this.closest('form').requestSubmit(); return false;" style="color:#E91E63;">Delete</a>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
