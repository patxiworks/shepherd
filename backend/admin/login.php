<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';

admin_session_start();

if (!empty($_SESSION['admin_id'])) {
    header('Location: /admin/index.php');
    exit;
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (admin_attempt_login(pastores_db(), $username, $password)) {
        header('Location: /admin/index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — Pastores</title>
<style>
  <?php try { echo theme_css_vars(theme_brand(pastores_db())); } catch (Throwable $e) { echo theme_css_vars(PASTORES_THEME_DEFAULT); } ?>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 16px; }
  form { background: #fff; padding: 32px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); width: 100%; max-width: 320px; }
  h1 { font-size: 18px; margin: 0 0 20px; color: var(--brand); }
  label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; }
  input { width: 100%; padding: 9px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
  button { width: 100%; margin-top: 20px; background: var(--brand); color: #fff; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px; }
  .error { background: #fdecea; color: #c62828; padding: 8px 10px; border-radius: 4px; font-size: 13px; margin-top: 12px; }
</style>
</head>
<body>
<form method="post">
  <h1>Pastores Admin</h1>
  <label for="username">Username</label>
  <input type="text" id="username" name="username" required autofocus>
  <label for="password">Password</label>
  <input type="password" id="password" name="password" required>
  <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
  <button type="submit">Log in</button>
</form>
</body>
</html>
