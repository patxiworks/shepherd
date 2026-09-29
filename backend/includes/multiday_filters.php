<?php
// Filters shared by the Multi-day Activities Calendar and Dashboard tabs:
// year / zone / venue / activity / group / section / priest selection, the filter
// card, and its tick-box dropdown + live-refresh script.

// Reads a checkbox-array GET param (e.g. ?venue[]=A&venue[]=B) into a clean
// list of distinct, non-empty strings. Missing/empty means "no filter",
// same as the original tool's multiselect (nothing checked = show all).
function mday_ms_param(string $key): array
{
    $raw = $_GET[$key] ?? [];
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $v = trim((string) $v);
        if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
}

// Appends "$col IN (?,?,...)" to $where/$params when $values isn't empty.
function mday_in_clause(string $col, array $values, array &$where, array &$params): void
{
    if (!$values) return;
    $where[] = "$col IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
    foreach ($values as $v) $params[] = $v;
}

// Renders one tick-box dropdown filter (trigger button + checkbox panel),
// matching the original Painted Calendar tool's venue/activity/group/section
// multiselects. Submitting the form (Apply) is what actually applies it;
// the trigger/panel behaviour itself is wired up by the <script> below.
function mday_render_multiselect(string $name, string $noun, array $options, array $selected): void
{
    mday_render_filter_styles();
    $selectedSet = array_flip($selected);
    ?>
    <div class="mday-ms" data-noun="<?= e($noun) ?>">
      <div class="mday-ms-trigger" tabindex="0" role="button">
        <span class="mday-ms-label">All <?= e($noun) ?></span><span>▾</span>
      </div>
      <div class="mday-ms-panel">
        <?php foreach ($options as $opt): ?>
          <label class="mday-ms-option">
            <input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($opt) ?>" <?= isset($selectedSet[$opt]) ? 'checked' : '' ?>>
            <span><?= e($opt) ?></span>
          </label>
        <?php endforeach; ?>
        <?php if (!$options): ?><div style="font-size:11px;color:#888;padding:4px 6px;">None yet</div><?php endif; ?>
        <div class="mday-ms-actions">
          <button type="button" data-ms-all>All</button>
          <button type="button" data-ms-none>None</button>
        </div>
      </div>
    </div>
    <?php
}

// ── Zone / year / filter selection ──────────────────────────────────────
// No ?zone= at all (or an empty value, e.g. picking "All zones" in the
// select) means "All zones" for a super admin; a zone-scoped admin is always
// locked to their own and has no "All zones" option. The dropdown only
// lists zones that actually have multi-day activities.
function mday_view_filters(PDO $pdo, array $admin): array
{
    if ($admin['role'] === 'super') {
        $zones = $pdo->query(
            'SELECT DISTINCT z.* FROM zones z JOIN multiday_activities m ON m.zone_id = z.id ORDER BY z.name'
        )->fetchAll();
        $zoneParam = $_GET['zone'] ?? '';
        $zoneId = $zoneParam !== '' ? (int) $zoneParam : null;
    } else {
        $zones = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
        $zones->execute([$admin['zone_id']]);
        $zones = $zones->fetchAll();
        $zoneId = (int) $admin['zone_id'];
    }
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    if ($year < 2000 || $year > 2100) $year = (int) date('Y');
    $venueFilter = mday_ms_param('venue');
    $activityFilter = mday_ms_param('activity');
    $groupFilter = mday_ms_param('group');
    $sectionFilter = mday_ms_param('section');
    $priestFilter = mday_ms_param('priest');

    $where = ['m.start_date <= ?', 'm.end_date >= ?'];
    $params = ["$year-12-31", "$year-01-01"];
    if ($zoneId !== null) { $where[] = 'm.zone_id = ?'; $params[] = $zoneId; }
    mday_in_clause('m.centre', $venueFilter, $where, $params);
    mday_in_clause('m.activity', $activityFilter, $where, $params);
    mday_in_clause('m.labor', $groupFilter, $where, $params);
    mday_in_clause('m.section', $sectionFilter, $where, $params);
    mday_in_clause('m.priest', $priestFilter, $where, $params);
    return compact('zones', 'zoneId', 'year', 'venueFilter', 'activityFilter', 'groupFilter', 'sectionFilter', 'priestFilter', 'where', 'params');
}

