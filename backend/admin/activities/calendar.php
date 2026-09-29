<?php
// Admin > Activities > Calendar: the same `activities` rows the List tab shows
// (one zone at a time, like the list) as a Month grid, a Week grid or a Day
// agenda. Each activity is a chip coloured by section; hovering shows the details and
// clicking opens the activity in the List tab's editor. The filter card and its
// live refresh are shared with the Multi-day Activities calendar
// (includes/multiday_filters.php).
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/multiday_filters.php';
$admin = admin_require_role('super', 'zone', 'centre');

$pdo = pastores_db();
$scopeCentreName = $admin['role'] === 'centre' ? admin_centre_name($pdo, $admin) : null;
$weekStart = get_week_start($pdo);

const ACAL_CHIPS_SHOWN = 3;

function acal_fill(?string $section): string
{
    $s = strtolower(trim((string) $section));
    if ($s === 'sf') return '#F3B6CB';
    if ($s === 'sv') return '#A9C8E8';
    return '#D5D0C8';
}

function acal_accent(?string $section): string
{
    $s = strtolower(trim((string) $section));
    if ($s === 'sf') return '#D6588A';
    if ($s === 'sv') return '#4F86C0';
    return '#8C857A';
}

function acal_time(?string $t): string
{
    return $t ? substr($t, 0, 5) : '';
}

// Hover text for one activity.
function acal_tip_html(array $a): string
{
    $times = acal_time($a['from_time']) . ($a['to_time'] ? ' – ' . acal_time($a['to_time']) : '');
    $html = '<span class="acal-tip-head">' . e($a['centre'] ?: '—') . '</span> · <span class="acal-tip-tag">' . e($a['activity'] ?: '—') . '</span>';
    $html .= '<span class="acal-tip-line">' . e(date('D j M Y', strtotime($a['activity_date']))) . ($times !== '' ? ' · ' . e($times) : '') . '</span>';
    $html .= '<span class="acal-tip-line">Grp: ' . e($a['labor'] ?: '—') . ' · Sec: ' . e($a['section'] ?: '—') . '</span>';
    $html .= '<span class="acal-tip-line">Priest: ' . ($a['priest'] !== null && $a['priest'] !== '' ? e($a['priest']) : '<em>none assigned</em>') . '</span>';
    if ($a['description'] !== null && $a['description'] !== '') {
        $html .= '<span class="acal-tip-line">' . e($a['description']) . '</span>';
    }
    return $html;
}

// One activity as a chip linking to the List tab's editor. $detailed (Week view)
// lets it wrap and adds a second line with the priest and group; $extra hides it
// until its day is expanded ("+N more", Month view); $showEnd adds the end time
// and $style positions it (Day view's time grid).
function acal_chip(array $a, bool $detailed = false, bool $extra = false, bool $showEnd = false, string $style = ''): string
{
    $from = acal_time($a['from_time']);
    $times = $from . ($showEnd && $a['to_time'] ? '–' . acal_time($a['to_time']) : '');
    $label = e(trim(($a['centre'] ?: '') . ' ' . ($a['activity'] ?: '')));
    $html = '<a class="acal-chip' . ($detailed ? ' detailed' : '') . ($extra ? ' acal-extra' : '') . (empty($a['priest']) ? ' nopriest' : '') . '"'
        . ' style="background:' . e(acal_fill($a['section'])) . ';border-left-color:' . e(acal_accent($a['section'])) . ';' . $style . '"'
        . ' href="/admin/activities/index.php?edit=' . (int) $a['id'] . '&zone=' . (int) $a['zone_id'] . '">';
    if ($times !== '') $html .= '<span class="t">' . e($times) . '</span>';
    $html .= $label;
    if ($detailed) {
        $sub = trim(($a['priest'] ?: 'no priest') . ($a['labor'] ? ' · ' . $a['labor'] : ''));
        $html .= '<span class="sub">' . e($sub) . '</span>';
    } elseif ($a['labor']) {
        $html .= ' · ' . e($a['labor']);
    }
    return $html . '<span class="acal-tip" hidden>' . acal_tip_html($a) . '</span></a>';
}

