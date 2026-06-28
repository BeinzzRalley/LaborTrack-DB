<?php
// =============================================================================
// routes/time_logs.php — Clock-in / clock-out and time log management
//
// GET  /backend/routes/time_logs.php                  → list logs
// POST /backend/routes/time_logs.php?action=clock_in
// POST /backend/routes/time_logs.php?action=clock_out
// PUT  /backend/routes/time_logs.php                  → admin edit
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

requireAuth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

const LATE_HOUR   = 9;
const LATE_MINUTE = 15;

function isLate(string $clockInDatetime): bool {
    $dt = new DateTime($clockInDatetime);
    $h  = (int)$dt->format('H');
    $m  = (int)$dt->format('i');
    return ($h > LATE_HOUR) || ($h === LATE_HOUR && $m > LATE_MINUTE);
}

function castLog(array $r): array {
    return array_merge($r, [
        'log_id'            => (int)$r['log_id'],
        'employee_id'       => (int)$r['employee_id'],
        'shift_category_id' => $r['shift_category_id'] !== null ? (int)$r['shift_category_id'] : null,
        'status_id'         => $r['status_id']         !== null ? (int)$r['status_id']         : null,
        'total_hours'       => $r['total_hours']        !== null ? (float)$r['total_hours']     : null,
    ]);
}

const LOG_SELECT =
    'SELECT tl.log_id, tl.employee_id, e.full_name,
            tl.shift_category_id, sc.category_name,
            tl.status_id, ast.status_label,
            tl.clock_in, tl.clock_out, tl.total_hours
     FROM   time_logs tl
     JOIN   employees e          ON e.employee_id           = tl.employee_id
     LEFT JOIN shift_categories sc ON sc.shift_category_id = tl.shift_category_id
     LEFT JOIN attendance_status ast ON ast.status_id       = tl.status_id';

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (currentAccessLevel() === 'admin') {
        $stmt = $pdo->query(LOG_SELECT . ' ORDER BY tl.clock_in DESC');
    } else {
        $empId = currentEmployeeId();
        if ($empId === null) json_err('No employee record linked to this account.', 403);
        $stmt = $pdo->prepare(LOG_SELECT . ' WHERE tl.employee_id = ? ORDER BY tl.clock_in DESC');
        $stmt->execute([$empId]);
    }
    json_ok(array_map('castLog', $stmt->fetchAll()));
}

// ── POST: clock in ────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'clock_in') {
    $empId = currentEmployeeId();
    if ($empId === null) json_err('No employee record linked to this account.', 403);

    $body            = bodyJson();
    $shiftCategoryId = intVal_($body, 'shift_category_id', 1);

    $today = (new DateTime())->format('Y-m-d');
    $check = $pdo->prepare(
        'SELECT log_id FROM time_logs WHERE employee_id = ? AND DATE(clock_in) = ? AND clock_out IS NULL LIMIT 1'
    );
    $check->execute([$empId, $today]);
    if ($check->fetch()) json_err('You are already clocked in. Clock out before clocking in again.');

    $now      = (new DateTime())->format('Y-m-d H:i:s');
    $statusId = isLate($now) ? 2 : 1;

    $pdo->prepare(
        'INSERT INTO time_logs (employee_id, shift_category_id, status_id, clock_in) VALUES (?, ?, ?, ?)'
    )->execute([$empId, $shiftCategoryId, $statusId, $now]);

    $logId = (int)$pdo->lastInsertId();
    $sel   = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel->execute([$logId]);
    json_ok(castLog($sel->fetch()), 201);
}

// ── POST: clock out ───────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'clock_out') {
    $empId = currentEmployeeId();
    if ($empId === null) json_err('No employee record linked to this account.', 403);

    $today = (new DateTime())->format('Y-m-d');
    $sel   = $pdo->prepare(
        'SELECT log_id, clock_in FROM time_logs WHERE employee_id = ? AND DATE(clock_in) = ? AND clock_out IS NULL LIMIT 1'
    );
    $sel->execute([$empId, $today]);
    $log = $sel->fetch();
    if (!$log) json_err('No open clock-in found for today.');

    $clockOut   = new DateTime();
    $clockIn    = new DateTime($log['clock_in']);
    $totalHours = round(($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 3600, 2);

    $pdo->prepare('UPDATE time_logs SET clock_out = ?, total_hours = ? WHERE log_id = ?')
        ->execute([$clockOut->format('Y-m-d H:i:s'), $totalHours, (int)$log['log_id']]);

    $sel2 = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel2->execute([(int)$log['log_id']]);
    json_ok(castLog($sel2->fetch()));
}

// ── PUT: admin edit ───────────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (currentAccessLevel() !== 'admin') json_err('Admins only.', 403);

    $body   = bodyJson();
    $logId  = intVal_($body, 'log_id');
    if (!$logId) json_err('log_id is required.');

    $fields = [];
    $params = [];

    if (isset($body['shift_category_id'])) { $fields[] = 'shift_category_id = ?'; $params[] = intVal_($body, 'shift_category_id'); }
    if (isset($body['status_id']))          { $fields[] = 'status_id = ?';          $params[] = intVal_($body, 'status_id'); }
    if (empty($fields)) json_err('Nothing to update.');

    $params[] = $logId;
    $pdo->prepare('UPDATE time_logs SET ' . implode(', ', $fields) . ' WHERE log_id = ?')->execute($params);

    $sel = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel->execute([$logId]);
    $row = $sel->fetch();
    if (!$row) json_err('Log not found.', 404);
    json_ok(castLog($row));
}

json_err('Not found.', 404);
