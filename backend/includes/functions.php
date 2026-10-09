<?php

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function apply_cors(): void
{
    $config = pastores_config();
    $allowed = $config['allowed_origins'] ?? [];
    if (empty($allowed)) {
        return;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Google Sheets exports time-only cells as ISO datetimes on the
// 1899-12-30 epoch (e.g. "1899-12-30T06:45:00.000Z"). The frontend
// (src/app/page.tsx) checks for a "T" in from/to and, when present,
// parses it as an ISO datetime and formats it as "h:mm a". Reproducing
// that shape here means the existing frontend needs no changes for
// activity times.
function time_to_sheets_iso(?string $time): ?string
{
    if ($time === null || $time === '') {
        return null;
    }
    // $time is HH:MM:SS from MySQL TIME columns.
    return '1899-12-30T' . substr($time, 0, 5) . ':00.000Z';
}

function date_to_iso(?string $date): ?string
{
    if ($date === null || $date === '') {
        return null;
    }
    return $date; // stored/returned as plain yyyy-MM-dd, matching how the
                  // frontend parses activity.date with format 'yyyy-MM-dd'
}

// Bumps zones.last_update so the frontend's "check for updates" polling
// (action=lastupdate) notices this zone's activities/users changed.
function touch_zone(PDO $pdo, int $zoneId): void
{
    $stmt = $pdo->prepare('UPDATE zones SET last_update = UTC_TIMESTAMP() WHERE id = ?');
    $stmt->execute([$zoneId]);
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Inline SVG icons for the Edit/Delete links in every admin table (icon_btn()
// wraps them in the <a class="icon-btn"> markup styled in layout_top.php).
// Static markup, safe to echo unescaped.
function icon_btn(string $href, string $label, string $svg, string $extraAttrs = '', string $class = ''): string
{
    $class = trim('icon-btn ' . $class);
    return '<a href="' . e($href) . '" class="' . e($class) . '" title="' . e($label) . '" aria-label="' . e($label) . '"' . $extraAttrs . '>' . $svg . '</a>';
}

function icon_edit(string $href, string $extraAttrs = ''): string
{
    $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';
    return icon_btn($href, 'Edit', $svg, $extraAttrs);
}

function icon_delete(string $onclick = "this.closest('form').requestSubmit(); return false;"): string
{
    $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';
    return icon_btn('#', 'Delete', $svg, ' onclick="' . e($onclick) . '"', 'delete');
}

// Days of the week, keyed by the 3-letter abbreviation stored in
// activities.day (matching the existing data) => full name.
const PASTORES_DAYS = [
    'Mon' => 'Monday',
    'Tue' => 'Tuesday',
    'Wed' => 'Wednesday',
    'Thu' => 'Thursday',
    'Fri' => 'Friday',
    'Sat' => 'Saturday',
    'Sun' => 'Sunday',
];

// ---------------------------------------------------------------------
// Settings (key/value, see the `settings` table; edited under Admin >
// Settings). Currently just week_start: 'sunday' (default) or 'monday'
// (only affects the weekday # numbering).
// ---------------------------------------------------------------------
const PASTORES_WEEK_STARTS = ['sunday' => 'Sunday', 'monday' => 'Monday'];

function get_setting(PDO $pdo, string $name, string $default): string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function set_setting(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$name, $value]);
}

function get_week_start(PDO $pdo): string
{
    $v = get_setting($pdo, 'week_start', 'sunday');
    return isset(PASTORES_WEEK_STARTS[$v]) ? $v : 'sunday';
}

// Everything the activities table derives from a yyyy-MM-dd date (nothing
// but the date is entered by hand):
//   day      'Mon'..'Sun'
//   weekday  position in the week: 1 = the first day of the week, so with
//            a Sunday start Sun = 1 ... Sat = 7, with a Monday start
//            Mon = 1 ... Sun = 7
//   week     which occurrence of that day of the week it is in the month:
//            2026-09-20 is the 3rd Sunday, so week = 3 (days 1-7 = 1,
//            8-14 = 2, ...). Independent of the week start.
// Returns null for an empty/invalid date.
function date_parts(?string $date, string $weekStart = 'sunday'): ?array
{
    if (!$date) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return null;
    }
    $day = $d->format('D');
    // Days since the start of the week: Sunday start => 'w' (Sun=0), Monday start => 'N' - 1.
    $index = $weekStart === 'monday' ? (int) $d->format('N') - 1 : (int) $d->format('w');
    return [
        'day' => $day,
        'day_name' => PASTORES_DAYS[$day],
        'weekday' => $index + 1,
        'week' => (int) ceil(((int) $d->format('j')) / 7),
    ];
}

// Day-of-week number => full name for the week start setting, e.g. with a
// Sunday start [1 => 'Sunday', 2 => 'Monday', ...]. This is what the numeric
// source.day means.
function weekday_names(string $weekStart = 'sunday'): array
{
    $names = array_values(PASTORES_DAYS); // Monday first
    if ($weekStart !== 'monday') {
        array_unshift($names, array_pop($names)); // Sunday first
    }
    return array_combine(range(1, 7), $names);
}

function ordinal(int $n): string
{
    $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
}

// Re-derives weekday/week on every activity (and day on every source row).
// Needed when the week_start setting changes, since those columns are stored.
// Call it only when the setting actually changed.
function recompute_activity_calendar(PDO $pdo, string $weekStart): void
{
    $weekday = $weekStart === 'monday' ? 'WEEKDAY(activity_date)' : 'DAYOFWEEK(activity_date) - 1';
    $pdo->exec("UPDATE activities SET weekday = $weekday + 1, week = CEIL(DAYOFMONTH(activity_date) / 7) WHERE activity_date IS NOT NULL");
    // source.day uses the same numbering (see weekday_names()) but has no date
    // to derive it from, so shift it (only called when the setting changed:
    // Sunday-start -> Monday-start means every day number goes down by one,
    // Sun 1 -> 7, and the reverse goes up).
    $shifted = $weekStart === 'monday' ? '(day + 5) % 7 + 1' : 'day % 7 + 1';
    $pdo->exec("UPDATE source SET day = $shifted WHERE day IS NOT NULL");
    $pdo->exec('UPDATE zones SET last_update = UTC_TIMESTAMP()');
}

// Lookup tables behind the admin dropdowns (see schema.sql). The table
// name is interpolated into SQL, so it must be one of these.
const PASTORES_LOOKUP_TABLES = ['priests', 'sections', 'labors', 'activity_types'];

// The priests of each zone as [zone_id, name] rows, ordered by name: those
// whose home zone it is (priests.zone_id) plus those who also serve there
// (priest_zones). A priest on a temporary transfer therefore appears under
// both zones. $zoneId limits the result to one zone.
function priests_by_zone(PDO $pdo, ?int $zoneId = null): array
{
    $stmt = $pdo->prepare(
        'SELECT zone_id, name FROM (
             SELECT zone_id, name FROM priests
             UNION
             SELECT pz.zone_id, p.name FROM priest_zones pz JOIN priests p ON p.id = pz.priest_id
         ) t' . ($zoneId !== null ? ' WHERE zone_id = ?' : '') . ' ORDER BY name'
    );
    $stmt->execute($zoneId !== null ? [$zoneId] : []);
    return $stmt->fetchAll();
}

