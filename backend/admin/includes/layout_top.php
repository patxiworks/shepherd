<?php
// Expects $pageTitle to be set by the including file.
require_once __DIR__ . '/../../includes/theme.php';
$themeBrand = theme_brand(pastores_db());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Pastores Admin') ?></title>
<style>
  <?= theme_css_vars($themeBrand) ?>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f5f5f5; color: #222; }
  header.topbar { background: var(--brand); color: #fff; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
  header.topbar a { color: #fff; text-decoration: none; margin-right: 16px; font-size: 14px; }
  header.topbar a.brand { font-weight: 600; font-size: 16px; margin-right: 32px; }
  header.topbar > div:first-child { display: flex; align-items: center; }
  .nav-toggle { display: none; background: none; border: none; color: #fff; width: 36px; height: 36px; padding: 0; border-radius: 5px; cursor: pointer; align-items: center; justify-content: center; flex-shrink: 0; }
  .nav-toggle svg { width: 20px; height: 20px; flex-shrink: 0; }
  .nav-toggle:hover, .nav-toggle[aria-expanded=true] { background: rgba(255,255,255,.18); }
  nav.topnav { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
  .nav-drop { position: relative; }
  .nav-drop-btn { background: none; border: none; color: #fff; font-size: 14px; padding: 8px 10px; margin-right: 4px; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 5px; border-radius: 4px; }
  .nav-drop-btn::after { content: ''; width: 0; height: 0; border: 4px solid transparent; border-top-color: #fff; margin-top: 2px; }
  .nav-drop-btn:hover, .nav-drop-btn:focus-visible, .nav-drop.open .nav-drop-btn { background: rgba(255,255,255,.18); }
  .nav-drop-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,.25); padding: 6px; min-width: 170px; z-index: 20; }
  .nav-drop.open .nav-drop-menu { display: block; }
  header.topbar .nav-drop-menu a { display: block; color: #333; text-decoration: none; padding: 8px 10px; border-radius: 4px; font-size: 14px; margin: 0; white-space: nowrap; }
  header.topbar .nav-drop-menu a:hover, header.topbar .nav-drop-menu a:focus-visible { background: var(--tint-menu); color: var(--brand); }
  main { max-width: <?= !empty($pageWide) ? 'none' : '1100px' ?>; margin: 24px auto; padding: 0 16px; }
  nav.tabs { display: flex; gap: 4px; margin-bottom: 20px; flex-wrap: wrap; border-bottom: 2px solid var(--brand); }
  nav.tabs a { padding: 8px 14px; background: #fff; border-radius: 6px 6px 0 0; text-decoration: none; color: #444; font-size: 14px; border: 1px solid #ddd; border-bottom: none; }
  nav.tabs a.active { background: var(--brand); color: #fff; border-color: var(--brand); }
  /* Every table is wrapped in this (added automatically by layout_bottom.php
     if a page hasn't already, as source/activities do) so a wide table
     scrolls horizontally on a narrow screen instead of squashing or
     overflowing the page. The border/rounding/shadow live here rather than
     on the table itself, so they frame it as a fixed outer box instead of
     scrolling away with the table's content; the actual rounding of the
     table's own corners is done on the corner cells below (not via
     overflow:hidden here), because overflow:hidden/auto/scroll on an
     ancestor is exactly what stops position:sticky (on <th> below) from
     sticking to the browser viewport — confirmed by testing both ways.
     overflow-x:auto is only needed once a table is wider than its wrap,
     which in practice is a narrow-screen problem, so it's dropped above
     900px width, letting the sticky header work whenever there's room not
     to need horizontal scrolling in the first place. */
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; background: #fff; border: 1px solid var(--tint-border); border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
  @media (min-width: 900px) {
    .table-wrap { overflow-x: visible; }
  }
  /* Below 900px, overflow-x:auto (above) is still needed for horizontal
     scroll, which rules out sticking to the browser viewport (see the
     comment above) — so instead the wrap itself becomes a bounded,
     both-axis scrollport (max-height + overflow-y:auto): once a table is
     taller than that, IT scrolls internally rather than the page, and the
     sticky <th> below correctly sticks to the top of that internal scroll.
     A table shorter than max-height is unaffected (nothing to scroll). */
  @media (max-width: 899px) {
    .table-wrap { max-height: 60vh; overflow-y: auto; }
  }
  /* No background here (unlike before): the wrap behind it already has
     background:#fff, and gives it correctly per its own border-radius —
     the table's own background, being a flat rectangle, doesn't, and since
     overflow can be visible now (≥900px, for the sticky header below) it's
     no longer clipped to match, so it was poking a small square notch past
     the wrap's rounded corners in place of the curve. */
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--tint-border); border-right: 1px solid var(--tint-border); font-size: var(--tbl-fs, 13px); }
  table .cell-input { font-size: var(--tbl-fs, inherit); }
  .tbl-zoom { display: flex; justify-content: flex-end; gap: 4px; margin: 0 0 4px; }
  .tbl-zoom-btn { background: #fff; color: var(--brand); border: 1px solid var(--tint-border); border-radius: 4px; padding: 0 7px; height: 26px; min-width: 32px; line-height: 1; cursor: pointer; font-weight: 600; font-size: 15px; }
  .tbl-zoom-btn.small { font-size: 12px; }
  .tbl-zoom-btn sup { font-size: 10px; vertical-align: top; position: relative; top: 1px; }
  .tbl-zoom-btn:hover:not(:disabled) { border-color: var(--brand); background: var(--tint-1); }
  .tbl-zoom-btn:disabled { opacity: .4; cursor: default; }
  body.act-focus .tbl-zoom { display: none; }
  th:last-child, td:last-child { border-right: none; }
  /* Rounds the table to match .table-wrap's own 6px corners. A bulk-delete
     checkbox column (layout_bottom.php), when present, is always inserted
     as the true first column and stays in the DOM (just hidden by CSS) even
     outside selection mode, so it — not the first visible column — is what
     :first-child would match; the "+ th"/"+ td" rule covers that case. */
  thead tr:first-child th:first-child,
  thead tr:first-child th.bulk-col:first-child + th { border-top-left-radius: 6px; }
  thead tr:first-child th:last-child { border-top-right-radius: 6px; }
  tbody tr:last-child td:first-child,
  tbody tr:last-child td.bulk-col:first-child + td { border-bottom-left-radius: 6px; }
  tbody tr:last-child td:last-child { border-bottom-right-radius: 6px; }
  /* Header: a light, faintly gradient tint of the brand purple (var(--brand)),
     not a flat grey. Alternating body rows use the same tint at low
     strength; :hover is deliberately the same specificity as the
     nth-child stripe but declared after it, so a hovered row always reads
     the same regardless of whether it's a striped row (page-specific "flag"
     row colours, e.g. activities' absent/duplicate/bilocation, are painted
     on the <td>s and sit on top of either). Sticky so it stays visible
     while scrolling a tall table (e.g. Activities) — the browser viewport
     ≥900px, or .table-wrap's own bounded scrollport below that, per the
     .table-wrap comments above. */
  th { background: linear-gradient(180deg, var(--tint-1) 0%, var(--tint-2) 100%); color: var(--brand-dark); font-weight: 600; position: sticky; top: 0; z-index: 1; }
  /* Explicit, not left to fall through to .table-wrap's background: a
     transparent row (relying on ancestors, now that `table` itself has no
     background either — see above) let .table-wrap's own right border show
     through at the edge of a cell with no other opaque background over it
     (e.g. an editing row's actions cell before the row got its own
     background, below) — painting each row white directly avoids the whole
     fallback chain. */
  tbody tr { background: #fff; }
  tbody tr:nth-child(even) { background: var(--tint-stripe); }
  tbody tr:hover { background: var(--tint-hover); }
  .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
  form.inline { display: inline; }
  label { display: block; font-size: 13px; font-weight: 600; margin: 10px 0 4px; }
  input, select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
  textarea { min-height: 70px; }
  .table-hint { font-size: 12px; color: #666; margin: 0 0 8px; }
  .hint { display: block; font-size: 12px; color: #666; margin-top: 4px; min-height: 1.3em; }
  .row { display: flex; gap: 12px; flex-wrap: wrap; }
  .row > div { flex: 1; min-width: 160px; }
  button, .btn { background: var(--brand); color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
  button.secondary, .btn.secondary { background: #999; }
  button.danger, .btn.danger { background: #E91E63; }
  .btn-row { margin-top: 16px; display: flex; gap: 8px; }
  .actions { white-space: nowrap; }
  .icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 5px; color: var(--brand); vertical-align: middle; }
  .icon-btn svg { width: 16px; height: 16px; }
  .icon-btn:hover, .icon-btn:focus-visible { background: var(--tint-soft); }
  .icon-btn.delete { color: #E91E63; }
  .icon-btn.delete:hover, .icon-btn.delete:focus-visible { background: #fdecea; }
  .flash { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
  .flash.success { background: #e8f5e9; color: #2e7d32; }
  .flash.error { background: #fdecea; color: #c62828; }
  .flash.warning { background: #fdecea; color: #c62828; }
  [data-toolbar-item]:not(.toolbar-placed) { display: none !important; }
  .toolbar-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
  .toolbar-row .push-right { margin-left: auto; }
  /* An invisible marker layout_bottom.php inserts right before the first
     right-aligned button. Below 640px it's given flex-basis:100%, which
     forces a line break there (without stretching the button itself, the
     way giving the button that flex-basis would) so the right-aligned group
     (Filter/Export/Import/…) drops onto its own row under the left-aligned
     ones (New x/Roll forward/…) instead of wrapping into the same crowded
     row. */
  .toolbar-break { flex-basis: 0; width: 0; }
  @media (max-width: 640px) {
    .toolbar-row .push-right { margin-left: 0; }
    .toolbar-break { flex-basis: 100%; height: 0; }
  }
  .btn.muted, button.muted { background: #999; }
  /* Modal forms are moved into a <dialog> by a script at the end of the page;
     keep them hidden until then so they don't flash on the page while it loads. */
  .card[data-modal] { display: none; }
  dialog.modal .card[data-modal] { display: block; }
  dialog.modal { border: none; border-radius: 8px; padding: 0; width: min(900px, 94vw); max-height: 90vh; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
  dialog.modal::backdrop { background: rgba(0,0,0,.45); }
  dialog.modal .card { margin: 0; box-shadow: none; position: relative; max-height: 90vh; overflow: auto; }
  dialog.modal .card h2 { padding-right: 32px; }
  .modal-close { position: absolute; top: 12px; right: 14px; background: none; color: #666; font-size: 24px; line-height: 1; padding: 0 6px; }
  /* The long-table pages show the sort cue from the first paint (the script that adds
     .sortable-th runs after the table is parsed); :where() keeps these at the lowest
     specificity so the rules below still win. */
  :where(table[data-bulk-total] thead th:not(:empty):not(.bulk-col)) { cursor: pointer; user-select: none; white-space: nowrap; }
  :where(table[data-bulk-total] thead th:not(:empty):not(.bulk-col))::after { content: ' \21C5'; color: #bbb; font-size: 11px; }
  th.sortable-th { cursor: pointer; user-select: none; white-space: nowrap; }
  th.sortable-th::after { content: ' \21C5'; color: #bbb; font-size: 11px; }
  th.sortable-th[aria-sort=ascending]::after { content: ' \25B2'; color: var(--brand); }
  th.sortable-th[aria-sort=descending]::after { content: ' \25BC'; color: var(--brand); }
  /* Bulk delete (see layout_bottom.php): the checkbox column and bar only show in selection mode. */
  .bulk-col { display: none; width: 1%; }
  table.selecting .bulk-col { display: table-cell; }
  input.bulk-cb { width: auto; margin: 0; }
  table.selecting tbody tr.picked td { background: var(--tint-soft); }
  table.selecting tbody tr:has(.bulk-cb) { cursor: pointer; }
  .toolbar-row button.bulk-toggle { background: #fff; color: var(--brand); border: 1px solid #ccc; padding: 5px 8px; line-height: 0; }
  .toolbar-row button.bulk-toggle:hover { border-color: var(--brand); }
  .toolbar-row button.bulk-toggle[aria-pressed=true] { background: var(--brand); color: #fff; border-color: var(--brand); }
  .bulk-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; background: var(--tint-soft); border-radius: 6px; padding: 8px 12px; margin-bottom: 10px; font-size: 13px; }
  .bulk-bar[hidden] { display: none; }
  .bulk-bar a { color: var(--brand); }
  .bulk-bar button:disabled { opacity: .5; cursor: default; }
  h1 { font-size: 20px; margin: 0 0 16px; }
  h2 { font-size: 16px; margin: 0 0 12px; }

  /* Smaller screens: the nav collapses behind a hamburger button instead of
     spreading across (or under) the header, tighten spacing, and shrink the
     modal's own padding. Tables already fall back to horizontal scroll
     (.table-wrap above), so they aren't touched here. */
  @media (max-width: 720px) {
    header.topbar { flex-wrap: wrap; row-gap: 8px; padding: 10px 16px; }
    header.topbar > div:first-child { width: 100%; flex-wrap: wrap; justify-content: space-between; }
    header.topbar > div:last-child { width: 100%; display: flex; align-items: center; justify-content: space-between; }
    header.topbar a.brand { margin-right: 0; }
    .nav-toggle { display: inline-flex; }
    /* Hidden until the hamburger opens it (.nav-open, toggled in
       layout_bottom.php); then it drops to its own full-width row below the
       brand/toggle row, as a column of full-width rows/accordions — nothing
       left absolutely positioned that could overflow a narrow screen. */
    nav.topnav { display: none; flex-basis: 100%; width: 100%; flex-direction: column; align-items: stretch; margin-top: 10px; }
    nav.topnav.nav-open { display: flex; }
    nav.topnav > a { padding: 10px 6px; margin-right: 0; }
    .nav-drop-btn { width: 100%; justify-content: space-between; margin-right: 0; }
    .nav-drop-menu { position: static; box-shadow: none; border-radius: 0; padding: 0 0 0 14px; min-width: 0; width: 100%; }
  }
  @media (max-width: 480px) {
    main { margin: 16px auto; padding: 0 12px; }
    .card { padding: 14px; }
    dialog.modal .card { padding: 14px; }
    h1 { font-size: 18px; }
    .row > div { min-width: 100%; }
    th, td { padding: 8px; font-size: 12.5px; }
  }
</style>
<noscript><style>.card[data-modal] { display: block; }</style></noscript>
<script>
// The modal buttons / toolbar (see below) are built by this function. layout_bottom.php
// calls it once the page is parsed; a page with a long table calls it earlier, right
// above the table, so the buttons are there before the table is painted (otherwise
// they vanish for a moment when a page such as ?edit=ID is loaded). Safe to call twice.
window.pastoresInitToolbar = function () {
  // The buttons that open the modals (and any [data-toolbar-item], e.g. an
  // Export link) share one row above the table. The first item marked
  // data-align="right" and everything after it is pushed to the right.
  var toolbar = null;
  function toolbarRow(anchor) {
    if (!toolbar) toolbar = document.querySelector('.toolbar-row');
    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.className = 'toolbar-row';
      anchor.parentNode.insertBefore(toolbar, anchor);
    }
    return toolbar;
  }
  function addToToolbar(el, anchor, alignRight) {
    var row = toolbarRow(anchor);
    if (alignRight && !row.querySelector('.push-right')) {
      // Invisible marker (see .toolbar-break in layout_top.php) so a narrow
      // screen can break the row here without stretching this button itself.
      var brk = document.createElement('span');
      brk.className = 'toolbar-break';
      row.appendChild(brk);
      el.classList.add('push-right');
    }
    row.appendChild(el);
  }

  // A .card[data-modal] holds an add/edit form. It is moved into a <dialog>
  // and opened by an "add" button, or straight away when the page was
  // loaded in edit mode (data-open, from ?edit=ID). Without JS it stays a
  // plain card on the page.
  document.querySelectorAll('.card[data-modal]:not([data-modal-ready])').forEach(function (card) {
    card.setAttribute('data-modal-ready', '');
    var dlg = document.createElement('dialog');
    dlg.className = 'modal';
    card.parentNode.insertBefore(dlg, card);
    dlg.appendChild(card);

    var editing = card.hasAttribute('data-open');
    var cancelLink = card.querySelector('a.btn.secondary'); // only present when editing
    var x = document.createElement('button');
    x.type = 'button'; x.className = 'modal-close'; x.setAttribute('aria-label', 'Close'); x.innerHTML = '&times;';
    x.addEventListener('click', function () { dlg.close(); });
    card.insertBefore(x, card.firstChild);

    if (!cancelLink) {
      var row = card.querySelector('.btn-row');
      if (row) {
        var cancel = document.createElement('button');
        cancel.type = 'button'; cancel.className = 'secondary'; cancel.textContent = 'Cancel';
        cancel.addEventListener('click', function () { dlg.close(); });
        row.appendChild(cancel);
      }
    }
    // Forms that trigger a file download (Export) leave the page in place,
    // so close the modal ourselves once the download has started.
    if (card.hasAttribute('data-close-on-submit')) {
      var closeSoon = function () { setTimeout(function () { dlg.close(); }, 300); };
      var form = card.querySelector('form');
      if (form) form.addEventListener('submit', closeSoon);
      Array.prototype.forEach.call(card.querySelectorAll('[data-close-modal]'), function (a) { a.addEventListener('click', closeSoon); });
    }
    // Closing an edit dialog (Esc, backdrop, X) leaves edit mode.
    dlg.addEventListener('close', function () { if (editing && cancelLink) location.href = cancelLink.href; });
    dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); }); // backdrop

    function open() {
      dlg.showModal();
      var first = card.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea');
      if (first) first.focus();
    }
    var add = document.createElement('button');
    add.type = 'button'; add.textContent = card.getAttribute('data-add-label') || 'Add';
    add.className = card.getAttribute('data-button-class') || '';
    add.addEventListener('click', open);
    addToToolbar(add, dlg, card.hasAttribute('data-align'));
    if (editing) open();
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-toolbar-item]:not(.toolbar-placed)'), function (el) {
    el.classList.add('toolbar-placed'); // shown only once moved, so it never flashes at its source position
    // data-before-right: goes just before the right-aligned group (e.g. before
    // Filter) and takes over the push to the right.
    // data-toolbar-first: goes at the very start of the row (the bulk-select icon).
    if (el.hasAttribute('data-toolbar-first')) {
      var first = toolbarRow(el);
      first.insertBefore(el, first.firstChild);
      return;
    }
    // data-after-left: goes at the end of the left-hand group (right after
    // the last modal button, e.g. next to New activity), before the break.
    var brk = el.hasAttribute('data-after-left') ? toolbarRow(el).querySelector('.toolbar-break') : null;
    if (brk) {
      brk.parentNode.insertBefore(el, brk);
      return;
    }
    var pushed = el.hasAttribute('data-before-right') ? toolbarRow(el).querySelector('.push-right') : null;
    if (pushed) {
      pushed.classList.remove('push-right');
      el.classList.add('push-right');
      pushed.parentNode.insertBefore(el, pushed);
      return;
    }
    addToToolbar(el, el, el.hasAttribute('data-align'));
  });
};
</script>
</head>
<body>
<?php $navRole = $_SESSION['admin_role'] ?? 'super'; ?>
<header class="topbar">
  <div>
    <a class="brand" href="/admin/index.php">Pastores Admin</a>
    <button type="button" class="nav-toggle" aria-label="Menu" aria-expanded="false" aria-controls="topnav">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/></svg>
    </button>
    <nav class="topnav" id="topnav">
    <?php if ($navRole === 'super'): ?>
      <div class="nav-drop">
        <button type="button" class="nav-drop-btn">Structure</button>
        <div class="nav-drop-menu">
          <a href="/admin/zones/index.php">Zones</a>
          <a href="/admin/centres/index.php">Centres</a>
          <a href="/admin/sections/index.php">Sections</a>
          <a href="/admin/labors/index.php">Labors</a>
          <a href="/admin/activity_types/index.php">Activity types</a>
        </div>
      </div>
      <div class="nav-drop">
        <button type="button" class="nav-drop-btn">People</button>
        <div class="nav-drop-menu">
          <a href="/admin/users/index.php">Users</a>
          <a href="/admin/priests/index.php">Priests</a>
          <a href="/admin/admins/index.php">Admins</a>
        </div>
      </div>
      <div class="nav-drop">
        <button type="button" class="nav-drop-btn">Records</button>
        <div class="nav-drop-menu">
          <a href="/admin/source/index.php">Source</a>
          <a href="/admin/absences/index.php">Absences</a>
        </div>
      </div>
      <div class="nav-drop">
        <button type="button" class="nav-drop-btn">Activities</button>
        <div class="nav-drop-menu">
          <a href="/admin/activities/index.php">Regular</a>
          <a href="/admin/multiday_activities/index.php">Multi-day</a>
        </div>
      </div>
      <a href="/admin/settings/index.php">Settings</a>
    <?php elseif ($navRole === 'zone'): ?>
      <a href="/admin/activities/index.php">Activities</a>
      <a href="/admin/absences/index.php">Absences</a>
    <?php else: ?>
      <a href="/admin/users/index.php">Users</a>
      <a href="/admin/activities/index.php">Activities</a>
    <?php endif; ?>
    </nav>
  </div>
  <div>
    <span style="margin-right:16px;font-size:13px;"><?= e($_SESSION['admin_username'] ?? '') ?> <span style="opacity:.7;">(<?= e($navRole) ?>)</span></span>
    <a href="/admin/logout.php">Logout</a>
  </div>
</header>
<main>
<?php if (!empty($_SESSION['flash'])): ?>
  <div class="flash <?= e($_SESSION['flash']['type']) ?>"><?= e($_SESSION['flash']['message']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
