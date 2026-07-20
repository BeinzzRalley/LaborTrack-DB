<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// Every employee gets ANNUAL_LEAVE_DAYS (see helpers.php) per year, shared
// across ALL leave types. leave_balances therefore has one row per
// (employee_id, year) -- never per leave type.

function castBalance(array $r): array {
    return [
        'balance_id'        => (int)$r['balance_id'],
        'employee_id'       => (int)$r['employee_id'],
        'employee_name'     => $r['employee_name'] ?? null,
        'department_name'   => $r['department_name'] ?? null,
        'year'              => (int)$r['year'],
        'entitled_days'     => (float)$r['entitled_days'],
        'carried_over_days' => (float)$r['carried_over_days'],
        'used_days'         => (float)$r['used_days'],
        'remaining_days'    => (float)$r['remaining_days'],
        'last_updated'      => $r['last_updated'] ?? null,
    ];
}

// GET -- list leave balances
if ($method === 'GET') {
    requireAuth();
    $pdo = getDB();
    $level = currentAccessLevel();
    $where = [];
    $params = [];

    if (in_array($level, ['system_admin', 'human_resources'], true)) {
        if (!empty($_GET['employee_id'])) {
            $where[] = 'lb.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['year'])) {
            $where[] = 'lb.year = ?';
            $params[] = (int)$_GET['year'];
        }
    } elseif ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) {
            json_ok([]);
        }
        $where[] = 'e.department_id = ?';
        $params[] = $deptId;
        if (!empty($_GET['employee_id'])) {
            $where[] = 'lb.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['year'])) {
            $where[] = 'lb.year = ?';
            $params[] = (int)$_GET['year'];
        }
    } else {
        $where[] = 'lb.employee_id = ?';
        $params[] = currentEmployeeId();
        if (!empty($_GET['year'])) {
            $where[] = 'lb.year = ?';
            $params[] = (int)$_GET['year'];
        }
    }

    $sql = 'SELECT lb.*,
                   CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                   d.department_name
            FROM   leave_balances lb
            JOIN   employees e ON e.employee_id = lb.employee_id
            LEFT   JOIN departments d ON d.department_id = e.department_id';

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY lb.year DESC, e.last_name, e.first_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    json_ok(array_map('castBalance', $stmt->fetchAll()));
}

// POST ?action=reset -- creates a fresh 12-day balance row for every active
// employee for the given year (no carryover -- the pool always resets flat
// to ANNUAL_LEAVE_DAYS). Skips any employee/year combo that already has a row.
if ($method === 'POST' && ($_GET['action'] ?? '') === 'reset') {
    requireHumanResources();
    $body = bodyJson();
    $year = intVal_($body, 'year');

    if (!$year) json_err('year is required.');

    $pdo = getDB();

    $empStmt = $pdo->prepare(
        "SELECT e.employee_id
         FROM   employees e
         JOIN   employment_status es ON es.employment_status_id = e.employment_status_id
         WHERE  es.status_name = 'Active'"
    );
    $empStmt->execute();
    $employees = $empStmt->fetchAll(PDO::FETCH_COLUMN);

    $created = 0;
    $skipped = 0;

    $chkStmt = $pdo->prepare('SELECT balance_id FROM leave_balances WHERE employee_id = ? AND year = ?');
    $insStmt = $pdo->prepare(
        'INSERT INTO leave_balances (employee_id, year, entitled_days, carried_over_days, used_days, remaining_days)
         VALUES (?, ?, ?, 0.0, 0.0, ?)'
    );

    foreach ($employees as $empId) {
        $chkStmt->execute([$empId, $year]);
        if ($chkStmt->fetch()) {
            $skipped++;
            continue;
        }

        $insStmt->execute([$empId, $year, ANNUAL_LEAVE_DAYS, ANNUAL_LEAVE_DAYS]);
        $created++;
    }

    logAudit($pdo, 'leave_balance_create', 'leave_balance', null, [
        'action'  => 'annual_reset',
        'year'    => $year,
        'created' => $created,
        'skipped' => $skipped,
    ]);

    json_ok([
        'message' => "Reset {$created} balance(s) to " . ANNUAL_LEAVE_DAYS . " days for {$year}" . ($skipped ? ", skipped {$skipped} already-existing." : "."),
        'created' => $created,
        'skipped' => $skipped,
    ]);
}

