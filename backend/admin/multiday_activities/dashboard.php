<?php
// Admin > Multi-day Activities > Dashboard: utilisation statistics over the
// multiday_activities rows (the List tab's data), ported from the dashboard
// tab of the standalone "Painted Calendar" tool. Year and the Zone / Venue /
// Activity / Group / Section filters work exactly as on the Calendar tab.
//
// Counting rules follow the original: a "day" is a calendar day, an entry
// counts on each day it runs within the selected year (an entry spanning
// New Year is clipped), and a venue's booked days are the union of its
// entries' days, so overlapping entries never count a day twice.
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/multiday_filters.php';
require __DIR__ . '/../includes/dash.php';
$admin = admin_require_role('super');

$pdo = pastores_db();

const MDAY_DASH_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
// Venue colours from the original tool, assigned by a stable hash of the name.
const MDAY_DASH_PALETTE = ['#C84B31', '#1A7F5E', '#B8860B', '#2D6A8F', '#6B4FA0', '#A0526D', '#4A7A3D', '#B5651D'];

$f = mday_view_filters($pdo, $admin);
['zones' => $zones, 'zoneId' => $zoneId, 'year' => $year, 'venueFilter' => $venueFilter, 'activityFilter' => $activityFilter, 'groupFilter' => $groupFilter, 'sectionFilter' => $sectionFilter, 'priestFilter' => $priestFilter, 'where' => $where, 'params' => $params] = $f;
$opts = mday_filter_options($pdo, $zoneId);

$stmt = $pdo->prepare('SELECT * FROM multiday_activities m WHERE ' . implode(' AND ', $where));
$stmt->execute($params);
$entries = $stmt->fetchAll();

$yearStart = new DateTimeImmutable("$year-01-01");
$yearEnd = new DateTimeImmutable("$year-12-31");
$daysInYear = (int) $yearStart->diff($yearEnd)->days + 1;

$totalDays = 0;
$venueDays = [];   // "zone|centre" => set of 'Y-m-d'
$venueLabel = [];
$groupDays = [];
$activityDays = [];
$activityCount = [];
$priestDays = [];
$priestCount = [];
$sectionDays = ['sf' => 0, 'sv' => 0, 'other' => 0];
$sectionCount = ['sf' => 0, 'sv' => 0, 'other' => 0];
$monthDays = array_fill(0, 12, 0);
$unassigned = 0;
$priests = [];
foreach ($entries as $e) {
    $s = max($e['start_date'], $yearStart->format('Y-m-d'));
    $en = min($e['end_date'], $yearEnd->format('Y-m-d'));
    if ($en < $s) continue;
    $n = (int) (new DateTimeImmutable($s))->diff(new DateTimeImmutable($en))->days + 1;
    $totalDays += $n;

    $centre = (string) $e['centre'];
    $key = $e['zone_id'] . '|' . $centre;
    $venueLabel[$key] = $centre !== '' ? $centre : '—';
    $d = new DateTimeImmutable($s);
    $last = new DateTimeImmutable($en);
    while ($d <= $last) {
        $venueDays[$key][$d->format('Y-m-d')] = true;
        $monthDays[(int) $d->format('n') - 1]++;
        $d = $d->modify('+1 day');
    }

    if ($e['labor'] !== null && $e['labor'] !== '') $groupDays[$e['labor']] = ($groupDays[$e['labor']] ?? 0) + $n;

    $act = $e['activity'] !== null && $e['activity'] !== '' ? $e['activity'] : '—';
    $activityDays[$act] = ($activityDays[$act] ?? 0) + $n;
    $activityCount[$act] = ($activityCount[$act] ?? 0) + 1;

    $sec = strtolower(trim((string) $e['section']));
    $sec = $sec === 'sf' || $sec === 'sv' ? $sec : 'other';
    $sectionDays[$sec] += $n;
    $sectionCount[$sec]++;

    if ($e['priest'] !== null && $e['priest'] !== '') {
        $priestDays[$e['priest']] = ($priestDays[$e['priest']] ?? 0) + $n;
        $priestCount[$e['priest']] = ($priestCount[$e['priest']] ?? 0) + 1;
    } else {
        $unassigned++;
    }
}
$totalEntries = count($entries);
$venuesUsed = count($venueDays);
$groupsUsed = count($groupDays);
$priestsUsed = count($priestDays);
$avgLength = $totalEntries ? $totalDays / $totalEntries : 0;

dash_sort($groupDays);
dash_sort($activityDays);
dash_sort($priestDays);
$venueCounts = array_map('count', $venueDays);
dash_sort($venueCounts);

$pageTitle = 'Multi-day Activities Dashboard — Pastores Admin';
$pageWide = true;
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Multi-day Activities</h1>
<nav class="tabs">
  <a href="/admin/multiday_activities/index.php">List</a>
  <a href="/admin/multiday_activities/calendar.php">Calendar</a>
  <a class="active" href="/admin/multiday_activities/dashboard.php">Dashboard</a>
</nav>

<?php mday_render_filter_form($admin, $f, $opts); ?>

