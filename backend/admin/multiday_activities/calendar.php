<?php
// Admin > Multi-day Activities > Calendar: read-only venue/date-range view
// of multiday_activities, ported from the standalone "Painted Calendar"
// venue-booking tool — each row is painted as a band across the days/venue
// it occupies, instead of the day-by-day list on index.php.
//
// Morning/Afternoon/Evening bands: start_time/end_time are freeform (see
// index.php), so for painting they're bucketed into the same three bands
// the original tool used (<12:00 Morning, <18:00 Afternoon, else Evening).
// A blank time follows the pattern actually used by these venues: an
// arrival with no time is an evening slot, a departure with no time is a
// morning one (mday_slot below).
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
$admin = admin_require_role('super', 'zone');

$pdo = pastores_db();

const MDAY_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const MDAY_SLOT_ORDER = ['M' => 0, 'A' => 1, 'E' => 2];

function mday_slot(?string $time, bool $isStart): string
{
    if ($time === null || $time === '') {
        return $isStart ? 'E' : 'M';
    }
    $hour = (int) substr($time, 0, 2);
    if ($hour < 12) return 'M';
    if ($hour < 18) return 'A';
    return 'E';
}

// Which of the day's 3 slots (0=Morning,1=Afternoon,2=Evening) an entry
// occupies on $dayIso ('Y-m-d'). Mirrors activeSlots() in the original tool:
// a day strictly between start and end is fully occupied; the start day (and
// a same-day entry) runs from its start slot to the end of the day; the end
// day runs from the start of the day to its end slot.
function mday_active_slots(array $e, string $dayIso): array
{
    if ($dayIso < $e['start_date'] || $dayIso > $e['end_date']) {
        return [];
    }
    $isStart = $dayIso === $e['start_date'];
    $isEnd = $dayIso === $e['end_date'];
    if (!$isStart && !$isEnd) {
        return [0, 1, 2]; // strictly between start and end: fully occupied
    }
    if ($isEnd && !$isStart) {
        return range(0, MDAY_SLOT_ORDER[$e['_end_slot']]);
    }
    return range(MDAY_SLOT_ORDER[$e['_start_slot']], 2); // start day, or a same-day entry
}

// The single day+slot to print this entry's label at: the midpoint of its
// run (by day count), at the average of that day's occupied slots.
function mday_label_position(array $e): array
{
    $start = new DateTimeImmutable($e['start_date']);
    $end = new DateTimeImmutable($e['end_date']);
    $midIndex = intdiv((int) $start->diff($end)->days, 2);
    $midIso = $start->modify("+{$midIndex} days")->format('Y-m-d');
    $slots = mday_active_slots($e, $midIso);
    $slot = $slots ? (int) round((min($slots) + max($slots)) / 2) : 1;
    return [$midIso, $slot];
}

// Flags every slot where two entries at the same centre genuinely overlap in
// time, within the entries already loaded for this zone/year. Grouped by
// zone_id+centre (not centre name alone), since "All zones" can otherwise
// mix up two different zones that happen to reuse the same centre name —
// the venues just aren't labelled with their zone, to keep the display
// simple, since that collision doesn't currently happen in practice.
// Returns a set of "entryId|y-m-d|slotIdx" keys and the count of distinct
// entries involved.
function mday_find_clashes(array $entries): array
{
    $byCentre = [];
    foreach ($entries as $e) {
        if ($e['centre'] === null || $e['centre'] === '') continue;
        $byCentre[$e['zone_id'] . '|' . $e['centre']][] = $e;
    }
    $clashKeys = [];
    $clashedIds = [];
    foreach ($byCentre as $centreEntries) {
        $n = count($centreEntries);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $centreEntries[$i];
                $b = $centreEntries[$j];
                if ($a['end_date'] < $b['start_date'] || $b['end_date'] < $a['start_date']) continue;
                $lo = max($a['start_date'], $b['start_date']);
                $hi = min($a['end_date'], $b['end_date']);
                $d = new DateTimeImmutable($lo);
                $end = new DateTimeImmutable($hi);
                while ($d <= $end) {
                    $day = $d->format('Y-m-d');
                    $common = array_intersect(mday_active_slots($a, $day), mday_active_slots($b, $day));
                    foreach ($common as $s) {
                        $clashKeys[$a['id'] . '|' . $day . '|' . $s] = true;
                        $clashKeys[$b['id'] . '|' . $day . '|' . $s] = true;
                        $clashedIds[$a['id']] = true;
                        $clashedIds[$b['id']] = true;
                    }
                    $d = $d->modify('+1 day');
                }
            }
        }
    }
    return [$clashKeys, count($clashedIds)];
}

