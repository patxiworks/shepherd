</main>
<script>
// Shared admin behaviour: form cards become modals, tables become sortable.
(function () {
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
    if (alignRight && !row.querySelector('.push-right')) el.classList.add('push-right');
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
    addToToolbar(el, el, el.hasAttribute('data-align'));
  });

  // Click a column heading to sort by it; click again to reverse. Values
  // come from a cell's data-sort if it has one, else its text. A column
  // sorts numerically when all its values are numbers. Empty cells always
  // sort last. Headings with no text (the actions column) aren't sortable.
  document.querySelectorAll('main table').forEach(function (table) {
    if (!table.tHead || !table.tBodies[0]) return;
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
