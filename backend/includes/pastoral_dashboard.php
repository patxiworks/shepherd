<?php
// "Priests load summary" panel on the admin home page: how the priests' work is
// spread. Based on the standalone pastoral-dashboard.html; the rows come from
// the activities table (dated within the chosen window) and, when migration 015
// is applied, the multi-day activities that run in it. Load per priest is the
// weighted number of engagements (an activity weighs what its labor's weight
// says, default 1; a multi-day programme has its own weight, default 3); the
// weights are adjustable in the browser and remembered there (localStorage).
// Everything is scoped to the admin's zone / centre, as the other pages are.

const PD_RANGES = [7 => 'Next 7 days', 30 => 'Next 30 days', 90 => 'Next 90 days'];
const PD_DEFAULT_RANGE = 30;

function pd_range(): int
{
    $d = (int) ($_GET['pd_days'] ?? PD_DEFAULT_RANGE);
    return isset(PD_RANGES[$d]) ? $d : PD_DEFAULT_RANGE;
}

/** @return array{rows: array, roster: array, from: string, to: string} */
function pd_data(PDO $pdo, array $admin, int $days, bool $mdayAvailable): array
{
    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime("+" . ($days - 1) . " days"));

    // Scope: [SQL fragment on alias $a, params]
    $scope = function (string $a) use ($pdo, $admin): array {
        if ($admin['role'] === 'zone') return [" AND $a.zone_id = ?", [(int) $admin['zone_id']]];
        if ($admin['role'] === 'centre') {
            return [" AND $a.zone_id = ? AND $a.centre = ?", [(int) $admin['zone_id'], (string) admin_centre_name($pdo, $admin)]];
        }
        return ['', []];
    };

    $rows = [];
    [$sql, $sp] = $scope('a');
    $stmt = $pdo->prepare(
        "SELECT z.name AS z, a.centre AS c, a.labor AS l, a.priest AS p, COUNT(*) AS n
           FROM activities a JOIN zones z ON z.id = a.zone_id
          WHERE a.activity_date BETWEEN ? AND ?$sql
          GROUP BY z.name, a.centre, a.labor, a.priest"
    );
    $stmt->execute(array_merge([$from, $to], $sp));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = ['k' => 'a'] + $r + [];
    }

    if ($mdayAvailable) {
        [$sql, $sp] = $scope('a');
        $stmt = $pdo->prepare(
            "SELECT z.name AS z, a.centre AS c, a.labor AS l, a.priest AS p, COUNT(*) AS n
               FROM multiday_activities a JOIN zones z ON z.id = a.zone_id
              WHERE a.end_date >= ? AND a.start_date <= ?$sql
              GROUP BY z.name, a.centre, a.labor, a.priest"
        );
        $stmt->execute(array_merge([$from, $to], $sp));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['k' => 'm'] + $r;
        }
    }
    foreach ($rows as &$r) {
        $r['n'] = (int) $r['n'];
        $r['l'] = (string) $r['l'];
        $r['c'] = (string) $r['c'];
        $r['p'] = trim((string) $r['p']);
    }
    unset($r);

    // Roster: priests with their home zone (not for a centre admin, who sees one centre only).
    $roster = [];
    if ($admin['role'] !== 'centre') {
        $sql = 'SELECT p.name AS n, z.name AS z FROM priests p LEFT JOIN zones z ON z.id = p.zone_id';
        $params = [];
        if ($admin['role'] === 'zone') {
            $sql .= ' WHERE p.zone_id = ? OR p.id IN (SELECT priest_id FROM priest_zones WHERE zone_id = ?)';
            $params = [(int) $admin['zone_id'], (int) $admin['zone_id']];
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY p.name');
        $stmt->execute($params);
        $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roster as &$p) $p['z'] = (string) $p['z'];
        unset($p);
    }
    return compact('rows', 'roster', 'from', 'to');
}

