<?php
// The liturgical celebration of one date, shown under the date text on the
// Activities Grid, List and Calendar (Day) tabs: "Celebration [Class/Rank]. Notes".
// Empty when the liturgical_calendar table is missing or has no row for the date.
function liturgical_day_text(PDO $pdo, string $date): string
{
    try {
        $stmt = $pdo->prepare('SELECT celebration, class, liturgical_rank, notes FROM liturgical_calendar WHERE cal_date = ?');
        $stmt->execute([$date]);
        $r = $stmt->fetch();
    } catch (PDOException $ex) {
        return '';
    }
    if (!$r) {
        return '';
    }
    $cr = array_filter([$r['class'], $r['liturgical_rank']], fn($v) => $v !== null && $v !== '');
    return trim((string) $r['celebration'] . ($cr ? ' [' . implode('/', $cr) . ']' : '') . ($r['notes'] ? '. ' . $r['notes'] : ''));
}
