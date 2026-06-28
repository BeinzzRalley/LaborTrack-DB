<?php
// =============================================================================
// middleware/helpers.php — Shared auth helpers, JSON responses, input casting
//
// Session shape (set on successful login, see routes/auth.php):
//   $_SESSION['account_id']    (int)
//   $_SESSION['employee_id']   (int|null)
//   $_SESSION['access_level']  ('admin'|'employee')
//   $_SESSION['username']      (string)
// =============================================================================

declare(strict_types=1);

// ── CORS ─────────────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin) ||
    preg_match('#^https?://[a-z0-9\-]+\.dcism\.org$#', $origin)
) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
    session_start();
}

// ── JSON response helpers ─────────────────────────────────────────────────────
function json_ok($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function json_err(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// ── Request body helper ───────────────────────────────────────────────────────
function bodyJson(): array {
    $raw    = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

// ── Input casting helpers ─────────────────────────────────────────────────────
function str(array $body, string $key, string $default = ''): string {
    if (!isset($body[$key]) || $body[$key] === null) return $default;
    return trim((string)$body[$key]);
}

function intVal_(array $body, string $key, ?int $default = null): ?int {
    if (!isset($body[$key]) || $body[$key] === null || $body[$key] === '') return $default;
    return (int)$body[$key];
}

function floatVal_(array $body, string $key, float $default = 0.0): float {
    if (!isset($body[$key]) || $body[$key] === null || $body[$key] === '') return $default;
    return (float)$body[$key];
}

// ── Auth helpers ──────────────────────────────────────────────────────────────
function isLoggedIn(): bool          { return isset($_SESSION['account_id']); }
function currentAccountId(): ?int    { return $_SESSION['account_id']   ?? null; }
function currentEmployeeId(): ?int   { return $_SESSION['employee_id']  ?? null; }
function currentAccessLevel(): ?string { return $_SESSION['access_level'] ?? null; }

function requireAuth(): void {
    if (!isLoggedIn()) json_err('Authentication required.', 401);
}

function requireAdmin(): void {
    requireAuth();
    if (currentAccessLevel() !== 'admin') json_err('Forbidden. Admins only.', 403);
}
