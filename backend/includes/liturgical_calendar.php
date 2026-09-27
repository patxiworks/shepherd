<?php

// Fills the liturgical_calendar table (see migrate/009_liturgical_calendar.sql)
// using the PHP port of ROMCAL in includes/romcal/. Run from Admin > Settings.

require_once __DIR__ . '/romcal/Romcal.php';

// settings name => [Romcal option, default, label]
const PASTORES_CALENDAR_OPTIONS = [
    'calendar_ascension_on_sunday' => ['ascension_on_sunday', false, 'Ascension on the Seventh Sunday of Easter (default: Thursday)'],
    'calendar_epiphany_on_jan6' => ['epiphany_on_jan6', false, 'Epiphany on January 6 (default: the Sunday between Jan 2 and 8)'],
    'calendar_corpus_christi_on_thursday' => ['corpus_christi_on_thursday', false, 'Corpus Christi on the Thursday (default: the Sunday)'],
    'calendar_optional_memorials' => ['optional_memorials', true, 'Include optional memorials'],
];

// Current calendar options, keyed as Romcal::generate() expects them.
function liturgical_calendar_options(PDO $pdo): array
{
    $options = [];
    foreach (PASTORES_CALENDAR_OPTIONS as $setting => [$option, $default]) {
        $options[$option] = get_setting($pdo, $setting, $default ? '1' : '0') === '1';
    }
    return $options;
}

// The generated window: 12 months starting today, both ends included.
function liturgical_calendar_window(?DateTimeImmutable $today = null): array
{
    $from = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $to = $from->modify('+1 year')->modify('-1 day');
    return [$from, $to];
}

// Replaces the calendar rows of the next 12 months with freshly computed ones
// (rows before today are left alone). All or nothing: one transaction.
// Returns ['from' => 'Y-m-d', 'to' => 'Y-m-d', 'days' => int].
function generate_liturgical_calendar(PDO $pdo, ?DateTimeImmutable $today = null): array
{
    [$from, $to] = liturgical_calendar_window($today);
    $fromDate = $from->format('Y-m-d');
    $toDate = $to->format('Y-m-d');
    $options = liturgical_calendar_options($pdo);

    // ROMCAL computes one calendar year at a time; the window can span two.
    $rows = [];
    for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); $year++) {
        foreach (Romcal::generate($year, $options) as $row) {
            if ($row['date'] >= $fromDate && $row['date'] <= $toDate) {
                $rows[] = $row;
            }
        }
    }
    $expected = (int) $from->diff($to)->days + 1;
    if (count($rows) !== $expected) {
        throw new RuntimeException('Calendar computation returned ' . count($rows) . " days, expected $expected.");
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM liturgical_calendar WHERE cal_date BETWEEN ? AND ?')
            ->execute([$fromDate, $toDate]);

        foreach (array_chunk($rows, 100) as $chunk) {
            $sql = 'INSERT INTO liturgical_calendar
                        (cal_date, celebration, `class`, liturgical_rank, color, season, notes, votive, devotion) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?)'));
            $params = [];
            foreach ($chunk as $row) {
                array_push(
                    $params,
                    $row['date'], $row['celebration'], $row['class'], $row['rank'], $row['color'],
                    $row['season'], $row['notes'], $row['votive'], $row['devotion']
                );
            }
            $pdo->prepare($sql)->execute($params);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['from' => $fromDate, 'to' => $toDate, 'days' => count($rows)];
}

// What the table currently holds: ['days', 'first', 'last', 'generated_at'].
function liturgical_calendar_status(PDO $pdo): array
{
    return $pdo->query(
        'SELECT COUNT(*) AS days, MIN(cal_date) AS first, MAX(cal_date) AS last, MAX(generated_at) AS generated_at
         FROM liturgical_calendar'
    )->fetch();
}

// The next $limit days from today, for the preview on the settings page.
function liturgical_calendar_upcoming(PDO $pdo, int $limit = 14): array
{
    $stmt = $pdo->prepare(
        'SELECT cal_date, celebration, `class`, liturgical_rank, color, season, notes
         FROM liturgical_calendar WHERE cal_date >= ? ORDER BY cal_date LIMIT ' . (int) $limit
    );
    $stmt->execute([(new DateTimeImmutable('today'))->format('Y-m-d')]);
    return $stmt->fetchAll();
}
