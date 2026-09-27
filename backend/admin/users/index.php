<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/flash.php';
$admin = admin_require_role('super', 'zone', 'centre');

$pdo = pastores_db();
$scopeCentreName = $admin['role'] === 'centre' ? admin_centre_name($pdo, $admin) : null;

// A zone/centre-scoped admin may only touch users inside their own
// zone (and, for 'centre', matching their own centre name).
function user_in_scope(PDO $pdo, int $userId, array $admin, ?string $scopeCentreName): bool
{
    if ($admin['role'] === 'super') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT zone_id, centre FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['zone_id'] !== (int) $admin['zone_id']) {
        return false;
    }
    if ($admin['role'] === 'centre') {
        return $row['centre'] === $scopeCentreName;
    }
    return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        if (user_in_scope($pdo, $id, $admin, $scopeCentreName)) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('success', 'User deleted.');
        } else {
            flash('error', 'You do not have access to that user.');
        }
    } else {
        $zoneId = $admin['role'] === 'super' ? (int) ($_POST['zone_id'] ?? 0) : (int) $admin['zone_id'];
        $name = trim($_POST['name'] ?? '');
        $centre = $admin['role'] === 'centre' ? $scopeCentreName : (trim($_POST['centre'] ?? '') ?: null);
        $section = trim($_POST['section'] ?? '') ?: null;
        $role = trim($_POST['role'] ?? '') ?: null;
        $passcode = trim($_POST['passcode'] ?? '');
        $id = $_POST['id'] ?? '';

        if (!$zoneId || $name === '' || ($id === '' && $passcode === '')) {
            flash('error', 'Zone, name and (for new users) a passcode are required.');
        } elseif ($id !== '' && !user_in_scope($pdo, (int) $id, $admin, $scopeCentreName)) {
            flash('error', 'You do not have access to that user.');
        } else {
            if ($id !== '') {
                if ($passcode !== '') {
                    $pdo->prepare(
                        'UPDATE users SET zone_id=?, name=?, centre=?, section=?, role=?, passcode_hash=? WHERE id=?'
                    )->execute([$zoneId, $name, $centre, $section, $role, hash_passcode($passcode), (int) $id]);
                } else {
                    $pdo->prepare(
                        'UPDATE users SET zone_id=?, name=?, centre=?, section=?, role=? WHERE id=?'
                    )->execute([$zoneId, $name, $centre, $section, $role, (int) $id]);
                }
                flash('success', 'User updated.');
            } else {
                $pdo->prepare(
                    'INSERT INTO users (zone_id, name, centre, section, role, passcode_hash) VALUES (?,?,?,?,?,?)'
                )->execute([$zoneId, $name, $centre, $section, $role, hash_passcode($passcode)]);
                flash('success', 'User created.');
            }
            touch_zone($pdo, $zoneId);
        }
    }
    header('Location: /admin/users/index.php');
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId && user_in_scope($pdo, $editId, $admin, $scopeCentreName)) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

if ($admin['role'] === 'super') {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
    $users = $pdo->query(
        'SELECT u.*, z.name AS zone_name FROM users u JOIN zones z ON z.id = u.zone_id ORDER BY z.name, u.name'
    )->fetchAll();
} else {
    $zones = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
    $zones->execute([$admin['zone_id']]);
    $zones = $zones->fetchAll();

    if ($admin['role'] === 'centre') {
        $users = $pdo->prepare(
            'SELECT u.*, z.name AS zone_name FROM users u JOIN zones z ON z.id = u.zone_id
             WHERE u.zone_id = ? AND u.centre = ? ORDER BY u.name'
        );
        $users->execute([$admin['zone_id'], $scopeCentreName]);
    } else {
        $users = $pdo->prepare(
            'SELECT u.*, z.name AS zone_name FROM users u JOIN zones z ON z.id = u.zone_id
             WHERE u.zone_id = ? ORDER BY u.name'
        );
        $users->execute([$admin['zone_id']]);
    }
    $users = $users->fetchAll();
}

$pageTitle = 'Users — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Users</h1>

<div class="card" data-modal data-add-label="New user"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit user' : 'New user' ?></h2>
  <form method="post">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="row">
      <div>
        <label for="zone_id">Zone</label>
        <?php if ($admin['role'] === 'super'): ?>
          <select id="zone_id" name="zone_id" required>
            <option value="">Select a zone</option>
            <?php foreach ($zones as $zone): ?>
              <option value="<?= (int) $zone['id'] ?>" <?= (($editing['zone_id'] ?? null) == $zone['id']) ? 'selected' : '' ?>>
                <?= e($zone['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" value="<?= e($zones[0]['name'] ?? '') ?>" disabled>
        <?php endif; ?>
      </div>
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
      </div>
      <div>
        <label for="role">Role</label>
        <input type="text" id="role" name="role" value="<?= e($editing['role'] ?? '') ?>" placeholder="admin, ctr, ...">
      </div>
    </div>
    <div class="row">
      <div>
        <label for="centre">Centre</label>
        <?php if ($admin['role'] === 'centre'): ?>
          <input type="text" value="<?= e($scopeCentreName ?? '') ?>" disabled>
        <?php else: ?>
          <input type="text" id="centre" name="centre" value="<?= e($editing['centre'] ?? '') ?>">
        <?php endif; ?>
      </div>
      <div>
        <label for="section">Section</label>
        <input type="text" id="section" name="section" list="sections" value="<?= e($editing['section'] ?? '') ?>">
      </div>
      <div>
        <label for="passcode">Passcode <?= $editing ? '(leave blank to keep current)' : '' ?></label>
        <input type="text" id="passcode" name="passcode" <?= $editing ? '' : 'required' ?>>
      </div>
    </div>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create user' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/users/index.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php render_datalist('sections', lookup_names($pdo, 'sections')); ?>

<table>
  <thead><tr><th>Zone</th><th>Name</th><th>Centre</th><th>Section</th><th>Role</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $user): ?>
    <tr>
      <td><?= e($user['zone_name']) ?></td>
      <td><?= e($user['name']) ?></td>
      <td><?= e($user['centre']) ?></td>
      <td><?= e($user['section']) ?></td>
      <td><?= e($user['role']) ?></td>
      <td class="actions">
        <a href="/admin/users/index.php?edit=<?= (int) $user['id'] ?>">Edit</a>
        <form class="inline" method="post" onsubmit="return confirm('Delete this user?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
          <a href="#" onclick="this.closest('form').requestSubmit(); return false;" style="color:#E91E63;">Delete</a>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