function lookup_names(PDO $pdo, string $table): array
{
    if (!in_array($table, PASTORES_LOOKUP_TABLES, true)) {
        throw new InvalidArgumentException("Unknown lookup table: $table");
    }
    return $pdo->query("SELECT name FROM $table ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

// Renders a <select>. $options is a list of values, or value => label.
// A $selected value that is no longer in $options (e.g. a section that was
// deleted from the list) is still shown, so editing an old record doesn't
// silently wipe it.
function render_select(string $id, string $name, array $options, ?string $selected, string $placeholder, array $attrs = []): void
{
    if (array_is_list($options)) {
        $options = array_combine($options, $options);
    }
    if ($selected !== null && $selected !== '' && !isset($options[$selected])) {
        $options[$selected] = $selected;
    }
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . e($k) . '="' . e($v) . '"';
    }
    echo '<select id="' . e($id) . '" name="' . e($name) . '"' . $extra . '>';
    echo '<option value="">' . e($placeholder) . '</option>';
    foreach ($options as $value => $label) {
        $sel = ((string) $value === (string) $selected) ? ' selected' : '';
        echo '<option value="' . e((string) $value) . '"' . $sel . '>' . e((string) $label) . '</option>';
    }
    echo '</select>';
}

function render_datalist(string $id, array $options): void
{
    echo '<datalist id="' . e($id) . '">';
    foreach ($options as $option) {
        echo '<option value="' . e($option) . '">';
    }
    echo '</datalist>';
}

// The "A- / A+" text size buttons above a table (see the zoom script in
// admin/includes/layout_bottom.php, which wires them up). Pages with a long table
// echo this right before it so the buttons are on screen from the first paint
// instead of appearing once the whole table has been parsed.
function table_zoom_bar(): string
{
    return '<div class="tbl-zoom" data-zoom-static>'
        . '<button type="button" class="tbl-zoom-btn small" title="Smaller text" aria-label="Smaller text"><span aria-hidden="true">A</span><sup aria-hidden="true">&minus;</sup></button>'
        . '<button type="button" class="tbl-zoom-btn" title="Larger text" aria-label="Larger text"><span aria-hidden="true">A</span><sup aria-hidden="true">+</sup></button>'
        . '</div>' . "\n";
}

// The "select rows to delete" icon that sits first in a table page's toolbar (see the
// bulk delete script in admin/includes/layout_bottom.php, which wires it up). Pages with
// a long table echo it before the table so it is there from the first paint.
function bulk_toggle_button(): string
{
    return '<button type="button" class="bulk-toggle" data-toolbar-item data-toolbar-first data-bulk-static title="Select rows to delete" aria-label="Select rows to delete" aria-pressed="false">'
        . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><path d="M5 6.5l1.5 1.5L9 5"/><path d="M14 5h7M14 8h5"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 16h7M14 19h5"/></svg>'
        . "</button>\n";
}