function mday_section_fill(?string $section): string
{
    $s = strtolower(trim((string) $section));
    if ($s === 'sf') return '#F3B6CB';
    if ($s === 'sv') return '#A9C8E8';
    return '#D5D0C8';
}

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

$where = ['m.start_date <= ?', 'm.end_date >= ?'];
$params = ["$year-12-31", "$year-01-01"];
if ($zoneId !== null) { $where[] = 'm.zone_id = ?'; $params[] = $zoneId; }
mday_in_clause('m.centre', $venueFilter, $where, $params);
mday_in_clause('m.activity', $activityFilter, $where, $params);
mday_in_clause('m.labor', $groupFilter, $where, $params);
mday_in_clause('m.section', $sectionFilter, $where, $params);

$stmt = $pdo->prepare('SELECT * FROM multiday_activities m WHERE ' . implode(' AND ', $where) . ' ORDER BY m.centre, m.start_date');
$stmt->execute($params);
$entries = $stmt->fetchAll();
foreach ($entries as &$e) {
    $e['_start_slot'] = mday_slot($e['start_time'], true);
    $e['_end_slot'] = mday_slot($e['end_time'], false);
}
unset($e);

// Filter option lists. Venue is scoped to the selected zone (or every
// centre, across zones, when viewing "All zones"); Activity/Group/Section
// come from the same lookup tables the CRUD form uses.
if ($zoneId !== null) {
    $venueOptStmt = $pdo->prepare('SELECT name FROM centres WHERE zone_id = ? ORDER BY name');
    $venueOptStmt->execute([$zoneId]);
} else {
    $venueOptStmt = $pdo->query('SELECT DISTINCT name FROM centres ORDER BY name');
}
$venueOptions = $venueOptStmt->fetchAll(PDO::FETCH_COLUMN);
$activityOptions = $pdo->query("SELECT name FROM activity_types WHERE is_multiday = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$sectionOptions = lookup_names($pdo, 'sections');
$groupOptions = lookup_names($pdo, 'labors');

// Venues to render, grouped by zone_id+centre internally (see
// mday_find_clashes above) but labelled by centre name alone.
$centreList = [];
$seenVenue = [];
foreach ($entries as $e) {
    if ($e['centre'] === null || $e['centre'] === '') continue;
    $key = $e['zone_id'] . '|' . $e['centre'];
    if (isset($seenVenue[$key])) continue;
    $seenVenue[$key] = true;
    $centreList[] = ['key' => $key, 'centre' => $e['centre'], 'label' => $e['centre']];
}
if (!$centreList && $venueFilter && $zoneId !== null) {
    // Show the specifically filtered venue(s) as an (empty) row even with no matches this year.
    foreach ($venueFilter as $v) {
        $centreList[] = ['key' => $zoneId . '|' . $v, 'centre' => $v, 'label' => $v];
    }
}
usort($centreList, fn($a, $b) => strcmp($a['label'], $b['label']));

$entriesByCentre = [];
foreach ($entries as $e) {
    if ($e['centre'] !== null && $e['centre'] !== '') $entriesByCentre[$e['zone_id'] . '|' . $e['centre']][] = $e;
}

[$clashKeys, $clashedCount] = mday_find_clashes($entries);

$labelPositions = []; // "$zoneId|$centre|$dayIso" => ['slot' => n, 'entry' => e]
foreach ($entries as $e) {
    [$midIso, $slot] = mday_label_position($e);
    $labelPositions[$e['zone_id'] . '|' . $e['centre'] . '|' . $midIso] = ['slot' => $slot, 'entry' => $e];
}

$today = date('Y-m-d');

$pageTitle = 'Multi-day Activities Calendar — Pastores Admin';
$pageWide = true;
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Multi-day Activities</h1>
<nav class="tabs">
  <a href="/admin/multiday_activities/index.php">List</a>
  <a class="active" href="/admin/multiday_activities/calendar.php">Calendar</a>
</nav>

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
  </form>
</div>

<div id="mday-results">
<?php if ($clashedCount > 0): ?>
<div class="flash error">
  ⚠ <strong><?= $clashedCount ?></strong> entr<?= $clashedCount === 1 ? 'y has' : 'ies have' ?> a scheduling clash this year —
  same centre, overlapping time. Flagged slots are outlined below.
</div>
<?php endif; ?>

<div class="legend" style="display:flex;gap:16px;align-items:center;margin-bottom:14px;font-size:12px;color:#555;">
  <span style="display:flex;gap:6px;align-items:center;"><span style="width:20px;height:12px;border-radius:2px;background:#F3B6CB;display:inline-block;"></span>sf — Women</span>
  <span style="display:flex;gap:6px;align-items:center;"><span style="width:20px;height:12px;border-radius:2px;background:#A9C8E8;display:inline-block;"></span>sv — Men</span>
  <span style="display:flex;gap:6px;align-items:center;"><span style="width:20px;height:12px;border-radius:2px;background:#D5D0C8;display:inline-block;"></span>Other/unset</span>
  <span>· Thick border = starts/ends · label = activity–group</span>
</div>

<style>
  .mday-scroll { overflow-x: auto; }
  .mday-grid { min-width: 900px; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; background: #fff; }
  .mday-month { border-bottom: 1px solid #ddd; }
  .mday-month:last-child { border-bottom: none; }
  .mday-month-header { background: none; color: #222; padding: 8px 12px 6px; font-size: 13px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
  .mday-month-header .mday-year { color: #666; font-weight: normal; font-size: 11px; }
  .mday-body { display: grid; grid-template-columns: 110px repeat(31, minmax(20px, 1fr)); border-top: 1px solid #ddd; }
  .mday-daynum { text-align: center; padding: 3px 1px; font-size: 9px; color: #999; border-right: 1px solid #eee; border-bottom: 1px solid #ddd; background: #f7f7f7; font-family: monospace; }
  .mday-daynum.weekend { background: #efeeea; color: #777; }
  .mday-daynum.today { background: #FFF3D6; color: #9B6B0A; font-weight: bold; }
  .mday-daynum.inactive { visibility: hidden; }
  .mday-venue { display: flex; align-items: center; padding: 0 10px; font-size: 10px; letter-spacing: .03em; text-transform: uppercase; font-weight: 600; border-right: 1px solid #ddd; border-bottom: 1px solid #ddd; background: #fff; min-height: 28px; }
  .mday-cell { border-right: 1px solid #eee; border-bottom: 1px solid #ddd; min-height: 28px; position: relative; }
  .mday-cell.inactive { background: #f7f7f7; opacity: .4; }
  .mday-cell.weekend { background: #fafaf7; }
  .mday-inner { display: flex; flex-direction: column; height: 100%; min-height: 28px; }
  .mday-slot { flex: 1; position: relative; }
  .mday-slot + .mday-slot { border-top: 1px solid rgba(255,255,255,.6); }
  .mday-slot.start { border-left: 2px solid #000; }
  .mday-slot.end { border-right: 2px solid #000; }
  /* Encircling outline: top edge on an activity's topmost slot of the day, bottom edge on its bottommost. */
  .mday-slot.ot { box-shadow: inset 0 2px 0 0 #000; }
  .mday-slot.ob { box-shadow: inset 0 -2px 0 0 #000; }
  .mday-slot.ot.ob { box-shadow: inset 0 2px 0 0 #000, inset 0 -2px 0 0 #000; }
  /* Short vertical line closing the gap where the outline's top/bottom edge changes height between two days. */
  .mday-slot.step::before { content: ''; position: absolute; left: -1px; width: 2px; background: #000; z-index: 2; top: 0; height: var(--step-h, 0); }
  /* Narrow gap between venue rows so painted bands read as separate venues. */
  .mday-gap { height: 6px; background: #f5f5f5; border: none; }
  .mday-gap.first { border-right: 1px solid #ddd; }
  .mday-slot.clash { outline: 2px dashed #C0392B; outline-offset: -2px; }
  .mday-slot.clash::after { content: '⚠'; position: absolute; top: 0; right: 0; font-size: 7px; color: #C0392B; }
  .mday-label { position: absolute; top: 50%; left: 3px; right: 2px; transform: translateY(-50%); font-size: 8px; font-weight: 700; line-height: 1.1; white-space: nowrap; overflow: visible; color: #000; z-index: 3; pointer-events: none; }

  .mday-ms { position: relative; min-width: 150px; }
  .mday-ms-trigger { border: 1px solid #ccc; border-radius: 4px; padding: 8px 10px; font-size: 13px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; user-select: none; }
  .mday-ms-trigger:hover { border-color: #999; }
  .mday-ms-panel { display: none; position: absolute; top: calc(100% + 4px); left: 0; z-index: 50; background: #fff; border: 1px solid #ccc; border-radius: 6px; box-shadow: 0 8px 24px rgba(0,0,0,.15); min-width: 200px; max-height: 260px; overflow-y: auto; padding: 6px; }
  .mday-ms-panel.open { display: block; }
  .mday-ms-option { display: flex; align-items: center; gap: 8px; padding: 5px 6px; border-radius: 4px; font-size: 12px; font-weight: normal; margin: 0; cursor: pointer; }
  .mday-ms-option:hover { background: #f5f5f5; }
  .mday-ms-option input { width: auto; margin: 0; }
  .mday-ms-actions { display: flex; gap: 8px; padding: 6px 6px 2px; border-top: 1px solid #eee; margin-top: 4px; }
  .mday-ms-actions button { font-size: 10px; text-transform: uppercase; letter-spacing: .03em; color: #666; background: none; border: none; cursor: pointer; padding: 2px 4px; }
  .mday-ms-actions button:hover { color: #222; }
</style>

<div class="mday-scroll">
<div class="mday-grid">
  <?php for ($m = 1; $m <= 12; $m++):
    $daysInM = (int) date('t', strtotime("$year-$m-01"));
  ?>
  <div class="mday-month">
    <div class="mday-month-header"><?= MDAY_MONTHS[$m - 1] ?> <span class="mday-year"><?= $year ?></span></div>
    <div class="mday-body">
      <div class="mday-daynum" style="background:#eee;"></div>
      <?php for ($d = 1; $d <= 31; $d++): ?>
        <?php if ($d > $daysInM): ?>
          <div class="mday-daynum inactive"></div>
        <?php else:
          $dayIso = sprintf('%04d-%02d-%02d', $year, $m, $d);
          $dow = (int) date('w', strtotime($dayIso));
          $isWeekend = $dow === 0 || $dow === 6;
        ?>
          <div class="mday-daynum<?= $isWeekend ? ' weekend' : '' ?><?= $dayIso === $today ? ' today' : '' ?>"><?= $d ?></div>
        <?php endif; ?>
      <?php endfor; ?>

      <?php foreach ($centreList as $vi => $venue): ?>
        <div class="mday-venue"><?= e($venue['label']) ?></div>
        <?php for ($d = 1; $d <= 31; $d++): ?>
          <?php if ($d > $daysInM): ?>
            <div class="mday-cell inactive"></div>
          <?php else:
            $dayIso = sprintf('%04d-%02d-%02d', $year, $m, $d);
            $dow = (int) date('w', strtotime($dayIso));
            $isWeekend = $dow === 0 || $dow === 6;
            $active = array_filter($entriesByCentre[$venue['key']] ?? [], fn($e) => $dayIso >= $e['start_date'] && $dayIso <= $e['end_date']);
            $label = $labelPositions[$venue['key'] . '|' . $dayIso] ?? null;
          ?>
            <div class="mday-cell<?= $isWeekend ? ' weekend' : '' ?>">
              <div class="mday-inner">
                <?php
                  $sf = [];
                  for ($si = 0; $si < 3; $si++) $sf[$si] = ['fill' => null, 'start' => false, 'end' => false, 'clash' => false, 'ot' => false, 'ob' => false, 'step' => 0];
                  foreach ($active as $e) {
                    $slots = mday_active_slots($e, $dayIso);
                    if (!$slots) continue;
                    $top = min($slots); $bottom = max($slots);
                    foreach ($slots as $si) {
                      $sf[$si]['fill'] = mday_section_fill($e['section']);
                      if ($dayIso === $e['start_date'] && $si === MDAY_SLOT_ORDER[$e['_start_slot']]) $sf[$si]['start'] = true;
                      if ($dayIso === $e['end_date'] && $si === MDAY_SLOT_ORDER[$e['_end_slot']]) $sf[$si]['end'] = true;
                      if (isset($clashKeys[$e['id'] . '|' . $dayIso . '|' . $si])) $sf[$si]['clash'] = true;
                      if ($si === $top) $sf[$si]['ot'] = true;
                      if ($si === $bottom) $sf[$si]['ob'] = true;
                    }
                    if ($dayIso > $e['start_date']) {
                      $prev = mday_active_slots($e, date('Y-m-d', strtotime("$dayIso -1 day")));
                      if ($prev) {
                        $pt = min($prev); $pb = max($prev);
                        if ($pt !== $top) { $lo = min($pt, $top); $hi = max($pt, $top); $sf[$lo]['step'] = max($sf[$lo]['step'], ($hi - $lo) * 100); }
                        if ($pb !== $bottom) { $lo = min($pb, $bottom); $hi = max($pb, $bottom); $k = min($lo + 1, 2); $sf[$k]['step'] = max($sf[$k]['step'], ($hi - $lo) * 100); }
                      }
                    }
                  }
                  for ($si = 0; $si < 3; $si++):
                    $f = $sf[$si];
                ?>
                  <div class="mday-slot<?= $f['start'] ? ' start' : '' ?><?= $f['end'] ? ' end' : '' ?><?= $f['clash'] ? ' clash' : '' ?><?= $f['ot'] ? ' ot' : '' ?><?= $f['ob'] ? ' ob' : '' ?><?= $f['step'] ? ' step' : '' ?>"
                       style="<?= $f['fill'] ? 'background:' . e($f['fill']) . ';' : '' ?><?= $f['step'] ? '--step-h:' . (int) $f['step'] . '%;' : '' ?>"
                       title="<?= e(implode(' · ', array_map(fn($e) => trim(($e['activity'] ?: '—') . '-' . ($e['labor'] ?: '') . ' (' . date('d/m', strtotime($e['start_date'])) . '–' . date('d/m', strtotime($e['end_date'])) . ')'), $active))) ?>">
                    <?php if ($label && $label['slot'] === $si): ?>
                      <div class="mday-label"><?= e(trim(($label['entry']['activity'] ?: '—') . '-' . ($label['entry']['labor'] ?: ''))) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endfor; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($vi !== array_key_last($centreList)): ?>
          <div class="mday-gap first"></div>
          <?php for ($d = 1; $d <= 31; $d++): ?><div class="mday-gap"></div><?php endfor; ?>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$centreList): ?>
        <div class="mday-venue" style="color:#888;">No entries</div>
        <?php for ($d = 1; $d <= 31; $d++): ?><div class="mday-cell<?= $d > $daysInM ? ' inactive' : '' ?>"></div><?php endfor; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endfor; ?>
</div>
</div>
</div><!-- #mday-results -->
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
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
