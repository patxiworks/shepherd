<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/liturgical_day.php';
require __DIR__ . '/../../includes/activity_io.php';
require __DIR__ . '/../../includes/source_apply.php';
require __DIR__ . '/../../includes/vigil.php';
require __DIR__ . '/../../includes/absences.php';
require __DIR__ . '/../../includes/activity_multiday_busy.php';
require __DIR__ . '/../../includes/activity_duplicates.php';
require __DIR__ . '/../../includes/activity_bilocation.php';
require __DIR__ . '/../../includes/activity_mass_limit.php';
require __DIR__ . '/../../includes/activity_no_priest.php';
require __DIR__ . '/../../includes/day_optimiser.php';
require __DIR__ . '/../includes/flash.php';
require __DIR__ . '/../includes/bulk.php';
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
    foreach (['date_from', 'date_to', 'day', 'centre', 'section', 'priest', 'absent', 'in_multiday', 'duplicate', 'bilocation', 'masses', 'no_priest'] as $key) {
        $v = trim((string) ($src[$key] ?? ''));
        if ($v === '') {
            continue;
        }
        if (in_array($key, ['absent', 'in_multiday', 'duplicate', 'bilocation', 'masses', 'no_priest'], true)) {
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
        if ($key === 'in_multiday') {
            $where[] = activity_in_multiday_sql();
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

    // The table has no Labor cell, so an inline save doesn't post it: keep the stored one.
    $labor = trim($in['labor'] ?? '') ?: null;
    if (!array_key_exists('labor', $in) && $id) {
        $stmt = $pdo->prepare('SELECT labor FROM activities WHERE id = ?');
        $stmt->execute([$id]);
        $labor = $stmt->fetchColumn() ?: null;
    }
    // Duration is always to − from (blank unless both are set and to is later).
    $from = ($in['from_time'] ?? '') ?: null;
    $to = ($in['to_time'] ?? '') ?: null;
    $duration = null;
    if ($from && $to && preg_match('/^(\d\d):(\d\d)/', $from, $f) && preg_match('/^(\d\d):(\d\d)/', $to, $t)) {
        $mins = ($t[1] * 60 + $t[2]) - ($f[1] * 60 + $f[2]);
        if ($mins > 0) {
            $duration = sprintf('%02d:%02d:00', intdiv($mins, 60), $mins % 60);
        }
    }

    return [
        'week' => $parts['week'] ?? null,
        'day' => $parts['day'] ?? null,
        'weekday' => $parts['weekday'] ?? null,
        'activity_date' => $activityDate,
        'centre' => $centre,
        'activity' => trim($in['activity'] ?? '') ?: null,
        'section' => $section ?: null,
        'labor' => $labor,
        'from_time' => $from,
        'to_time' => $to,
        'duration' => $duration,
        'priest' => trim($in['priest'] ?? '') ?: null,
        'description' => trim($in['description'] ?? '') ?: null,
    ];
}

// The extra columns (absent_note, duplicate_count, bilocation_note, mass_count)
// that activity_row_html() turns into badges.
function activity_flags_select(PDO $pdo): string
{
    return activity_absent_select($pdo) . activity_in_multiday_select($pdo) . activity_duplicate_select() . activity_bilocation_select() . activity_mass_count_select();
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
    // Priest in charge of a multi-day activity elsewhere (see includes/activity_multiday_busy.php).
    $inMultiday = !empty($a['multiday_note']);
    if ($inMultiday) {
        $priestText .= ' <span class="multiday-badge" title="' . e('In charge of ' . $a['multiday_detail']) . '">' . e('priest in ' . $a['multiday_note']) . '</span>';
    }
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
    $classes = trim(($missingPriest ? 'no-priest ' : '') . (!empty($a['absent_note']) ? 'absent ' : '') . ($inMultiday ? 'in-multiday ' : '') . ($hasBilocation ? 'bilocation ' : '') . ($overLimit ? 'mass-limit ' : '') . ($dupes ? 'duplicate' : ''));
    $activityText = e($a['activity']) . ($dupes
        ? ' <span class="dup-badge" title="' . e("Identical to $dupes other activit" . ($dupes === 1 ? 'y' : 'ies') . ' (same date, centre, activity, priest and times)') . '">duplicate</span>' : '');
    ob_start();
    ?>
<tr data-id="<?= $id ?>" data-zone="<?= $zone ?>" data-masses="<?= $massCount ?>"<?= $classes !== '' ? ' class="' . $classes . '"' : '' ?>>
  <?= $cell('activity_date', 'date', $a['activity_date'], e($a['activity_date'])) ?>
  <?= $cell('centre', 'centre', $a['centre'], e($a['centre'])) ?>
  <?= $cell('activity', 'activity', $a['activity'], $activityText, ' data-sort="' . e($a['activity']) . '"') ?>
  <?= $cell('priest', 'priest', $a['priest'], $priestText, ' data-sort="' . e($a['priest']) . '"') ?>
  <?= $cell('from_time', 'time', $a['from_time'] ? substr($a['from_time'], 0, 5) : '', $t($a['from_time'])) ?>
  <?= $cell('to_time', 'time', $a['to_time'] ? substr($a['to_time'], 0, 5) : '', $t($a['to_time'])) ?>
  <?= $cell('description', 'text', $a['description'], e($a['description']), ' class="desc" title="' . e($a['description']) . '"') ?>
  <td class="actions">
    <?= icon_edit('/admin/activities/index.php?edit=' . $id . '&zone=' . $zone . ($qs !== '' ? '&' . $qs : '')) ?>
    <form class="inline" method="post" onsubmit="return confirm('Delete this activity?');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="zone_id" value="<?= $zone ?>">
      <input type="hidden" name="qs" value="<?= e($qs) ?>">
      <?= icon_delete() ?>
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
        $reply(['html' => activity_row_html($row, http_build_query(activity_filters_from_qs($_POST['qs'] ?? ''))), 'absent' => !empty($row['absent_note']), 'in_multiday' => !empty($row['multiday_note']), 'duplicate' => (int) $row['duplicate_count'] > 0, 'bilocation' => !empty($row['bilocation_note']), 'masses' => (int) $row['mass_count'] > mass_limit(), 'no_priest' => empty($row['priest'])]);
    }

    if ($action === 'optimise') {
        // Proposal for one date (nothing is saved); answers with JSON.
        $zoneId = $admin['role'] === 'super' ? (int) ($_POST['zone_id'] ?? 0) : (int) $admin['zone_id'];
        $date = (string) ($_POST['date'] ?? '');
        header('Content-Type: application/json; charset=utf-8');
        if (!$zoneId || !date_parts($date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Pick a zone and a date first.']);
            exit;
        }
        echo json_encode(optimise_day($pdo, $zoneId, $date, $scopeCentreName));
        exit;
    }

    if ($action === 'from_source') {
        // Only ever the current user's own zone (a super admin has none, so
        // the zone being viewed); a centre admin only touches their centre.
        $zoneId = $admin['role'] === 'super' ? (int) ($_POST['zone_id'] ?? 0) : (int) $admin['zone_id'];
        try {
            if (!$zoneId) {
                throw new InvalidArgumentException('Zone is required.');
            }
            $r = apply_source_to_activities($pdo, $zoneId, (string) ($_POST['source_from'] ?? ''), (string) ($_POST['source_to'] ?? ''), $weekStart, $scopeCentreName, !empty($_POST['overwrite']));
            $parts = [];
            if ($r['dates']) {
                $parts[] = "Added from source: {$r['inserted']} activities on {$r['dates']} dates" . ($r['deleted'] ? " (replacing {$r['deleted']} existing)" : '');
            }
            if ($r['kept_dates']) {
                $parts[] = "Left {$r['kept_dates']} date" . ($r['kept_dates'] === 1 ? '' : 's') . ' with existing activities untouched (tick "Overwrite existing activities" to replace them)';
            }
            if ($r['class_a_dates']) {
                $parts[] = "Class A: added {$r['class_a_added']} Med/Ben activities on {$r['class_a_dates']} class A date" . ($r['class_a_dates'] === 1 ? '' : 's');
            }
            if ($r['auto_added']) {
                $parts[] = "Med/Ben/Vigil rows added automatically: {$r['auto_timed']} of {$r['auto_added']} got start/end times from source"
                    . ($r['auto_fallback'] ? " ({$r['auto_fallback']} of them the centre's usual times, as source has none for that weekday)" : '')
                    . ($r['auto_added'] > $r['auto_timed'] ? '; the rest have none in source and are blank' : '');
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
                $inMultiday = in_multiday_activities_in_range($pdo, $zoneId, $from, $to, $scopeCentreName);
                if ($inMultiday['count']) {
                    $names = [];
                    foreach ($inMultiday['priests'] as $priest => $n) {
                        $names[] = "$priest ($n)";
                    }
                    $findings['in_multiday'] = ['Priest in a multi-day activity', $inMultiday['count']];
                    $parts[] = "Warning: {$inMultiday['count']} activit" . ($inMultiday['count'] === 1 ? 'y has a priest who is' : 'ies have a priest who is') . ' in charge of a multi-day activity elsewhere: ' . implode(', ', $names);
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
    } elseif ($action === 'optimise_apply') {
        $zoneId = $admin['role'] === 'super' ? (int) ($_POST['zone_id'] ?? 0) : (int) $admin['zone_id'];
        $date = (string) ($_POST['date'] ?? '');
        if (!$zoneId || !date_parts($date)) {
            flash('error', 'Pick a zone and a date first.');
        } else {
            [$changed, $error] = apply_day_assignments($pdo, $zoneId, $date, $scopeCentreName, (array) ($_POST['assign'] ?? []));
            if ($error !== null) {
                flash('error', "Optimisation not applied: $error");
            } else {
                $left = day_conflict_count($pdo, $zoneId, $date, $scopeCentreName);
                $message = "Optimisation applied: $changed activit" . ($changed === 1 ? 'y' : 'ies') . ' changed priest on ' . date('d/m/Y', strtotime($date));
                if ($left) {
                    flash('warning', "$message. $left activit" . ($left === 1 ? 'y still has' : 'ies still have') . ' a conflict.');
                } else {
                    flash('success', "$message. No conflicts left on that day.");
                }
            }
        }
    } elseif ($action === 'bulk_delete') {
        if (!empty($_POST['all_matching'])) {
            // Every activity matching the filters in `qs` in the posted zone (a zone/centre admin's own zone).
            $bulkZone = $admin['role'] === 'super' ? (int) ($_POST['zone'] ?? 0) : (int) $admin['zone_id'];
            $bulkFilters = activity_filters_from_qs($_POST['qs'] ?? '');
            if (!absences_available($pdo)) {
                unset($bulkFilters['absent']);
            }
            if (!multiday_busy_available_safe($pdo)) {
                unset($bulkFilters['in_multiday']);
            }
            [$whereSql, $whereArgs] = activity_where($admin, $scopeCentreName, $bulkZone, $bulkFilters);
            bulk_delete_matching($pdo, 'activities', $whereSql, $whereArgs, 'activity', 'activities', fn() => touch_zone($pdo, $bulkZone));
        } else {
            bulk_run(function (int $id) use ($pdo, $admin, $scopeCentreName): ?string {
                if (!activity_in_scope($pdo, $id, $admin, $scopeCentreName)) {
                    return 'You do not have access to that activity.';
                }
                $stmt = $pdo->prepare('SELECT zone_id FROM activities WHERE id = ?');
                $stmt->execute([$id]);
                $zoneId = $stmt->fetchColumn();
                $pdo->prepare('DELETE FROM activities WHERE id = ?')->execute([$id]);
                if ($zoneId) {
                    touch_zone($pdo, (int) $zoneId);
                }
                return null;
            }, 'activity', 'activities');
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
                // Thursday before the first Friday: every centre gets a Vigil, with
                // the times source gives that centre's Vigil.
                $vigilAdded = add_vigil_activities($pdo, $zoneId, (string) $fields['activity_date'], $weekStart, $scopeCentreName,
                    source_time_lookup($pdo, $zoneId, $scopeCentreName));
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
                $multidayNote = activity_in_multiday_note($pdo, $savedId);
                if ($multidayNote !== null) {
                    $warnings[] = "{$fields['priest']} is in charge of {$multidayNote[1]} then";
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
        $query = array_diff_key($query, array_flip(['date_from', 'date_to', 'day', 'centre', 'section', 'priest', 'absent', 'in_multiday', 'duplicate', 'bilocation', 'masses', 'no_priest'])) + $sourceFilters;
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
if (!multiday_busy_available_safe($pdo)) {
    unset($filters['in_multiday']); // multi-day priest column (migration 016) not applied yet
}
$filterQs = http_build_query($filters);
$activities = [];
$totalMatching = 0;
if ($filterZone) {
    [$whereSql, $whereArgs] = activity_where($admin, $scopeCentreName, (int) $filterZone, $filters);
    $stmt = $pdo->prepare("SELECT a.*" . activity_flags_select($pdo) . " FROM activities a WHERE $whereSql ORDER BY a.activity_date DESC, a.from_time DESC LIMIT 300");
    $stmt->execute($whereArgs);
    $activities = $stmt->fetchAll();
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activities a WHERE $whereSql");
    $countStmt->execute($whereArgs);
    $totalMatching = (int) $countStmt->fetchColumn();
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
    'in_multiday' => ['Priest in multi-day', 'in-multiday'],
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
        'in_multiday' => multiday_busy_available_safe($pdo) ? activity_in_multiday_sql() : null,
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
$filterLabels = ['date_from' => 'From', 'date_to' => 'To', 'day' => 'Day', 'centre' => 'Centre', 'section' => 'Section', 'priest' => 'Priest', 'absent' => 'Priest absent', 'in_multiday' => 'Priest in multi-day', 'duplicate' => 'Duplicates', 'bilocation' => 'Priest bilocation', 'masses' => 'Over mass limit', 'no_priest' => 'No priest'];
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
    <?php if (multiday_busy_available_safe($pdo)): ?>
    <label style="font-weight:normal;"><input type="checkbox" name="in_multiday" value="1" style="width:auto;" <?= isset($filters['in_multiday']) ? 'checked' : '' ?>> Only activities whose priest is in charge of a multi-day activity at another centre</label>
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
<?php /* page styles first, so the (long) table is styled from the first paint */ ?>
<style>
  main table td { vertical-align: middle; }
  tbody tr[data-id] { cursor: pointer; }
  td[data-type=date], td[data-type=time], td[data-derived] { white-space: nowrap; }
  td.desc { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  tr.duplicate td { background: #eaf1fb; }
  tr.duplicate:not(.editing):hover td { background: #dce8f8; }
  tr.mass-limit td { background: #f1e9fb; }
  tr.mass-limit:not(.editing):hover td { background: #e6d9f7; }
  tr.in-multiday td { background: #e0f2f1; }
  tr.in-multiday:not(.editing):hover td { background: #cfe9e7; }
  tr.bilocation td { background: #fff3cd; }
  tr.bilocation:not(.editing):hover td { background: #ffeaa7; }
  tr.absent td { background: #fdecea; }
  tr.absent:not(.editing):hover td { background: #fbdcd8; }
  tr.no-priest td { background: #eceff1; }
  tr.no-priest:not(.editing):hover td { background: #dde3e6; }
  /* No padding: the .cell-input below fills the cell edge-to-edge (its own
     padding/border give it breathing room) instead of leaving a gap around
     it, so an editing row's wider fields (selects, longer inputs) don't
     also need the cell — and so the table — any wider than they already do. */
  /* border-collapse resolves a same-width/same-style border conflict (this
     row's border-top vs. the row above's border-bottom, both 1px solid)
     unreliably — not necessarily in favour of the one declared here — so
     these are 2px: strictly wider always wins, unambiguously. */
  tr.editing td { background: #fffbe6; padding: 0; cursor: default; border-top: 2px solid #000; border-bottom: 2px solid #000; }
  tr.editing td.desc { max-width: none; overflow: visible; }
  tr.editing .cell-input { width: 100%; min-width: 96px; height: 40px; padding: 4px 6px; font-size: 12px; border: none; }
  tr.editing td[data-type=time] .cell-input { min-width: 84px; }
  tr.editing td.actions { white-space: nowrap; padding: 0 8px; }
  tr.editing td.actions button { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; padding: 0; vertical-align: middle; }
  tr.editing td.actions button svg { width: 14px; height: 14px; }
  tr.editing .row-error { display: block; color: #c62828; font-size: 12px; margin-top: 4px; white-space: normal; }
  /* Fixed column widths (sized for the inline editors), so a row doesn't
     change the layout when it switches to editing. Description takes the rest. */
  table.act-table { table-layout: fixed; width: 100%; min-width: 1000px; }
  table.act-table th.bulk-col { width: 36px; }
  table.act-table th.w-date { width: 140px; }
  table.act-table th.w-centre { width: 170px; }
  table.act-table th.w-activity { width: 170px; }
  table.act-table th.w-priest { width: 200px; }
  table.act-table th.w-time { width: 100px; }
  table.act-table th.w-actions { width: 84px; }
  table.act-table td { overflow-wrap: anywhere; }
  table.act-table tr.editing .cell-input { min-width: 0; }
  table.act-table th { position: sticky; }
  .col-resize { position: absolute; top: 0; right: -3px; width: 7px; height: 100%; cursor: col-resize; z-index: 3; touch-action: none; }
  .col-resize:hover, .col-resize.dragging { background: rgba(103,58,183,.35); }
  tr.saved td { background: #e8f5e9; }
  .table-hint { font-size: 12px; color: #666; margin: 0 0 8px; }
  .legend { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 12px; }
  .legend-box { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; text-decoration: none; border: 1px solid transparent; }
  .legend-box strong { font-weight: 700; }
  a.legend-box:hover { filter: brightness(0.95); }
  a.legend-box.active { outline: 2px solid currentColor; outline-offset: 1px; }
  .legend-box.zero { opacity: .5; cursor: default; }
  .legend-no-priest { background: #eceff1; color: #37474f; border-color: #b0bec5; }
  .legend-absent { background: #fdecea; color: #c62828; border-color: #ef9a9a; }
  .legend-in-multiday { background: #e0f2f1; color: #00695c; border-color: #80cbc4; }
  .legend-bilocation { background: #fff3cd; color: #8a6100; border-color: #e0c060; }
  .legend-mass-limit { background: #f1e9fb; color: #5b2fa0; border-color: #c3a8ec; }
  .legend-duplicate { background: #eaf1fb; color: #1a56a8; border-color: #9dbbe6; }
  .absent-badge { display: inline-block; background: #fff; color: #c62828; border: 1px solid #ef9a9a; border-radius: 10px; font-size: calc(var(--tbl-fs, 13px) - 2px); line-height: 1.5; padding: 0 6px; margin-left: 4px; cursor: help; }
  .multiday-badge { display: inline-block; background: #fff; color: #00695c; border: 1px solid #80cbc4; border-radius: 10px; font-size: calc(var(--tbl-fs, 13px) - 2px); line-height: 1.5; padding: 0 6px; margin-left: 4px; cursor: help; }
  .no-priest-badge { display: inline-block; background: #fff; color: #37474f; border: 1px solid #b0bec5; border-radius: 10px; font-size: calc(var(--tbl-fs, 13px) - 2px); line-height: 1.5; padding: 0 6px; margin-left: 4px; cursor: help; }
  tr.saved-warn td { background: #f8c9c4; }
  tr.saved-dup td { background: #cfe0f7; }
  tr.saved-multiday td { background: #b2dfdb; }
  tr.saved-bilocation td { background: #ffe08a; }
  tr.saved-masses td { background: #dccbf5; }
  tr.saved-no-priest td { background: #cfd8dc; }
  .mass-badge { display: inline-block; background: #fff; color: #5b2fa0; border: 1px solid #c3a8ec; border-radius: 10px; font-size: calc(var(--tbl-fs, 13px) - 2px); line-height: 1.5; padding: 0 6px; margin-left: 4px; cursor: help; }
  .bilocation-badge { display: inline-block; background: #fff; color: #8a6100; border: 1px solid #e0c060; border-radius: 10px; font-size: calc(var(--tbl-fs, 13px) - 2px); line-height: 1.5; padding: 0 6px; margin-left: 4px; cursor: help; }
  .dup-badge { display: inline-block; background: #fff; color: #1a56a8; border: 1px solid #9dbbe6; border-radius: 10px; font-size: calc(var(--tbl-fs, 13px) - 2px); line-height: 1.5; padding: 0 6px; margin-left: 4px; cursor: help; }
  /* Zone admin, small screens only: a fixed icon (always at the same
     viewport corner, so it never scrolls away) hides everything above the
     table — including the shared top menu bar — so the table can use the
     full screen height instead of what's left under all of that. Its
     chevron points up ("collapse everything upward") normally and flips to
     point down ("bring it back") once toggled — #act-*.js below just toggles
     body.act-focus; toggling back restores everything exactly as it was
     (nothing here is ever removed, just hidden). */
  .act-focus-toggle { display: none; }
  @media (max-width: 899px) {
    /* top: below the (2-row, ~83px at this width) header normally, so it
       doesn't sit over "Logout"; once the header's hidden (act-focus) there's
       nothing to clear, so it moves up to the very top of the screen. */
    .act-focus-toggle { display: flex; align-items: center; justify-content: center; position: fixed; top: 93px; right: 10px; z-index: 50; width: 36px; height: 36px; background: #673AB7; color: #fff; border: none; border-radius: 50%; box-shadow: 0 2px 8px rgba(0,0,0,.3); cursor: pointer; transition: top .15s; }
    .act-focus-toggle svg { width: 18px; height: 18px; transition: transform .15s; }
    body.act-focus .act-focus-toggle { top: 10px; }
    body.act-focus .act-focus-toggle svg { transform: rotate(180deg); }
    body.act-focus header.topbar,
    body.act-focus main > .flash,
    body.act-focus #act-above-table { display: none; }
    body.act-focus main { margin: 0; padding: 0; max-width: none; }
    body.act-focus .table-wrap { max-height: 100vh; height: 100vh; border: none; border-radius: 0; box-shadow: none; }
  }
</style>
<?php if ($admin['role'] === 'zone'): ?>
<!-- Small screens only (act-focus-toggle is hidden by CSS otherwise): hides
     everything above the table — including the top menu bar — so the table
     can use the full screen height. See #act-focus-toggle.js below and
     .act-focus-toggle/body.act-focus in the <style> further down. A single
     up-chevron that layout_top.php-style CSS rotates 180deg (down) once
     body.act-focus is set — no icon-swapping JS needed. -->
<button type="button" id="act-focus-toggle" class="act-focus-toggle" aria-pressed="false" aria-label="Hide filters and menu, show just the table" title="Hide filters and menu">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 15l-6-6-6 6"/></svg>
</button>
<?php endif; ?>
<div id="act-above-table">
<h1>Activities</h1>
<nav class="tabs">
  <a class="active" href="/admin/activities/index.php">List</a>
  <a href="/admin/activities/calendar.php<?= $filterZone ? '?zone=' . (int) $filterZone : '' ?>">Calendar</a>
  <a href="/admin/activities/grid.php<?= $filterZone ? '?zone=' . (int) $filterZone : '' ?>">Grid</a>
  <a href="/admin/activities/dashboard.php<?= $filterZone ? '?zone=' . (int) $filterZone : '' ?>">Dashboard</a>
</nav>

<div class="card" data-modal data-add-label="Add from source">
  <h2>Add from source</h2>
  <form method="post" data-zone="<?= e($sourceZoneName) ?>" onsubmit="if (this.overwrite.checked) return confirm('Overwrite the existing activities of ' + this.dataset.zone + ' on every date in this range that has source rows? This cannot be undone.'); return true;">
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
    <label style="font-weight:normal;margin-top:8px;"><input type="checkbox" name="overwrite" value="1" style="width:auto;"> Overwrite existing activities in this date range</label>
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

<?php
// "Pick a date" calendar button, placed after New activity by the toolbar
// script (data-toolbar-item / data-before-right). Choosing a date reloads the
// list filtered to that single date (date_from = date_to), keeping the other
// filters; clearing it removes the date filter. It reads "No date" unless
// exactly one date is being filtered on.
$dayBase = array_diff_key($filters, ['date_from' => 1, 'date_to' => 1]) + ($admin['role'] === 'super' ? ['zone' => (int) $filterZone] : []);
$dayValue = (isset($filters['date_from'], $filters['date_to']) && $filters['date_from'] === $filters['date_to']) ? $filters['date_from'] : '';
?>
<?php if ($dayValue !== '' && $filterZone): ?>
<button type="button" id="opt-btn" class="secondary" data-toolbar-item data-after-left title="Propose priests for this date's activities so that none of them clash">Optimise &amp; Review</button>
<?php endif; ?>
<?php
$dayHref = fn(string $d) => '/admin/activities/index.php?' . http_build_query($dayBase + ['date_from' => $d, 'date_to' => $d]);
$dayLit = $dayValue !== '' ? liturgical_day_text($pdo, $dayValue) : '';
?>
<span class="day-nav" data-toolbar-item data-after-left>
  <?php if ($dayValue !== ''): ?><a class="btn secondary" href="<?= e($dayHref((new DateTimeImmutable($dayValue))->modify('-1 day')->format('Y-m-d'))) ?>" aria-label="Previous day">&lsaquo;</a><?php endif; ?>
  <label class="day-pick<?= $dayValue === '' ? ' empty' : '' ?>" title="Show only the activities of one date">
    <input type="date" id="day-pick" value="<?= e($dayValue) ?>" data-base="<?= e(http_build_query($dayBase)) ?>" aria-label="Show activities of one date">
    <span class="day-pick-empty" aria-hidden="true">No date</span>
  </label>
  <?php if ($dayValue !== ''): ?><a class="btn secondary" href="<?= e($dayHref((new DateTimeImmutable($dayValue))->modify('+1 day')->format('Y-m-d'))) ?>" aria-label="Next day">&rsaquo;</a><?php endif; ?>
  <button type="button" class="secondary" id="day-today">Today</button>
  <?php if ($dayValue !== ''): ?>
  <span class="day-box">
    <span class="day-text"><?= e((new DateTimeImmutable($dayValue))->format('l j F Y')) ?></span>
    <?php if ($dayLit !== ''): ?><span class="day-lit"><?= e($dayLit) ?></span><?php endif; ?>
  </span>
  <?php endif; ?>
</span>
<style>
  .day-nav { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .day-box { display: flex; flex-direction: column; margin-left: 4px; max-width: 460px; }
  .day-text { font-size: 16px; font-weight: 600; }
  .day-lit { font-size: 14px; color: #666; margin-top: 2px; }
  .day-pick { position: relative; display: inline-flex; align-items: center; margin: 0; font-weight: normal; }
  .day-pick input { width: auto; margin: 0; }
  /* No single date filtered (none, or a range): hide the dd/mm/yyyy placeholder and say "No date" instead. */
  .day-pick-empty { display: none; position: absolute; left: 10px; pointer-events: none; color: #555; background: #fff; padding-right: 4px; }
  .day-pick.empty .day-pick-empty { display: inline; }
  .day-pick.empty input::-webkit-datetime-edit { opacity: 0; }
</style>
<script>
(function () {
  var input = document.getElementById('day-pick');
  if (!input) return;
  var FLAG = 'actShowAll';   // set when the date was cleared on purpose, so today isn't re-applied
  function dayUrl(value) {
    var q = input.dataset.base;
    if (value) q += (q ? '&' : '') + 'date_from=' + value + '&date_to=' + value;
    return '/admin/activities/index.php' + (q ? '?' + q : '');
  }
  // Default date = today: arriving with no filters at all (only a zone, at most)
  // opens the list on today, by the browser's clock.
  var onlyZone = location.search.replace(/^\?/, '').split('&').every(function (p) { return p === '' || /^zone=/.test(p); });
  var showAll = false;
  try { showAll = sessionStorage.getItem(FLAG) === '1'; } catch (e) {}
  if (onlyZone && !showAll) {
    var t = new Date();
    location.replace(dayUrl(t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0')));
    return;
  }
  var todayBtn = document.getElementById('day-today');
  if (todayBtn) todayBtn.addEventListener('click', function () {
    var t = new Date();
    try { sessionStorage.removeItem(FLAG); } catch (e) {}
    location.href = dayUrl(t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'));
  });
  input.addEventListener('change', function () {
    try { if (input.value) sessionStorage.removeItem(FLAG); else sessionStorage.setItem(FLAG, '1'); } catch (e) {}
    var q = input.dataset.base;
    if (input.value) q += (q ? '&' : '') + 'date_from=' + input.value + '&date_to=' + input.value;
    location.href = '/admin/activities/index.php' + (q ? '?' + q : '');
  });
})();
</script>

<?php if ($dayValue !== '' && $filterZone): ?>
<?php
// "Optimise day": only with a single date picked. The server proposes a priest
// for every activity of that date with no clashes (includes/day_optimiser.php);
// the modal shows it, lets the admin change priests, then accept or reject.
?>
<dialog class="modal" id="opt-dialog">
  <div class="card">
    <button type="button" class="modal-close" id="opt-x" aria-label="Close">&times;</button>
    <h2>Proposed distribution for <?= e(date('d/m/Y', strtotime($dayValue))) ?></h2>
    <p id="opt-summary" class="hint" style="min-height:0;">Working out a proposal…</p>
    <div class="opt-legend" id="opt-legend" hidden>
      <span><i style="background:#e8f5e9"></i>Priest changed</span>
      <span><i style="background:#fdecea"></i>Still in conflict</span>
      <span><span class="mass-badge">3 masses</span> over the mass limit (<?= mass_limit() ?>)</span>
      <span><span class="bilocation-badge">bilocation</span> overlapping times, different centres</span>
      <span><span class="absent-badge">absent</span> priest away</span>
      <span>⚠ in a dropdown: that priest would clash</span>
    </div>
    <div class="opt-layout" id="opt-wrap" hidden>
      <div class="table-wrap">
        <table id="opt-table">
          <thead><tr><th>Time</th><th>Centre</th><th>Activity</th><th>Current priest</th><th>Proposed priest</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <aside class="opt-summary-box">
        <h3>Priests on <?= e(date('D j M Y', strtotime($dayValue))) ?> <span id="opt-sum-count"></span></h3>
        <table><tbody id="opt-sum-body"></tbody></table>
      </aside>
    </div>
    <form method="post" id="opt-form" class="btn-row" hidden>
      <input type="hidden" name="action" value="optimise_apply">
      <input type="hidden" name="zone_id" value="<?= (int) $filterZone ?>">
      <input type="hidden" name="date" value="<?= e($dayValue) ?>">
      <input type="hidden" name="qs" value="<?= e($filterQs) ?>">
      <span id="opt-fields"></span>
      <button type="submit" id="opt-accept">Accept</button>
      <button type="button" class="secondary" id="opt-reject">Reject</button>
    </form>
  </div>
</dialog>
<style>
  #opt-table td { white-space: nowrap; }
  #opt-table tr.changed td { background: #e8f5e9; }
  #opt-table tr.bad td { background: #fdecea; }
  #opt-table select { min-width: 190px; margin: 0; }
  #opt-table .why { display: block; font-size: 12px; color: #c62828; white-space: normal; }
  #opt-table .was-bad { color: #c62828; }
  #opt-form .btn-row, form#opt-form { margin-top: 14px; display: flex; gap: 8px; }
  dialog#opt-dialog { width: min(1280px, 96vw); }
  .opt-layout { display: flex; gap: 16px; align-items: flex-start; }
  .opt-layout > .table-wrap { flex: 1 1 auto; min-width: 0; }
  .opt-summary-box { flex: 0 0 300px; font-size: 13px; border: 1px solid #ceb9f3; border-radius: 8px; padding: 8px 12px; position: sticky; top: 0; max-height: 70vh; overflow-y: auto; }
  .opt-summary-box h3 { margin: 0 0 6px; font-size: 14px; color: #673AB7; }
  .opt-summary-box h3 span { font-weight: normal; color: #666; }
  .opt-summary-box td { vertical-align: top; padding: 4px 6px; }
  .opt-summary-box td:first-child { white-space: nowrap; }
  .opt-summary-box td div { padding: 1px 0; }
  .opt-summary-box small { color: #666; }
  .opt-summary-box .fixed-act { color: #777; font-style: italic; }
  .opt-summary-box .absent-note { color: #c62828; }
  .opt-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 12px; color: #444; margin-bottom: 10px; }
  .opt-legend i { display: inline-block; width: 12px; height: 12px; border: 1px solid #bbb; vertical-align: -2px; margin-right: 4px; }
  @media (max-width: 900px) { .opt-layout { flex-direction: column; } .opt-summary-box { flex-basis: auto; width: 100%; position: static; } }
  #opt-form[hidden], #opt-wrap[hidden], #opt-legend[hidden] { display: none; }
</style>
<script>
(function () {
  var btn = document.getElementById('opt-btn'), dlg = document.getElementById('opt-dialog');
  if (!btn || !dlg) return;
  var summary = document.getElementById('opt-summary'), wrap = document.getElementById('opt-wrap'),
      form = document.getElementById('opt-form'), tbody = dlg.querySelector('tbody'),
      accept = document.getElementById('opt-accept'), legend = document.getElementById('opt-legend'),
      sumBody = document.getElementById('opt-sum-body'), sumCount = document.getElementById('opt-sum-count');
  var data = null, sel = [];
  function key(s) { return (s || '').trim().toLowerCase(); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  // Would giving row i the priest p clash, with the other rows as they stand now?
  function clash(i, p) {
    if (!p) return 'no priest';
    var r = data.rows[i], k = key(p);
    for (var n = 0; n < r.pairs.length; n++) if (key(sel[r.pairs[n]]) === k) return 'bilocation';
    if (r.mass) {
      var c = (data.fixed_masses[k] || 0);
      data.rows.forEach(function (o, j) { if (o.mass && key(sel[j]) === k) c++; });
      if (c > data.mass_limit) return 'over the mass limit';
    }
    return '';
  }
  // The "Priests on <date>" box for the table as it stands: each priest with
  // their activities (including the ones that stay as they are), plus the mass,
  // bilocation and absent flags.
  function renderSummary() {
    var by = {}, masses = {}, bilo = {};
    Object.keys(data.absent).forEach(function (p) { by[p] = []; });
    data.rows.forEach(function (r, i) {
      var name = sel[i] || '(no priest)';
      (by[name] = by[name] || []).push({ time: r.from, activity: r.activity, centre: r.centre });
      if (sel[i]) {
        if (r.mass) masses[key(name)] = (masses[key(name)] || 0) + 1;
        if (r.pairs.some(function (j) { return key(sel[j]) === key(sel[i]); })) bilo[key(name)] = true;
      }
    });
    data.fixed.forEach(function (f) {
      (by[f.priest] = by[f.priest] || []).push({ time: f.from, activity: f.activity, centre: f.centre, fixed: true });
    });
    function rank(n) { return data.absent[n] !== undefined ? 2 : n === '(no priest)' ? 1 : 0; }
    var names = Object.keys(by).sort(function (a, b) { return rank(a) - rank(b) || a.localeCompare(b, undefined, { sensitivity: 'base' }); });
    sumBody.textContent = '';
    names.forEach(function (name) {
      var tr = sumBody.insertRow(), th = tr.insertCell(), td = tr.insertCell(), k = key(name);
      th.appendChild(document.createTextNode(name));
      var cnt = document.createElement('small'); cnt.textContent = ' (' + by[name].length + ')'; th.appendChild(cnt);
      var total = (masses[k] || 0) + (data.fixed_masses[k] || 0);
      if (name !== '(no priest)') { var ms = document.createElement('div'), sm0 = document.createElement('small'); sm0.textContent = 'Mass: ' + total; ms.appendChild(sm0); th.appendChild(ms); }
      function badge(cls, text, title) { var d = document.createElement('div'), b = document.createElement('span'); b.className = cls; b.textContent = text; b.title = title; d.appendChild(b); th.appendChild(d); }
      if (total > data.mass_limit) badge('mass-badge', total + ' masses', name + ' has ' + total + ' masses (maximum ' + data.mass_limit + ')');
      if (bilo[k]) badge('bilocation-badge', 'bilocation', 'Overlapping activities in different centres');
      if (data.absent[name] !== undefined) {
        var an = document.createElement('div'); an.className = 'absent-note'; an.textContent = data.absent[name]; td.appendChild(an);
      }
      by[name].sort(function (a, b) { return (a.time || '99').localeCompare(b.time || '99'); }).forEach(function (it) {
        var div = document.createElement('div');
        if (it.fixed) { div.className = 'fixed-act'; div.title = 'Not part of this optimisation'; }
        if (it.time) { var b = document.createElement('b'); b.textContent = it.time; div.appendChild(b); div.appendChild(document.createTextNode(' ')); }
        div.appendChild(document.createTextNode(it.activity || ''));
        if (it.centre) { var sm = document.createElement('small'); sm.textContent = ' \u00b7 ' + it.centre; div.appendChild(sm); }
        td.appendChild(div);
      });
    });
    sumCount.textContent = '(' + names.length + ')';
  }
  function render() {
    renderSummary();
    var bad = 0, changed = 0;
    Array.prototype.forEach.call(tbody.rows, function (tr, i) {
      var r = data.rows[i], select = tr.querySelector('select'), why = clash(i, sel[i]);
      if (select) {
        Array.prototype.forEach.call(select.options, function (o) {
          if (!o.value) return;
          var w = o.value === sel[i] ? '' : clash(i, o.value);
          o.textContent = o.value + (w ? ' ⚠ ' + w : '');
        });
      }
      var note = tr.querySelector('.why');
      note.textContent = why;
      tr.classList.toggle('bad', !!why);
      var diff = key(sel[i]) !== key(r.current);
      tr.classList.toggle('changed', diff && !why);
      if (why) bad++;
      if (diff) changed++;
    });
    summary.textContent = 'Before: ' + data.conflicts_before + ' activit' + (data.conflicts_before === 1 ? 'y' : 'ies') + ' in conflict. '
      + (bad ? 'As shown: ' + bad + ' still in conflict (red).' : 'As shown: no conflicts.') + ' ' + changed + ' priest change' + (changed === 1 ? '' : 's') + '.'
      + (data.unresolved ? ' ' + data.unresolved + ' could not be given any priest.' : '');
  }
  function build() {
    tbody.innerHTML = '';
    sel = data.rows.map(function (r) { return r.proposed || ''; });
    data.rows.forEach(function (r, i) {
      var tr = tbody.insertRow();
      tr.innerHTML = '<td>' + esc(r.from + (r.to ? '–' + r.to : '')) + '</td><td>' + esc(r.centre) + '</td><td>' + esc(r.activity) + '</td>'
        + '<td class="' + (r.before.length ? 'was-bad' : '') + '">' + esc(r.current || '(none)') + (r.before.length ? '<span class="why">' + esc(r.before.join(', ')) + '</span>' : '') + '</td>'
        + '<td><select></select><span class="why"></span></td>';
      var s = tr.querySelector('select');
      s.add(new Option('(no priest)', ''));
      r.options.forEach(function (o) { s.add(new Option(o, o)); });
      s.value = sel[i];
      s.addEventListener('change', function () { sel[i] = s.value; render(); });
    });
    wrap.hidden = false; legend.hidden = false; form.hidden = false;
    render();
  }
  function open() {
    data = null; tbody.innerHTML = ''; wrap.hidden = true; legend.hidden = true; form.hidden = true;
    summary.textContent = 'Working out a proposal…';
    dlg.showModal();
    var fd = new FormData();
    fd.append('action', 'optimise');
    fd.append('zone_id', form.elements.zone_id.value);
    fd.append('date', form.elements.date.value);
    fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Failed'); return j; }); })
      .then(function (j) {
        data = j;
        if (!j.rows.length) { summary.textContent = 'There are no activities on this date.'; return; }
        build();
      })
      .catch(function (e) { summary.textContent = 'Could not optimise: ' + e.message; });
  }
  btn.addEventListener('click', open);
  document.getElementById('opt-x').addEventListener('click', function () { dlg.close(); });
  document.getElementById('opt-reject').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
  form.addEventListener('submit', function (ev) {
    var left = Array.prototype.filter.call(tbody.rows, function (tr) { return tr.classList.contains('bad'); }).length;
    if (left && !confirm(left + ' activit' + (left === 1 ? 'y is' : 'ies are') + ' still in conflict. Accept anyway?')) { ev.preventDefault(); return; }
    var box = document.getElementById('opt-fields');
    box.innerHTML = '';
    data.rows.forEach(function (r, i) {
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'assign[' + r.id + ']'; h.value = sel[i];
      box.appendChild(h);
    });
  });
})();
</script>
<?php endif; ?>

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
    &middot; <?= e($filterLabels[$key]) ?>: <?= e($key === 'day' ? PASTORES_DAYS[$value] : (in_array($key, ['absent', 'in_multiday', 'duplicate', 'bilocation', 'masses', 'no_priest'], true) ? 'yes' : $value)) ?>
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
</div>
<?php
// Single date filtered: the priests of the zone who are absent that day (with
// or without activities), for the summary box, which is drawn by the script
// below from the table rows plus this list.
$dayAbsent = [];
if ($dayValue !== '' && absences_available($pdo)) {
    $stmt = $pdo->prepare(
        "SELECT priest, GROUP_CONCAT(CONCAT(COALESCE(NULLIF(activity, ''), 'Absent'), ' (', DATE_FORMAT(start_at, '%d/%m/%Y'), ' to ', DATE_FORMAT(end_at, '%d/%m/%Y'), ')')
                ORDER BY start_at SEPARATOR '; ') AS note
         FROM absences WHERE start_at < DATE_ADD(?, INTERVAL 1 DAY) AND end_at > ? GROUP BY priest"
    );
    $stmt->execute([$dayValue, $dayValue]);
    foreach ($stmt as $row) {
        if (in_array($row['priest'], $formPriests, true) && (!isset($filters['priest']) || $filters['priest'] === $row['priest'])) {
            $dayAbsent[$row['priest']] = $row['note'];
        }
    }
}
?>
<div class="act-layout">
<div class="table-wrap">
<table class="act-table" data-bulk-total="<?= $totalMatching ?>" data-bulk-extra="<?= e(json_encode(['qs' => $filterQs, 'zone' => (int) $filterZone])) ?>">
  <thead><tr>
    <th class="w-date">Date</th><th class="w-centre">Centre</th><th class="w-activity">Activity</th>
    <th class="w-priest">Priest</th><th class="w-time">From</th><th class="w-time">To</th><th>Description</th><th class="w-actions"></th>
  </tr></thead>
  <tbody id="activities-body" data-qs="<?= e($filterQs) ?>">
  <?php foreach ($activities as $a): ?>
    <?= activity_row_html($a, $filterQs) ?>

  <?php endforeach; ?>
  <?php if (!$activities): ?>
    <tr><td colspan="8" style="text-align:center;color:#888;"><?= $filters ? 'No activities match these filters.' : 'No activities for this zone yet.' ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($dayValue !== ''): ?>
<details class="day-summary" id="day-summary" open>
  <summary>Priests on <?= e(date('D j M Y', strtotime($dayValue))) ?> <span id="day-summary-count"></span></summary>
  <table data-no-zoom>
    <thead><tr><th>Priest</th><th>Activities</th></tr></thead>
    <tbody id="day-summary-body"></tbody>
  </table>
</details>
<style>
  .act-layout { display: block; }
  .day-summary { position: relative; z-index: 15; background: #fff; border: 1px solid #ceb9f3; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.15); font-size: 13px; }
  .day-summary summary { cursor: pointer; padding: 8px 12px; font-weight: 600; color: #673AB7; }
  .day-summary summary span { font-weight: normal; color: #666; }
  .day-summary table { width: 100%; }
  .day-summary td, .day-summary th { vertical-align: top; }
  .day-summary tbody tr, .day-summary tbody tr:nth-child(even) { background: #fff; }
  .day-summary .absent-note { color: #c62828; }
  .day-summary tbody td:first-child { white-space: nowrap; }
  .day-summary td div { padding: 1px 0; }
  .day-summary td small { color: #666; }
  /* Large screens: docked to the right of the table, following the scroll. */
  @media (min-width: 1410px) {
    .act-layout { display: flex; align-items: flex-start; gap: 16px; }
    .act-layout > .table-wrap { flex: 1 1 auto; min-width: 0; }
    .day-summary { flex: 0 0 340px; position: sticky; top: 8px; max-height: calc(100vh - 16px); overflow-y: auto; }
  }
  /* Small screens: floating bottom-right, collapsed to its title until tapped. */
  @media (max-width: 1409px) {
    .day-summary { position: fixed; right: 10px; bottom: 10px; z-index: 15; width: min(92vw, 360px); max-height: 60vh; overflow-y: auto; }
    body.act-focus .day-summary { display: none; }
  }
</style>
<script>
(function () {
  var d = document.getElementById('day-summary');
  if (d && window.matchMedia('(max-width: 1409px)').matches) d.removeAttribute('open');

  // Rebuilds the summary from the table rows as they are now; called after an
  // inline save so an edit shows up here without reloading.
  var absent = <?= json_encode($dayAbsent, JSON_FORCE_OBJECT | JSON_HEX_TAG) ?>;   // {priest: absence text} for this date
  window.refreshDaySummary = function (doc) {
    var body = document.getElementById('day-summary-body');
    var rows = (doc || document).querySelectorAll('#activities-body tr[data-id]');
    if (!body) return;
    var by = {}, flags = {};   // flags[priest] = the mass-limit / bilocation badges of any of their rows
    Object.keys(absent).forEach(function (p) { by[p] = []; });
    Array.prototype.forEach.call(rows, function (tr) {
      function v(f) { var td = tr.querySelector('td[data-field=' + f + ']'); return td ? (td.getAttribute('data-value') || '') : ''; }
      var from = v('from_time'), to = v('to_time');
      var name = v('priest') || '(no priest)';
      var f = flags[name] = flags[name] || {};
      ['mass-badge', 'bilocation-badge'].forEach(function (c) { var b = tr.querySelector('.' + c); if (b && !f[c]) f[c] = b; });
      (by[name] = by[name] || []).push({ time: from, activity: v('activity'), centre: v('centre') });
      // Masses that day: those shown here, or the server's count (which also includes other zones) if higher.
      var isMass = v('activity').toLowerCase() === 'mass';
      f.masses = Math.max(f.masses || 0, parseInt(tr.getAttribute('data-masses'), 10) || 0);
      if (isMass) f.local = (f.local || 0) + 1;
    });
    var names = Object.keys(by).sort(function (a, b) {
      // absent priests always last, then "(no priest)"
      function rank(n) { return absent[n] !== undefined ? 2 : n === '(no priest)' ? 1 : 0; }
      if (rank(a) !== rank(b)) return rank(a) - rank(b);
      return a.localeCompare(b, undefined, { sensitivity: 'base' });
    });
    body.textContent = '';
    names.forEach(function (name) {
      var tr = document.createElement('tr'), th = document.createElement('td'), td = document.createElement('td');
      th.appendChild(document.createTextNode(name));
      var cnt = document.createElement('small');
      cnt.textContent = ' (' + by[name].length + ')';
      cnt.title = by[name].length + ' activit' + (by[name].length === 1 ? 'y' : 'ies');
      th.appendChild(cnt);
      var fl = flags[name] || {};
      if (name !== '(no priest)') {
        var md = document.createElement('div'), ms = document.createElement('small');
        ms.textContent = 'Mass: ' + Math.max(fl.local || 0, fl.masses || 0);
        md.appendChild(ms); th.appendChild(md);
      }
      ['mass-badge', 'bilocation-badge'].forEach(function (c) {
        if (!fl[c]) return;
        var d = document.createElement('div'); d.appendChild(fl[c].cloneNode(true)); th.appendChild(d);
      });
      if (absent[name] !== undefined) {
        var an = document.createElement('div');
        an.className = 'absent-note';
        an.textContent = absent[name];
        td.appendChild(an);
      }
      by[name].sort(function (a, b) { return (a.time || '99').localeCompare(b.time || '99'); }).forEach(function (it) {
        var div = document.createElement('div');
        if (it.time) { var b = document.createElement('b'); b.textContent = it.time; div.appendChild(b); div.appendChild(document.createTextNode(' ')); }
        div.appendChild(document.createTextNode(it.activity));
        if (it.centre) { var s = document.createElement('small'); s.textContent = ' \u00b7 ' + it.centre; div.appendChild(s); }
        td.appendChild(div);
      });
      tr.appendChild(th); tr.appendChild(td); body.appendChild(tr);
    });
    var n = document.getElementById('day-summary-count');
    if (n) n.textContent = '(' + names.length + ')';
  };
  window.refreshDaySummary(); // initial render, so the flags (badges) are shown too
})();
</script>
<?php endif; ?>
</div>
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
    var d = tr.querySelector('[data-derived=day]'), w = tr.querySelector('[data-derived=week]'), s = tr.querySelector('[data-derived=section]');
    if (d) d.textContent = p ? p.dayName.slice(0, 3) : '';
    if (w) w.textContent = p ? p.week : '';
    var centre = tr.querySelector('[name=centre]');
    if (s && centre) s.textContent = sectionOf(tr.getAttribute('data-zone'), centre.value);
  }

  function startEdit(tr, focusTd) {
    var orig = tr.cloneNode(true);
    var zone = tr.getAttribute('data-zone');
    tr.classList.add('editing');
    Array.prototype.forEach.call(tr.querySelectorAll('td[data-field]'), function (td) { makeEditor(td, zone); });
    tr.querySelector('td.actions').innerHTML =
      '<button type="button" class="save" aria-label="Save" title="Save"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></button> ' +
      '<button type="button" class="secondary cancel" aria-label="Cancel" title="Cancel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg></button>' +
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
        if (window.refreshDaySummary) {
          window.refreshDaySummary();
          // The server only re-rendered this row, but an edit can change the mass
          // count (and so the badge) of other rows, so re-read the page for those.
          fetch(location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) { window.refreshDaySummary(new DOMParser().parseFromString(html, 'text/html')); })
            .catch(function () {});
        }
        // green normally; red if the priest is absent, amber for a priest bilocation, purple if over the mass limit, blue if it duplicates another row
        var flashClass = res.body.absent ? 'saved-warn' : res.body.in_multiday ? 'saved-multiday' : res.body.bilocation ? 'saved-bilocation' : res.body.masses ? 'saved-masses' : res.body.no_priest ? 'saved-no-priest' : res.body.duplicate ? 'saved-dup' : 'saved';
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
<script>
// Zone admin, small screens only (button is absent otherwise — see the
// <style> above): toggles body.act-focus, which hides the top menu bar and
// everything above the table (#act-above-table) so the table fills the
// screen. The chevron itself flips via CSS (body.act-focus .act-focus-toggle
// svg { transform: rotate(180deg) }), so there's no icon markup to swap here.
(function () {
  var toggle = document.getElementById('act-focus-toggle');
  if (!toggle) return;
  toggle.addEventListener('click', function () {
    var on = document.body.classList.toggle('act-focus');
    toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
    toggle.setAttribute('aria-label', on ? 'Show filters and menu' : 'Hide filters and menu, show just the table');
    toggle.title = on ? 'Show filters and menu' : 'Hide filters and menu';
  });
})();
</script>
<script>
// Drag the right edge of a column heading to resize it. The first drag freezes
// every column at its current width (so the table can grow wider than its box
// and scroll); widths are remembered per browser. Double-click an edge to reset.
(function () {
  var table = document.querySelector('table.act-table');
  if (!table) return;
  var KEY = 'actColWidths';
  var ths = function () { return Array.prototype.slice.call(table.tHead.rows[0].cells).filter(function (th) { return !th.classList.contains('bulk-col'); }); };
  function apply(widths) {
    var cols = ths();
    if (widths.length !== cols.length) return;
    // Never wider than the space beside the summary box (the table's own box),
    // but never narrower than the 1000px minimum either.
    var maxW = Math.max(1000, table.parentNode.clientWidth);
    var total = widths.reduce(function (a, b) { return a + b; }, 0);
    if (total > maxW) widths = widths.map(function (w) { return Math.max(50, Math.floor(w * maxW / total)); });
    cols.forEach(function (th, i) { th.style.width = widths[i] + 'px'; });
    table.style.width = widths.reduce(function (a, b) { return a + b; }, 0) + 'px';
    table.style.minWidth = '1000px';
  }
  function save(widths) { try { localStorage.setItem(KEY, JSON.stringify(widths)); } catch (e) {} }
  function reset() {
    ths().forEach(function (th) { th.style.width = ''; });
    table.style.width = ''; table.style.minWidth = '';
    try { localStorage.removeItem(KEY); } catch (e) {}
  }
  try { var saved = JSON.parse(localStorage.getItem(KEY) || 'null'); if (Array.isArray(saved)) apply(saved.map(Number)); } catch (e) {}

  // Once columns have fixed widths the table no longer follows its frame by
  // itself (the frame changes with the window and with the summary box), so
  // rescale the columns to the frame's width whenever that changes.
  function fit() {
    if (!table.style.width) return;   // default layout: width 100%, already follows
    var cols = ths();
    var widths = cols.map(function (th) { return parseFloat(th.style.width) || th.getBoundingClientRect().width; });
    var total = widths.reduce(function (a, b) { return a + b; }, 0);
    var target = Math.max(1000, table.parentNode.clientWidth);
    if (Math.abs(target - total) < 2) return;
    widths = widths.map(function (w) { return Math.max(50, Math.round(w * target / total)); });
    cols.forEach(function (th, i) { th.style.width = widths[i] + 'px'; });
    table.style.width = widths.reduce(function (a, b) { return a + b; }, 0) + 'px';
  }
  fit();
  if (window.ResizeObserver) new ResizeObserver(fit).observe(table.parentNode);
  else window.addEventListener('resize', fit);

  var cols = ths();
  cols.slice(0, -1).forEach(function (th, i) {
    var h = document.createElement('span');
    h.className = 'col-resize'; h.title = 'Drag to resize · double-click to reset all';
    th.appendChild(h);
    h.addEventListener('click', function (ev) { ev.stopPropagation(); }); // not a sort click
    h.addEventListener('dblclick', function (ev) { ev.stopPropagation(); reset(); });
    h.addEventListener('pointerdown', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      var all = ths();
      var widths = all.map(function (t) { return t.getBoundingClientRect().width; });
      apply(widths.map(Math.round));
      var startX = ev.clientX, startW = widths[i];
      h.setPointerCapture(ev.pointerId);
      h.classList.add('dragging');
      function move(e) {
        var w = Math.max(50, Math.round(startW + e.clientX - startX));
        var next = widths.map(Math.round); next[i] = w;
        var maxW = Math.max(1000, table.parentNode.clientWidth);
        var over = next.reduce(function (a, b) { return a + b; }, 0) - maxW;
        if (over > 0) next[i] = Math.max(50, w - over);   // stop at the available width
        apply(next);
      }
      function up() {
        h.classList.remove('dragging');
        h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up);
        save(ths().map(function (t) { return Math.round(t.getBoundingClientRect().width); }));
      }
      h.addEventListener('pointermove', move); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
    });
  });
})();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