// Filter option lists. Venue is scoped to the selected zone (or every
// centre, across zones, when viewing "All zones"); Activity/Group/Section
// come from the same lookup tables the CRUD form uses.
function mday_filter_options(PDO $pdo, ?int $zoneId): array
{
    if ($zoneId !== null) {
        $venueOptStmt = $pdo->prepare('SELECT name FROM centres WHERE zone_id = ? ORDER BY name');
        $venueOptStmt->execute([$zoneId]);
    } else {
        $venueOptStmt = $pdo->query('SELECT DISTINCT name FROM centres ORDER BY name');
    }
    $venueOptions = $venueOptStmt->fetchAll(PDO::FETCH_COLUMN);
    $activityOptions = $pdo->query("SELECT name FROM activity_types WHERE is_multiday = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $sectionOptions = lookup_names($pdo, 'sections');
    // Priests of the selected zone (every zone's, in "All zones"), by name.
    $priestOptions = array_values(array_unique(array_column(priests_by_zone($pdo, $zoneId), 'name')));
    $groupOptions = lookup_names($pdo, 'labors');
    return compact('venueOptions', 'activityOptions', 'sectionOptions', 'groupOptions', 'priestOptions');
}

// Styles of the tick-box dropdowns. Emitted with the form itself (not at the end of
// the page) so the option panels are hidden from the first paint.
function mday_render_filter_styles(): void
{
    static $done = false; // once per page, whichever form asks first
    if ($done) return;
    $done = true;
    ?>
<style>
  .mday-ms { position: relative; min-width: 150px; }
  .mday-ms-trigger { border: 1px solid #ccc; border-radius: 4px; padding: 8px 10px; font-size: 13px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; user-select: none; }
  .mday-ms-trigger:hover { border-color: #999; }
  .mday-ms-panel { display: none; position: absolute; top: calc(100% + 4px); left: 0; z-index: 50; background: #fff; border: 1px solid #ccc; border-radius: 6px; box-shadow: 0 8px 24px rgba(0,0,0,.15); min-width: 200px; max-width: calc(100vw - 32px); max-height: 260px; overflow-y: auto; padding: 6px; }
  .mday-ms-panel.open { display: block; }
  .mday-ms-option { display: flex; align-items: center; gap: 8px; padding: 5px 6px; border-radius: 4px; font-size: 12px; font-weight: normal; margin: 0; cursor: pointer; }
  .mday-ms-option:hover { background: #f5f5f5; }
  .mday-ms-option input { width: auto; margin: 0; }
  .mday-ms-actions { display: flex; gap: 8px; padding: 6px 6px 2px; border-top: 1px solid #eee; margin-top: 4px; }
  .mday-ms-actions button { font-size: 10px; text-transform: uppercase; letter-spacing: .03em; color: #666; background: none; border: none; cursor: pointer; padding: 2px 4px; }
  .mday-ms-actions button:hover { color: #222; }
</style>
    <?php
}

// The Year/Zone/Venue/Activity/Group/Section filter card shared by the
// Calendar and Dashboard tabs.
function mday_render_filter_form(array $admin, array $f, array $opts): void
{
    mday_render_filter_styles();
    ['venueOptions' => $venueOptions, 'activityOptions' => $activityOptions, 'groupOptions' => $groupOptions, 'sectionOptions' => $sectionOptions, 'priestOptions' => $priestOptions] = $opts;
    $zones = $f['zones']; $zoneId = $f['zoneId']; $year = $f['year'];
    $venueFilter = $f['venueFilter']; $activityFilter = $f['activityFilter']; $groupFilter = $f['groupFilter']; $sectionFilter = $f['sectionFilter']; $priestFilter = $f['priestFilter'];
    ?>
<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div>
      <label for="year">Year</label>
      <input type="number" id="year" name="year" value="<?= (int) $year ?>" min="2000" max="2100" style="width:90px;">
    </div>
    <?php if ($admin['role'] === 'super'): ?>
    <div>
      <label for="zone">Zone</label>
      <select id="zone" name="zone" onchange="this.form.querySelectorAll('input[name=\'venue[]\']').forEach(function (c) { c.checked = false; }); this.form.submit();">
        <option value="" <?= $zoneId === null ? 'selected' : '' ?>>All zones</option>
        <?php foreach ($zones as $zone): ?>
          <option value="<?= (int) $zone['id'] ?>" <?= $zoneId === (int) $zone['id'] ? 'selected' : '' ?>><?= e($zone['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div>
      <label>Venue</label>
      <?php mday_render_multiselect('venue', 'venues', $venueOptions, $venueFilter); ?>
    </div>
    <div>
      <label>Activity</label>
      <?php mday_render_multiselect('activity', 'activities', $activityOptions, $activityFilter); ?>
    </div>
    <div>
      <label>Group</label>
      <?php mday_render_multiselect('group', 'groups', $groupOptions, $groupFilter); ?>
    </div>
    <div>
      <label>Section</label>
      <?php mday_render_multiselect('section', 'sections', $sectionOptions, $sectionFilter); ?>
    </div>
    <div>
      <label>Priest</label>
      <?php mday_render_multiselect('priest', 'priests', $priestOptions, $priestFilter); ?>
    </div>
  </form>
</div>
    <?php
}

// Script for the filter card: tick-box dropdowns plus the live
// refresh that swaps #mday-results with the freshly fetched page.
function mday_render_filter_assets(): void
{
    ?>
<script>
// Wires up the Venue/Activity/Group/Section tick-box dropdowns: a plain
// checkbox list under the hood (so it still submits fine without JS run —
// just without the collapsible panel/trigger label), matching the original
// Painted Calendar tool's multiselect filters.
(function () {
  document.querySelectorAll('.mday-ms').forEach(function (ms) {
    var trigger = ms.querySelector('.mday-ms-trigger');
    var panel = ms.querySelector('.mday-ms-panel');
    var label = ms.querySelector('.mday-ms-label');
    var noun = ms.dataset.noun || 'items';
    var allLabel = 'All ' + noun;
    var boxes = Array.prototype.slice.call(panel.querySelectorAll('input[type=checkbox]'));

    function updateLabel() {
      var checked = boxes.filter(function (c) { return c.checked; });
      if (checked.length === 0 || checked.length === boxes.length) label.textContent = allLabel;
      else if (checked.length === 1) label.textContent = checked[0].value;
      else label.textContent = checked.length + ' ' + noun + ' selected';
    }
    trigger.addEventListener('click', function () {
      document.querySelectorAll('.mday-ms-panel.open').forEach(function (p) { if (p !== panel) p.classList.remove('open'); });
      panel.classList.toggle('open');
    });
    trigger.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); trigger.click(); } });
    boxes.forEach(function (cb) { cb.addEventListener('change', updateLabel); });
    var allBtn = ms.querySelector('[data-ms-all]');
    var noneBtn = ms.querySelector('[data-ms-none]');
    if (allBtn) allBtn.addEventListener('click', function () { boxes.forEach(function (c) { c.checked = true; }); updateLabel(); });
    if (noneBtn) noneBtn.addEventListener('click', function () { boxes.forEach(function (c) { c.checked = false; }); updateLabel(); });
    updateLabel();
  });
  // No Apply button: any filter change re-fetches this page and swaps in the
  // new banner + grid, leaving the form (and any open dropdown) untouched, so
  // several boxes can be ticked in a row. Falls back to a normal reload.
  var form = document.querySelector('.card form');
  var seq = 0;
  function refresh() {
    var mine = ++seq;
    var qs = new URLSearchParams(new FormData(form)).toString();
    fetch(location.pathname + '?' + qs, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        if (mine !== seq) return; // a newer change superseded this one
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('mday-results');
        if (!fresh) throw new Error('no results');
        document.getElementById('mday-results').innerHTML = fresh.innerHTML;
        history.replaceState(null, '', location.pathname + '?' + qs);
      })
      .catch(function () { form.submit(); });
  }
  form.querySelectorAll('.mday-ms input[type=checkbox]').forEach(function (cb) { cb.addEventListener('change', refresh); });
  form.querySelectorAll('.mday-ms [data-ms-all], .mday-ms [data-ms-none]').forEach(function (b) { b.addEventListener('click', refresh); });
  var yearInput = document.getElementById('year');
  if (yearInput) yearInput.addEventListener('change', refresh);
  form.addEventListener('submit', function (ev) { ev.preventDefault(); refresh(); }); // Enter in the year box

  document.addEventListener('click', function (ev) {
    if (!ev.target.closest('.mday-ms')) document.querySelectorAll('.mday-ms-panel.open').forEach(function (p) { p.classList.remove('open'); });
  });
})();
</script>
    <?php
}