// POST -- create (manual grant/adjustment, e.g. correcting a new hire's pool)
if ($method === 'POST') {
    requireHumanResources();
    $body = bodyJson();

    $employeeId  = intVal_($body, 'employee_id');
    $year        = intVal_($body, 'year');
    $entitled    = floatVal_($body, 'entitled_days', ANNUAL_LEAVE_DAYS);
    $carriedOver = floatVal_($body, 'carried_over_days', 0.0);
    $used        = floatVal_($body, 'used_days', 0.0);

    if (!$employeeId) {
        json_err('employee_id is required.');
    }
    if (!$year) {
        json_err('year is required.');
    }

    $remaining = $entitled + $carriedOver - $used;

    $pdo = getDB();

    // Check duplication -- one pool per employee per year.
    $chk = $pdo->prepare('SELECT balance_id FROM leave_balances WHERE employee_id = ? AND year = ?');
    $chk->execute([$employeeId, $year]);
    if ($chk->fetch()) {
        json_err('A leave balance record already exists for this employee and year.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leave_balances
            (employee_id, year, entitled_days, carried_over_days, used_days, remaining_days)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $employeeId,
        $year,
        $entitled,
        $carriedOver,
        $used,
        $remaining
    ]);

    $balanceId = (int)$pdo->lastInsertId();

    logAudit($pdo, 'leave_balance_create', 'leave_balance', $balanceId, [
        'employee_id'       => $employeeId,
        'year'              => $year,
        'entitled_days'     => $entitled,
        'carried_over_days' => $carriedOver,
        'used_days'         => $used,
        'remaining_days'    => $remaining,
    ]);

    json_ok(['balance_id' => $balanceId, 'message' => 'Leave balance granted.']);
}

// PUT -- update
if ($method === 'PUT') {
    requireHumanResources();
    $body = bodyJson();

    $id          = intVal_($body, 'balance_id');
    $entitled    = floatVal_($body, 'entitled_days', ANNUAL_LEAVE_DAYS);
    $carriedOver = floatVal_($body, 'carried_over_days', 0.0);
    $used        = floatVal_($body, 'used_days', 0.0);

    if (!$id) {
        json_err('balance_id is required.');
    }

    $remaining = $entitled + $carriedOver - $used;

    $pdo = getDB();
    $existsStmt = $pdo->prepare('SELECT balance_id FROM leave_balances WHERE balance_id = ? LIMIT 1');
    $existsStmt->execute([$id]);
    if (!$existsStmt->fetch()) {
        json_err('Leave balance not found.', 404);
    }

    $stmt = $pdo->prepare(
        'UPDATE leave_balances
         SET    entitled_days = ?, carried_over_days = ?, used_days = ?, remaining_days = ?
         WHERE  balance_id = ?'
    );
    $stmt->execute([
        $entitled,
        $carriedOver,
        $used,
        $remaining,
        $id
    ]);

    logAudit($pdo, 'leave_balance_update', 'leave_balance', $id, [
        'entitled_days'     => $entitled,
        'carried_over_days' => $carriedOver,
        'used_days'         => $used,
        'remaining_days'    => $remaining,
    ]);

    json_ok(['message' => 'Leave balance updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireHumanResources();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('DELETE FROM leave_balances WHERE balance_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Leave balance not found.', 404);
    }

    logAudit($pdo, 'leave_balance_delete', 'leave_balance', $id, null);

    json_ok(['message' => 'Leave balance deleted.']);
}

json_err('Method not allowed.', 405);