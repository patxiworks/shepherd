<?php
// Manage admin_users accounts and their access level. Super-only.
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/flash.php';
$admin = admin_require_role('super');

$pdo = pastores_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        if ($id === (int) $admin['id']) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
            flash('success', 'Admin account deleted.');
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'super';
        $id = $_POST['id'] ?? '';

        if (!in_array($role, ['super', 'zone', 'centre'], true)) {
            $role = 'super';
        }

        // Derive zone_id/centre_id server-side so they can never disagree
        // with the chosen role, regardless of what the form posted.
        $zoneId = null;
        $centreId = null;
        if ($role === 'zone') {
            $zoneId = (int) ($_POST['zone_id'] ?? 0) ?: null;
        } elseif ($role === 'centre') {
            $centreId = (int) ($_POST['centre_id'] ?? 0) ?: null;
            if ($centreId) {
                $stmt = $pdo->prepare('SELECT zone_id FROM centres WHERE id = ?');
                $stmt->execute([$centreId]);
                $zoneId = (int) $stmt->fetchColumn() ?: null;
            }
        }

        if ($username === '' || ($id === '' && $password === '')) {
            flash('error', 'Username and (for new accounts) a password are required.');
        } elseif ($role === 'zone' && !$zoneId) {
            flash('error', 'Select a zone for a zone-level admin.');
        } elseif ($role === 'centre' && !$centreId) {
            flash('error', 'Select a centre for a centre-level admin.');
        } else {
            if ($id !== '') {
                if ($password !== '') {
                    $pdo->prepare(
                        'UPDATE admin_users SET username=?, role=?, zone_id=?, centre_id=?, password_hash=? WHERE id=?'
                    )->execute([$username, $role, $zoneId, $centreId, password_hash($password, PASSWORD_DEFAULT), (int) $id]);
                } else {
                    $pdo->prepare(
                        'UPDATE admin_users SET username=?, role=?, zone_id=?, centre_id=? WHERE id=?'
                    )->execute([$username, $role, $zoneId, $centreId, (int) $id]);
                }
                flash('success', 'Admin account updated.');
            } else {
                $pdo->prepare(
                    'INSERT INTO admin_users (username, role, zone_id, centre_id, password_hash) VALUES (?,?,?,?,?)'
                )->execute([$username, $role, $zoneId, $centreId, password_hash($password, PASSWORD_DEFAULT)]);
                flash('success', 'Admin account created.');
            }
        }
    }
    header('Location: /admin/admins/index.php');
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

$zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
$centres = $pdo->query(
    'SELECT c.*, z.name AS zone_name FROM centres c JOIN zones z ON z.id = c.zone_id ORDER BY z.name, c.name'
)->fetchAll();
$admins = $pdo->query(
    'SELECT a.*, z.name AS zone_name, c.name AS centre_name
     FROM admin_users a
     LEFT JOIN zones z ON z.id = a.zone_id
     LEFT JOIN centres c ON c.id = a.centre_id
     ORDER BY a.username'
)->fetchAll();

$pageTitle = 'Admins — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Admins</h1>

<div class="card" data-modal data-add-label="New admin account"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit admin account' : 'New admin account' ?></h2>
  <form method="post">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="row">
      <div>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= e($editing['username'] ?? '') ?>" required>
      </div>
      <div>
        <label for="password">Password <?= $editing ? '(leave blank to keep current)' : '' ?></label>
        <input type="password" id="password" name="password" <?= $editing ? '' : 'required' ?>>
      </div>
      <div>
        <label for="role">Access level</label>
        <select id="role" name="role">
          <option value="super" <?= (($editing['role'] ?? 'super') === 'super') ? 'selected' : '' ?>>Super — everything</option>
          <option value="zone" <?= (($editing['role'] ?? '') === 'zone') ? 'selected' : '' ?>>Zone — one zone only</option>
          <option value="centre" <?= (($editing['role'] ?? '') === 'centre') ? 'selected' : '' ?>>Centre — one centre only</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="zone_id">Zone (for "Zone" access level)</label>
        <select id="zone_id" name="zone_id">
          <option value="">—</option>
          <?php foreach ($zones as $zone): ?>
            <option value="<?= (int) $zone['id'] ?>" <?= (($editing['zone_id'] ?? null) == $zone['id']) ? 'selected' : '' ?>>
              <?= e($zone['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="centre_id">Centre (for "Centre" access level)</label>
        <select id="centre_id" name="centre_id">
          <option value="">—</option>
          <?php foreach ($centres as $centre): ?>
            <option value="<?= (int) $centre['id'] ?>" <?= (($editing['centre_id'] ?? null) == $centre['id']) ? 'selected' : '' ?>>
              <?= e($centre['zone_name']) ?> / <?= e($centre['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <p style="font-size:12px; color:#888; margin-top:8px;">
      Only the field matching the chosen access level is used — a "Centre" admin's
      zone is set automatically from their centre.
    </p>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create admin' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/admins/index.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<table>
  <thead><tr><th>Username</th><th>Access level</th><th>Scope</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($admins as $a): ?>
    <tr>
      <td><?= e($a['username']) ?></td>
      <td><?= e($a['role']) ?></td>
      <td>
        <?php if ($a['role'] === 'zone'): ?>
          <?= e($a['zone_name'] ?? 'Unknown') ?>
        <?php elseif ($a['role'] === 'centre'): ?>
          <?= e($a['zone_name'] ?? 'Unknown') ?> / <?= e($a['centre_name'] ?? 'Unknown') ?>
        <?php else: ?>
          All zones
        <?php endif; ?>
      </td>
      <td class="actions">
        <a href="/admin/admins/index.php?edit=<?= (int) $a['id'] ?>">Edit</a>
        <?php if ((int) $a['id'] !== (int) $admin['id']): ?>
          <form class="inline" method="post" onsubmit="return confirm('Delete this admin account?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <a href="#" onclick="this.closest('form').requestSubmit(); return false;" style="color:#E91E63;">Delete</a>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