<?php dash_css(); ?>

<div id="mday-results">
<div class="dash-notice">
  ⓘ &nbsp;Figures reflect the selected Year and active filters. Days are counted once per venue even when entries overlap;
  an entry running past 31 December (or before 1 January) only counts its days inside <?= (int) $year ?>.
</div>

<div class="dash-grid">
  <div class="dash-stat"><div class="dash-stat-label">Total Entries</div><div class="dash-stat-value"><?= $totalEntries ?></div><div class="dash-stat-sub">in current filter</div></div>
  <div class="dash-stat"><div class="dash-stat-label">Venues Active</div><div class="dash-stat-value"><?= $venuesUsed ?></div><div class="dash-stat-sub">of <?= count($opts['venueOptions']) ?> total</div></div>
  <div class="dash-stat"><div class="dash-stat-label">Groups Active</div><div class="dash-stat-value"><?= $groupsUsed ?></div><div class="dash-stat-sub">of <?= count($opts['groupOptions']) ?> total</div></div>
  <div class="dash-stat"><div class="dash-stat-label">Priests in Charge</div><div class="dash-stat-value"><?= $priestsUsed ?></div><div class="dash-stat-sub"><?= $unassigned ?> entr<?= $unassigned === 1 ? 'y' : 'ies' ?> with no priest set</div></div>
  <div class="dash-stat"><div class="dash-stat-label">Activity-Days</div><div class="dash-stat-value"><?= $totalDays ?></div><div class="dash-stat-sub">sum across all bookings in <?= (int) $year ?></div></div>
  <div class="dash-stat"><div class="dash-stat-label">Avg. Length</div><div class="dash-stat-value"><?= number_format($avgLength, 1) ?></div><div class="dash-stat-sub">days per entry</div></div>
</div>

<div class="dash-two-col">
  <div class="card dash-section">
    <h2>Venue Utilisation — Days Booked / Days in Year</h2>
    <?php foreach ($venueCounts as $key => $days):
        $colour = MDAY_DASH_PALETTE[crc32($venueLabel[$key]) % count(MDAY_DASH_PALETTE)];
        dash_bar($venueLabel[$key], $days / $daysInYear * 100, $days . 'd · ' . dash_pct($days, $daysInYear) . '%', $colour, true);
    endforeach; ?>
    <?php if (!$venueCounts) dash_empty(); ?>
  </div>
  <div class="card dash-section">
    <h2>Group Utilisation — Share of Booked Activity-Days</h2>
    <?php $maxGroup = $groupDays ? max($groupDays) : 1;
    foreach ($groupDays as $g => $days):
        dash_bar((string) $g, $days / $maxGroup * 100, $days . 'd · ' . dash_pct($days, $totalDays) . '%', '#222');
    endforeach; ?>
    <?php if (!$groupDays) dash_empty(); ?>
  </div>
</div>

<div class="card dash-section">
  <h2>Monthly Load — Activity-Days by Month</h2>
  <?php $maxMonth = max($monthDays) ?: 1;
  foreach (MDAY_DASH_MONTHS as $i => $m):
      dash_bar($m, $monthDays[$i] / $maxMonth * 100, $monthDays[$i] . ' act-days', '#222');
  endforeach; ?>
</div>

<div class="dash-two-col">
  <div class="card dash-section">
    <h2>By Activity — Activity-Days and Entries</h2>
    <?php $maxAct = $activityDays ? max($activityDays) : 1;
    foreach ($activityDays as $a => $days):
        dash_bar((string) $a, $days / $maxAct * 100, $days . 'd · ' . $activityCount[$a] . ' entr' . ($activityCount[$a] === 1 ? 'y' : 'ies'), '#673AB7');
    endforeach; ?>
    <?php if (!$activityDays) dash_empty(); ?>
  </div>
  <div class="card dash-section">
    <h2>By Section — Activity-Days and Entries</h2>
    <?php $sections = ['sf' => ['sf — Women', '#F3B6CB'], 'sv' => ['sv — Men', '#A9C8E8'], 'other' => ['Other/unset', '#D5D0C8']];
    $maxSec = max($sectionDays) ?: 1;
    foreach ($sections as $k => [$name, $colour]):
        dash_bar($name, $sectionDays[$k] / $maxSec * 100, $sectionDays[$k] . 'd · ' . $sectionCount[$k] . ' entr' . ($sectionCount[$k] === 1 ? 'y' : 'ies'), $colour, true);
    endforeach; ?>
    <h2 style="margin-top:22px;">By Priest in Charge — Activity-Days and Entries</h2>
    <?php $maxPriest = $priestDays ? max($priestDays) : 1;
    foreach ($priestDays as $p => $days):
        dash_bar((string) $p, $days / $maxPriest * 100, $days . 'd · ' . $priestCount[$p] . ' entr' . ($priestCount[$p] === 1 ? 'y' : 'ies'), '#2D6A8F');
    endforeach; ?>
    <?php if (!$priestDays) dash_empty(); ?>
  </div>
</div>
</div><!-- #mday-results -->
<?php mday_render_filter_assets(); ?>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
