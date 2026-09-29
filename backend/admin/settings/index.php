<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/liturgical_calendar.php';
require __DIR__ . '/../../includes/theme.php';
require __DIR__ . '/../../includes/activity_mass_limit.php';
require __DIR__ . '/../includes/flash.php';
admin_require_role('super');

$pdo = pastores_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? 'save_week_start';

    if ($action === 'save_theme') {
        $mode = $_POST['theme_mode'] ?? '';
        if (!isset(PASTORES_THEME_MODES[$mode])) {
            flash('error', 'Invalid theme.');
        } else {
            set_setting($pdo, 'theme_mode', $mode);
            flash('success', 'Theme saved.');
        }
        header('Location: /admin/settings/index.php');
        exit;
    }

    if ($action === 'save_max_masses') {
        [$min, $max] = PASTORES_MAX_MASSES_SETTING_RANGE;
        $value = trim($_POST['max_masses_per_day'] ?? '');
        if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
            flash('error', "The maximum number of masses per day must be a whole number from $min to $max.");
        } else {
            set_setting($pdo, 'max_masses_per_day', (string) (int) $value);
            flash('success', 'Maximum masses per day saved.');
        }
        header('Location: /admin/settings/index.php');
        exit;
    }

    if ($action === 'save_calendar' || $action === 'generate_calendar') {
        foreach (PASTORES_CALENDAR_OPTIONS as $setting => $unused) {
            set_setting($pdo, $setting, isset($_POST[$setting]) ? '1' : '0');
        }
        if ($action === 'generate_calendar') {
            try {
                $result = generate_liturgical_calendar($pdo);
                flash('success', sprintf(
                    'Liturgical calendar generated: %d days, %s to %s.',
                    $result['days'], $result['from'], $result['to']
                ));
            } catch (PDOException $e) {
                error_log('generate_liturgical_calendar: ' . $e->getMessage());
                flash('error', $e->getCode() === '42S02'
                    ? 'The liturgical_calendar table does not exist yet. Apply migrate/009_liturgical_calendar.sql first.'
                    : 'Could not save the liturgical calendar (database error). Nothing was changed.');
            } catch (Throwable $e) {
                error_log('generate_liturgical_calendar: ' . $e->getMessage());
                flash('error', 'Could not generate the liturgical calendar: ' . $e->getMessage());
            }
        } else {
            flash('success', 'Calendar options saved.');
        }
        header('Location: /admin/settings/index.php');
        exit;
    }

    $weekStart = $_POST['week_start'] ?? '';
    if (!isset(PASTORES_WEEK_STARTS[$weekStart])) {
        flash('error', 'Invalid week start.');
    } else {
        $changed = $weekStart !== get_week_start($pdo);
        set_setting($pdo, 'week_start', $weekStart);
        if ($changed) {
            // weekday # / week # are stored on each activity (and source) row, so re-derive them.
            recompute_activity_calendar($pdo, $weekStart);
            flash('success', 'Settings saved. Weekday numbers of all activities were recalculated.');
        } else {
            flash('success', 'Settings saved.');
        }
    }
    header('Location: /admin/settings/index.php');
    exit;
}

$weekStart = get_week_start($pdo);
$maxMasses = mass_limit($pdo);
$themeMode = theme_mode($pdo);
$themeToday = theme_today_color($pdo);
$calendarOptions = liturgical_calendar_options($pdo);
[$windowFrom, $windowTo] = liturgical_calendar_window();
$calendarStatus = null;
$calendarUpcoming = [];
try {
    $calendarStatus = liturgical_calendar_status($pdo);
    $calendarUpcoming = liturgical_calendar_upcoming($pdo);
} catch (PDOException $e) {
    // table not created yet: the card says so below
}

$pageTitle = 'Settings — Pastores Admin';
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Settings</h1>

<div class="card">
  <form method="post">
    <input type="hidden" name="action" value="save_week_start">
    <label for="week_start">Week starts on</label>
    <?php render_select('week_start', 'week_start', PASTORES_WEEK_STARTS, $weekStart, 'Select…'); ?>
    <small class="hint" style="min-height:0;">
      Used to number each activity's weekday # and each source row's day (1 = the first day of the week: Sunday = 1 if the
      week starts on Sunday, Monday = 1 otherwise). Changing this recalculates every existing activity and source row.
    </small>
    <div class="btn-row">
      <button type="submit">Save settings</button>
    </div>
  </form>
