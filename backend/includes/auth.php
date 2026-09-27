<?php

// Passcode hashing helpers. Case-insensitivity of the old Google Sheets
// comparison (`u.passcode.toLowerCase() === passcode.toLowerCase()`) is
// preserved by always lower-casing before hashing/verifying.

function hash_passcode(string $passcode): string
{
    return password_hash(strtolower($passcode), PASSWORD_DEFAULT);
}

function verify_passcode(string $passcode, string $hash): bool
{
    return password_verify(strtolower($passcode), $hash);
}

// --- Admin backend session auth (separate from zone/passcode login) ---

function admin_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

// Returns the logged-in admin's session data, or null if not logged in.
// Shape: ['id', 'username', 'role' => 'super'|'zone'|'centre', 'zone_id', 'centre_id']
function admin_current(): ?array
{
    admin_session_start();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    return [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'],
        'role' => $_SESSION['admin_role'],
        'zone_id' => $_SESSION['admin_zone_id'],
        'centre_id' => $_SESSION['admin_centre_id'],
    ];
}

function admin_require_login(): array
{
    $admin = admin_current();
    if ($admin === null) {
        header('Location: /admin/login.php');
        exit;
    }
    return $admin;
}

// Aborts with 403 unless the current admin's role is one of $roles.
// Call after admin_require_login(). Returns the current admin on success.
function admin_require_role(string ...$roles): array
{
    $admin = admin_require_login();
    if (!in_array($admin['role'], $roles, true)) {
        http_response_code(403);
        die('Forbidden: your admin account does not have access to this section.');
    }
    return $admin;
}

// Resolves the centre name a 'centre'-scoped admin is restricted to.
function admin_centre_name(PDO $pdo, array $admin): ?string
{
    if (!$admin['centre_id']) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT name FROM centres WHERE id = ?');
    $stmt->execute([$admin['centre_id']]);
    return $stmt->fetchColumn() ?: null;
}

function admin_attempt_login(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, password_hash, role, zone_id, centre_id FROM admin_users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        admin_session_start();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $row['id'];
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role'] = $row['role'];
        $_SESSION['admin_zone_id'] = $row['zone_id'] !== null ? (int) $row['zone_id'] : null;
        $_SESSION['admin_centre_id'] = $row['centre_id'] !== null ? (int) $row['centre_id'] : null;
        return true;
    }
    return false;
}

function admin_logout(): void
{
    admin_session_start();
    $_SESSION = [];
    session_destroy();
}
