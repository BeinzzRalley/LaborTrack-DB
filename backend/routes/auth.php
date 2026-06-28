<?php
// =============================================================================
// routes/auth.php — Login / logout / session check
//
// POST /backend/routes/auth.php?action=login    body: { username, password }
// POST /backend/routes/auth.php?action=logout
// GET  /backend/routes/auth.php?action=me
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── POST: login ───────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $body     = bodyJson();
    $username = str($body, 'username');
    $password = str($body, 'password');

    if ($username === '') json_err('username is required.');
    if ($password === '') json_err('password is required.');

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT a.account_id, a.employee_id, a.username, a.password_hash, a.access_level,
                e.full_name
         FROM   accounts a
         LEFT   JOIN employees e ON e.employee_id = a.employee_id
         WHERE  a.username = ?
         LIMIT  1'
    );
    $stmt->execute([$username]);
    $acc = $stmt->fetch();

    if (!$acc || !password_verify($password, $acc['password_hash'])) {
        json_err('Invalid username or password.', 401);
    }

    session_regenerate_id(true);

    $_SESSION['account_id']   = (int)$acc['account_id'];
    $_SESSION['employee_id']  = $acc['employee_id'] !== null ? (int)$acc['employee_id'] : null;
    $_SESSION['access_level'] = $acc['access_level'];
    $_SESSION['username']     = $acc['username'];

    json_ok([
        'account_id'   => (int)$acc['account_id'],
        'employee_id'  => $acc['employee_id'] !== null ? (int)$acc['employee_id'] : null,
        'username'     => $acc['username'],
        'access_level' => $acc['access_level'],
        'full_name'    => $acc['full_name'],
    ]);
}

// ── POST: logout ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie('PHPSESSID', '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    json_ok(['message' => 'Logged out.']);
}

// ── GET: current session ──────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'me') {
    requireAuth();
    json_ok([
        'account_id'   => currentAccountId(),
        'employee_id'  => currentEmployeeId(),
        'access_level' => currentAccessLevel(),
        'username'     => $_SESSION['username'] ?? null,
    ]);
}

json_err('Not found.', 404);
