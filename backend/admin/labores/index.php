<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/flash.php';
admin_require_role('super');

// Labores and their sub-labores (see labor_text() in includes/functions.php for
// what rows store). Super admin only. A labor has parent_id NULL; a sub-labor
// points at its labor. Renaming cascades to the rows that store the text.

$pdo = pastores_db();
$url = '/admin/labores/index.php';
const LABOR_TEXT_TABLES = ['activities', 'source', 'multiday_activities'];

// Rows (all three tables) whose labor is $text, or, with $orSubs, $text or "$text …".
function labores_uses(PDO $pdo, string $text, bool $orSubs = false, bool $sourceOnly = false): int
{
    $like = addcslashes($text, '\\%_') . ' %';
    $n = 0;
    foreach ($sourceOnly ? ['source'] : LABOR_TEXT_TABLES as $t) {
        if ($orSubs) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE labor = ? OR labor LIKE ?");
            $stmt->execute([$text, $like]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE labor = ?");
            $stmt->execute([$text]);
        }
        $n += (int) $stmt->fetchColumn();
    }
    return $n;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $parentId = (int) ($_POST['parent_id'] ?? 0);

    $get = function (int $labId) use ($pdo): ?array {
        $stmt = $pdo->prepare('SELECT l.id, l.name, l.parent_id, p.name AS parent_name FROM labores l LEFT JOIN labores p ON p.id = l.parent_id WHERE l.id = ?');
        $stmt->execute([$labId]);
        return $stmt->fetch() ?: null;
    };
    // Is there already a labor / sub-labor of that name (case-insensitive, like the collation)?
    $taken = function (string $n, ?int $parent, int $exceptId = 0) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM labores WHERE name = ? AND parent_id <=> ? AND id <> ?');
        $stmt->execute([$n, $parent, $exceptId]);
        return (bool) $stmt->fetchColumn();
    };

    try {
        if ($action === 'add') {
            $parent = $parentId ? $get($parentId) : null;
            if ($name === '') {
                flash('error', 'Name is required.');
            } elseif (mb_strlen($name) > 30) {
                flash('error', 'Names can have at most 30 characters.');
            } elseif ($parentId && (!$parent || $parent['parent_id'] !== null)) {
                flash('error', 'A sub-labor belongs to a labor.');
            } elseif ($taken($name, $parentId ?: null)) {
                flash('error', $parentId ? "\"{$parent['name']}\" already has a sub-labor \"$name\"." : "Labor \"$name\" already exists.");
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO labores (name, parent_id) VALUES (?, ?)')->execute([$name, $parentId ?: null]);
                $dropped = 0;
                if ($parentId) {
                    // The labor now has sub-labores, so a centre can no longer have it "on its own".
                    $stmt = $pdo->prepare('DELETE FROM centre_labores WHERE labor_id = ?');
                    $stmt->execute([$parentId]);
                    $dropped = $stmt->rowCount();
                }
                $pdo->commit();
                flash('success', ($parentId ? 'Sub-labor' : 'Labor') . ' added.'
                    . ($dropped ? " $dropped centre" . ($dropped === 1 ? ' had' : 's had') . " \"{$parent['name']}\" without a sub-labor: tick its sub-labores under Admin > Centres." : ''));
            }
        } elseif ($action === 'rename') {
            $row = $get($id);
            if (!$row) {
                flash('error', 'Not found.');
            } elseif ($name === '' || mb_strlen($name) > 30) {
                flash('error', 'Name is required (at most 30 characters).');
            } elseif ($taken($name, $row['parent_id'] === null ? null : (int) $row['parent_id'], $id)) {
                flash('error', 'That name already exists here.');
            } elseif ($name !== $row['name']) {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE labores SET name = ? WHERE id = ?')->execute([$name, $id]);
                foreach (LABOR_TEXT_TABLES as $t) {
                    if ($row['parent_id'] === null) {
                        // "sm" -> "sx", "sm agd" -> "sx agd"
                        $pdo->prepare("UPDATE $t SET labor = CONCAT(?, SUBSTRING(labor, ?)) WHERE labor = ? OR labor LIKE ?")
                            ->execute([$name, mb_strlen($row['name']) + 1, $row['name'], addcslashes($row['name'], '\\%_') . ' %']);
                    } else {
                        $pdo->prepare("UPDATE $t SET labor = ? WHERE labor = ?")
                            ->execute([labor_text($row['parent_name'], $name), labor_text($row['parent_name'], $row['name'])]);
                    }
                }
                $pdo->exec('UPDATE zones SET last_update = UTC_TIMESTAMP()');
                $pdo->commit();
                flash('success', 'Renamed. Existing rows follow the new name.');
            }
        } elseif ($action === 'delete') {
            $row = $get($id);
            if (!$row) {
                flash('error', 'Not found.');
            } else {
                $isLabor = $row['parent_id'] === null;
                $text = $isLabor ? $row['name'] : labor_text($row['parent_name'], $row['name']);
                $inSource = labores_uses($pdo, $text, $isLabor, true);
                if ($inSource) {
                    flash('error', "\"$text\" is used by $inSource row" . ($inSource === 1 ? '' : 's') . ' in Source, so it can\'t be deleted. Change or remove those source rows first.');
                } else {
                    $pdo->prepare('DELETE FROM labores WHERE id = ?')->execute([$id]);
                    flash('success', ($isLabor ? 'Labor and its sub-labores' : 'Sub-labor') . ' deleted. Existing activities keep their current value.');
                }
            }
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($ex->getCode() !== '23000') {
            throw $ex;
        }
        flash('error', 'That name already exists here.');
    }
    header('Location: ' . $url);
    exit;
}

