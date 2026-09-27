<?php
// Expects $pageTitle to be set by the including file.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Pastores Admin') ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f5f5f5; color: #222; }
  header.topbar { background: #673AB7; color: #fff; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
  header.topbar a { color: #fff; text-decoration: none; margin-right: 16px; font-size: 14px; }
  header.topbar a.brand { font-weight: 600; font-size: 16px; margin-right: 32px; }
  main { max-width: <?= !empty($pageWide) ? 'none' : '1100px' ?>; margin: 24px auto; padding: 0 16px; }
  nav.tabs { display: flex; gap: 4px; margin-bottom: 20px; flex-wrap: wrap; }
  nav.tabs a { padding: 8px 14px; background: #fff; border-radius: 6px 6px 0 0; text-decoration: none; color: #444; font-size: 14px; border: 1px solid #ddd; border-bottom: none; }
  nav.tabs a.active { background: #673AB7; color: #fff; border-color: #673AB7; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
  th { background: #fafafa; font-weight: 600; }
  tr:hover { background: #fafaff; }
  .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
  form.inline { display: inline; }
  label { display: block; font-size: 13px; font-weight: 600; margin: 10px 0 4px; }
  input, select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
  textarea { min-height: 70px; }
  .hint { display: block; font-size: 12px; color: #666; margin-top: 4px; min-height: 1.3em; }
  .row { display: flex; gap: 12px; flex-wrap: wrap; }
  .row > div { flex: 1; min-width: 160px; }
  button, .btn { background: #673AB7; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
  button.secondary, .btn.secondary { background: #999; }
  button.danger, .btn.danger { background: #E91E63; }
  .btn-row { margin-top: 16px; display: flex; gap: 8px; }
  .actions a { margin-right: 10px; font-size: 12px; }
  .flash { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
  .flash.success { background: #e8f5e9; color: #2e7d32; }
  .flash.error { background: #fdecea; color: #c62828; }
  .flash.warning { background: #fdecea; color: #c62828; }
  .toolbar-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
  .toolbar-row .push-right { margin-left: auto; }
  .btn.muted, button.muted { background: #999; }
  dialog.modal { border: none; border-radius: 8px; padding: 0; width: min(900px, 94vw); max-height: 90vh; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
  dialog.modal::backdrop { background: rgba(0,0,0,.45); }
  dialog.modal .card { margin: 0; box-shadow: none; position: relative; max-height: 90vh; overflow: auto; }
  dialog.modal .card h2 { padding-right: 32px; }
  .modal-close { position: absolute; top: 12px; right: 14px; background: none; color: #666; font-size: 24px; line-height: 1; padding: 0 6px; }
  th.sortable-th { cursor: pointer; user-select: none; white-space: nowrap; }
  th.sortable-th::after { content: ' \21C5'; color: #bbb; font-size: 11px; }
  th.sortable-th[aria-sort=ascending]::after { content: ' \25B2'; color: #673AB7; }
  th.sortable-th[aria-sort=descending]::after { content: ' \25BC'; color: #673AB7; }
  h1 { font-size: 20px; margin: 0 0 16px; }
  h2 { font-size: 16px; margin: 0 0 12px; }
</style>
</head>
<body>
<?php $navRole = $_SESSION['admin_role'] ?? 'super'; ?>
<header class="topbar">
  <div>
    <a class="brand" href="/admin/index.php">Pastores Admin</a>
    <?php if ($navRole === 'super'): ?>
      <a href="/admin/zones/index.php">Zones</a>
    <?php endif; ?>
    <?php if ($navRole === 'super'): ?>
      <a href="/admin/centres/index.php">Centres</a>
    <?php endif; ?>
    <a href="/admin/users/index.php">Users</a>
    <a href="/admin/activities/index.php">Activities</a>
    <?php if ($navRole === 'super'): ?>
      <a href="/admin/source/index.php">Source</a>
    <?php endif; ?>
    <?php if (in_array($navRole, ['super', 'zone'], true)): ?>
      <a href="/admin/masses/index.php">Masses</a>
      <a href="/admin/absences/index.php">Absences</a>
    <?php endif; ?>
    <?php if ($navRole === 'super'): ?>
      <a href="/admin/priests/index.php">Priests</a>
      <a href="/admin/sections/index.php">Sections</a>
      <a href="/admin/labors/index.php">Labors</a>
      <a href="/admin/activity_types/index.php">Activity types</a>
    <?php endif; ?>
    <?php if ($navRole === 'super'): ?>
      <a href="/admin/admins/index.php">Admins</a>
      <a href="/admin/settings/index.php">Settings</a>
    <?php endif; ?>
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
