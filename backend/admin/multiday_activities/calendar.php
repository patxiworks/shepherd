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
// time, within the entries already loaded for this zone/year. Returns a set
// of "entryId|y-m-d|slotIdx" keys and the count of distinct entries involved.
function mday_find_clashes(array $entries): array
{
    $byCentre = [];
    foreach ($entries as $e) {
        if ($e['centre'] === null || $e['centre'] === '') continue;
        $byCentre[$e['centre']][] = $e;
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

// ── Zone / year / centre selection ──────────────────────────────────────
if ($admin['role'] === 'super') {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
    $zoneId = isset($_GET['zone']) ? (int) $_GET['zone'] : ($zones[0]['id'] ?? 0);
} else {
    $zones = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
    $zones->execute([$admin['zone_id']]);
    $zones = $zones->fetchAll();
    $zoneId = (int) $admin['zone_id'];
}
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$centreFilter = trim($_GET['centre'] ?? '');

$stmt = $pdo->prepare(
    'SELECT * FROM multiday_activities WHERE zone_id = ? AND start_date <= ? AND end_date >= ?' .
    ($centreFilter !== '' ? ' AND centre = ?' : '') . ' ORDER BY centre, start_date'
);
$params = [$zoneId, "$year-12-31", "$year-01-01"];
if ($centreFilter !== '') $params[] = $centreFilter;
$stmt->execute($params);
$entries = $stmt->fetchAll();
foreach ($entries as &$e) {
    $e['_start_slot'] = mday_slot($e['start_time'], true);
    $e['_end_slot'] = mday_slot($e['end_time'], false);
}
unset($e);

$allCentresInZone = $pdo->prepare('SELECT name FROM centres WHERE zone_id = ? ORDER BY name');
$allCentresInZone->execute([$zoneId]);
$allCentresInZone = $allCentresInZone->fetchAll(PDO::FETCH_COLUMN);

$centreList = $centreFilter !== '' ? [$centreFilter] : array_values(array_unique(array_column($entries, 'centre')));
sort($centreList);

$entriesByCentre = [];
foreach ($entries as $e) {
    if ($e['centre'] !== null && $e['centre'] !== '') $entriesByCentre[$e['centre']][] = $e;
}

[$clashKeys, $clashedCount] = mday_find_clashes($entries);

$labelPositions = []; // "$centre|$dayIso" => ['slot' => n, 'entry' => e]
foreach ($entries as $e) {
    [$midIso, $slot] = mday_label_position($e);
    $labelPositions[$e['centre'] . '|' . $midIso] = ['slot' => $slot, 'entry' => $e];
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
    <?php if ($admin['role'] === 'super'): ?>
    <div>
      <label for="zone">Zone</label>
      <select id="zone" name="zone" onchange="this.form.submit()">
        <?php foreach ($zones as $zone): ?>
          <option value="<?= (int) $zone['id'] ?>" <?= $zoneId === (int) $zone['id'] ? 'selected' : '' ?>><?= e($zone['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div>
      <label for="year">Year</label>
      <input type="number" id="year" name="year" value="<?= (int) $year ?>" min="2000" max="2100" style="width:90px;">
    </div>
    <div>
      <label for="centre">Centre / Venue</label>
      <select id="centre" name="centre">
        <option value="">All centres</option>
        <?php foreach ($allCentresInZone as $c): ?>
          <option value="<?= e($c) ?>" <?= $centreFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0;"><button type="submit">Apply</button></div>
  </form>
</div>

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
  .mday-month-header { background: #333; color: #fff; padding: 6px 12px; font-size: 12px; letter-spacing: .04em; text-transform: uppercase; }
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
  .mday-slot.clash { outline: 2px dashed #C0392B; outline-offset: -2px; }
  .mday-slot.clash::after { content: '⚠'; position: absolute; top: 0; right: 0; font-size: 7px; color: #C0392B; }
  .mday-label { position: absolute; top: 50%; left: 3px; right: 2px; transform: translateY(-50%); font-size: 8px; font-weight: 700; line-height: 1.1; white-space: nowrap; overflow: visible; color: #000; z-index: 3; pointer-events: none; }
</style>

<div class="mday-scroll">
<div class="mday-grid">
  <?php for ($m = 1; $m <= 12; $m++):
    $daysInM = (int) date('t', strtotime("$year-$m-01"));
  ?>
  <div class="mday-month">
    <div class="mday-month-header"><?= MDAY_MONTHS[$m - 1] ?> <span style="opacity:.5;"><?= $year ?></span></div>
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

      <?php foreach ($centreList as $centre): ?>
        <div class="mday-venue"><?= e($centre) ?></div>
        <?php for ($d = 1; $d <= 31; $d++): ?>
          <?php if ($d > $daysInM): ?>
            <div class="mday-cell inactive"></div>
          <?php else:
            $dayIso = sprintf('%04d-%02d-%02d', $year, $m, $d);
            $dow = (int) date('w', strtotime($dayIso));
            $isWeekend = $dow === 0 || $dow === 6;
            $active = array_filter($entriesByCentre[$centre] ?? [], fn($e) => $dayIso >= $e['start_date'] && $dayIso <= $e['end_date']);
            $label = $labelPositions[$centre . '|' . $dayIso] ?? null;
          ?>
            <div class="mday-cell<?= $isWeekend ? ' weekend' : '' ?>">
              <div class="mday-inner">
                <?php for ($si = 0; $si < 3; $si++):
                  $fill = null; $isStartSlot = false; $isEndSlot = false; $isClash = false;
                  foreach ($active as $e) {
                    $slots = mday_active_slots($e, $dayIso);
                    if (!in_array($si, $slots, true)) continue;
                    $fill = mday_section_fill($e['section']);
                    if ($dayIso === $e['start_date'] && $si === MDAY_SLOT_ORDER[$e['_start_slot']]) $isStartSlot = true;
                    if ($dayIso === $e['end_date'] && $si === MDAY_SLOT_ORDER[$e['_end_slot']]) $isEndSlot = true;
                    if (isset($clashKeys[$e['id'] . '|' . $dayIso . '|' . $si])) $isClash = true;
                  }
                ?>
                  <div class="mday-slot<?= $isStartSlot ? ' start' : '' ?><?= $isEndSlot ? ' end' : '' ?><?= $isClash ? ' clash' : '' ?>"
                       style="<?= $fill ? 'background:' . e($fill) . ';' : '' ?>"
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
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
