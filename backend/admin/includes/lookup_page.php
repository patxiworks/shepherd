<?php
// Number of rows of the source table that use $name in this lookup's
// source column (0 if source doesn't use the lookup). $zoneId limits it
// to one zone's rows.
function lookup_source_uses(PDO $pdo, array $cfg, string $name, ?int $zoneId = null): int
{
    $total = 0;
    foreach ($cfg['used_in'] as [$t, $col]) {
        if ($t === 'source') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM source WHERE $col = ?" . ($zoneId !== null ? ' AND zone_id = ?' : ''));
            $stmt->execute($zoneId !== null ? [$name, $zoneId] : [$name]);
            $total += (int) $stmt->fetchColumn();
        }
    }
    return $total;
}

// Replaces the "also serves in" zones of a priest.
function lookup_save_extra_zones(PDO $pdo, string $table, int $id, array $zoneIds): void
{
    if ($table !== 'priests') {
        return;
    }
    $pdo->prepare('DELETE FROM priest_zones WHERE priest_id = ?')->execute([$id]);
    $insert = $pdo->prepare('INSERT INTO priest_zones (priest_id, zone_id) VALUES (?, ?)');
    foreach ($zoneIds as $zid) {
        $insert->execute([$id, $zid]);
    }
}

// Shared list + create/rename/delete page for the name-only lookup tables
// that feed the Activities dropdowns (priests, sections, labors). Super
// admin only. Each admin/<entity>/index.php is a thin wrapper around this.
//
// $cfg keys:
//   table    lookup table (must be in PASTORES_LOOKUP_TABLES)
//   plural   e.g. 'Priests'
//   singular e.g. 'priest'
//   zone     optional bool: entries belong to a home zone (priests) and the
//            form has a required Zone dropdown. Such entries can also serve
//            in other zones (priest_zones, "Also serves in" checkboxes): the
//            entries "of" a zone are those whose home it is plus those that
//            list it (see priests_by_zone()).
//   multiday optional bool (activity_types only): the form gets a "Multi-day
//            programme" checkbox saved to the is_multiday column (needs
//            migration 015), and the list shows it.
//   used_in  list of [table, column] pairs that store this name as free
//            text. Renaming an entry is cascaded to these; the "In use"
//            count on the list is computed from them too. An entry that
//            the `source` table uses can't be deleted (source must only
//            hold values that exist in these lists).
function lookup_admin_page(array $cfg): void
{
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/flash.php';
    admin_require_role('super');

    $pdo = pastores_db();
    $table = $cfg['table'];
    if (!in_array($table, PASTORES_LOOKUP_TABLES, true)) {
        throw new InvalidArgumentException("Unknown lookup table: $table");
    }
    $url = '/admin/' . $table . '/index.php';
    $hasZone = !empty($cfg['zone']);
    $hasMultiday = !empty($cfg['multiday']);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = $_POST['action'] ?? '';
        $id = $_POST['id'] ?? '';

        if ($action === 'delete') {
            $stmt = $pdo->prepare("SELECT name FROM $table WHERE id = ?");
            $stmt->execute([(int) $id]);
            $name = $stmt->fetchColumn();
            $inSource = $name === false ? 0 : lookup_source_uses($pdo, $cfg, $name);
            if ($inSource) {
                flash('error', ucfirst($cfg['singular']) . " \"$name\" is used by $inSource row" . ($inSource === 1 ? '' : 's') . ' in Source, so it can\'t be deleted. Change or remove those source rows first.');
            } else {
                $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([(int) $id]);
                flash('success', ucfirst($cfg['singular']) . ' deleted. Existing activities keep their current value.');
            }
        } else {
            $name = trim($_POST['name'] ?? '');
            $zoneId = (int) ($_POST['zone_id'] ?? 0);
            $isMultiday = isset($_POST['is_multiday']) ? 1 : 0;
            // Other zones the entry also serves in (never its home zone).
            $extraZones = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['extra_zones'] ?? [])), fn($z) => $z && $z !== $zoneId)));
            if ($name === '' || ($hasZone && !$zoneId)) {
                flash('error', $hasZone ? 'Name and zone are required.' : 'Name is required.');
            } else {
                try {
                    if ($id !== '') {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("SELECT name FROM $table WHERE id = ?");
                        $stmt->execute([(int) $id]);
                        $oldName = $stmt->fetchColumn();
                        if ($hasZone && $oldName !== false) {
                            // The zones whose source rows use this entry must stay among
                            // its zones (home + also serves in), or those rows would no
                            // longer match the lists.
                            $sourceCol = null;
                            foreach ($cfg['used_in'] as [$t, $col]) {
                                $sourceCol = $t === 'source' ? $col : $sourceCol;
                            }
                            if ($sourceCol !== null) {
                                $stmt = $pdo->prepare("SELECT z.id, z.name FROM source s JOIN zones z ON z.id = s.zone_id WHERE s.$sourceCol = ? GROUP BY z.id, z.name ORDER BY z.name");
                                $stmt->execute([$oldName]);
                                $lost = array_filter($stmt->fetchAll(), fn($z) => (int) $z['id'] !== $zoneId && !in_array((int) $z['id'], $extraZones, true));
                                if ($lost) {
                                    $pdo->rollBack();
                                    flash('error', ucfirst($cfg['singular']) . " \"$oldName\" is still used in Source rows of " . implode(', ', array_column($lost, 'name'))
                                        . ', so that zone must stay in its zone or "Also serves in" list. Change or remove those source rows first.');
                                    header('Location: ' . $url);
                                    exit;
                                }
                            }
                        }
                        if ($hasZone) {
                            $pdo->prepare("UPDATE $table SET name = ?, zone_id = ? WHERE id = ?")->execute([$name, $zoneId, (int) $id]);
                        } elseif ($hasMultiday) {
                            $pdo->prepare("UPDATE $table SET name = ?, is_multiday = ? WHERE id = ?")->execute([$name, $isMultiday, (int) $id]);
                        } else {
                            $pdo->prepare("UPDATE $table SET name = ? WHERE id = ?")->execute([$name, (int) $id]);
                        }
                        if ($hasZone) {
                            lookup_save_extra_zones($pdo, $table, (int) $id, $extraZones);
                        }
                        if ($oldName !== false && $oldName !== $name) {
                            foreach ($cfg['used_in'] as [$t, $col]) {
                                $pdo->prepare("UPDATE $t SET $col = ? WHERE $col = ?")->execute([$name, $oldName]);
                            }
                            // Activities changed, so bump every zone's last_update
                            // for the frontend's "update available" check.
                            $pdo->exec('UPDATE zones SET last_update = UTC_TIMESTAMP()');
                        }
                        $pdo->commit();
                        flash('success', ucfirst($cfg['singular']) . ' updated.');
                    } else {
                        if ($hasZone) {
                            $pdo->beginTransaction();
                            $pdo->prepare("INSERT INTO $table (name, zone_id) VALUES (?, ?)")->execute([$name, $zoneId]);
                            lookup_save_extra_zones($pdo, $table, (int) $pdo->lastInsertId(), $extraZones);
                            $pdo->commit();
                        } elseif ($hasMultiday) {
                            $pdo->prepare("INSERT INTO $table (name, is_multiday) VALUES (?, ?)")->execute([$name, $isMultiday]);
                        } else {
                            $pdo->prepare("INSERT INTO $table (name) VALUES (?)")->execute([$name]);
                        }
                        flash('success', ucfirst($cfg['singular']) . ' created.');
                    }
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($ex->getCode() === '23000') {
                        flash('error', 'That ' . $cfg['singular'] . ' already exists.');
                    } else {
                        throw $ex;
                    }
                }
            }
        }
        header('Location: ' . $url);
        exit;
    }

    $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
    $editing = null;
    if ($editId) {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$editId]);
        $editing = $stmt->fetch();
    }

    $zones = $hasZone ? $pdo->query('SELECT id, name FROM zones ORDER BY name')->fetchAll() : [];
    $rows = $hasZone
        ? $pdo->query("SELECT t.*, z.name AS zone_name FROM $table t LEFT JOIN zones z ON z.id = t.zone_id ORDER BY z.name, t.name")->fetchAll()
        : $pdo->query("SELECT * FROM $table ORDER BY name")->fetchAll();
    $extras = []; // priest id => [zone ids] / names
    if ($hasZone && $table === 'priests') {
        foreach ($pdo->query('SELECT pz.priest_id, z.id, z.name FROM priest_zones pz JOIN zones z ON z.id = pz.zone_id ORDER BY z.name') as $x) {
            $extras[(int) $x['priest_id']][(int) $x['id']] = $x['name'];
        }
    }
    foreach ($rows as &$row) {
        $row['in_use'] = 0;
        foreach ($cfg['used_in'] as [$t, $col]) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE $col = ?");
            $stmt->execute([$row['name']]);
            $row['in_use'] += (int) $stmt->fetchColumn();
        }
    }
    unset($row);

    $pageTitle = $cfg['plural'] . ' — Pastores Admin';
    require __DIR__ . '/layout_top.php';
    ?>