const ACAL_HOUR_PX = 72;     // Day view: height of one hour
const ACAL_MIN_MINUTES = 30; // Day view: shortest a block is drawn (no end time, or a very short activity)

function acal_minutes(?string $t): ?int
{
    return $t ? (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2) : null;
}

// Day view layout. Splits the day's activities into those with a start time,
// placed on the hour grid, and those without ("untimed"). A timed block runs from
// its start to its end time (else start + duration, else half an hour, at least
// ACAL_MIN_MINUTES, never past midnight). Overlapping blocks share the width:
// each cluster of overlapping blocks is split into as many columns as it needs.
// Returns [timed blocks with start/end/col/cols, untimed, first hour, last hour].
function acal_day_layout(array $list): array
{
    $timed = [];
    $untimed = [];
    foreach ($list as $a) {
        $start = acal_minutes($a['from_time']);
        if ($start === null) {
            $untimed[] = $a;
            continue;
        }
        $end = acal_minutes($a['to_time']);
        if ($end === null || $end <= $start) {
            $dur = acal_minutes($a['duration']);
            $end = $start + ($dur ?: ACAL_MIN_MINUTES);
        }
        $timed[] = ['a' => $a, 'start' => $start, 'end' => min(24 * 60, max($end, $start + ACAL_MIN_MINUTES))];
    }
    usort($timed, fn($x, $y) => [$x['start'], $x['end']] <=> [$y['start'], $y['end']]);

    $cluster = [];
    $clusterEnd = -1;
    $flush = function () use (&$cluster, &$timed) {
        $colEnds = [];
        foreach ($cluster as $i) {
            $placed = false;
            foreach ($colEnds as $c => $endAt) {
                if ($endAt <= $timed[$i]['start']) {
                    $colEnds[$c] = $timed[$i]['end'];
                    $timed[$i]['col'] = $c;
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                $timed[$i]['col'] = count($colEnds);
                $colEnds[] = $timed[$i]['end'];
            }
        }
        foreach ($cluster as $i) {
            $timed[$i]['cols'] = count($colEnds);
        }
        $cluster = [];
    };
    foreach ($timed as $i => $t) {
        if ($cluster && $t['start'] >= $clusterEnd) {
            $flush();
            $clusterEnd = -1;
        }
        $cluster[] = $i;
        $clusterEnd = max($clusterEnd, $t['end']);
    }
    if ($cluster) $flush();

    // Hours shown: at least 06:00-20:00, wider if an activity falls outside.
    $firstHour = 6;
    $lastHour = 20;
    foreach ($timed as $t) {
        $firstHour = min($firstHour, intdiv($t['start'], 60));
        $lastHour = max($lastHour, (int) ceil($t['end'] / 60));
    }
    return [$timed, $untimed, $firstHour, min(24, $lastHour)];
}

// ── Zone / view / date / filters ──────────────────────────────────────────────
// A super admin picks the zone (first one by default, as on the List tab); a
// zone or centre admin is locked to their own. A centre admin also only sees
// their own centre.
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
$view = in_array($_GET['view'] ?? '', ['month', 'week', 'day'], true) ? $_GET['view'] : 'month';
// The date the view is anchored on (its month, week or day). ?month=YYYY-MM is still accepted.
$dateParam = (string) ($_GET['date'] ?? '');
if ($dateParam === '' && preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? ''))) {
    $dateParam = $_GET['month'] . '-01';
}
$anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $dateParam);
if (!$anchor || $anchor->format('Y-m-d') !== $dateParam || (int) $anchor->format('Y') < 2000 || (int) $anchor->format('Y') > 2100) {
    $anchor = new DateTimeImmutable('today');
}
$month = $anchor->format('Y-m');
$centreFilter = mday_ms_param('centre');
$sectionFilter = mday_ms_param('section');
$groupFilter = mday_ms_param('labor');
$activityFilter = mday_ms_param('activity');
$priestFilter = mday_ms_param('priest');