</div>

<div class="card">
  <form method="post">
    <input type="hidden" name="action" value="save_max_masses">
    <label for="max_masses_per_day">Maximum masses per priest per day</label>
    <input type="number" id="max_masses_per_day" name="max_masses_per_day" value="<?= (int) $maxMasses ?>"
           min="<?= PASTORES_MAX_MASSES_SETTING_RANGE[0] ?>" max="<?= PASTORES_MAX_MASSES_SETTING_RANGE[1] ?>" step="1" required style="max-width:120px;">
    <small class="hint" style="min-height:0;">
      On the Activities page, the masses of a priest who has more than this many on the same day (all zones counted) are
      flagged and the priest gets a warning when saving. Nothing is blocked or changed in the data, and it applies straight away.
    </small>
    <div class="btn-row">
      <button type="submit">Save</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Theme</h2>
  <form method="post">
    <input type="hidden" name="action" value="save_theme">
    <?php foreach (PASTORES_THEME_MODES as $value => $label): ?>
      <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
        <input type="radio" name="theme_mode" value="<?= e($value) ?>" style="width:auto;" <?= $themeMode === $value ? 'checked' : '' ?>>
        <?= e($label) ?>
      </label>
    <?php endforeach; ?>
    <small class="hint" style="min-height:0;">
      "Liturgical season" colours the panel after today's colour in the liturgical calendar: green, red, purple, rose, or gold
      in place of white. <?php if ($themeToday !== null): ?>Today is <strong><?= e(PASTORES_THEME_COLORS[$themeToday][0]) ?></strong>.<?php else: ?>
      The calendar has no entry for today (generate it below), so the default sky blue is used meanwhile.<?php endif; ?>
    </small>
    <div class="btn-row">
      <button type="submit">Save theme</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Liturgical calendar</h2>
  <form method="post">
    <?php foreach (PASTORES_CALENDAR_OPTIONS as $setting => [$option, $default, $label]): ?>
      <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
        <input type="checkbox" name="<?= e($setting) ?>" value="1" style="width:auto;" <?= $calendarOptions[$option] ? 'checked' : '' ?>>
        <?= e($label) ?>
      </label>
    <?php endforeach; ?>

    <?php if ($calendarStatus === null): ?>
      <p class="hint" style="color:#c62828;">
        The liturgical_calendar table does not exist yet. Apply <code>migrate/009_liturgical_calendar.sql</code>, then reload this page.
      </p>
    <?php else: ?>
      <p class="hint">
        <?php if ((int) $calendarStatus['days'] > 0): ?>
          The table holds <?= (int) $calendarStatus['days'] ?> days, <?= e($calendarStatus['first']) ?> to <?= e($calendarStatus['last']) ?>,
          last generated <?= e($calendarStatus['generated_at']) ?> (server time).
        <?php else: ?>
          The table is empty.
        <?php endif; ?>
        Generating computes the next 12 months (<?= e($windowFrom->format('Y-m-d')) ?> to <?= e($windowTo->format('Y-m-d')) ?>)
        and replaces those days. Earlier dates are kept.
      </p>
    <?php endif; ?>

    <div class="btn-row">
      <button type="submit" name="action" value="generate_calendar" <?= $calendarStatus === null ? 'disabled' : '' ?>
              onclick="return confirm('Replace the liturgical calendar for <?= e($windowFrom->format('Y-m-d')) ?> to <?= e($windowTo->format('Y-m-d')) ?>?');">
        Generate calendar
      </button>
      <button type="submit" name="action" value="save_calendar" class="secondary">Save options only</button>
    </div>
  </form>

  <?php if ($calendarUpcoming): ?>
    <h2 style="margin-top:20px;">Next <?= count($calendarUpcoming) ?> days</h2>
    <table>
      <thead><tr><th>Date</th><th>Celebration</th><th>Class</th><th>Rank</th><th>Color</th><th>Season</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($calendarUpcoming as $day): ?>
        <tr>
          <td style="white-space:nowrap;"><?= e($day['cal_date']) ?></td>
          <td><?= e($day['celebration']) ?></td>
          <td><?= e($day['class']) ?></td>
          <td><?= e($day['liturgical_rank']) ?></td>
          <td><?= e($day['color']) ?></td>
          <td><?= e($day['season']) ?></td>
          <td><?= e($day['notes']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