function pd_render(PDO $pdo, array $admin, bool $mdayAvailable): void
{
    $days = pd_range();
    $data = pd_data($pdo, $admin, $days, $mdayAvailable);
    $json = json_encode(
        ['rows' => $data['rows'], 'roster' => $data['roster'], 'multiday' => $mdayAvailable],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
    ?>
<style>
  .pd { margin-top: 8px; }
  .pd-head { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 8px; margin: 8px 0 12px; }
  .pd-head h2 { margin: 0; font-size: 18px; }
  .pd-sub, .pd-lb { color: #666; font-size: 12px; }
  .pd-head form { display: flex; gap: 8px; align-items: center; }
  .pd-head select { width: auto; padding: 4px 8px; }
  .pd-kp { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 12px; }
  .pd-kp .card { margin: 0; padding: 14px; text-align: center; }
  .pd-big { font-size: 26px; font-weight: 700; color: var(--brand); }
  .pd-g2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; margin-bottom: 20px; }
  .pd-g2 .card { margin: 0; }
  .pd-row { display: grid; grid-template-columns: minmax(80px, 140px) 1fr minmax(110px, 170px); gap: 8px; align-items: center; margin: 6px 0; font-size: 13px; }
  .pd-track { position: relative; height: 14px; background: var(--tint-soft); border-radius: 7px; }
  .pd-bar { height: 100%; border-radius: 7px; background: var(--brand); }
  .pd-bar.hi { background: #c0392b; }
  .pd-bar.lo { background: #c58b1a; }
  .pd-avg { position: absolute; top: -3px; bottom: -3px; width: 2px; background: #222; opacity: .6; }
  .pd-v { color: #666; font-size: 12px; text-align: right; }
  .pd-tag { font-size: 11px; padding: 1px 6px; border-radius: 9px; color: #fff; background: #c58b1a; }
  .pd-tag.hi { background: #c0392b; }
  .pd ul { margin: 0; padding-left: 18px; }
  .pd li { margin: 4px 0; font-size: 13px; }
  .pd table { font-size: 13px; }
  .pd .pd-wg { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0; }
  .pd .pd-wg label { font-size: 12px; color: #666; font-weight: normal; }
  .pd .pd-wg input { width: 60px; padding: 4px 6px; }
  .pd details summary { cursor: pointer; font-weight: 600; }
  .pd-zf { display: flex; align-items: center; gap: 6px; }
  .pd-zf select { width: auto; padding: 4px 8px; }
</style>
<div class="pd" id="pd">
  <div class="pd-head">
    <div>
      <h2>Priests load summary</h2>
      <div class="pd-sub" id="pd-sub"></div>
    </div>
    <form method="get">
      <label class="pd-lb" for="pd_days">Period</label>
      <select id="pd_days" name="pd_days" onchange="this.form.submit()">
        <?php foreach (PD_RANGES as $d => $label): ?>
          <option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="pd-kp" id="pd-kp"></div>
  <div class="card">
    <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:6px;">
      <h2>Priest load <span class="pd-lb">(weighted engagements; black tick = average)</span></h2>
      <label class="pd-lb pd-zf">Zone <select id="pd-zf"></select></label>
    </div>
    <div id="pd-pl"></div>
  </div>
  <div class="card"><h2>Alerts</h2><ul id="pd-al"></ul></div>
  <div class="pd-g2">
    <div class="card"><h2>Most demanding centres</h2><div class="table-wrap" id="pd-ct"></div></div>
    <div class="card"><h2>Lightest centres</h2><div class="table-wrap" id="pd-cl"></div></div>
  </div>
  <div class="card"><h2>Zones</h2><div class="table-wrap" id="pd-zn"></div></div>
  <div class="card">
    <details>
      <summary>Weights</summary>
      <p class="pd-lb">Each activity counts as 1 by default. Raise the weight of groups (labors) that take more priestly time. Saved in this browser.</p>
      <div class="pd-wg" id="pd-wg"></div>
    </details>
  </div>
</div>
<script>
(function () {
  var D = <?= $json ?>;
  var WINDOW = <?= json_encode(PD_RANGES[$days]) ?>;
  var MW_KEY = '\u0000multiday';
  var W = {};
  try { W = JSON.parse(localStorage.getItem('pastoresPdWeights') || '{}') || {}; } catch (e) {}
  function saveW() { try { localStorage.setItem('pastoresPdWeights', JSON.stringify(W)); } catch (e) {} }
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function label(l) { return l || '(no group)'; }
  function weight(r) {
    if (r.k === 'm') return W[MW_KEY] == null ? 3 : W[MW_KEY];
    return W[label(r.l)] == null ? 1 : W[label(r.l)];
  }
  function keys(o) { return Object.keys(o); }

  function calc() {
    var P = {}, C = {}, Z = {};
    D.roster.forEach(function (p) { P[p.n] = {n: p.n, z: p.z || '—', load: 0, g: 0, c: {}, a: {}, ro: 1}; });
    D.rows.forEach(function (r) {
      var w = weight(r) * r.n;
      var ck = r.z + '|' + r.c;
      var c = C[ck] || (C[ck] = {n: r.c || '(no centre)', load: 0, g: 0, p: {}});
      c.load += w; c.g += r.n;
      var z = Z[r.z] || (Z[r.z] = {n: r.z, g: 0, p: {}});
      z.g += r.n;
      if (!r.p) return;
      c.p[r.p] = 1; z.p[r.p] = 1;
      var p = P[r.p] || (P[r.p] = {n: r.p, z: '—', load: 0, g: 0, c: {}, a: {}, ro: 0});
      p.load += w; p.g += r.n; p.c[ck] = 1; p.a[r.z] = 1;
    });
    return {P: P, C: C, Z: Z};
  }

  function render() {
    var d = calc(), ps = Object.keys(d.P).map(function (k) { return d.P[k]; });
    var act = ps.filter(function (p) { return p.g > 0; });
    var tot = act.reduce(function (s, p) { return s + p.load; }, 0);
    var avg = act.length ? tot / act.length : 0, hi = avg * 1.3, lo = avg * .6;
    var nAct = 0, nMday = 0, nGap = 0;
    D.rows.forEach(function (r) { if (r.k === 'm') nMday += r.n; else nAct += r.n; if (!r.p) nGap += r.n; });
    var gaps = D.rows.filter(function (r) { return !r.p; });
    var idle = ps.filter(function (p) { return p.g === 0 && p.ro; });
    var f = $('pd-zf').value || 'All';
    function cls(p) { return p.load > hi ? 'hi' : p.load < lo ? 'lo' : ''; }
    var over = act.filter(function (p) { return p.load > hi; });

    $('pd-sub').textContent = WINDOW + ' · overloaded = above 130% of the average load (' + avg.toFixed(1) + '), light = below 60%';
    var kp = [[act.length, 'priests with assignments'], [nAct, 'activities']];
    if (D.multiday) kp.push([nMday, 'multi-day programmes']);
    kp.push([avg.toFixed(1), 'average load per priest'], [over.length, 'possibly overloaded'], [nGap, 'without a priest']);
    if (D.roster.length) kp.push([idle.length, 'priests with nothing assigned']);
    $('pd-kp').innerHTML = kp.map(function (k) { return '<div class="card"><div class="pd-big">' + k[0] + '</div><div class="pd-lb">' + k[1] + '</div></div>'; }).join('');

    var list = ps.filter(function (p) { return (p.g > 0 || p.ro) && (f === 'All' || p.z === f || p.a[f]); })
      .sort(function (a, b) { return b.load - a.load; });
    var mx = Math.max.apply(null, list.map(function (p) { return p.load; }).concat([1]));
    $('pd-pl').innerHTML = list.length ? list.map(function (p) {
      return '<div class="pd-row"><span>' + esc(p.n) + '</span><div class="pd-track"><div class="pd-bar ' + cls(p) + '" style="width:' + (p.load / mx * 100) + '%"></div><i class="pd-avg" style="left:' + (avg / mx * 100) + '%"></i></div><span class="pd-v">' + +p.load.toFixed(1) + ' · ' + p.g + ' eng · ' + keys(p.c).length + ' ctr</span></div>';
    }).join('') : '<div class="pd-lb">No priests to show.</div>';

    var al = [];
    over.forEach(function (p) { al.push('<span class="pd-tag hi">overload</span> ' + esc(p.n) + ': load ' + +p.load.toFixed(1) + ' across ' + keys(p.c).length + ' centres'); });
    if (nGap) {
      var byC = {};
      gaps.forEach(function (r) { var k = r.c || '(no centre)'; byC[k] = (byC[k] || 0) + r.n; });
      var top = keys(byC).sort(function (a, b) { return byC[b] - byC[a]; }).slice(0, 8).map(function (c) { return c + ' (' + byC[c] + ')'; });
      al.push('<span class="pd-tag">gap</span> ' + nGap + ' without a priest, mostly at: ' + esc(top.join(', ')));
    }
    if (idle.length) al.push('<span class="pd-tag">capacity</span> Nothing assigned: ' + esc(idle.map(function (p) { return p.n + ' (' + p.z + ')'; }).join(', ')));
    act.forEach(function (p) {
      var o = keys(p.a).filter(function (a) { return p.ro && p.z !== '—' && a !== p.z; });
      if (o.length) al.push('<span class="pd-tag">cross-zone</span> ' + esc(p.n) + ' (' + esc(p.z) + ') also serves ' + esc(o.join(', ')));
    });
    if (D.roster.length) act.filter(function (p) { return !p.ro; }).forEach(function (p) { al.push('<span class="pd-tag">roster</span> ' + esc(p.n) + ' has assignments but is not on the priests list'); });
    keys(d.C).map(function (k) { return d.C[k]; }).filter(function (c) { return keys(c.p).length >= 3; }).forEach(function (c) {
      al.push('<span class="pd-tag">split</span> ' + esc(c.n) + ' is shared by ' + keys(c.p).length + ' priests: ' + esc(keys(c.p).join(', ')));
    });
    $('pd-al').innerHTML = al.length ? al.map(function (a) { return '<li>' + a + '</li>'; }).join('') : '<li>No alerts</li>';

    var cs = keys(d.C).map(function (k) { return d.C[k]; });
    function tb(a) {
      return '<table><thead><tr><th>Centre</th><th>Load</th><th>Priests</th></tr></thead><tbody>' + (a.length ? a.map(function (c) {
        return '<tr><td>' + esc(c.n) + '</td><td>' + +c.load.toFixed(1) + '</td><td>' + keys(c.p).length + '</td></tr>';
      }).join('') : '<tr><td colspan="3">No data.</td></tr>') + '</tbody></table>';
    }
    var sd = cs.slice().sort(function (a, b) { return b.load - a.load; });
    $('pd-ct').innerHTML = tb(sd.slice(0, 8));
    $('pd-cl').innerHTML = tb(sd.slice(-8).reverse());
    $('pd-zn').innerHTML = '<table><thead><tr><th>Zone</th><th>Engagements</th><th>Priests serving</th><th>Per priest</th></tr></thead><tbody>' +
      (keys(d.Z).length ? keys(d.Z).map(function (k) { return d.Z[k]; }).sort(function (a, b) { return b.g - a.g; }).map(function (z) {
        var n = keys(z.p).length;
        return '<tr><td>' + esc(z.n) + '</td><td>' + z.g + '</td><td>' + n + '</td><td>' + (n ? (z.g / n).toFixed(1) : '—') + '</td></tr>';
      }).join('') : '<tr><td colspan="4">No data.</td></tr>') + '</tbody></table>';
  }

  function init() {
    var ls = {};
    D.rows.forEach(function (r) { if (r.k === 'a') ls[label(r.l)] = 1; });
    var inputs = keys(ls).sort().map(function (l) { return [l, l, 1]; });
    if (D.multiday) inputs.push([MW_KEY, 'Multi-day programme', 3]);
    $('pd-wg').innerHTML = inputs.map(function (i) {
      return '<label>' + esc(i[1]) + ' <input type="number" min="0" step="0.5" value="' + (W[i[0]] == null ? i[2] : W[i[0]]) + '" data-l="' + esc(i[0]) + '"></label>';
    }).join('');
    Array.prototype.forEach.call(document.querySelectorAll('#pd-wg input'), function (i) {
      i.onchange = function () { var v = parseFloat(i.value); W[i.getAttribute('data-l')] = isNaN(v) ? 0 : v; saveW(); render(); };
    });
    var zs = {};
    D.roster.forEach(function (p) { if (p.z) zs[p.z] = 1; });
    D.rows.forEach(function (r) { zs[r.z] = 1; });
    var z = $('pd-zf');
    z.innerHTML = '<option>All</option>' + keys(zs).sort().map(function (k) { return '<option>' + esc(k) + '</option>'; }).join('');
    z.onchange = render;
    render();
  }
  init();
})();
</script>
    <?php
}
