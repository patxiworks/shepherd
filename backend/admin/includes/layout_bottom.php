</main>
<script>
// Shared admin behaviour: form cards become modals, tables become sortable.
(function () {
  // Every table scrolls horizontally instead of squashing on a narrow
  // screen (.table-wrap, styled in layout_top.php). A couple of pages
  // (source, activities) already wrap their own table for other reasons;
  // everyone else gets it wrapped here automatically.
  document.querySelectorAll('main table').forEach(function (table) {
    if (table.closest('.table-wrap') || table.hasAttribute('data-no-wrap')) return;
    var wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
  });

  // Top-nav dropdowns (super admin: Structure / People / Records / Activities).
  // Click a .nav-drop-btn to open its sibling .nav-drop-menu; only one open at
  // a time; closes on outside click, Escape, or choosing a link.
  var drops = Array.prototype.slice.call(document.querySelectorAll('.nav-drop'));
  function closeDrops() {
    drops.forEach(function (d) { d.classList.remove('open'); });
  }
  drops.forEach(function (drop) {
    var btn = drop.querySelector('.nav-drop-btn');
    btn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var willOpen = !drop.classList.contains('open');
      closeDrops();
      drop.classList.toggle('open', willOpen);
    });
  });

  // Small screens: the whole nav collapses behind a hamburger button
  // (.nav-toggle, styled in layout_top.php) instead of spreading across or
  // under the header. Toggled open/closed by tapping it; closes on an
  // outside click, Escape, or picking a link (which navigates away anyway).
  var navToggle = document.querySelector('.nav-toggle');
  var topnav = document.querySelector('nav.topnav');
  function closeNav() {
    if (!topnav || !topnav.classList.contains('nav-open')) return;
    topnav.classList.remove('nav-open');
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
  }
  if (navToggle && topnav) {
    navToggle.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var willOpen = !topnav.classList.contains('nav-open');
      topnav.classList.toggle('nav-open', willOpen);
      navToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (!willOpen) closeDrops();
    });
  }
  document.addEventListener('click', function (ev) {
    closeDrops();
    if (topnav && !topnav.contains(ev.target)) closeNav();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    closeDrops();
    closeNav();
  });

  // The buttons that open the modals (and any [data-toolbar-item], e.g. an
  // Export link) share one row above the table. The first item marked
  // data-align="right" and everything after it is pushed to the right.
  var toolbar = null;
  function toolbarRow(anchor) {
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
  document.querySelectorAll('.card[data-modal]').forEach(function (card) {
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
  Array.prototype.forEach.call(document.querySelectorAll('[data-toolbar-item]'), function (el) {
    el.classList.add('toolbar-placed'); // shown only once moved, so it never flashes at its source position
    // data-before-right: goes just before the right-aligned group (e.g. before
    // Filter) and takes over the push to the right.
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

  // Bulk delete: every table whose rows have a Delete form gets a "select rows"
  // icon at the far left of the toolbar. It reveals a checkbox column and a bar
  // (Delete selected / Cancel); clicking a row then ticks it instead of opening
  // its editor, Shift-click ticks a range. If the table shows only the latest N
  // of `data-bulk-total` rows, ticking every shown row offers "select all
  // matching the filters" (the page re-counts before deleting). Posts
  // action=bulk_delete (+ ids[] or all_matching/expected + data-bulk-extra) to
  // the current URL; the page's own handler does the deleting (includes/bulk.php).
  document.querySelectorAll('main table').forEach(function (table) {
    var tbody = table.tBodies[0];
    if (!table.tHead || !tbody) return;
    var headRow = table.tHead.rows[0];
    var cols = headRow.cells.length;
    function deleteId(tr) {
      var a = tr.querySelector('form input[name=action][value=delete]');
      var id = a && a.form.querySelector('input[name=id]');
      return id ? id.value : null;
    }
    if (!Array.prototype.some.call(tbody.rows, function (tr) { return deleteId(tr) !== null; })) return;

    // The column is always present (hidden by CSS) so column numbers stay the
    // same for sorting; rows re-rendered by inline editing get one too.
    var th = document.createElement('th');
    th.className = 'bulk-col';
    th.innerHTML = '<input type="checkbox" class="bulk-cb" aria-label="Select all shown rows">';
    headRow.insertBefore(th, headRow.firstChild);
    var selAll = th.firstChild;
    function addCell(tr) {
      if (tr.cells.length !== cols) return;
      var td = document.createElement('td');
      td.className = 'bulk-col';
      var id = deleteId(tr);
      if (id !== null) {
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.className = 'bulk-cb'; cb.value = id; cb.setAttribute('aria-label', 'Select this row');
        td.appendChild(cb);
      }
      tr.insertBefore(td, tr.firstChild);
    }
    Array.prototype.forEach.call(tbody.rows, addCell);
    new MutationObserver(function (muts) {
      muts.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.tagName === 'TR') addCell(n); }); });
    }).observe(tbody, { childList: true });

    var toolbar = document.querySelector('.toolbar-row');
    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.className = 'toolbar-row';
      var anchor = table.closest('.table-wrap') || table;
      anchor.parentNode.insertBefore(toolbar, anchor);
    }
    var toggle = document.createElement('button');
    toggle.type = 'button'; toggle.className = 'bulk-toggle';
    toggle.title = 'Select rows to delete'; toggle.setAttribute('aria-label', 'Select rows to delete'); toggle.setAttribute('aria-pressed', 'false');
    toggle.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><path d="M5 6.5l1.5 1.5L9 5"/><path d="M14 5h7M14 8h5"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 16h7M14 19h5"/></svg>';
    toolbar.insertBefore(toggle, toolbar.firstChild);
    var bar = document.createElement('div');
    bar.className = 'bulk-bar'; bar.hidden = true;
    bar.innerHTML = '<span class="bulk-count">0 selected</span> <a href="#" class="bulk-all" hidden></a> <button type="button" class="danger bulk-delete" disabled>Delete selected</button> <button type="button" class="secondary bulk-cancel">Cancel</button>';
    toolbar.insertAdjacentElement('afterend', bar);
    var barCount = bar.querySelector('.bulk-count'), barAll = bar.querySelector('.bulk-all'), barDelete = bar.querySelector('.bulk-delete');

    var total = parseInt(table.getAttribute('data-bulk-total') || '0', 10);
    var extra = {};
    try { extra = JSON.parse(table.getAttribute('data-bulk-extra') || '{}'); } catch (e) {}
    var allMatching = false, lastBox = null;
    function boxes() { return Array.prototype.slice.call(tbody.querySelectorAll('input.bulk-cb')); }
    function picked() { return boxes().filter(function (b) { return b.checked; }); }
    function refresh() {
      var all = boxes(), n = picked().length, shown = all.length;
      all.forEach(function (b) { b.closest('tr').classList.toggle('picked', b.checked); });
      selAll.checked = shown > 0 && n === shown;
      selAll.indeterminate = n > 0 && n < shown;
      if (n === 0) allMatching = false;
      barAll.hidden = !(n === shown && total > shown);
      barAll.textContent = allMatching ? 'Only the ' + shown + ' shown' : 'Select all ' + total + ' matching the current filters';
      barCount.textContent = allMatching ? 'All ' + total + ' matching the filters selected' : n + ' selected';
      barDelete.disabled = n === 0;
    }
    function setSelecting(on) {
      if (on) Array.prototype.forEach.call(table.querySelectorAll('tr.editing button.cancel'), function (b) { b.click(); });
      table.classList.toggle('selecting', on);
      bar.hidden = !on;
      toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (!on) { boxes().forEach(function (b) { b.checked = false; }); allMatching = false; }
      refresh();
    }
    function post(fields) {
      var f = document.createElement('form');
      f.method = 'post'; f.action = location.href;
      function add(name, value) { var i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value; f.appendChild(i); }
      add('action', 'bulk_delete');
      Object.keys(extra).forEach(function (k) { add(k, extra[k]); });
      fields.forEach(function (kv) { add(kv[0], kv[1]); });
      document.body.appendChild(f);
      f.submit();
    }
    toggle.addEventListener('click', function () { setSelecting(!table.classList.contains('selecting')); });
    bar.querySelector('.bulk-cancel').addEventListener('click', function () { setSelecting(false); });
    selAll.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = selAll.checked; }); refresh(); });
    barAll.addEventListener('click', function (ev) { ev.preventDefault(); allMatching = !allMatching; refresh(); });
    barDelete.addEventListener('click', function () {
      var n = picked().length;
      if (allMatching) {
        var typed = prompt('This permanently deletes all ' + total + ' rows matching the current filters.\nType ' + total + ' to confirm.');
        if (typed === null || typed.trim() !== String(total)) return;
        post([['all_matching', '1'], ['expected', String(total)]]);
      } else {
        if (!confirm('Delete ' + n + ' selected row' + (n === 1 ? '' : 's') + '? This cannot be undone.')) return;
        post(picked().map(function (b) { return ['ids[]', b.value]; }));
      }
    });
    // Capture phase, so a row click ticks the row instead of reaching the page's own row editor.
    table.addEventListener('click', function (ev) {
      if (!table.classList.contains('selecting')) return;
      var tr = ev.target.closest('tbody tr');
      var box = tr && tr.querySelector('input.bulk-cb');
      if (!box || ev.target.closest('a, button, form')) return;
      if (ev.target !== box) box.checked = !box.checked;
      if (ev.shiftKey && lastBox && lastBox !== box) {
        var all = boxes(), a = all.indexOf(lastBox), b = all.indexOf(box);
        all.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) { x.checked = box.checked; });
      }
      lastBox = box;
      allMatching = false;
      refresh();
      ev.stopPropagation();
    }, true);
  });

  // Click a column heading to sort by it; click again to reverse. Values
  // come from a cell's data-sort if it has one, else its text. A column
  // sorts numerically when all its values are numbers. Empty cells always
  // sort last. Headings with no text (the actions column) aren't sortable.
  document.querySelectorAll('main table').forEach(function (table) {
    if (!table.tHead || !table.tBodies[0] || table.hasAttribute('data-no-sort')) return;
    var ths = Array.prototype.slice.call(table.tHead.rows[0].cells);
    var tbody = table.tBodies[0];
    ths.forEach(function (th, i) {
      if (!th.textContent.trim()) return;
      th.classList.add('sortable-th');
      th.tabIndex = 0;
      function sort() {
        if (table.querySelector('tr.editing')) return;
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        ths.forEach(function (t) { t.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', dir);
        var rows = Array.prototype.slice.call(tbody.rows).filter(function (r) { return r.cells.length === ths.length; });
        var val = function (r) { var c = r.cells[i]; return (c.getAttribute('data-sort') !== null ? c.getAttribute('data-sort') : c.textContent).trim(); };
        var numeric = rows.every(function (r) { var v = val(r); return v === '' || !isNaN(Number(v)); });
        var sign = dir === 'ascending' ? 1 : -1;
        rows.sort(function (a, b) {
          var va = val(a), vb = val(b);
          if (va === '' || vb === '') return va === vb ? 0 : (va === '' ? 1 : -1);
          var c = numeric ? Number(va) - Number(vb) : va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' });
          return c * sign;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
      }
      th.addEventListener('click', sort);
      th.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); sort(); } });
    });
  });
})();
</script>
</body>
</html>