// The visible range. Month and Week are whole weeks (the week starts on the
// "week start" setting), so a month's neighbouring days show their activities too.
$first = $anchor->modify('first day of this month');
$weekLead = fn(DateTimeImmutable $d) => $weekStart === 'monday' ? ((int) $d->format('w') + 6) % 7 : (int) $d->format('w');
if ($view === 'month') {
    $lead = $weekLead($first);
    $weeks = (int) ceil(($lead + (int) $first->format('t')) / 7);
    $gridStart = $first->modify("-$lead days");
} elseif ($view === 'week') {
    $weeks = 1;
    $gridStart = $anchor->modify('-' . $weekLead($anchor) . ' days');
} else {
    $weeks = 1;
    $gridStart = $anchor;
}
$gridEnd = $view === 'day' ? $anchor : $gridStart->modify('+' . ($weeks * 7 - 1) . ' days');

$where = ['a.zone_id = ?', 'a.activity_date BETWEEN ? AND ?'];
$params = [$zoneId, $gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d')];
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
$stmt = $pdo->prepare('SELECT a.* FROM activities a WHERE ' . implode(' AND ', $where) . ' ORDER BY a.activity_date, a.from_time, a.centre, a.id');
$stmt->execute($params);
$byDate = [];
$inRange = 0; // in the month itself (Month view), or in the whole week/day
foreach ($stmt->fetchAll() as $a) {
    $byDate[$a['activity_date']][] = $a;
    if ($view !== 'month' || substr($a['activity_date'], 0, 7) === $month) $inRange++;
}

// Filter option lists: the zone's centres and priests, the shared lookups.
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

// Navigation keeps every other filter.
$navUrl = function (string $date, ?string $v = null) use ($view) {
    return '/admin/activities/calendar.php?' . http_build_query(['date' => $date, 'view' => $v ?? $view] + array_diff_key($_GET, ['month' => 1]));
};
$step = ['month' => '1 month', 'week' => '7 days', 'day' => '1 day'][$view];
$prevDate = ($view === 'month' ? $first : $anchor)->modify("-$step")->format('Y-m-d');
$nextDate = ($view === 'month' ? $first : $anchor)->modify("+$step")->format('Y-m-d');
if ($view === 'month') {
    $title = $first->format('F Y');
    $unit = 'in ' . $first->format('F');
} elseif ($view === 'week') {
    $sameMonth = $gridStart->format('Y-m') === $gridEnd->format('Y-m');
    $title = $gridStart->format($sameMonth ? 'j' : 'j M') . ' – ' . $gridEnd->format('j M Y');
    $unit = 'this week';
} else {
    $title = $anchor->format('l j F Y');
    $unit = 'on this day';
}
$dayNames = weekday_names($weekStart);
$today = date('Y-m-d');

$pageTitle = 'Activities Calendar — Pastores Admin';
$pageWide = true;
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Activities</h1>
<nav class="tabs">
  <a href="/admin/activities/index.php<?= $zoneId ? '?zone=' . $zoneId : '' ?>">List</a>
  <a class="active" href="/admin/activities/calendar.php">Calendar</a>
  <a href="/admin/activities/dashboard.php<?= $zoneId ? '?zone=' . $zoneId : '' ?>">Dashboard</a>
</nav>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div>
      <label for="date">Go to date</label>
      <input type="date" id="date" name="date" value="<?= e($anchor->format('Y-m-d')) ?>" style="width:160px;">
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
</div>

<style>
  .acal-head { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
  .acal-head h2 { margin: 0; font-size: 18px; min-width: 170px; text-align: center; }
  .acal-head a.btn { padding: 6px 12px; }
  .acal-head .acal-count { margin-left: auto; font-size: 12px; color: #666; }
  .acal-legend { display: flex; gap: 16px; align-items: center; margin-bottom: 12px; font-size: 12px; color: #555; flex-wrap: wrap; }
  .acal-legend span.sw { width: 20px; height: 12px; border-radius: 2px; display: inline-block; margin-right: 6px; vertical-align: middle; }
  .acal-scroll { overflow-x: auto; }
  .acal-grid { min-width: 840px; display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border: 2px solid #222; border-radius: 6px; overflow: hidden; background: #fff; }
  .acal-dow { background: #f7f7f7; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #666; padding: 6px 8px; border-bottom: 1px solid #ddd; border-right: 1px solid #eee; }
  .acal-day { min-height: 104px; padding: 4px; border-right: 1px solid #eee; border-bottom: 1px solid #ddd; background: #fff; }
  .acal-day.weekend { background: #fafaf7; }
  .acal-day.other { background: #f5f5f3; }
  .acal-day.other .acal-num { color: #bbb; }
  .acal-day.today { background: #FFF8E6; }
  .acal-num { font-size: 12px; font-weight: 600; color: #444; display: flex; justify-content: space-between; margin-bottom: 3px; }
  .acal-day.today .acal-num span:first-child { background: var(--brand); color: #fff; border-radius: 10px; padding: 0 7px; }
  .acal-num .n { font-weight: normal; font-size: 10px; color: #999; }
  a.acal-chip { display: block; font-size: 11px; line-height: 1.35; padding: 2px 5px; margin-bottom: 2px; border-radius: 3px; border-left: 4px solid #999; color: #222; text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  a.acal-chip:hover { filter: brightness(.95); outline: 1px solid var(--brand); }
  a.acal-chip.nopriest { border-left-style: dashed; }
  a.acal-chip .t { font-weight: 600; margin-right: 3px; }
  /* Selectors must out-rank `a.acal-chip { display: block }` above, or the extras are never hidden. */
  a.acal-chip.acal-extra { display: none; }
  .acal-day.open a.acal-chip.acal-extra { display: block; }
  .acal-views { display: inline-flex; margin-left: 8px; }
  .acal-views a { padding: 6px 14px; font-size: 13px; text-decoration: none; color: #444; background: #fff; border: 1px solid #ccc; margin-left: -1px; }
  .acal-views a:first-child { border-radius: 4px 0 0 4px; margin-left: 0; }
  .acal-views a:last-child { border-radius: 0 4px 4px 0; }
  .acal-views a.on { background: var(--brand); border-color: var(--brand); color: #fff; }
  .acal-grid.week .acal-day { min-height: 340px; }
  a.acal-chip.detailed { white-space: normal; padding: 3px 6px; margin-bottom: 4px; font-size: 12px; }
  a.acal-chip .sub { display: block; font-size: 10.5px; color: #444; opacity: .85; }
  /* Day view: hours down the left, each activity a block spanning its start to end time. */
  .acal-timegrid { position: relative; background: linear-gradient(to right, #f7f7f7 0, #f7f7f7 59px, #ddd 59px, #ddd 60px, #fff 60px); border: 2px solid #222; border-radius: 6px; overflow: hidden; margin-bottom: 12px; }
  .acal-hour { position: absolute; left: 0; right: 0; border-top: 1px solid #e4e2dc; box-sizing: border-box; }
  .acal-hour:first-child { border-top: none; }
  .acal-hour span { position: absolute; top: 3px; left: 0; width: 56px; text-align: right; padding-right: 8px; font-size: 11px; color: #777; font-family: monospace; }
  .acal-events { position: absolute; left: 60px; right: 0; top: 0; bottom: 0; }
  .acal-events a.acal-chip { position: absolute; margin: 0; box-sizing: border-box; overflow: hidden; font-size: 12px; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,.15); }
  .acal-events a.acal-chip .sub { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .acal-now[hidden] { display: none; }
  .acal-now { position: absolute; left: 60px; right: 0; height: 0; border-top: 2px solid #E53935; z-index: 5; pointer-events: none; }
  .acal-untimed { background: #fff; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  .acal-untimed .lbl { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #666; margin-right: 10px; }
  .acal-empty { background: #fff; border-radius: 8px; padding: 28px; text-align: center; color: #888; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  .acal-more { font-size: 11px; color: var(--brand); background: none; padding: 0 2px; border: none; cursor: pointer; text-decoration: underline; }
  .acal-tooltip { position: fixed; z-index: 200; display: none; background: #1C1A17; color: #fff; padding: 8px 10px; border-radius: 4px; font-size: 11px; line-height: 1.6; max-width: 320px; min-width: 170px; pointer-events: none; box-shadow: 0 4px 12px rgba(0,0,0,.25); }
  .acal-tip-head { font-weight: 600; letter-spacing: .03em; }
  .acal-tip-tag { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; background: #F7F6F2; color: #555; }
  .acal-tip-line { display: block; }
</style>

<div id="mday-results">
<div class="acal-head">
  <a class="btn secondary" href="<?= e($navUrl($prevDate)) ?>" aria-label="Previous <?= e($view) ?>">&lsaquo;</a>
  <h2><?= e($title) ?></h2>
  <a class="btn secondary" href="<?= e($navUrl($nextDate)) ?>" aria-label="Next <?= e($view) ?>">&rsaquo;</a>
  <a class="btn secondary" href="<?= e($navUrl(date('Y-m-d'))) ?>">Today</a>
  <span class="acal-views">
    <?php foreach (['month' => 'Month', 'week' => 'Week', 'day' => 'Day'] as $v => $label): ?>
      <a href="<?= e($navUrl($anchor->format('Y-m-d'), $v)) ?>"<?= $v === $view ? ' class="on" aria-current="true"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
  </span>
  <span class="acal-count"><?= $inRange ?> activit<?= $inRange === 1 ? 'y' : 'ies' ?> <?= e($unit) ?></span>
</div>
<div class="acal-legend">
  <span><span class="sw" style="background:#F3B6CB;"></span>sf — Women</span>
  <span><span class="sw" style="background:#A9C8E8;"></span>sv — Men</span>
  <span><span class="sw" style="background:#D5D0C8;"></span>Other/unset</span>
  <span>· dashed edge = no priest assigned · click an activity to edit it</span>
</div>
<?php if ($view === 'day'):
  $list = $byDate[$anchor->format('Y-m-d')] ?? [];
  [$timed, $untimed, $firstHour, $lastHour] = acal_day_layout($list);
  $gridHeight = ($lastHour - $firstHour) * ACAL_HOUR_PX;
?>
  <?php if (!$list): ?>
    <div class="acal-empty">No activities on this day.</div>
  <?php else: ?>
  <?php if ($untimed): ?>
    <div class="acal-untimed"><span class="lbl">No time set</span>
      <?php foreach ($untimed as $a): ?><?= acal_chip($a, true, false, true, 'display:inline-block;margin:0 6px 4px 0;') ?><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="acal-timegrid" style="height:<?= $gridHeight ?>px;">
    <?php for ($h = $firstHour; $h < $lastHour; $h++): ?>
      <div class="acal-hour" style="top:<?= ($h - $firstHour) * ACAL_HOUR_PX ?>px;height:<?= ACAL_HOUR_PX ?>px;"><span><?= sprintf('%02d:00', $h) ?></span></div>
    <?php endfor; ?>
    <div class="acal-events">
      <?php foreach ($timed as $t):
        $top = ($t['start'] - $firstHour * 60) * ACAL_HOUR_PX / 60;
        $height = ($t['end'] - $t['start']) * ACAL_HOUR_PX / 60;
        $width = 100 / $t['cols'];
        $style = sprintf('top:%.1fpx;height:%.1fpx;left:calc(%.3f%% + 1px);width:calc(%.3f%% - 3px);', $top, $height - 2, $t['col'] * $width, $width);
      ?>
        <?= acal_chip($t['a'], true, false, true, $style) ?>
      <?php endforeach; ?>
    </div>
    <!-- "Now" line: placed by the script below from the browser's clock (PHP's timezone may differ from the admin's). -->
    <div class="acal-now" hidden data-date="<?= e($anchor->format('Y-m-d')) ?>" data-first-hour="<?= (int) $firstHour ?>" data-last-hour="<?= (int) $lastHour ?>" data-hour-px="<?= ACAL_HOUR_PX ?>"></div>
  </div>
  <?php endif; ?>
<?php else: ?>
<div class="acal-scroll">
<div class="acal-grid<?= $view === 'week' ? ' week' : '' ?>">
  <?php foreach ($dayNames as $name): ?><div class="acal-dow"><?= e(substr($name, 0, 3)) ?></div><?php endforeach; ?>
  <?php for ($i = 0; $i < $weeks * 7; $i++):
    $d = $gridStart->modify("+$i days");
    $iso = $d->format('Y-m-d');
    $list = $byDate[$iso] ?? [];
    $w = (int) $d->format('w');
    $cls = ($w === 0 || $w === 6 ? ' weekend' : '') . ($view === 'month' && $d->format('Y-m') !== $month ? ' other' : '') . ($iso === $today ? ' today' : '');
  ?>
    <div class="acal-day<?= $cls ?>">
      <div class="acal-num"><span><a href="<?= e($navUrl($iso, 'day')) ?>" style="color:inherit;text-decoration:none;" title="Open this day"><?= (int) $d->format('j') ?><?= $view === 'week' || $d->format('j') === '1' ? ' ' . e($d->format('M')) : '' ?></a></span><?php if ($list): ?><span class="n"><?= count($list) ?></span><?php endif; ?></div>
      <?php foreach ($list as $n => $a): ?>
        <?= acal_chip($a, $view === 'week', $view === 'month' && $n >= ACAL_CHIPS_SHOWN) ?>
      <?php endforeach; ?>
      <?php if ($view === 'month' && count($list) > ACAL_CHIPS_SHOWN): ?>
        <button type="button" class="acal-more" data-more="<?= count($list) - ACAL_CHIPS_SHOWN ?>">+<?= count($list) - ACAL_CHIPS_SHOWN ?> more</button>
      <?php endif; ?>
    </div>
  <?php endfor; ?>
</div>
</div>
<?php endif; ?>
</div><!-- #mday-results -->
<?php mday_render_filter_assets(); ?>
<script>
(function () {
  // Picking a date reloads the view live, like the other filters (the shared script handles the submit).
  var date = document.getElementById('date');
  if (date) date.addEventListener('change', function () { if (date.value) date.form.requestSubmit(); });

  // "+N more" expands a busy day in place (delegated, so it survives the live refresh).
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.acal-more') : null;
    if (!b) return;
    var day = b.closest('.acal-day');
    var open = day.classList.toggle('open');
    b.textContent = open ? 'show less' : '+' + b.getAttribute('data-more') + ' more';
  });

  // Day view's red "now" line, from the browser's clock; only when the viewed day is today. Re-placed every minute.
  function placeNow() {
    var line = document.querySelector('.acal-now');
    if (!line) return;
    var n = new Date();
    var today = n.getFullYear() + '-' + ('0' + (n.getMonth() + 1)).slice(-2) + '-' + ('0' + n.getDate()).slice(-2);
    var mins = n.getHours() * 60 + n.getMinutes();
    var first = +line.dataset.firstHour * 60, last = +line.dataset.lastHour * 60;
    var show = line.dataset.date === today && mins >= first && mins <= last;
    line.hidden = !show;
    if (show) line.style.top = ((mins - first) * +line.dataset.hourPx / 60) + 'px';
  }
  placeNow();
  setInterval(placeNow, 60000);
  new MutationObserver(placeNow).observe(document.getElementById('mday-results'), { childList: true });

  // Hover tooltip: one fixed element fed from the chip's hidden .acal-tip.
  var tip = document.createElement('div');
  tip.className = 'acal-tooltip';
  document.body.appendChild(tip);
  var tipChip = null;
  function hideTip() { tip.style.display = 'none'; tipChip = null; }
  document.addEventListener('mouseover', function (ev) {
    var chip = ev.target.closest ? ev.target.closest('.acal-chip') : null;
    if (!chip) { hideTip(); return; }
    if (chip === tipChip) return;
    tipChip = chip;
    tip.innerHTML = chip.querySelector('.acal-tip').innerHTML;
    tip.style.display = 'block';
    var r = chip.getBoundingClientRect();
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
