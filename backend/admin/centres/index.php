<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/bulk.php';
admin_require_role('super');

$pdo = pastores_db();

// Source rows must only use centres that exist, so a centre they use can't go.
$deleteOne = function (int $id) use ($pdo): ?string {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM source s JOIN centres c ON c.zone_id = s.zone_id AND c.name = s.centre WHERE c.id = ?');
    $stmt->execute([$id]);
    if ($inSource = (int) $stmt->fetchColumn()) {
        return "That centre is used by $inSource row" . ($inSource === 1 ? '' : 's') . ' in Source, so it can\'t be deleted. Change or remove those source rows first.';
    }
    $pdo->prepare('DELETE FROM centres WHERE id = ?')->execute([$id]);
    return null;
};

// The labores a centre can run, as the most specific entries: each sub-labor, and
// each labor that has none. [labor id => [name, [[id, name, ticked-able]...]]]
$laborTree = [];
foreach ($pdo->query('SELECT id, name FROM labores WHERE parent_id IS NULL ORDER BY name') as $l) {
    $laborTree[(int) $l['id']] = ['name' => $l['name'], 'subs' => []];
}
foreach ($pdo->query('SELECT id, name, parent_id FROM labores WHERE parent_id IS NOT NULL ORDER BY name') as $c) {
    if (isset($laborTree[(int) $c['parent_id']])) {
        $laborTree[(int) $c['parent_id']]['subs'][(int) $c['id']] = $c['name'];
    }
}
$pickable = []; // ids a centre can be given
foreach ($laborTree as $lid => $l) {
    if ($l['subs']) {
        foreach ($l['subs'] as $sid => $_) {
            $pickable[$sid] = true;
        }
    } else {
        $pickable[$lid] = true;
    }
}

// Replaces the labores of a centre with the posted ones.
$saveLabores = function (int $centreId) use ($pdo, $pickable): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['labor_ids'] ?? [])), fn($i) => isset($pickable[$i]))));
    $pdo->prepare('DELETE FROM centre_labores WHERE centre_id = ?')->execute([$centreId]);
    $insert = $pdo->prepare('INSERT INTO centre_labores (centre_id, labor_id) VALUES (?, ?)');
    foreach ($ids as $i) {
        $insert->execute([$centreId, $i]);
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_delete') {
        bulk_run($deleteOne, 'centre', 'centres');
    } elseif ($action === 'delete') {
        $err = $deleteOne((int) $_POST['id']);
        flash($err === null ? 'success' : 'error', $err ?? 'Centre deleted.');
    } else {
        $name = trim($_POST['name'] ?? '');
        $fullName = mb_substr(trim($_POST['full_name'] ?? ''), 0, 255) ?: null;
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        $section = trim($_POST['section'] ?? '') ?: null;
        $hasVigils = isset($_POST['has_vigils']) ? 1 : 0;

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
                $pdo->prepare('UPDATE centres SET name = ?, full_name = ?, zone_id = ?, section = ?, has_vigils = ? WHERE id = ?')
                    ->execute([$name, $fullName, $zoneId, $section, $hasVigils, (int) $id]);
                if ($old) {
                    $pdo->prepare('UPDATE source SET centre = ?, section = ? WHERE zone_id = ? AND centre = ?')
                        ->execute([$name, $section, $old['zone_id'], $old['name']]);
                }
                $saveLabores((int) $id);
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
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO centres (name, full_name, zone_id, section, has_vigils) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $fullName, $zoneId, $section, $hasVigils]);
                $saveLabores((int) $pdo->lastInsertId());
                $pdo->commit();
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

$editingLabores = [];
if ($editing) {
    $stmt = $pdo->prepare('SELECT labor_id FROM centre_labores WHERE centre_id = ?');
    $stmt->execute([$editing['id']]);
    $editingLabores = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}
// centre id => "sm: agd, club · sg" for the list.
$centreLabores = [];
foreach ($pdo->query('SELECT cl.centre_id, l.name, p.name AS parent FROM centre_labores cl JOIN labores l ON l.id = cl.labor_id LEFT JOIN labores p ON p.id = l.parent_id ORDER BY COALESCE(p.name, l.name), l.name') as $r) {
    $top = $r['parent'] ?? $r['name'];
    $centreLabores[(int) $r['centre_id']][$top][] = $r['parent'] !== null ? $r['name'] : null;
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
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" maxlength="255" value="<?= e($editing['full_name'] ?? '') ?>">
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
        <?php render_select('section', 'section', lookup_names($pdo, 'sections'), $editing['section'] ?? null, 'None'); ?>
      </div>
      <div>
        <label for="has_vigils">Vigils</label>
        <label class="inline"><input type="checkbox" id="has_vigils" name="has_vigils" value="1" <?= ($editing['has_vigils'] ?? 1) ? 'checked' : '' ?>> Has vigils</label>
      </div>
    </div>
    <div class="labores-pick">
      <label>Labores</label>
      <?php if (!$laborTree): ?>
        <p class="table-hint">No labores yet. Add them under <a href="/admin/labores/index.php">Labores</a>.</p>
      <?php endif; ?>
      <?php foreach ($laborTree as $lid => $l): ?>
        <div class="labor-group"><strong><?= e($l['name']) ?></strong>
          <?php if ($l['subs']): foreach ($l['subs'] as $sid => $sname): ?>
            <label class="inline"><input type="checkbox" name="labor_ids[]" value="<?= $sid ?>" <?= isset($editingLabores[$sid]) ? 'checked' : '' ?>> <?= e($sname) ?></label>
          <?php endforeach; else: ?>
            <label class="inline"><input type="checkbox" name="labor_ids[]" value="<?= $lid ?>" <?= isset($editingLabores[$lid]) ? 'checked' : '' ?>> (whole labor)</label>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <p class="table-hint">A centre only offers the labores ticked here; with none ticked it offers none.</p>
    </div>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create centre' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/centres/index.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<style>
  .labores-pick { margin: 10px 0; }
  .labor-group { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 14px; padding: 4px 0; }
  .labor-group strong { min-width: 60px; }
  .labor-group label.inline { display: inline-flex; align-items: center; gap: 4px; font-weight: normal; margin: 0; }
</style>

<table>
  <thead><tr><th>Name</th><th>Full name</th><th>Zone</th><th>Section</th><th>Labores</th><th>Vigils</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($centres as $centre): ?>
    <tr>
      <td><?= e($centre['name']) ?></td>
      <td><?= e($centre['full_name'] ?? '') ?: '—' ?></td>
      <td><?= e($centre['zone_name']) ?></td>
      <td><?= e($centre['section']) ?></td>
      <td><?php
        $parts = [];
        foreach ($centreLabores[(int) $centre['id']] ?? [] as $top => $subs) {
            $subs = array_filter($subs);
            $parts[] = $subs ? $top . ': ' . implode(', ', $subs) : $top;
        }
        echo e(implode(' · ', $parts)) ?: '—';
      ?></td>
      <td><?= !empty($centre['has_vigils']) ? 'Yes' : 'No' ?></td>
      <td class="actions">
        <?= icon_edit('/admin/centres/index.php?edit=' . (int) $centre['id']) ?>
        <form class="inline" method="post" onsubmit="return confirm('Delete this centre?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $centre['id'] ?>">
          <?= icon_delete() ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
