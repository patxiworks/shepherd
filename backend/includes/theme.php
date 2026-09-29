<?php
// Admin-panel colour theme. The `theme_mode` setting is 'default' (sky blue)
// or 'season' (follows today's colour in the liturgical_calendar table).
// Liturgical white is swapped for gold, since a white theme would vanish
// against the page; if the calendar has no row for today the default is used.
const PASTORES_THEME_MODES = ['default' => 'Default (sky blue)', 'season' => 'Liturgical season'];
const PASTORES_THEME_DEFAULT = '#0284C7';
// liturgical_calendar.color => [label, brand colour]
const PASTORES_THEME_COLORS = [
    'Green'  => ['Green', '#2E7D32'],
    'Red'    => ['Red', '#C62828'],
    'White'  => ['Gold (for white)', '#94700A'],
    'Purple' => ['Purple', '#673AB7'],
    'Rose'   => ['Rose', '#B0396A'],
];

function theme_mode(PDO $pdo): string
{
    $v = get_setting($pdo, 'theme_mode', 'default');
    return isset(PASTORES_THEME_MODES[$v]) ? $v : 'default';
}

/** Today's liturgical colour name (e.g. 'Green'), or null if unknown. */
function theme_today_color(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT color FROM liturgical_calendar WHERE cal_date = ?');
        $stmt->execute([date('Y-m-d')]);
        $c = $stmt->fetchColumn();
    } catch (PDOException $e) {
        return null;
    }
    return is_string($c) && isset(PASTORES_THEME_COLORS[$c]) ? $c : null;
}

/** Brand colour (hex) for the current mode and day. */
function theme_brand(PDO $pdo): string
{
    if (theme_mode($pdo) === 'season') {
        $c = theme_today_color($pdo);
        if ($c !== null) return PASTORES_THEME_COLORS[$c][1];
    }
    return PASTORES_THEME_DEFAULT;
}

/** CSS custom properties for the theme; tints are mixed from the brand colour. */
function theme_css_vars(string $brand): string
{
    $mix = fn(int $pct, string $with = '#fff') => "color-mix(in srgb, $brand $pct%, $with)";
    return ':root{'
        . "--brand:$brand;"
        . '--brand-dark:' . $mix(65, '#000') . ';'
        . '--tint-1:' . $mix(4) . ';'
        . '--tint-2:' . $mix(13) . ';'
        . '--tint-stripe:' . $mix(3) . ';'
        . '--tint-hover:' . $mix(10) . ';'
        . '--tint-soft:' . $mix(12) . ';'
        . '--tint-menu:' . $mix(8) . ';'
        . '--tint-border:' . $mix(30) . ';'
        . '}';
}