<h1><?= e($cfg['plural']) ?></h1>

<div class="card" data-modal data-add-label="New <?= e($cfg['singular']) ?>"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit ' . e($cfg['singular']) : 'New ' . e($cfg['singular']) ?></h2>
  <form method="post">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="row">
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
      </div>
      <?php if ($hasZone): ?>
      <div>
        <label for="zone_id">Zone</label>
        <select id="zone_id" name="zone_id" required>
          <option value="">Select a zone</option>
          <?php foreach ($zones as $zone): ?>
            <option value="<?= (int) $zone['id'] ?>" <?= (($editing['zone_id'] ?? null) == $zone['id']) ? 'selected' : '' ?>><?= e($zone['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($hasMultiday): ?>
    <label style="font-weight:normal;"><input type="checkbox" name="is_multiday" value="1" style="width:auto;" <?= !empty($editing['is_multiday']) ? 'checked' : '' ?>> Multi-day programme (retreat, course, camp&hellip;)</label>
    <small class="hint" style="min-height:0;">Ticked types are offered under Multi-day Activities (list and calendar) instead of being used for the day-to-day activities.</small>
    <?php endif; ?>
    <?php if ($hasZone && $table === 'priests'): ?>
    <?php $chosenExtras = $extras[(int) ($editing['id'] ?? 0)] ?? []; ?>
    <label for="extra_zone_add">Also serves in</label>
    <div id="extra-zones" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
      <?php foreach ($chosenExtras as $zid => $zname): ?>
        <span class="zone-chip" data-zone="<?= (int) $zid ?>"><?= e($zname) ?>
          <input type="hidden" name="extra_zones[]" value="<?= (int) $zid ?>">
          <button type="button" aria-label="Remove <?= e($zname) ?>">&times;</button></span>
      <?php endforeach; ?>
    </div>
    <input type="text" id="extra_zone_add" list="extra_zone_list" placeholder="Type a zone name&hellip;" autocomplete="off">
    <datalist id="extra_zone_list"></datalist>
    <small class="hint" style="min-height:0;">For a priest on a temporary transfer or who covers several zones: the zone above is their home zone, and they also appear in the priest lists of the zones added here. Start typing a zone name and pick it (or press Enter). Removing a zone whose source rows still use them is refused.</small>
    <style>
      .zone-chip { display:inline-flex; align-items:center; gap:4px; background:#ede7f6; color:#4527a0; border-radius:12px; padding:2px 4px 2px 10px; font-size:13px; }
      .zone-chip button { background:none; color:#4527a0; padding:0 6px; font-size:16px; line-height:1; }
    </style>
    <script>
    (function () {
      var chips = document.getElementById('extra-zones'), field = document.getElementById('extra_zone_add'),
          list = document.getElementById('extra_zone_list'), home = document.getElementById('zone_id');
      var zones = <?= json_encode(array_map(fn($z) => ['id' => (int) $z['id'], 'name' => $z['name']], $zones)) ?>;
      function chipFor(id) { return chips.querySelector('.zone-chip[data-zone="' + id + '"]'); }
      // Suggest the zones that aren't the home zone and aren't added yet.
      function refresh() {
        list.innerHTML = '';
        zones.forEach(function (z) {
          if (chipFor(z.id) || (home && home.value === String(z.id))) return;
          var o = document.createElement('option');
          o.value = z.name;
          list.appendChild(o);
        });
      }
      function addChip(z) {
        var chip = document.createElement('span');
        chip.className = 'zone-chip'; chip.setAttribute('data-zone', z.id);
        chip.appendChild(document.createTextNode(z.name + ' '));
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = 'extra_zones[]'; input.value = z.id;
        var x = document.createElement('button');
        x.type = 'button'; x.innerHTML = '&times;'; x.setAttribute('aria-label', 'Remove ' + z.name);
        chip.appendChild(input); chip.appendChild(x);
        chips.appendChild(chip);
      }
      // Adds the zone whose name was typed / picked (case-insensitive exact match; with $force, i.e. Enter, also the only zone that starts with the text).
      function tryAdd(force) {
        var v = field.value.trim().toLowerCase();
        if (!v) return;
        var free = zones.filter(function (z) { return !chipFor(z.id) && !(home && home.value === String(z.id)); });
        var hit = free.filter(function (z) { return z.name.toLowerCase() === v; })[0]
          || (force ? (free.filter(function (z) { return z.name.toLowerCase().indexOf(v) === 0; }).length === 1
              ? free.filter(function (z) { return z.name.toLowerCase().indexOf(v) === 0; })[0] : null) : null);
        if (!hit) return;
        addChip(hit);
        field.value = '';
        refresh();
      }
      chips.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (b) { b.parentNode.remove(); refresh(); }
      });
      // Picking a suggestion fires `input` without typed text (inputType is unset or
      // insertReplacementText); plain typing must not add a zone that merely
      // starts another zone's name.
      field.addEventListener('input', function (ev) {
        if (!ev.inputType || ev.inputType === 'insertReplacementText') tryAdd(false);
      });
      field.addEventListener('change', function () { tryAdd(false); }); // a full name picked from the list
      field.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); tryAdd(true); } // Enter adds the zone, it doesn't submit the form
      });
      if (home) home.addEventListener('change', function () {
        var same = chipFor(home.value);
        if (same) same.remove();
        refresh();
      });
      refresh();
    })();
    </script>
    <?php endif; ?>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create ' . e($cfg['singular']) ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="<?= e($url) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<table>
  <thead><tr><th>Name</th><?php if ($hasZone): ?><th>Zone</th><th>Also serves in</th><?php endif; ?><?php if ($hasMultiday): ?><th>Multi-day</th><?php endif; ?><th>In use</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= e($row['name']) ?></td>
      <?php if ($hasZone): ?><td><?= e($row['zone_name'] ?? '—') ?></td><td><?= e(implode(', ', $extras[(int) $row['id']] ?? [])) ?: '—' ?></td><?php endif; ?>
      <?php if ($hasMultiday): ?><td data-sort="<?= (int) $row['is_multiday'] ?>"><?= $row['is_multiday'] ? 'Yes' : '' ?></td><?php endif; ?>
      <td><?= (int) $row['in_use'] ?></td>
      <td class="actions">
        <a href="<?= e($url) ?>?edit=<?= (int) $row['id'] ?>">Edit</a>
        <form class="inline" method="post" onsubmit="return confirm('Delete this <?= e($cfg['singular']) ?> from the list? Existing records keep their current value.');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <a href="#" onclick="this.closest('form').requestSubmit(); return false;" style="color:#E91E63;">Delete</a>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <tr><td colspan="<?= ($hasZone ? 5 : 3) + ($hasMultiday ? 1 : 0) ?>" style="text-align:center;color:#888;">Nothing here yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php
    require __DIR__ . '/layout_bottom.php';
}
