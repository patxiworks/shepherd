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
require __DIR__ . '/../../includes/multiday_filters.php';
$admin = admin_require_role('super');

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

// Tooltip body for one entry (shown on hover over any day it occupies), like
// the original tool: venue and activity, priest in charge, group/section, date range, description.
function mday_tip_html(array $e): string
{
    $when = function (string $date, ?string $time, string $slot): string {
        $d = date('j M Y', strtotime($date));
        if ($time !== null && $time !== '') return $d . ' ' . substr($time, 0, 5);
        return $d . ' (' . ($slot === 'M' ? 'morning' : ($slot === 'A' ? 'afternoon' : 'evening')) . ')';
    };
    $html = '<span class="mday-tip-venue">' . e($e['centre']) . '</span> · <span class="mday-tip-tag">' . e($e['activity'] ?: '—') . '</span>';
    if ($e['priest'] !== null && $e['priest'] !== '') {
        $html .= '<span class="mday-tip-line">Priest: ' . e($e['priest']) . '</span>';
    }
    $html .= '<span class="mday-tip-line">Grp: ' . e($e['labor'] ?: '—') . ' · Sec: ' . e($e['section'] ?: '—') . '</span>';
    $html .= '<span class="mday-tip-line">' . e($when($e['start_date'], $e['start_time'], $e['_start_slot'])) . ' → ' . e($when($e['end_date'], $e['end_time'], $e['_end_slot'])) . '</span>';
    if ($e['description'] !== null && $e['description'] !== '') {
        $html .= '<span class="mday-tip-line">' . e($e['description']) . '</span>';
    }
    return $html;
}

function mday_section_fill(?string $section): string
{
    $s = strtolower(trim((string) $section));
    if ($s === 'sf') return '#F3B6CB';
    if ($s === 'sv') return '#A9C8E8';
    return '#D5D0C8';
}

$f = mday_view_filters($pdo, $admin);
['zones' => $zones, 'zoneId' => $zoneId, 'year' => $year, 'venueFilter' => $venueFilter, 'activityFilter' => $activityFilter, 'groupFilter' => $groupFilter, 'sectionFilter' => $sectionFilter, 'priestFilter' => $priestFilter, 'where' => $where, 'params' => $params] = $f;

$stmt = $pdo->prepare('SELECT * FROM multiday_activities m WHERE ' . implode(' AND ', $where) . ' ORDER BY m.centre, m.start_date');
$stmt->execute($params);
$entries = $stmt->fetchAll();
foreach ($entries as &$e) {
    $e['_start_slot'] = mday_slot($e['start_time'], true);
    $e['_end_slot'] = mday_slot($e['end_time'], false);
}
unset($e);

$opts = mday_filter_options($pdo, $zoneId);

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
  <a href="/admin/multiday_activities/dashboard.php">Dashboard</a>
</nav>

<?php mday_render_filter_form($admin, $f, $opts); ?>

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
  .mday-grid { min-width: 900px; }
  /* Each month is its own bold-bordered block with a gap below, so months read as clearly separate. */
  .mday-month { border: 2px solid #222; border-radius: 6px; overflow: hidden; background: #fff; margin-bottom: 18px; }
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

  .mday-tooltip { position: fixed; z-index: 200; display: none; background: #1C1A17; color: #fff; padding: 8px 10px; border-radius: 4px; font-size: 11px; line-height: 1.6; max-width: 320px; min-width: 170px; pointer-events: none; box-shadow: 0 4px 12px rgba(0,0,0,.25); }
  .mday-tooltip hr { border: none; border-top: 1px solid #444; margin: 4px 0; }
  .mday-tip-venue { font-weight: 600; letter-spacing: .03em; }
  .mday-tip-tag { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; background: #F7F6F2; color: #555; }
  .mday-tip-line { display: block; }

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
                       style="<?= $f['fill'] ? 'background:' . e($f['fill']) . ';' : '' ?><?= $f['step'] ? '--step-h:' . (int) $f['step'] . '%;' : '' ?>">
                    <?php if ($label && $label['slot'] === $si): ?>
                      <div class="mday-label"><?= e(trim(($label['entry']['activity'] ?: '—') . '-' . ($label['entry']['labor'] ?: ''))) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endfor; ?>
              </div>
              <?php if ($active): ?>
                <div class="mday-tip" hidden><?= implode('<hr>', array_map('mday_tip_html', $active)) ?></div>
              <?php endif; ?>
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
<?php mday_render_filter_assets(); ?>
<script>
(function () {
  // Hover tooltip (venue, activity, group/section, dates, description) for any
  // day cell with activity. One fixed-position element, so it is never clipped
  // by the scrolling grid, and delegated, so it survives the live refresh.
  var tip = document.createElement('div');
  tip.className = 'mday-tooltip';
  document.body.appendChild(tip);
  var tipCell = null;
  function hideTip() { tip.style.display = 'none'; tipCell = null; }
  document.addEventListener('mouseover', function (ev) {
    var cell = ev.target.closest ? ev.target.closest('.mday-cell') : null;
    var src = cell && cell.querySelector('.mday-tip');
    if (!src) { hideTip(); return; }
    if (cell === tipCell) return;
    tipCell = cell;
    tip.innerHTML = src.innerHTML;
    tip.style.display = 'block';
    var r = cell.getBoundingClientRect();
    var left = Math.min(r.left, window.innerWidth - tip.offsetWidth - 8);
    var top = r.bottom + 4;
    if (top + tip.offsetHeight > window.innerHeight - 8) top = r.top - tip.offsetHeight - 4;
    tip.style.left = Math.max(8, left) + 'px';
    tip.style.top = Math.max(8, top) + 'px';
  });
  window.addEventListener('scroll', hideTip, true);
})();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
