<?php
// Admin > Activities > Dashboard: statistics over the same `activities` rows the
// List tab shows, for one zone and a date range (the current month by default),
// with the Calendar tab's Zone / Centre / Activity / Group / Section / Priest
// filters. Modelled on the Multi-day Activities dashboard: stat cards, a row of
// "needs attention" counts (the List tab's flags) and horizontal bars.
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/absences.php';
require __DIR__ . '/../../includes/activity_duplicates.php';
require __DIR__ . '/../../includes/activity_bilocation.php';
require __DIR__ . '/../../includes/activity_mass_limit.php';
require __DIR__ . '/../../includes/activity_no_priest.php';
require __DIR__ . '/../../includes/multiday_filters.php';
require __DIR__ . '/../includes/dash.php';
$admin = admin_require_role('super', 'zone', 'centre');

$pdo = pastores_db();
mass_limit($pdo); // load the max-masses-per-day setting
$scopeCentreName = $admin['role'] === 'centre' ? admin_centre_name($pdo, $admin) : null;
$weekStart = get_week_start($pdo);

const ADASH_MAX_DAYS = 731;  // longest range accepted
const ADASH_TOP_PRIESTS = 15;

// Minutes since midnight of a 'HH:MM[:SS]' time, or null.
function adash_minutes(?string $t): ?int
{
    return $t ? (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2) : null;
}

// How long an activity runs, in minutes: to_time - from_time, else its `duration`, else 0.
function adash_length(array $a): int
{
    $from = adash_minutes($a['from_time']);
    $to = adash_minutes($a['to_time']);
    if ($from !== null && $to !== null && $to > $from) {
        return $to - $from;
    }
    return adash_minutes($a['duration']) ?? 0;
}

// ── Zone / range / filters (same rules as the Calendar tab) ───────────────
if ($admin['role'] === 'super') {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
    $zoneId = (int) ($_GET['zone'] ?? 0);
    if (!in_array($zoneId, array_map(fn($z) => (int) $z['id'], $zones), true)) {
        $zoneId = (int) ($zones[0]['id'] ?? 0);
    }
} else {
    $zones = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
    $zones->execute([$admin['zone_id']]);
    $zones = $zones->fetchAll();
    $zoneId = (int) $admin['zone_id'];
}
$parseDate = function (string $v, DateTimeImmutable $default): DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v && (int) $d->format('Y') >= 2000 && (int) $d->format('Y') <= 2100 ? $d : $default;
};
$from = $parseDate((string) ($_GET['from'] ?? ''), new DateTimeImmutable('first day of this month'));
$to = $parseDate((string) ($_GET['to'] ?? ''), new DateTimeImmutable('last day of this month'));
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
if ((int) $from->diff($to)->days + 1 > ADASH_MAX_DAYS) {
    $to = $from->modify('+' . (ADASH_MAX_DAYS - 1) . ' days');
}
$daysInRange = (int) $from->diff($to)->days + 1;

$centreFilter = mday_ms_param('centre');
$sectionFilter = mday_ms_param('section');
$groupFilter = mday_ms_param('labor');
$activityFilter = mday_ms_param('activity');
$priestFilter = mday_ms_param('priest');

$where = ['a.zone_id = ?', 'a.activity_date BETWEEN ? AND ?'];
$params = [$zoneId, $from->format('Y-m-d'), $to->format('Y-m-d')];
if ($admin['role'] === 'centre') {
    $where[] = 'a.centre = ?';
    $params[] = $scopeCentreName;
} else {
    mday_in_clause('a.centre', $centreFilter, $where, $params);
}
mday_in_clause('a.section', $sectionFilter, $where, $params);
mday_in_clause('a.labor', $groupFilter, $where, $params);
mday_in_clause('a.activity', $activityFilter, $where, $params);
mday_in_clause('a.priest', $priestFilter, $where, $params);
$whereSql = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT a.* FROM activities a WHERE $whereSql ORDER BY a.activity_date, a.from_time");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Filter option lists.
$centreOptions = [];
if ($admin['role'] !== 'centre') {
    $centreStmt = $pdo->prepare('SELECT name FROM centres WHERE zone_id = ? ORDER BY name');
    $centreStmt->execute([$zoneId]);
    $centreOptions = $centreStmt->fetchAll(PDO::FETCH_COLUMN);
}
$sectionOptions = lookup_names($pdo, 'sections');
$groupOptions = lookup_names($pdo, 'labors');
$activityOptions = lookup_names($pdo, 'activity_types');
$priestOptions = array_values(array_unique(array_column(priests_by_zone($pdo, $zoneId), 'name')));

