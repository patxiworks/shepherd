<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/multiday_activities.php';
require __DIR__ . '/../includes/pastoral_dashboard.php';
$admin = admin_require_login();

$pdo = pastores_db();
$scopeLabel = null;
$mdayAvailable = multiday_activities_available($pdo); // migration 015 may not be applied yet

if ($admin['role'] === 'super') {
    $counts = [
        'Zones' => $pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn(),
        'Centres' => $pdo->query('SELECT COUNT(*) FROM centres')->fetchColumn(),
        'Users' => $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'Activities' => $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn(),
    ];
    if ($mdayAvailable) {
        $counts['Multi-day Activities'] = $pdo->query('SELECT COUNT(*) FROM multiday_activities')->fetchColumn();
    }
} elseif ($admin['role'] === 'zone') {
    $zoneName = $pdo->prepare('SELECT name FROM zones WHERE id = ?');
    $zoneName->execute([$admin['zone_id']]);
    $scopeLabel = 'Zone: ' . ($zoneName->fetchColumn() ?: 'Unknown');

    $users = $pdo->prepare('SELECT COUNT(*) FROM users WHERE zone_id = ?');
    $users->execute([$admin['zone_id']]);
    $activities = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE zone_id = ?');
    $activities->execute([$admin['zone_id']]);

    $centres = $pdo->prepare('SELECT COUNT(*) FROM centres WHERE zone_id = ?');
    $centres->execute([$admin['zone_id']]);

    $counts = [
        'Users' => $users->fetchColumn(),
        'Centres' => $centres->fetchColumn(),
        'Activities' => $activities->fetchColumn(),
    ];
} else { // centre
    $centreName = admin_centre_name($pdo, $admin);
    $scopeLabel = 'Centre: ' . ($centreName ?? 'Unknown');

    $users = $pdo->prepare('SELECT COUNT(*) FROM users WHERE zone_id = ? AND centre = ?');
    $users->execute([$admin['zone_id'], $centreName]);
    $activities = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE zone_id = ? AND centre = ?');
    $activities->execute([$admin['zone_id'], $centreName]);

    $counts = [
        'Users' => $users->fetchColumn(),
        'Activities' => $activities->fetchColumn(),
    ];
}

$pageTitle = 'General Dashboard — Pastores Admin';
require __DIR__ . '/includes/layout_top.php';
?>
<h1>General Dashboard</h1>
<?php if ($scopeLabel): ?><p style="color:#666; margin-top:-8px;"><?= e($scopeLabel) ?></p><?php endif; ?>
<div class="row">
  <?php foreach ($counts as $label => $count): ?>
    <div class="card" style="flex:1; min-width:140px; text-align:center;">
      <div style="font-size:28px; font-weight:700; color:var(--brand);"><?= (int) $count ?></div>
      <div style="font-size:13px; color:#666;"><?= e($label) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php pd_render($pdo, $admin, $mdayAvailable); ?>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