$labores = [];
foreach ($pdo->query('SELECT id, name FROM labores WHERE parent_id IS NULL ORDER BY name') as $l) {
    $labores[(int) $l['id']] = $l + ['subs' => []];
}
foreach ($pdo->query('SELECT c.id, c.name, c.parent_id FROM labores c WHERE c.parent_id IS NOT NULL ORDER BY c.name') as $c) {
    if (isset($labores[(int) $c['parent_id']])) {
        $labores[(int) $c['parent_id']]['subs'][] = $c;
    }
}
$centreCounts = []; // labor id => number of centres that run it (through any of its sub-labores)
foreach ($pdo->query('SELECT COALESCE(l.parent_id, l.id) AS top, COUNT(DISTINCT cl.centre_id) AS n FROM centre_labores cl JOIN labores l ON l.id = cl.labor_id GROUP BY top') as $r) {
    $centreCounts[(int) $r['top']] = (int) $r['n'];
}

$pageTitle = 'Labores — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Labores</h1>
<p class="table-hint">A labor can have sub-labores (e.g. <em>sm</em> → <em>agd</em>, <em>club</em>). Activities and source rows store the labor followed by the sub-labor, like <em>sm agd</em>; a labor without sub-labores is stored on its own. Which labores a centre runs is set under <a href="/admin/centres/index.php">Centres</a>.</p>

<div class="card">
  <h2>New labor</h2>
  <form method="post" class="row">
    <input type="hidden" name="action" value="add">
    <div>
      <label for="new_labor">Name</label>
      <input type="text" id="new_labor" name="name" maxlength="30" required>
    </div>
    <div class="btn-row"><button type="submit">Add labor</button></div>
  </form>
</div>

<form method="post" id="act" hidden>
  <input type="hidden" name="action"><input type="hidden" name="id"><input type="hidden" name="name"><input type="hidden" name="parent_id">
</form>

<table>
  <thead><tr><th>Labor</th><th>Sub-labores</th><th>Centres</th><th>In use</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($labores as $l): ?>
    <tr>
      <td><?= e($l['name']) ?></td>
      <td>
        <?php foreach ($l['subs'] as $s): ?>
          <span class="zone-chip"><?= e($s['name']) ?>
            <button type="button" title="Rename" aria-label="Rename <?= e($s['name']) ?>" onclick="laborRename(<?= (int) $s['id'] ?>, this.dataset.name)" data-name="<?= e($s['name']) ?>">&#9998;</button>
            <button type="button" title="Delete" aria-label="Delete <?= e($s['name']) ?>" onclick="laborDelete(<?= (int) $s['id'] ?>, this.dataset.name)" data-name="<?= e($l['name'] . ' ' . $s['name']) ?>">&times;</button>
          </span>
        <?php endforeach; ?>
        <?php if (!$l['subs']): ?><span style="color:#888;">none</span><?php endif; ?>
        <button type="button" class="secondary" onclick="laborAddSub(<?= (int) $l['id'] ?>)">+ sub-labor</button>
      </td>
      <td><?= $centreCounts[(int) $l['id']] ?? 0 ?></td>
      <td><?= labores_uses($pdo, $l['name'], true) ?></td>
      <td class="actions">
        <a href="#" class="icon-btn" title="Rename" aria-label="Rename" onclick="laborRename(<?= (int) $l['id'] ?>, this.dataset.name); return false;" data-name="<?= e($l['name']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg></a>
        <a href="#" class="icon-btn delete" title="Delete" aria-label="Delete" onclick="laborDelete(<?= (int) $l['id'] ?>, this.dataset.name); return false;" data-name="<?= e($l['name']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$labores): ?>
    <tr><td colspan="5" style="text-align:center;color:#888;">No labores yet. Add the first one above.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<style>
  .zone-chip { display:inline-flex; align-items:center; gap:4px; background:#eef1f5; border-radius:12px; padding:2px 6px 2px 10px; margin:2px 4px 2px 0; }
  .zone-chip button { border:0; background:none; cursor:pointer; padding:0 2px; font-size:14px; line-height:1; color:#555; }
  td button.secondary { padding:2px 8px; font-size:12px; }
</style>
<script>
function laborPost(action, id, name, parent) {
  var f = document.getElementById('act');
  f.elements.action.value = action; f.elements.id.value = id || '';
  f.elements.name.value = name || ''; f.elements.parent_id.value = parent || '';
  f.submit();
}
function laborRename(id, current) {
  var n = prompt('New name:', current);
  if (n !== null && n.trim() !== '' && n.trim() !== current) laborPost('rename', id, n.trim());
}
function laborAddSub(parent) {
  var n = prompt('Name of the new sub-labor:');
  if (n !== null && n.trim() !== '') laborPost('add', 0, n.trim(), parent);
}
function laborDelete(id, text) {
  if (confirm('Delete "' + text + '" from the list? Existing records keep their current value.')) laborPost('delete', id);
}
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