// ── Statistics ────────────────────────────────────────────────────────────
$total = count($rows);
$totalMinutes = 0;
$byCentre = $byActivity = $byGroup = $byPriest = [];
$priestMinutes = [];
$priestCentre = []; // priest => [centre => count]
$priestSection = []; // priest => ['sf' => n, 'sv' => n, 'other' => n]
$bySection = ['sf' => 0, 'sv' => 0, 'other' => 0];
$byWeekday = array_fill(1, 7, 0);   // ISO: 1 = Monday
$byHour = [];
$activeDays = [];
$untimed = 0;
$byPeriod = []; // week-start date (or month) => count
$monthly = $daysInRange > 120;
foreach ($rows as $a) {
    $mins = adash_length($a);
    $totalMinutes += $mins;
    $c = $a['centre'] !== null && $a['centre'] !== '' ? $a['centre'] : '—';
    $byCentre[$c] = ($byCentre[$c] ?? 0) + 1;
    $act = $a['activity'] !== null && $a['activity'] !== '' ? $a['activity'] : '—';
    $byActivity[$act] = ($byActivity[$act] ?? 0) + 1;
    if ($a['labor'] !== null && $a['labor'] !== '') $byGroup[$a['labor']] = ($byGroup[$a['labor']] ?? 0) + 1;
    if ($a['priest'] !== null && $a['priest'] !== '') {
        $byPriest[$a['priest']] = ($byPriest[$a['priest']] ?? 0) + 1;
        $priestMinutes[$a['priest']] = ($priestMinutes[$a['priest']] ?? 0) + $mins;
    }
    $s = strtolower(trim((string) $a['section']));
    $sec = $s === 'sf' || $s === 'sv' ? $s : 'other';
    $bySection[$sec]++;
    if ($a['priest'] !== null && $a['priest'] !== '') {
        $priestCentre[$a['priest']][$c] = ($priestCentre[$a['priest']][$c] ?? 0) + 1;
        $priestSection[$a['priest']][$sec] = ($priestSection[$a['priest']][$sec] ?? 0) + 1;
    }
    $date = new DateTimeImmutable($a['activity_date']);
    $byWeekday[(int) $date->format('N')]++;
    $activeDays[$a['activity_date']] = true;
    $h = adash_minutes($a['from_time']);
    if ($h === null) {
        $untimed++;
    } else {
        $byHour[intdiv($h, 60)] = ($byHour[intdiv($h, 60)] ?? 0) + 1;
    }
    if ($monthly) {
        $key = $date->format('Y-m');
    } else {
        $lead = $weekStart === 'monday' ? ((int) $date->format('w') + 6) % 7 : (int) $date->format('w');
        $key = $date->modify("-$lead days")->format('Y-m-d');
    }
    $byPeriod[$key] = ($byPeriod[$key] ?? 0) + 1;
}
ksort($byPeriod);
ksort($byHour);
dash_sort($byCentre);
dash_sort($byActivity);
dash_sort($byGroup);
dash_sort($byPriest);

// "Needs attention" counts: the List tab's flags, over the same rows as everything above.
$flags = [
    'no_priest' => ['No priest', activity_priest_missing_sql()],
    'absent' => ['Priest absent', absences_available($pdo) ? activity_absent_sql() : null],
    'bilocation' => ['Priest bilocation', activity_has_bilocation_sql()],
    'masses' => ['Over mass limit', activity_over_mass_limit_sql()],
    'duplicate' => ['Duplicates', activity_has_duplicate_sql()],
];
$flagCounts = [];
foreach ($flags as $key => [$label, $sql]) {
    if ($sql === null) continue; // e.g. the absences table isn't migrated yet
    $q = $pdo->prepare("SELECT COUNT(*) FROM activities a WHERE $whereSql AND $sql");
    $q->execute($params);
    $flagCounts[$key] = [$label, (int) $q->fetchColumn()];
}
$listUrl = fn(string $flag) => '/admin/activities/index.php?' . http_build_query(
    ($admin['role'] === 'super' ? ['zone' => $zoneId] : []) + ['date_from' => $from->format('Y-m-d'), 'date_to' => $to->format('Y-m-d'), $flag => '1']
);

$presetUrl = function (DateTimeImmutable $f, DateTimeImmutable $t) {
    return '/admin/activities/dashboard.php?' . http_build_query(['from' => $f->format('Y-m-d'), 'to' => $t->format('Y-m-d')] + array_diff_key($_GET, ['from' => 1, 'to' => 1]));
};
$thisMonth = new DateTimeImmutable('first day of this month');
$presets = [
    'This month' => [$thisMonth, $thisMonth->modify('last day of this month')],
    'Last month' => [$thisMonth->modify('-1 month'), $thisMonth->modify('-1 day')],
    'Next month' => [$thisMonth->modify('+1 month'), $thisMonth->modify('+1 month')->modify('last day of this month')],
    'This year' => [new DateTimeImmutable('first day of january this year'), new DateTimeImmutable('last day of december this year')],
];
$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$fmtHours = fn(int $m) => number_format($m / 60, 1) . ' h';

