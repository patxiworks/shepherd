<?php
// Building blocks shared by the admin dashboards (Multi-day Activities and
// Activities): stat cards, horizontal bars, a donut chart, and their CSS. A page
// prints dash_css() once, then a `.dash-grid` of `.dash-stat` cards and
// `.dash-section` cards holding dash_bar() rows or a dash_donut().
//
// Bars are the default (magnitude across categories reads more accurately as a
// bar than as an angle — see the dataviz skill's anti-patterns). dash_donut() is
// for the other job, share of a whole, and only reads well up to a handful of
// segments whose sizes are actually distinct; for close values a bar still wins.

// Sorts an [label => number] array by number descending, then label.
function dash_sort(array &$a): void
{
    uksort($a, fn($x, $y) => [$a[$y], $x] <=> [$a[$x], $y]);
}

function dash_pct(int|float $n, int|float $of, int $dec = 1): string
{
    return $of > 0 ? number_format($n / $of * 100, $dec) : '0.0';
}

// One horizontal bar: label, filled track, value text. $width is 0-100.
function dash_bar(string $label, float $width, string $value, string $colour, bool $dot = false): void
{
    ?>
    <div class="dash-bar-row">
      <div class="dash-bar-label" title="<?= e($label) ?>"><?php if ($dot): ?><span class="dash-dot" style="background:<?= e($colour) ?>"></span><?php endif; ?><?= e($label) ?></div>
      <div class="dash-bar-track"><div class="dash-bar-fill" style="width:<?= round($width, 1) ?>%;background:<?= e($colour) ?>"></div></div>
      <div class="dash-bar-value"><?= e($value) ?></div>
    </div>
    <?php
}

function dash_empty(): void
{
    echo '<div class="dash-empty">No data for current filters.</div>';
}

// One row of a stacked bar: a label, then a bar whose overall length is
// $rowTotal/$maxTotal (so every row shares one scale and totals stay
// comparable), split inside into $segments (each ['label' =>, 'value' =>,
// 'colour' =>]) with a thin gap between them. $valueText is the text on the
// right (e.g. the total, or a per-segment breakdown).
function dash_stack_bar(string $label, array $segments, float $rowTotal, float $maxTotal, string $valueText): void
{
    $pct = $maxTotal > 0 ? min(100, $rowTotal / $maxTotal * 100) : 0;
    ?>
    <div class="dash-bar-row">
      <div class="dash-bar-label" title="<?= e($label) ?>"><?= e($label) ?></div>
      <div class="dash-bar-track">
        <div class="dash-stack" style="width:<?= round($pct, 1) ?>%;">
          <?php foreach ($segments as $s): if ($s['value'] <= 0) continue;
            $w = $rowTotal > 0 ? $s['value'] / $rowTotal * 100 : 0;
          ?>
            <span class="dash-stack-seg" style="width:<?= round($w, 2) ?>%;background:<?= e($s['colour']) ?>;" title="<?= e($s['label'] . ': ' . $s['value']) ?>"></span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="dash-bar-value"><?= e($valueText) ?></div>
    </div>
    <?php
}

// A small inline legend (dot + label) for the section colours, above a
// dash_stack_bar() group that uses them.
function dash_legend(array $items): void
{
    echo '<div class="dash-legend">';
    foreach ($items as $label => $colour) {
        echo '<span class="dash-legend-item"><span class="dash-dot" style="background:' . e($colour) . '"></span>' . e($label) . '</span>';
    }
    echo '</div>';
}

