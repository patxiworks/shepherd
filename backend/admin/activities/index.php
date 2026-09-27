<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/activity_io.php';
require __DIR__ . '/../../includes/source_apply.php';
require __DIR__ . '/../../includes/vigil.php';
require __DIR__ . '/../../includes/absences.php';
require __DIR__ . '/../../includes/activity_duplicates.php';
require __DIR__ . '/../../includes/activity_bilocation.php';
require __DIR__ . '/../../includes/activity_mass_limit.php';
require __DIR__ . '/../../includes/activity_no_priest.php';
require __DIR__ . '/../includes/flash.php';
$admin = admin_require_role('super', 'zone', 'centre');

$pdo = pastores_db();
mass_limit($pdo); // load the max-masses-per-day setting
$scopeCentreName = $admin['role'] === 'centre' ? admin_centre_name($pdo, $admin) : null;

// A zone/centre-scoped admin may only touch activities inside their own
// zone (and, for 'centre', matching their own centre name).
function activity_in_scope(PDO $pdo, int $activityId, array $admin, ?string $scopeCentreName): bool
{
    if ($admin['role'] === 'super') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT zone_id, centre FROM activities WHERE id = ?');
    $stmt->execute([$activityId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['zone_id'] !== (int) $admin['zone_id']) {
        return false;
    }
    if ($admin['role'] === 'centre') {
        return $row['centre'] === $scopeCentreName;
    }
    return true;
}

$weekStart = get_week_start($pdo);

// The filters the Activities page understands (query-string params). Only
// well-formed values are kept; anything else is dropped.
function activity_filters(array $src): array
{
    $filters = [];
    foreach (['date_from', 'date_to', 'day', 'centre', 'section', 'priest', 'absent', 'duplicate', 'bilocation', 'masses', 'no_priest'] as $key) {
        $v = trim((string) ($src[$key] ?? ''));
        if ($v === '') {
            continue;
        }
        if (in_array($key, ['absent', 'duplicate', 'bilocation', 'masses', 'no_priest'], true)) {
            $filters[$key] = '1'; // yes/no flags: only activities whose priest is absent / that have a duplicate / that is a bilocation / whose priest is over the mass limit / that have no priest
            continue;
        }
        if (($key === 'date_from' || $key === 'date_to') && !date_parts($v)) {
            continue;
        }
        if ($key === 'day' && !isset(PASTORES_DAYS[$v])) {
            continue;
        }
        $filters[$key] = $v;
    }
    return $filters;
}

// WHERE clause (on `activities a`) for a zone's activities matching the
// filters, limited to their own centre for centre-scoped admins.
function activity_where(array $admin, ?string $scopeCentreName, int $zoneId, array $filters): array
{
    $where = ['a.zone_id = ?'];
    $args = [$zoneId];
    if ($admin['role'] === 'centre') {
        $where[] = 'a.centre = ?';
        $args[] = $scopeCentreName;
    }
    $columns = ['date_from' => 'a.activity_date >= ?', 'date_to' => 'a.activity_date <= ?', 'day' => 'a.day = ?',
                'centre' => 'a.centre = ?', 'section' => 'a.section = ?', 'priest' => 'a.priest = ?'];
    foreach ($filters as $key => $value) {
        if ($key === 'centre' && $admin['role'] === 'centre') {
            continue; // already limited to their own centre
        }
        if ($key === 'absent') {
            $where[] = activity_absent_sql();
            continue;
        }
        if ($key === 'duplicate') {
            $where[] = activity_has_duplicate_sql();
            continue;
        }
        if ($key === 'bilocation') {
            $where[] = activity_has_bilocation_sql();
            continue;
        }
        if ($key === 'masses') {
            $where[] = activity_over_mass_limit_sql();
            continue;
        }
        if ($key === 'no_priest') {
            $where[] = activity_priest_missing_sql();
            continue;
        }
        $where[] = $columns[$key];
        $args[] = $value;
    }
    return [implode(' AND ', $where), $args];
}

// Filters carried through a POST (hidden `qs` field) so saving/deleting
// returns to the same filtered list.
function activity_filters_from_qs(?string $qs): array
{
    parse_str((string) $qs, $parsed);
    return activity_filters($parsed);
}

// Turns submitted form values into the activities columns. Day, weekday #,
// week # come from the date and section from the centre; none of those are
// read from the request.
function activity_fields(PDO $pdo, array $admin, ?string $scopeCentreName, int $zoneId, ?int $id, array $in, string $weekStart): array
{
    $centre = $admin['role'] === 'centre' ? $scopeCentreName : (trim($in['centre'] ?? '') ?: null);
    $activityDate = ($in['activity_date'] ?? '') ?: null;
    $parts = date_parts($activityDate, $weekStart);

    $stmt = $pdo->prepare('SELECT section FROM centres WHERE zone_id = ? AND name = ?');
    $stmt->execute([$zoneId, $centre]);
    $section = $stmt->fetchColumn();
    if ($section === false && $id) {
        // Centre isn't in the centres list (legacy row): leave the section as it was.
        $stmt = $pdo->prepare('SELECT section FROM activities WHERE id = ?');
        $stmt->execute([$id]);
        $section = $stmt->fetchColumn();
    }

    return [
        'week' => $parts['week'] ?? null,
        'day' => $parts['day'] ?? null,
        'weekday' => $parts['weekday'] ?? null,
        'activity_date' => $activityDate,
        'centre' => $centre,
        'activity' => trim($in['activity'] ?? '') ?: null,
        'section' => $section ?: null,
        'labor' => trim($in['labor'] ?? '') ?: null,
        'from_time' => ($in['from_time'] ?? '') ?: null,
        'to_time' => ($in['to_time'] ?? '') ?: null,
        'duration' => ($in['duration'] ?? '') ?: null,
        'priest' => trim($in['priest'] ?? '') ?: null,
        'description' => trim($in['description'] ?? '') ?: null,
    ];
}

// The extra columns (absent_note, duplicate_count, bilocation_note, mass_count)
// that activity_row_html() turns into badges.
function activity_flags_select(PDO $pdo): string
{
    return activity_absent_select($pdo) . activity_duplicate_select() . activity_bilocation_select() . activity_mass_count_select();
}

// One table row. Cells with data-field are editable in place (see the
// script below); data-value is the raw value the editor starts from and
// data-derived cells are recalculated from the date/centre while editing.
function activity_row_html(array $a, string $qs = ''): string
{
    $t = fn($v) => e(substr((string) ($v ?? ''), 0, 5));
    $cell = fn(string $field, string $type, ?string $raw, string $text, string $extra = '') =>
        '<td data-field="' . $field . '" data-type="' . $type . '" data-value="' . e($raw ?? '') . '"' . $extra . '>' . $text . '</td>';
    $id = (int) $a['id'];
    $zone = (int) $a['zone_id'];
    // Flag a priest who is absent at the activity's time (see includes/absences.php).
    $missingPriest = empty($a['priest']);
    $priestText = e($a['priest'] ?? '') . ($missingPriest
        ? ' <span class="no-priest-badge" title="No priest assigned for this activity">no priest</span>' : '')
        . (!empty($a['absent_note'])
        ? ' <span class="absent-badge" title="' . e('Absent ' . $a['absent_note']) . '">absent</span>' : '');
    // Same priest, same date, overlapping times (see includes/activity_bilocation.php).
    $hasBilocation = !empty($a['bilocation_note']);
    if ($hasBilocation) {
        $priestText .= ' <span class="bilocation-badge" title="' . e('Overlaps with ' . $a['bilocation_note']) . '">bilocation</span>';
    }
    // A priest with more masses that day than allowed (see includes/activity_mass_limit.php).
    $massCount = (int) ($a['mass_count'] ?? 0);
    $overLimit = $massCount > mass_limit();
    if ($overLimit) {
        $priestText .= ' <span class="mass-badge" title="' . e("{$a['priest']} has $massCount masses on this day (maximum " . mass_limit() . ')') . '">' . $massCount . ' masses</span>';
    }
    // Flag identical activities (see includes/activity_duplicates.php).
    $dupes = (int) ($a['duplicate_count'] ?? 0);
    $classes = trim(($missingPriest ? 'no-priest ' : '') . (!empty($a['absent_note']) ? 'absent ' : '') . ($hasBilocation ? 'bilocation ' : '') . ($overLimit ? 'mass-limit ' : '') . ($dupes ? 'duplicate' : ''));
    $activityText = e($a['activity']) . ($dupes
        ? ' <span class="dup-badge" title="' . e("Identical to $dupes other activit" . ($dupes === 1 ? 'y' : 'ies') . ' (same date, centre, activity, priest and times)') . '">duplicate</span>' : '');
    ob_start();
    ?>
<tr data-id="<?= $id ?>" data-zone="<?= $zone ?>"<?= $classes !== '' ? ' class="' . $classes . '"' : '' ?>>
  <?= $cell('activity_date', 'date', $a['activity_date'], e($a['activity_date'])) ?>
  <td data-derived="day"><?= e($a['day']) ?></td>
  <td data-derived="week"><?= e((string) $a['week']) ?></td>
  <?= $cell('centre', 'centre', $a['centre'], e($a['centre'])) ?>
  <td data-derived="section"><?= e($a['section']) ?></td>
  <?= $cell('activity', 'activity', $a['activity'], $activityText, ' data-sort="' . e($a['activity']) . '"') ?>
  <?= $cell('labor', 'labor', $a['labor'], e($a['labor'])) ?>
  <?= $cell('priest', 'priest', $a['priest'], $priestText, ' data-sort="' . e($a['priest']) . '"') ?>
  <?= $cell('from_time', 'time', $a['from_time'] ? substr($a['from_time'], 0, 5) : '', $t($a['from_time'])) ?>
  <?= $cell('to_time', 'time', $a['to_time'] ? substr($a['to_time'], 0, 5) : '', $t($a['to_time'])) ?>
  <?= $cell('duration', 'time', $a['duration'] ? substr($a['duration'], 0, 5) : '', $t($a['duration'])) ?>
  <?= $cell('description', 'text', $a['description'], e($a['description']), ' class="desc" title="' . e($a['description']) . '"') ?>
  <td class="actions">
    <a href="/admin/activities/index.php?edit=<?= $id ?>&zone=<?= $zone ?><?= $qs !== '' ? '&' . e($qs) : '' ?>">Edit</a>
    <form class="inline" method="post" onsubmit="return confirm('Delete this activity?');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="zone_id" value="<?= $zone ?>">
      <input type="hidden" name="qs" value="<?= e($qs) ?>">
      <a href="#" onclick="this.closest('form').requestSubmit(); return false;" style="color:#E91E63;">Delete</a>
    </form>
  </td>
</tr>
<?php
    return trim(ob_get_clean());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'inline_save') {
        // Spreadsheet-style edit of one row; answers with JSON.
        $reply = function (array $data, int $status = 200): void {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data);
            exit;
        };
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id || !activity_in_scope($pdo, $id, $admin, $scopeCentreName)) {
            $reply(['error' => 'You do not have access to that activity.'], 403);
        }
        $stmt = $pdo->prepare('SELECT zone_id FROM activities WHERE id = ?');
        $stmt->execute([$id]);
        $zoneId = (int) $stmt->fetchColumn(); // an inline edit never moves an activity between zones
        $fields = activity_fields($pdo, $admin, $scopeCentreName, $zoneId, $id, $_POST, $weekStart);
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $pdo->prepare("UPDATE activities SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
        touch_zone($pdo, $zoneId);
        $stmt = $pdo->prepare('SELECT a.*' . activity_flags_select($pdo) . ' FROM activities a WHERE a.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $reply(['html' => activity_row_html($row, http_build_query(activity_filters_from_qs($_POST['qs'] ?? ''))), 'absent' => !empty($row['absent_note']), 'duplicate' => (int) $row['duplicate_count'] > 0, 'bilocation' => !empty($row['bilocation_note']), 'masses' => (int) $row['mass_count'] > mass_limit(), 'no_priest' => empty($row['priest'])]);
    }

    if ($action === 'from_source') {
        // Only ever the current user's own zone (a super admin has none, so
        // the zone being viewed); a centre admin only touches their centre.
        $zoneId = $admin['role'] === 'super' ? (int) ($_POST['zone_id'] ?? 0) : (int) $admin['zone_id'];
        try {
            if (!$zoneId) {
                throw new InvalidArgumentException('Zone is required.');
            }
            $r = apply_source_to_activities($pdo, $zoneId, (string) ($_POST['source_from'] ?? ''), (string) ($_POST['source_to'] ?? ''), $weekStart, $scopeCentreName);
            $parts = [];
            if ($r['dates']) {
                $parts[] = "Added from source: {$r['inserted']} activities on {$r['dates']} dates" . ($r['deleted'] ? " (replacing {$r['deleted']} existing)" : '');
            }
            if ($r['class_a_dates']) {
                $parts[] = "Class A: added {$r['class_a_added']} Med/Ben activities on {$r['class_a_dates']} class A date" . ($r['class_a_dates'] === 1 ? '' : 's');
            }
            if ($r['vigil_added']) {
                $parts[] = "Vigil: added {$r['vigil_added']} Vigil activit" . ($r['vigil_added'] === 1 ? 'y' : 'ies') . " on {$r['vigil_dates']} date" . ($r['vigil_dates'] === 1 ? '' : 's') . ' (the Thursday before the first Friday)';
            }
            if ($r['skipped']) {
                // Identical source rows are only added once.
                $parts[] = "Skipped {$r['skipped']} duplicate source row" . ($r['skipped'] === 1 ? '' : 's') . ' (same date, centre, activity, priest and times): '
                    . implode(', ', array_slice($r['skipped_list'], 0, 5)) . ($r['skipped'] > 5 ? ' …' : '');
            }
            if ($parts) {
                // Source knows nothing about absences or clashes between activities,
                // so check the range and open the list filtered to the first problem.
                [$from, $to] = [(string) $_POST['source_from'], (string) $_POST['source_to']];
                $absent = absent_activities_in_range($pdo, $zoneId, $from, $to, $scopeCentreName);
                $findings = []; // filter key => [label, count]; most important first
                $noPriest = missing_priest_activities_in_range($pdo, $zoneId, $from, $to, $scopeCentreName);
                if ($noPriest) {
                    $findings['no_priest'] = ['No priest assigned', $noPriest];
                    $parts[] = "Warning: $noPriest activit" . ($noPriest === 1 ? 'y has' : 'ies have') . ' no priest assigned';
                }
                if ($absent['count']) {
                    $names = [];
                    foreach ($absent['priests'] as $priest => $n) {
                        $names[] = "$priest ($n)";
                    }
                    $findings['absent'] = ['Priest absent', $absent['count']];
                    $parts[] = "Warning: {$absent['count']} activit" . ($absent['count'] === 1 ? 'y has a priest who is' : 'ies have a priest who is') . ' absent: ' . implode(', ', $names);
                }
                $bilocations = bilocation_activities_in_range($pdo, $zoneId, $from, $to, $scopeCentreName);
                if ($bilocations) {
                    $findings['bilocation'] = ['Priest bilocation', $bilocations];
                    $parts[] = "Warning: $bilocations activit" . ($bilocations === 1 ? 'y overlaps' : 'ies overlap') . ' another activity of the same priest in a different centre';
                }
                $massLimit = mass_limit_in_range($pdo, $zoneId, $from, $to, $scopeCentreName);
                if ($massLimit['count']) {
                    $findings['masses'] = ['Over the mass limit', $massLimit['count']];
                    $parts[] = 'Warning: more than ' . mass_limit() . ' masses in a day for ' . implode(', ', array_slice($massLimit['items'], 0, 5)) . (count($massLimit['items']) > 5 ? ' …' : '');
                }
                $dupeCount = duplicate_activities_in_range($pdo, $zoneId, $from, $to, $scopeCentreName);
                if ($dupeCount) {
                    $findings['duplicate'] = ['Duplicates', $dupeCount];
                    $parts[] = "Warning: $dupeCount activit" . ($dupeCount === 1 ? 'y is' : 'ies are') . ' identical to another activity (same date, centre, activity, priest and times)';
                }
                if ($findings) {
                    $first = array_key_first($findings);
                    $sourceFilters = ['date_from' => $from, 'date_to' => $to, $first => '1'];
                    $parts[] = count($findings) === 1 ? 'Showing them below; clear the filters to see everything'
                        : 'Showing the ' . mb_strtolower($findings[$first][0]) . ' ones below; use Filter to see the others';
                }
                flash($findings || $r['skipped'] ? 'warning' : 'success', implode('. ', $parts) . '.');
            } else {
                flash('error', 'Nothing to add: the source table has no rows for any date in that range' . ($r['calendar'] ? ' and there are no class A dates.' : ' (and the liturgical_calendar table does not exist yet, so class A dates were not checked).'));
            }
        } catch (InvalidArgumentException $ex) {
            flash('error', $ex->getMessage());
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        if (activity_in_scope($pdo, $id, $admin, $scopeCentreName)) {
            $stmt = $pdo->prepare('SELECT zone_id FROM activities WHERE id = ?');
            $stmt->execute([$id]);
            $zoneId = $stmt->fetchColumn();
            $pdo->prepare('DELETE FROM activities WHERE id = ?')->execute([$id]);
            if ($zoneId) {
                touch_zone($pdo, (int) $zoneId);
            }
            flash('success', 'Activity deleted.');
        } else {
            flash('error', 'You do not have access to that activity.');
        }
    } else {
        $zoneId = $admin['role'] === 'super' ? (int) ($_POST['zone_id'] ?? 0) : (int) $admin['zone_id'];
        $id = $_POST['id'] ?? '';
        $fields = activity_fields($pdo, $admin, $scopeCentreName, $zoneId, $id !== '' ? (int) $id : null, $_POST, $weekStart);

        if (!$zoneId) {
            flash('error', 'Zone is required.');
        } elseif ($id !== '' && !activity_in_scope($pdo, (int) $id, $admin, $scopeCentreName)) {
            flash('error', 'You do not have access to that activity.');
        } else {
            if ($id !== '') {
                $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                $pdo->prepare("UPDATE activities SET zone_id = ?, $set WHERE id = ?")
                    ->execute([$zoneId, ...array_values($fields), (int) $id]);
                $savedId = (int) $id;
                $message = 'Activity updated.';
            } elseif (identical_activity_exists($pdo, $zoneId, $fields)) {
                // A new activity identical to an existing one is not added.
                flash('error', 'Not added: an identical activity already exists (same date, centre, activity, priest and times).');
                $savedId = null;
            } else {
                $cols = implode(', ', array_merge(['zone_id'], array_keys($fields)));
                $placeholders = implode(', ', array_fill(0, count($fields) + 1, '?'));
                $pdo->prepare("INSERT INTO activities ($cols) VALUES ($placeholders)")
                    ->execute([$zoneId, ...array_values($fields)]);
                $savedId = (int) $pdo->lastInsertId();
                $message = 'Activity created.';
                // Thursday before the first Friday: every centre gets a Vigil.
                $vigilAdded = add_vigil_activities($pdo, $zoneId, (string) $fields['activity_date'], $weekStart, $scopeCentreName);
                if ($vigilAdded) {
                    $message .= " Also added $vigilAdded Vigil activit" . ($vigilAdded === 1 ? 'y' : 'ies') . ' (the Thursday before the first Friday).';
                }
            }
            if ($savedId !== null) {
                touch_zone($pdo, $zoneId);
                // Saved anyway, but warn if the priest is absent at that time, is
                // already booked at an overlapping time, or (after an edit) the
                // activity now duplicates another one.
                $warnings = [];
                if (empty($fields['priest'])) {
                    $warnings[] = 'no priest is assigned';
                }
                $absentNote = activity_absent_note($pdo, $savedId);
                if ($absentNote !== null) {
                    $warnings[] = "{$fields['priest']} is absent then ($absentNote)";
                }
                $bilocationNote = activity_bilocation_note($pdo, $savedId);
                if ($bilocationNote !== null) {
                    $warnings[] = "it overlaps another activity of {$fields['priest']} in a different centre ($bilocationNote)";
                }
                $massCount = activity_mass_count($pdo, $savedId);
                if ($massCount > mass_limit()) {
                    $warnings[] = "{$fields['priest']} now has $massCount masses that day (maximum " . mass_limit() . ')';
                }
                $dupes = activity_duplicate_count($pdo, $savedId);
                if ($dupes) {
                    $warnings[] = "it is identical to $dupes other activit" . ($dupes === 1 ? 'y' : 'ies') . ' (same date, centre, activity, priest and times)';
                }
                if ($warnings) {
                    flash('warning', "$message Warning: " . implode('; ', $warnings) . '.');
                } else {
                    flash('success', $message);
                }
            }
        }
    }
    $query = isset($_POST['zone_id']) ? ['zone' => (int) $_POST['zone_id']] : [];
    $query += activity_filters_from_qs($_POST['qs'] ?? '');
    if (!empty($sourceFilters)) {
        $query = array_diff_key($query, array_flip(['date_from', 'date_to', 'day', 'centre', 'section', 'priest', 'absent', 'duplicate', 'bilocation', 'masses', 'no_priest'])) + $sourceFilters;
    }
    header('Location: /admin/activities/index.php' . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId && activity_in_scope($pdo, $editId, $admin, $scopeCentreName)) {
    $stmt = $pdo->prepare('SELECT * FROM activities WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

if ($admin['role'] === 'super') {
    $zones = $pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll();
    $filterZone = isset($_GET['zone']) ? (int) $_GET['zone'] : ($zones[0]['id'] ?? null);
} else {
    // zone/centre admins are locked to their own zone regardless of ?zone=
    $zones = $pdo->prepare('SELECT * FROM zones WHERE id = ?');
    $zones->execute([$admin['zone_id']]);
    $zones = $zones->fetchAll();
    $filterZone = $admin['zone_id'];
}

// Dropdown data. Centres are filtered client-side by the selected zone
// (super admins can switch zones in the form); the server renders the
// initial zone's list so the form is right before any JS runs.
$zoneScope = $admin['role'] === 'super' ? '' : 'WHERE zone_id = ' . (int) $admin['zone_id'];
$allCentres = $pdo->query("SELECT zone_id, name, section FROM centres $zoneScope ORDER BY name")->fetchAll();
$allPriests = priests_by_zone($pdo, $admin['role'] === 'super' ? null : (int) $admin['zone_id']); // home zone or also serving there
$formZoneId = (int) ($editing['zone_id'] ?? $filterZone);
$inFormZone = fn(array $rows) => array_values(array_filter($rows, fn($r) => (int) $r['zone_id'] === $formZoneId));
$formCentres = array_column($inFormZone($allCentres), 'name');
$formPriests = array_column($inFormZone($allPriests), 'name');
$laborOptions = lookup_names($pdo, 'labors');
$sectionOptions = lookup_names($pdo, 'sections');
$activityOptions = lookup_names($pdo, 'activity_types');

// Initial hints under the Date and Centre fields (the script below keeps
// them current as the form changes).
$dateParts = date_parts($editing['activity_date'] ?? null, $weekStart);
$dateHint = $dateParts ? "{$dateParts['day_name']} · weekday {$dateParts['weekday']} · " . ordinal($dateParts['week']) . " {$dateParts['day_name']} of the month" : '';
$currentCentre = $admin['role'] === 'centre' ? $scopeCentreName : ($editing['centre'] ?? null);
$currentSection = null;
foreach ($allCentres as $c) {
    if ($c['name'] === $currentCentre && (int) $c['zone_id'] === $formZoneId) {
        $currentSection = $c['section'];
    }
}
$sectionHint = $currentCentre ? 'Section: ' . ($currentSection ?: '—') : '';

// Table rows: the zone's latest activities matching the filters.
$filters = activity_filters($_GET);
if (!absences_available($pdo)) {
    unset($filters['absent']); // migration 012 not applied yet
}
$filterQs = http_build_query($filters);
$activities = [];
if ($filterZone) {
    [$whereSql, $whereArgs] = activity_where($admin, $scopeCentreName, (int) $filterZone, $filters);
    $stmt = $pdo->prepare("SELECT a.*" . activity_flags_select($pdo) . " FROM activities a WHERE $whereSql ORDER BY a.activity_date DESC, a.from_time DESC LIMIT 300");
    $stmt->execute($whereArgs);
    $activities = $stmt->fetchAll();
}

// Legend (above the table): how many activities currently match each rule —
// same zone/centre scope and the same non-flag filters (date/day/centre/
// section/priest) as the table above, but ignoring any flag filter that is
// already applied, so every box always shows the true total. Clicking a box
// replaces any active flag filter with just that one; a zero count is shown
// as plain text, not a link, per rule (2) below.
const PASTORES_ACTIVITY_FLAGS = [
    'no_priest' => ['No priest', 'no-priest'],
    'absent' => ['Priest absent', 'absent'],
    'bilocation' => ['Priest bilocation', 'bilocation'],
    'masses' => ['Over mass limit', 'mass-limit'],
    'duplicate' => ['Duplicate', 'duplicate'],
];
$legend = [];
if ($filterZone) {
    $nonFlagFilters = array_diff_key($filters, array_flip(array_keys(PASTORES_ACTIVITY_FLAGS)));
    [$legendWhere, $legendArgs] = activity_where($admin, $scopeCentreName, (int) $filterZone, $nonFlagFilters);
    $flagSql = [
        'no_priest' => activity_priest_missing_sql(),
        'absent' => absences_available($pdo) ? activity_absent_sql() : null,
        'bilocation' => activity_has_bilocation_sql(),
        'masses' => activity_over_mass_limit_sql(),
        'duplicate' => activity_has_duplicate_sql(),
    ];
    $legendUrl = fn(string $key) => '/admin/activities/index.php?' . http_build_query(
        ($admin['role'] === 'super' ? ['zone' => (int) $filterZone] : []) + $nonFlagFilters + [$key => '1']
    );
    foreach (PASTORES_ACTIVITY_FLAGS as $key => [$label, $class]) {
        if ($flagSql[$key] === null) {
            continue; // e.g. absences table not migrated yet
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activities a WHERE $legendWhere AND {$flagSql[$key]}");
        $stmt->execute($legendArgs);
        $legend[$key] = ['label' => $label, 'class' => $class, 'count' => (int) $stmt->fetchColumn(), 'active' => isset($filters[$key])];
    }
}

// CSV export of everything the current view is filtered to (not just the
// 300 rows the table shows).
if (isset($_GET['export']) && $filterZone) {
    $stmt = $pdo->prepare(
        "SELECT a.*, z.name AS zone_name FROM activities a JOIN zones z ON z.id = a.zone_id WHERE $whereSql ORDER BY a.activity_date, a.from_time, a.id"
    );
    $stmt->execute($whereArgs);
    $zoneName = '';
    foreach ($zones as $z) {
        if ((int) $z['id'] === (int) $filterZone) {
            $zoneName = $z['name'];
        }
    }
    export_activities_csv($stmt, 'activities-' . $zoneName . '-' . date('Y-m-d') . '.csv');
    exit;
}
$filterLabels = ['date_from' => 'From', 'date_to' => 'To', 'day' => 'Day', 'centre' => 'Centre', 'section' => 'Section', 'priest' => 'Priest', 'absent' => 'Priest absent', 'duplicate' => 'Duplicates', 'bilocation' => 'Priest bilocation', 'masses' => 'Over mass limit', 'no_priest' => 'No priest'];
$exportAllUrl = '/admin/activities/index.php?' . http_build_query(['export' => 1] + ($admin['role'] === 'super' ? ['zone' => (int) $filterZone] : []));
$clearUrl = '/admin/activities/index.php' . ($admin['role'] === 'super' ? '?zone=' . (int) $filterZone : '');

// The date/day/centre/section/priest fields shared by the Filter and Export
// modals; $p prefixes the element ids so the two forms don't clash.
$renderFilterFields = function (string $p) use ($pdo, $filters, $admin, $formCentres, $formPriests, $sectionOptions) {
?>
    <div class="row">
      <div>
        <label for="<?= $p ?>date_from">Date from</label>
        <input type="date" id="<?= $p ?>date_from" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
      </div>
      <div>
        <label for="<?= $p ?>date_to">Date to</label>
        <input type="date" id="<?= $p ?>date_to" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
      </div>
    </div>
    <small class="hint" style="min-height:0;">For a single date, set both to the same day. Leave one empty for "on or after" / "on or before".</small>
    <div class="row">
      <div>
        <label for="<?= $p ?>day">Day</label>
        <?php render_select($p . 'day', 'day', PASTORES_DAYS, $filters['day'] ?? null, 'Any'); ?>
      </div>
      <?php if ($admin['role'] !== 'centre'): ?>
      <div>
        <label for="<?= $p ?>centre">Centre</label>
        <?php render_select($p . 'centre', 'centre', $formCentres, $filters['centre'] ?? null, 'Any'); ?>
      </div>
      <?php endif; ?>
      <div>
        <label for="<?= $p ?>section">Section</label>
        <?php render_select($p . 'section', 'section', $sectionOptions, $filters['section'] ?? null, 'Any'); ?>
      </div>
      <div>
        <label for="<?= $p ?>priest">Priest</label>
        <?php render_select($p . 'priest', 'priest', $formPriests, $filters['priest'] ?? null, 'Any'); ?>
      </div>
    </div>
    <?php if (absences_available($pdo)): ?>
    <label style="font-weight:normal;"><input type="checkbox" name="absent" value="1" style="width:auto;" <?= isset($filters['absent']) ? 'checked' : '' ?>> Only activities whose priest is absent (see Absences)</label>
    <?php endif; ?>
    <label style="font-weight:normal;"><input type="checkbox" name="duplicate" value="1" style="width:auto;" <?= isset($filters['duplicate']) ? 'checked' : '' ?>> Only duplicates (identical date, centre, activity, priest and times)</label>
    <label style="font-weight:normal;"><input type="checkbox" name="bilocation" value="1" style="width:auto;" <?= isset($filters['bilocation']) ? 'checked' : '' ?>> Only priest bilocation (same priest and date, overlapping times, different centres)</label>
    <label style="font-weight:normal;"><input type="checkbox" name="masses" value="1" style="width:auto;" <?= isset($filters['masses']) ? 'checked' : '' ?>> Only masses of priests with more than <?= mass_limit() ?> masses in a day</label>
    <label style="font-weight:normal;"><input type="checkbox" name="no_priest" value="1" style="width:auto;" <?= isset($filters['no_priest']) ? 'checked' : '' ?>> Only activities with no priest assigned</label>
<?php
};

// The zone "Add from source" fills: the user's own zone, or for a super admin
// (who has none) the zone being viewed.
$sourceZoneName = '';
foreach ($zones as $z) {
    if ((int) $z['id'] === (int) $filterZone) {
        $sourceZoneName = $z['name'];
    }
}

$pageTitle = 'Activities — Pastores Admin';
$pageWide = true; // the table has many columns
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Activities</h1>

<div class="card" data-modal data-add-label="Add from source">
  <h2>Add from source</h2>
  <form method="post" data-zone="<?= e($sourceZoneName) ?>" onsubmit="return confirm('Replace the activities of ' + this.dataset.zone + ' on the dates in this range with the source data?');">
    <input type="hidden" name="action" value="from_source">
    <input type="hidden" name="qs" value="<?= e($filterQs) ?>">
    <?php if ($admin['role'] === 'super'): ?><input type="hidden" name="zone_id" value="<?= (int) $filterZone ?>"><?php endif; ?>
    <p style="margin:0 0 4px;">Fills <strong><?= e($sourceZoneName) ?></strong><?= $admin['role'] === 'centre' ? ' (' . e($scopeCentreName ?? '') . ' only)' : '' ?> from the source table:
      each date in the range takes the source rows with the same week (which occurrence of that day it is in the month) and day, e.g. 24/09/2026 is the 4th Thursday, so week 4, day 5.</p>
    <div class="row">
      <div>
        <label for="source_from">Start date</label>
        <input type="date" id="source_from" name="source_from" required>
      </div>
      <div>
        <label for="source_to">End date</label>
        <input type="date" id="source_to" name="source_to" required>
      </div>
    </div>
    <small class="hint" style="min-height:0;">Existing activities of <?= $admin['role'] === 'centre' ? 'your centre' : 'this zone' ?> on the dates that have source rows are <strong>replaced</strong>. Dates with no source rows are left untouched. Each class A date in the range (see the liturgical calendar) also gets Med and Ben activities for <?= $admin['role'] === 'centre' ? 'your centre' : 'every centre of the zone' ?>, if they don't have them yet. Up to <?= SOURCE_APPLY_MAX_DAYS ?> days at a time.</small>
    <div class="btn-row">
      <button type="submit">Add from source</button>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="New activity"<?= $editing ? ' data-open' : '' ?>>
  <h2><?= $editing ? 'Edit activity' : 'New activity' ?></h2>
  <form method="post">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <input type="hidden" name="qs" value="<?= e($filterQs) ?>">
    <div class="row">
      <div>
        <label for="zone_id">Zone</label>
        <?php if ($admin['role'] === 'super'): ?>
          <select id="zone_id" name="zone_id" required>
            <option value="">Select a zone</option>
            <?php foreach ($zones as $zone): ?>
              <option value="<?= (int) $zone['id'] ?>" <?= (($editing['zone_id'] ?? $filterZone) == $zone['id']) ? 'selected' : '' ?>>
                <?= e($zone['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" value="<?= e($zones[0]['name'] ?? '') ?>" disabled>
        <?php endif; ?>
      </div>
      <div>
        <label for="activity_date">Date</label>
        <input type="date" id="activity_date" name="activity_date" value="<?= e($editing['activity_date'] ?? '') ?>">
        <small class="hint" id="date_hint"><?= e($dateHint) ?></small>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="centre">Centre</label>
        <?php if ($admin['role'] === 'centre'): ?>
          <input type="text" value="<?= e($scopeCentreName ?? '') ?>" disabled>
        <?php else: ?>
          <?php render_select('centre', 'centre', $formCentres, $editing['centre'] ?? null, 'Select a centre'); ?>
        <?php endif; ?>
        <small class="hint" id="section_hint"><?= e($sectionHint) ?></small>
      </div>
      <div>
        <label for="activity">Activity</label>
        <?php render_select('activity', 'activity', $activityOptions, $editing['activity'] ?? null, 'Select an activity'); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="labor">Labor</label>
        <?php render_select('labor', 'labor', $laborOptions, $editing['labor'] ?? null, 'Select a labor'); ?>
      </div>
      <div>
        <label for="priest">Priest</label>
        <?php render_select('priest', 'priest', $formPriests, $editing['priest'] ?? null, 'Select a priest'); ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="from_time">From</label>
        <input type="time" id="from_time" name="from_time" value="<?= e($editing['from_time'] ?? '') ?>">
      </div>
      <div>
        <label for="to_time">To</label>
        <input type="time" id="to_time" name="to_time" value="<?= e($editing['to_time'] ?? '') ?>">
      </div>
      <div>
        <label for="duration">Duration</label>
        <input type="time" id="duration" name="duration" value="<?= e($editing['duration'] ?? '') ?>">
      </div>
    </div>
    <label for="description">Description</label>
    <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
    <div class="btn-row">
      <button type="submit"><?= $editing ? 'Save changes' : 'Create activity' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/activities/index.php?zone=<?= (int) $filterZone ?><?= $filterQs !== '' ? '&' . e($filterQs) : '' ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="<?= $filters ? 'Filter (' . count($filters) . ')' : 'Filter' ?>" data-button-class="muted" data-align="right">
  <h2>Filter activities</h2>
  <form method="get">
    <?php if ($admin['role'] === 'super'): ?><input type="hidden" name="zone" value="<?= (int) $filterZone ?>"><?php endif; ?>
    <?php $renderFilterFields('f_'); ?>
    <div class="btn-row">
      <button type="submit">Apply filters</button>
      <a class="btn muted" href="<?= e($clearUrl) ?>">Clear filters</a>
    </div>
  </form>
</div>

<div class="card" data-modal data-add-label="Export" data-button-class="muted" data-close-on-submit>
  <h2>Export activities to CSV</h2>
  <form method="get">
    <input type="hidden" name="export" value="1">
    <?php if ($admin['role'] === 'super'): ?><input type="hidden" name="zone" value="<?= (int) $filterZone ?>"><?php endif; ?>
    <p style="margin:0 0 4px;">Do you want to filter what gets exported? Leave everything empty to export
      <strong>all</strong> activities<?= $admin['role'] === 'centre' ? ' of your centre' : ' in this zone' ?>.</p>
    <?php $renderFilterFields('x_'); ?>
    <div class="btn-row">
      <button type="submit">Export<?= $filters ? ' with these filters' : '' ?></button>
      <a class="btn muted" href="<?= e($exportAllUrl) ?>" data-close-modal>Export all</a>
    </div>
  </form>
</div>


<?php if ($admin['role'] === 'super'): ?>
<div class="card">
  <form method="get">
    <label for="zone">Showing zone</label>
    <select id="zone" name="zone" onchange="this.form.submit()">
      <?php foreach ($zones as $zone): ?>
        <option value="<?= (int) $zone['id'] ?>" <?= $filterZone == $zone['id'] ? 'selected' : '' ?>><?= e($zone['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>
<?php endif; ?>

<?php if ($filters): ?>
<p class="table-hint">
  <strong>Filtered by</strong>
  <?php foreach ($filters as $key => $value): ?>
    &middot; <?= e($filterLabels[$key]) ?>: <?= e($key === 'day' ? PASTORES_DAYS[$value] : (in_array($key, ['absent', 'duplicate', 'bilocation', 'masses', 'no_priest'], true) ? 'yes' : $value)) ?>
  <?php endforeach; ?>
  &middot; <a href="<?= e($clearUrl) ?>">Clear filters</a>
</p>
<?php endif; ?>
<?php if ($legend): ?>
<div class="legend" role="group" aria-label="Flagged activities">
  <?php foreach ($legend as $key => $l): ?>
    <?php if ($l['count'] > 0): ?>
      <a class="legend-box legend-<?= e($l['class']) ?><?= $l['active'] ? ' active' : '' ?>" href="<?= e($legendUrl($key)) ?>"
         title="Show only: <?= e($l['label']) ?>"><?= e($l['label']) ?> <strong><?= $l['count'] ?></strong></a>
    <?php else: ?>
      <span class="legend-box legend-<?= e($l['class']) ?> zero"><?= e($l['label']) ?> <strong>0</strong></span>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<p class="table-hint">Click a row to edit it in place &middot; click a column heading to sort &middot; showing the latest <?= count($activities) ?> activit<?= count($activities) === 1 ? 'y' : 'ies' ?><?= count($activities) >= 300 ? ' (limit 300)' : '' ?>.</p>
<div class="table-wrap">
<table>
  <thead><tr>
    <th>Date</th><th>Day</th><th>Wk</th><th>Centre</th><th>Section</th><th>Activity</th>
    <th>Labor</th><th>Priest</th><th>From</th><th>To</th><th>Duration</th><th>Description</th><th></th>
  </tr></thead>
  <tbody id="activities-body" data-qs="<?= e($filterQs) ?>">
  <?php foreach ($activities as $a): ?>
    <?= activity_row_html($a, $filterQs) ?>

  <?php endforeach; ?>
  <?php if (!$activities): ?>
    <tr><td colspan="13" style="text-align:center;color:#888;"><?= $filters ? 'No activities match these filters.' : 'No activities for this zone yet.' ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<style>
  main table td { vertical-align: middle; }
  tbody tr[data-id] { cursor: pointer; }
  td[data-type=date], td[data-type=time], td[data-derived] { white-space: nowrap; }
  td.desc { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  tr.duplicate td { background: #eaf1fb; }
  tr.duplicate:not(.editing):hover td { background: #dce8f8; }
  tr.mass-limit td { background: #f1e9fb; }
  tr.mass-limit:not(.editing):hover td { background: #e6d9f7; }
  tr.bilocation td { background: #fff3cd; }
  tr.bilocation:not(.editing):hover td { background: #ffeaa7; }
  tr.absent td { background: #fdecea; }
  tr.absent:not(.editing):hover td { background: #fbdcd8; }
  tr.no-priest td { background: #eceff1; }
  tr.no-priest:not(.editing):hover td { background: #dde3e6; }
  tr.editing td { background: #fffbe6; padding: 4px 6px; cursor: default; }
  tr.editing td.desc { max-width: none; overflow: visible; }
  tr.editing .cell-input { width: 100%; min-width: 96px; padding: 4px 6px; font-size: 12px; }
  tr.editing td[data-type=time] .cell-input { min-width: 84px; }
  tr.editing td.actions { white-space: nowrap; }
  tr.editing td.actions button { padding: 4px 10px; font-size: 12px; }
  tr.editing .row-error { display: block; color: #c62828; font-size: 12px; margin-top: 4px; white-space: normal; }
  tr.saved td { background: #e8f5e9; }
  .table-wrap { overflow-x: auto; }
  .table-hint { font-size: 12px; color: #666; margin: 0 0 8px; }
  .legend { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 12px; }
  .legend-box { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; text-decoration: none; border: 1px solid transparent; }
  .legend-box strong { font-weight: 700; }
  a.legend-box:hover { filter: brightness(0.95); }
  a.legend-box.active { outline: 2px solid currentColor; outline-offset: 1px; }
  .legend-box.zero { opacity: .5; cursor: default; }
  .legend-no-priest { background: #eceff1; color: #37474f; border-color: #b0bec5; }
  .legend-absent { background: #fdecea; color: #c62828; border-color: #ef9a9a; }
  .legend-bilocation { background: #fff3cd; color: #8a6100; border-color: #e0c060; }
  .legend-mass-limit { background: #f1e9fb; color: #5b2fa0; border-color: #c3a8ec; }
  .legend-duplicate { background: #eaf1fb; color: #1a56a8; border-color: #9dbbe6; }
  .absent-badge { display: inline-block; background: #fff; color: #c62828; border: 1px solid #ef9a9a; border-radius: 10px; font-size: 11px; line-height: 16px; padding: 0 6px; margin-left: 4px; cursor: help; }
  .no-priest-badge { display: inline-block; background: #fff; color: #37474f; border: 1px solid #b0bec5; border-radius: 10px; font-size: 11px; line-height: 16px; padding: 0 6px; margin-left: 4px; cursor: help; }
  tr.saved-warn td { background: #f8c9c4; }
  tr.saved-dup td { background: #cfe0f7; }
  tr.saved-bilocation td { background: #ffe08a; }
  tr.saved-masses td { background: #dccbf5; }
  tr.saved-no-priest td { background: #cfd8dc; }
  .mass-badge { display: inline-block; background: #fff; color: #5b2fa0; border: 1px solid #c3a8ec; border-radius: 10px; font-size: 11px; line-height: 16px; padding: 0 6px; margin-left: 4px; cursor: help; }
  .bilocation-badge { display: inline-block; background: #fff; color: #8a6100; border: 1px solid #e0c060; border-radius: 10px; font-size: 11px; line-height: 16px; padding: 0 6px; margin-left: 4px; cursor: help; }
  .dup-badge { display: inline-block; background: #fff; color: #1a56a8; border: 1px solid #9dbbe6; border-radius: 10px; font-size: 11px; line-height: 16px; padding: 0 6px; margin-left: 4px; cursor: help; }
</style>
<script>
(function () {
  var weekStart = <?= json_encode($weekStart) ?>;          // 'sunday' | 'monday'
  var centres = <?= json_encode($allCentres) ?>;           // {zone_id, name, section}
  var priests = <?= json_encode($allPriests) ?>;           // {zone_id, name}
  var labors = <?= json_encode($laborOptions) ?>;
  var activityTypes = <?= json_encode($activityOptions) ?>;
  var fixedCentre = <?= json_encode($admin['role'] === 'centre' ? $scopeCentreName : null) ?>;
  var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  // Day, weekday # and week # (which occurrence of that day in the month,
  // e.g. 3rd Sunday = 3) are derived from the date, the same way as
  // the server does (see date_parts() in includes/functions.php).
  function calendarParts(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    var d = m && new Date(+m[1], +m[2] - 1, +m[3]);
    if (!d || d.getMonth() !== +m[2] - 1) return null;
    var idx = weekStart === 'monday' ? (d.getDay() + 6) % 7 : d.getDay();
    return { dayName: dayNames[d.getDay()], weekday: idx + 1, week: Math.ceil(d.getDate() / 7) };
  }
  function ordinal(n) {
    var s = (n % 100 >= 11 && n % 100 <= 13) ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] || 'th');
    return n + s;
  }
  function sectionOf(zone, name) {
    var c = centres.filter(function (c) { return c.zone_id == zone && c.name === name; })[0];
    return (c && c.section) || '';
  }

  // ---- Form (modal) -------------------------------------------------
  var dateInput = document.getElementById('activity_date');
  var dateHint = document.getElementById('date_hint');
  var zoneSel = document.getElementById('zone_id');
  var centreSel = document.getElementById('centre');      // absent for centre-scoped admins
  var priestSel = document.getElementById('priest');
  var sectionHint = document.getElementById('section_hint');

  function formZone() { return zoneSel ? parseInt(zoneSel.value, 10) : <?= (int) $formZoneId ?>; }

  function showDateHint() {
    var p = calendarParts(dateInput.value);
    dateHint.textContent = p ? p.dayName + ' · weekday ' + p.weekday + ' · ' + ordinal(p.week) + ' ' + p.dayName + ' of the month' : '';
  }
  function showSectionHint() {
    var name = centreSel ? centreSel.value : fixedCentre;
    sectionHint.textContent = name ? 'Section: ' + (sectionOf(formZone(), name) || '—') : '';
  }
  // Refill a <select> with the current zone's entries, keeping the current
  // choice if it's still available.
  function refill(sel, rows, placeholder) {
    var keep = sel.value;
    sel.innerHTML = '';
    sel.add(new Option(placeholder, ''));
    rows.forEach(function (r) {
      if (r.zone_id == formZone()) sel.add(new Option(r.name, r.name, false, r.name === keep));
    });
  }

  dateInput.addEventListener('input', showDateHint);
  if (centreSel) centreSel.addEventListener('change', showSectionHint);
  if (zoneSel) {
    zoneSel.addEventListener('change', function () {
      if (centreSel) refill(centreSel, centres, 'Select a centre');
      refill(priestSel, priests, 'Select a priest');
      showSectionHint();
    });
  }

  // ---- Spreadsheet-style row editing --------------------------------
  // Click a row to turn its cells into inputs. Enter or Save stores it,
  // Esc or Cancel puts it back. Day/week/section update as you type
  // because they are derived; they aren't editable themselves.
  var tbody = document.getElementById('activities-body');
  var editing = null;   // { tr, orig }
  var dirty = false;
  var url = '/admin/activities/index.php';

  function optionsFor(type, zone, current) {
    var list = type === 'centre' ? centres.filter(function (c) { return c.zone_id == zone; }).map(function (c) { return c.name; })
      : type === 'priest' ? priests.filter(function (p) { return p.zone_id == zone; }).map(function (p) { return p.name; })
      : type === 'activity' ? activityTypes.slice()
      : labors.slice();
    if (current && list.indexOf(current) < 0) list.push(current); // keep values that are no longer in the list
    return list;
  }

  function makeEditor(td, zone) {
    var type = td.getAttribute('data-type'), value = td.getAttribute('data-value') || '', el;
    if (type === 'centre' && fixedCentre) return;      // centre admins can't move an activity to another centre
    if (type === 'centre' || type === 'priest' || type === 'labor' || type === 'activity') {
      el = document.createElement('select');
      el.add(new Option('', ''));
      optionsFor(type, zone, value).forEach(function (o) { el.add(new Option(o, o)); });
    } else {
      el = document.createElement('input');
      el.type = type;
    }
    el.value = value;
    el.name = td.getAttribute('data-field');
    el.className = 'cell-input';
    td.textContent = '';
    td.appendChild(el);
  }

  function updateDerived(tr) {
    var date = tr.querySelector('[name=activity_date]');
    var p = date ? calendarParts(date.value) : null;
    tr.querySelector('[data-derived=day]').textContent = p ? p.dayName.slice(0, 3) : '';
    tr.querySelector('[data-derived=week]').textContent = p ? p.week : '';
    var centre = tr.querySelector('[name=centre]');
    if (centre) tr.querySelector('[data-derived=section]').textContent = sectionOf(tr.getAttribute('data-zone'), centre.value);
  }

  function startEdit(tr, focusTd) {
    var orig = tr.cloneNode(true);
    var zone = tr.getAttribute('data-zone');
    tr.classList.add('editing');
    Array.prototype.forEach.call(tr.querySelectorAll('td[data-field]'), function (td) { makeEditor(td, zone); });
    tr.querySelector('td.actions').innerHTML =
      '<button type="button" class="save">Save</button> <button type="button" class="secondary cancel">Cancel</button>' +
      '<span class="row-error"></span>';
    editing = { tr: tr, orig: orig };
    dirty = false;
    var target = (focusTd && focusTd.querySelector('.cell-input')) || tr.querySelector('.cell-input');
    if (target) target.focus();
  }

  function cancelEdit() {
    if (!editing) return;
    editing.tr.replaceWith(editing.orig);
    editing = null;
  }

  function showError(msg) {
    var box = editing && editing.tr.querySelector('.row-error');
    if (box) box.textContent = msg;
  }

  function saveEdit() {
    if (!editing) return;
    var tr = editing.tr;
    var fd = new FormData();
    fd.append('action', 'inline_save');
    fd.append('id', tr.getAttribute('data-id'));
    fd.append('qs', tbody.getAttribute('data-qs') || '');
    Array.prototype.forEach.call(tr.querySelectorAll('[name]'), function (el) { fd.append(el.name, el.value); });
    var buttons = tr.querySelectorAll('td.actions button');
    Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
    showError('');
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || res.body.error) throw new Error(res.body.error || 'Save failed.');
        var holder = document.createElement('tbody');
        holder.innerHTML = res.body.html;
        var fresh = holder.firstElementChild;
        tr.replaceWith(fresh);
        editing = null;
        // green normally; red if the priest is absent, amber for a priest bilocation, purple if over the mass limit, blue if it duplicates another row
        var flashClass = res.body.absent ? 'saved-warn' : res.body.bilocation ? 'saved-bilocation' : res.body.masses ? 'saved-masses' : res.body.no_priest ? 'saved-no-priest' : res.body.duplicate ? 'saved-dup' : 'saved';
        fresh.classList.add(flashClass);
        setTimeout(function () { fresh.classList.remove(flashClass); }, flashClass === 'saved' ? 1200 : 3000);
      })
      .catch(function (err) {
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
        showError(err.message === 'Failed to fetch' ? 'Could not reach the server.' : err.message);
      });
  }

  tbody.addEventListener('click', function (ev) {
    if (ev.target.closest('button.save')) { saveEdit(); return; }
    if (ev.target.closest('button.cancel')) { cancelEdit(); return; }
    if (ev.target.closest('a, button, input, select, textarea, form')) return;
    var tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    if (editing) {
      if (editing.tr === tr) return;
      if (dirty) { showError('Save or cancel this row before editing another.'); return; }
      cancelEdit();
    }
    startEdit(tr, ev.target.closest('td'));
  });
  tbody.addEventListener('input', function (ev) { if (editing) { dirty = true; updateDerived(editing.tr); } });
  tbody.addEventListener('change', function (ev) { if (editing) { dirty = true; updateDerived(editing.tr); } });
  tbody.addEventListener('keydown', function (ev) {
    if (!editing) return;
    if (ev.key === 'Enter' && ev.target.matches('input, select')) { ev.preventDefault(); saveEdit(); }
    if (ev.key === 'Escape') { ev.preventDefault(); cancelEdit(); }
  });
})();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