$pageTitle = 'Activities Dashboard — Pastores Admin';
$pageWide = true;
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Activities</h1>
<nav class="tabs">
  <a href="/admin/activities/index.php<?= $zoneId ? '?zone=' . $zoneId : '' ?>">List</a>
  <a href="/admin/activities/calendar.php<?= $zoneId ? '?zone=' . $zoneId : '' ?>">Calendar</a>
  <a class="active" href="/admin/activities/dashboard.php">Dashboard</a>
</nav>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div>
      <label for="from">From</label>
      <input type="date" id="from" name="from" value="<?= e($from->format('Y-m-d')) ?>" style="width:150px;">
    </div>
    <div>
      <label for="to">To</label>
      <input type="date" id="to" name="to" value="<?= e($to->format('Y-m-d')) ?>" style="width:150px;">
    </div>
    <?php if ($admin['role'] === 'super'): ?>
    <div>
      <label for="zone">Zone</label>
      <select id="zone" name="zone" onchange="this.form.querySelectorAll('input[name=\'centre[]\'], input[name=\'priest[]\']').forEach(function (c) { c.checked = false; }); this.form.submit();">
        <?php foreach ($zones as $zone): ?>
          <option value="<?= (int) $zone['id'] ?>" <?= $zoneId === (int) $zone['id'] ? 'selected' : '' ?>><?= e($zone['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($admin['role'] !== 'centre'): ?>
    <div>
      <label>Centre</label>
      <?php mday_render_multiselect('centre', 'centres', $centreOptions, $centreFilter); ?>
    </div>
    <?php endif; ?>
    <div>
      <label>Activity</label>
      <?php mday_render_multiselect('activity', 'activities', $activityOptions, $activityFilter); ?>
    </div>
    <div>
      <label>Group</label>
      <?php mday_render_multiselect('labor', 'groups', $groupOptions, $groupFilter); ?>
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
  <div style="margin-top:10px;font-size:12px;">
    Quick range:
    <?php foreach ($presets as $label => [$pf, $pt]): ?>
      <a href="<?= e($presetUrl($pf, $pt)) ?>" style="margin-right:10px;"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php dash_css(); ?>

<div id="mday-results">
<div class="dash-notice">
  ⓘ &nbsp;Figures cover <?= e($from->format('j M Y')) ?> – <?= e($to->format('j M Y')) ?> (<?= $daysInRange ?> day<?= $daysInRange === 1 ? '' : 's' ?>) and the active filters.
  Hours are each activity's end time minus start time (or its duration when there is no end time).
</div>

<div class="dash-grid">
  <?php
  dash_stat('Total Activities', $total, 'in current filter');
  // "of N in the zone" counts the zone's lists; activities can still hold a name that isn't (any longer) on the list.
  $unlistedCentres = count(array_diff(array_keys($byCentre), $centreOptions, ['—']));
  $unlistedPriests = count(array_diff(array_keys($byPriest), $priestOptions));
  dash_stat('Centres Active', count($byCentre), $admin['role'] === 'centre' ? '' : count($centreOptions) . ' in the zone' . ($unlistedCentres ? " · $unlistedCentres not in the Centres list" : ''));
  dash_stat('Priests Involved', count($byPriest), count($priestOptions) . ' in the zone' . ($unlistedPriests ? " · $unlistedPriests not in the Priests list" : ''));
  dash_stat('Days With Activity', count($activeDays), 'of ' . $daysInRange . ' in range');
  dash_stat('Scheduled Time', $fmtHours($totalMinutes), 'sum of activity lengths');
  dash_stat('Avg. per Active Day', count($activeDays) ? number_format($total / count($activeDays), 1) : '0.0', 'activities');
  ?>
</div>

<?php if ($flagCounts): ?>
<div class="dash-grid">
  <?php foreach ($flagCounts as $key => [$label, $count]):
    dash_stat($label, $count, $count ? 'open in the List tab' : 'none', $count ? $listUrl($key) : null, $count ? 'color:#c62828;' : '');
  endforeach; ?>
</div>
<?php endif; ?>

<div class="dash-two-col">
  <div class="card dash-section">
    <h2>By Centre — Activities</h2>
    <?php $max = $byCentre ? max($byCentre) : 1;
    foreach ($byCentre as $name => $n) dash_bar((string) $name, $n / $max * 100, $n . ' · ' . dash_pct($n, $total) . '%', 'var(--brand)');
    if (!$byCentre) dash_empty(); ?>
  </div>
  <div class="card dash-section">
    <h2>By Activity</h2>
    <?php $max = $byActivity ? max($byActivity) : 1;
    foreach ($byActivity as $name => $n) dash_bar((string) $name, $n / $max * 100, $n . ' · ' . dash_pct($n, $total) . '%', '#2D6A8F');
    if (!$byActivity) dash_empty(); ?>
  </div>
</div>

<div class="dash-two-col">
  <div class="card dash-section">
    <h2>By Priest — Activities and Hours</h2>
    <?php $max = $byPriest ? max($byPriest) : 1;
    foreach (array_slice($byPriest, 0, ADASH_TOP_PRIESTS, true) as $name => $n) dash_bar((string) $name, $n / $max * 100, $n . ' · ' . $fmtHours($priestMinutes[$name] ?? 0), '#222');
    if (!$byPriest) dash_empty(); ?>
    <?php if (count($byPriest) > ADASH_TOP_PRIESTS): ?><div class="dash-footnote">Top <?= ADASH_TOP_PRIESTS ?> of <?= count($byPriest) ?> priests.</div><?php endif; ?>
    <?php if ($flagCounts['no_priest'][1] ?? 0): ?><div class="dash-footnote"><?= (int) $flagCounts['no_priest'][1] ?> activit<?= $flagCounts['no_priest'][1] === 1 ? 'y has' : 'ies have' ?> no priest assigned.</div><?php endif; ?>
  </div>
  <div class="card dash-section">
    <h2>By Group</h2>
    <?php $max = $byGroup ? max($byGroup) : 1;
    foreach ($byGroup as $name => $n) dash_bar((string) $name, $n / $max * 100, $n . ' · ' . dash_pct($n, $total) . '%', '#222');
    if (!$byGroup) dash_empty(); ?>
  </div>
</div>

<div class="dash-two-col">
  <div class="card dash-section">
    <h2>By Day of the Week</h2>
    <?php $max = max($byWeekday) ?: 1;
    foreach ($weekStart === 'monday' ? [1, 2, 3, 4, 5, 6, 7] : [7, 1, 2, 3, 4, 5, 6] as $n) {
        dash_bar($dayNames[$n - 1], $byWeekday[$n] / $max * 100, $byWeekday[$n] . ' · ' . dash_pct($byWeekday[$n], $total) . '%', '#222');
    } ?>
  </div>
  <div class="card dash-section">
    <h2>By Section</h2>
    <?php
    // A donut, not a bar: this is a share-of-the-whole question with only 3, usually
    // clearly-uneven, slices — the case a donut actually reads well (dataviz skill).
    // Colours are the section accents used elsewhere (Calendar tab, legend), darkened
    // enough here to stay distinguishable as chart fills, not just pastel swatches.
    $sectionDonut = [
        ['label' => 'sf — Women', 'value' => $bySection['sf'], 'colour' => '#D6588A'],
        ['label' => 'sv — Men', 'value' => $bySection['sv'], 'colour' => '#4F86C0'],
        ['label' => 'Other/unset', 'value' => $bySection['other'], 'colour' => '#5C574E'],
    ];
    dash_donut($sectionDonut, (string) $total, 'activities');
    ?>
  </div>
</div>

<div class="card dash-section">
  <h2>Load Over Time — Activities per <?= $monthly ? 'Month' : 'Week' ?></h2>
  <?php $max = $byPeriod ? max($byPeriod) : 1;
  foreach ($byPeriod as $key => $n) {
      $label = $monthly ? date('M Y', strtotime("$key-01")) : 'Week of ' . date('j M', strtotime($key));
      dash_bar($label, $n / $max * 100, (string) $n, 'var(--brand)');
  }
  if (!$byPeriod) dash_empty(); ?>
</div>

<div class="card dash-section">
  <h2>Start Time — Activities Beginning in Each Hour</h2>
  <?php $max = $byHour ? max($byHour) : 1;
  foreach ($byHour as $h => $n) dash_bar(sprintf('%02d:00', $h), $n / $max * 100, $n . ' · ' . dash_pct($n, $total) . '%', '#2D6A8F');
  if (!$byHour) dash_empty(); ?>
  <?php if ($untimed): ?><div class="dash-footnote"><?= $untimed ?> activit<?= $untimed === 1 ? 'y has' : 'ies have' ?> no start time.</div><?php endif; ?>
</div>
</div><!-- #mday-results -->
<?php mday_render_filter_assets(); ?>
<script>
// Changing either date reloads the figures live, like the other filters (the shared script handles the submit).
['from', 'to'].forEach(function (id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('change', function () { if (el.value) el.form.requestSubmit(); });
});
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