// A priest × category heatmap: $rows are row labels (e.g. priests, already
// capped/ordered by the caller), $cols column labels, $matrix[$row][$col] a
// count (missing/0 = blank cell). Shading is *within each row* — the share of
// that row's own total — so it reads as "how this priest's/entry's load
// spreads", not absolute volume; the count is always printed too, so the exact
// value is never colour alone. Built as a plain HTML table, which doubles as
// the chart's accessible/table view.
function dash_heatmap(array $rows, array $cols, array $matrix, string $hueRgb = '103,58,183'): void
{
    if (!$rows || !$cols) {
        dash_empty();
        return;
    }
    ?>
    <div class="dash-heatmap-wrap">
      <table class="dash-heatmap">
        <thead><tr><th></th><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($rows as $r):
            $rowData = $matrix[$r] ?? [];
            $rowTotal = array_sum($rowData);
          ?>
          <tr>
            <th><?= e($r) ?></th>
            <?php foreach ($cols as $c):
              $v = $rowData[$c] ?? 0;
              $pct = $rowTotal > 0 ? $v / $rowTotal : 0;
              $alpha = $v > 0 ? round(0.12 + $pct * 0.78, 2) : 0;
            ?>
              <td style="background:rgba(<?= $hueRgb ?>,<?= $alpha ?>);" title="<?= e("$r · $c: $v (" . dash_pct($v, $rowTotal ?: 1) . '% of ' . e($r) . "'s activities)") ?>"><?= $v ?: '' ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="dash-heatmap-scale">
      <span>Lighter = smaller share of that row's own total</span>
      <span class="dash-heatmap-swatches">
        <?php foreach ([0.12, 0.35, 0.58, 0.9] as $a): ?><span style="background:rgba(<?= $hueRgb ?>,<?= $a ?>);"></span><?php endforeach; ?>
      </span>
      <span>Darker = larger share</span>
    </div>
    <?php
}

// A donut chart for "share of a whole": $segments is a list of ['label' =>,
// 'value' =>, 'colour' =>], already in the order they should be drawn (12
// o'clock, clockwise). $centerLabel/$centerValue print in the hole (e.g. the
// total). The legend beside it carries the exact count and percentage in text —
// identity and value are never colour/angle alone — so it doubles as the
// chart's table view.
function dash_donut(array $segments, string $centerValue = '', string $centerLabel = ''): void
{
    $total = array_sum(array_column($segments, 'value'));
    if ($total <= 0) {
        dash_empty();
        return;
    }
    $segments = array_values(array_filter($segments, fn($s) => $s['value'] > 0));
    $r = 64;
    $cx = 80;
    $circumference = 2 * M_PI * $r;
    $gap = count($segments) > 1 ? 3.0 : 0.0; // px of surface colour between segments
    $usable = $circumference - $gap * count($segments);
    ?>
    <div class="dash-donut-wrap">
      <svg class="dash-donut" viewBox="0 0 160 160" width="160" height="160" role="img" aria-label="<?= e($centerLabel !== '' ? $centerLabel . ': ' . $centerValue : 'Breakdown') ?>">
        <g transform="rotate(-90 <?= $cx ?> <?= $cx ?>)">
          <?php $offset = 0.0; foreach ($segments as $s): $len = $s['value'] / $total * $usable; ?>
          <circle cx="<?= $cx ?>" cy="<?= $cx ?>" r="<?= $r ?>" fill="none" stroke="<?= e($s['colour']) ?>" stroke-width="26"
                  stroke-dasharray="<?= round($len, 2) ?> <?= round($circumference - $len, 2) ?>" stroke-dashoffset="<?= round(-$offset, 2) ?>">
            <title><?= e($s['label'] . ': ' . $s['value'] . ' (' . dash_pct($s['value'], $total) . '%)') ?></title>
          </circle>
          <?php $offset += $len + $gap; endforeach; ?>
        </g>
        <?php if ($centerValue !== ''): ?>
        <text x="<?= $cx ?>" y="<?= $cx - 4 ?>" text-anchor="middle" class="dash-donut-value"><?= e($centerValue) ?></text>
        <text x="<?= $cx ?>" y="<?= $cx + 14 ?>" text-anchor="middle" class="dash-donut-center-label"><?= e($centerLabel) ?></text>
        <?php endif; ?>
      </svg>
      <div class="dash-donut-legend">
        <?php foreach ($segments as $s): ?>
          <div class="dash-donut-row">
            <span class="dash-dot" style="background:<?= e($s['colour']) ?>"></span>
            <span class="dash-donut-label"><?= e($s['label']) ?></span>
            <span class="dash-donut-value-text"><?= (int) $s['value'] ?> · <?= dash_pct($s['value'], $total) ?>%</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

// A stat card. $sub is small grey text under the value; $href (optional) makes the card a link.
function dash_stat(string $label, string|int $value, string $sub = '', ?string $href = null, string $valueStyle = ''): void
{
    $tag = $href !== null ? 'a href="' . e($href) . '" style="text-decoration:none;color:inherit;display:block;"' : 'div';
    echo '<div class="dash-stat"><' . $tag . '><div class="dash-stat-label">' . e($label) . '</div><div class="dash-stat-value"' . ($valueStyle ? ' style="' . e($valueStyle) . '"' : '') . '>' . e((string) $value) . '</div>'
        . ($sub !== '' ? '<div class="dash-stat-sub">' . e($sub) . '</div>' : '') . '</' . ($href !== null ? 'a' : 'div') . '></div>';
}

function dash_css(): void
{
    ?>
<style>
  .dash-notice { background: #fff; border-left: 3px solid var(--brand); padding: 10px 14px; border-radius: 4px; font-size: 12px; color: #555; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-bottom: 20px; }
  .dash-stat { background: #fff; border-radius: 8px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  .dash-stat-label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #666; margin-bottom: 6px; }
  .dash-stat-value { font-size: 28px; font-weight: 600; color: #222; }
  .dash-stat-sub { font-size: 11px; color: #999; margin-top: 4px; }
  .dash-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  @media (max-width: 900px) { .dash-two-col { grid-template-columns: 1fr; } }
  .dash-section h2 { font-size: 14px; font-weight: 600; color: #555; margin: 0 0 14px; }
  .dash-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 9px; }
  .dash-bar-label { width: 130px; flex-shrink: 0; font-size: 12px; display: flex; align-items: center; gap: 6px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
  .dash-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .dash-bar-track { flex: 1; height: 16px; background: #f1efe9; border-radius: 3px; overflow: hidden; }
  .dash-bar-fill { height: 100%; border-radius: 3px 0 0 3px; }
  .dash-bar-value { width: 120px; flex-shrink: 0; font-size: 11px; color: #666; text-align: right; font-family: monospace; }
  .dash-empty { font-size: 12px; color: #999; }
  .dash-footnote { font-size: 11px; color: #999; margin-top: 10px; }
  .dash-donut-wrap { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
  .dash-donut { flex-shrink: 0; }
  .dash-donut-value { font-size: 22px; font-weight: 600; fill: #222; font-family: inherit; }
  .dash-donut-center-label { font-size: 9px; fill: #999; text-transform: uppercase; letter-spacing: .04em; font-family: inherit; }
  .dash-donut-legend { flex: 1; min-width: 160px; }
  .dash-donut-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; padding: 5px 0; border-bottom: 1px solid #f1efe9; }
  .dash-donut-row:last-child { border-bottom: none; }
  .dash-donut-label { flex: 1; }
  .dash-donut-value-text { color: #666; font-size: 11px; font-family: monospace; }
  .dash-stack { height: 100%; display: flex; }
  .dash-stack-seg { height: 100%; }
  .dash-stack-seg + .dash-stack-seg { margin-left: 2px; }
  .dash-legend { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; font-size: 12px; color: #555; }
  .dash-legend-item { display: flex; align-items: center; gap: 6px; }
  .dash-heatmap-wrap { overflow-x: auto; }
  table.dash-heatmap { border-collapse: collapse; font-size: 11px; width: 100%; }
  table.dash-heatmap th, table.dash-heatmap td { padding: 6px 8px; text-align: center; border: 1px solid #f1efe9; white-space: nowrap; }
  table.dash-heatmap thead th { font-weight: 600; color: #666; background: #fafafa; }
  table.dash-heatmap tbody th { text-align: left; font-weight: 500; color: #333; position: sticky; left: 0; background: #fff; }
  table.dash-heatmap td { font-family: monospace; color: #222; min-width: 34px; }
  .dash-heatmap-scale { display: flex; align-items: center; gap: 8px; font-size: 10.5px; color: #999; margin-top: 10px; }
  .dash-heatmap-swatches { display: inline-flex; gap: 2px; }
  .dash-heatmap-swatches span { width: 14px; height: 10px; border-radius: 2px; }
  @media (max-width: 480px) {
    .dash-bar-label { width: 84px; font-size: 11px; }
    .dash-bar-value { width: 64px; font-size: 10px; }
  }
</style>
    <?php
}
