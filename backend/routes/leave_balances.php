<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list leave balances
if ($method === 'GET') {
    requireAuth();
    $pdo = getDB();
    $level = currentAccessLevel();
    $where = [];
    $params = [];

    if (in_array($level, ['system_admin', 'payroll_admin'], true)) {
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
                   d.department_name,
                   lt.leave_name AS leave_type_name
            FROM   leave_balances lb
            JOIN   employees e ON e.employee_id = lb.employee_id
            LEFT   JOIN departments d ON d.department_id = e.department_id
            LEFT   JOIN leave_types lt ON lt.leave_type_id = lb.leave_type_id';

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY lb.year DESC, e.last_name, e.first_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    json_ok(array_map(fn($r) => [
        'balance_id'        => (int)$r['balance_id'],
        'employee_id'       => (int)$r['employee_id'],
        'employee_name'     => $r['employee_name'],
        'department_name'   => $r['department_name'],
        'leave_type_id'     => (int)$r['leave_type_id'],
        'leave_type_name'   => $r['leave_type_name'],
        'year'              => (int)$r['year'],
        'entitled_days'     => (float)$r['entitled_days'],
        'carried_over_days' => (float)$r['carried_over_days'],
        'used_days'         => (float)$r['used_days'],
        'remaining_days'    => (float)$r['remaining_days'],
        'last_updated'      => $r['last_updated'],
    ], $stmt->fetchAll()));
}

// POST — create
if ($method === 'POST') {
    requirePayrollAdmin();
    $body = bodyJson();
    
    $employeeId  = intVal_($body, 'employee_id');
    $leaveTypeId = intVal_($body, 'leave_type_id');
    $year        = intVal_($body, 'year');
    $entitled    = floatVal_($body, 'entitled_days', 0.0);
    $carriedOver = floatVal_($body, 'carried_over_days', 0.0);
    $used        = floatVal_($body, 'used_days', 0.0);

    if (!$employeeId) {
        json_err('employee_id is required.');
    }
    if (!$leaveTypeId) {
        json_err('leave_type_id is required.');
    }
    if (!$year) {
        json_err('year is required.');
    }

    $remaining = $entitled + $carriedOver - $used;

    $pdo = getDB();
    
    // Check duplication
    $chk = $pdo->prepare('SELECT balance_id FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?');
    $chk->execute([$employeeId, $leaveTypeId, $year]);
    if ($chk->fetch()) {
        json_err('A leave balance record already exists for this employee, type, and year.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leave_balances 
            (employee_id, leave_type_id, year, entitled_days, carried_over_days, used_days, remaining_days)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $employeeId,
        $leaveTypeId,
        $year,
        $entitled,
        $carriedOver,
        $used,
        $remaining
    ]);

    json_ok(['balance_id' => (int)$pdo->lastInsertId(), 'message' => 'Leave balance granted.']);
}

// PUT — update
if ($method === 'PUT') {
    requirePayrollAdmin();
    $body = bodyJson();
    
    $id          = intVal_($body, 'balance_id');
    $entitled    = floatVal_($body, 'entitled_days', 0.0);
    $carriedOver = floatVal_($body, 'carried_over_days', 0.0);
    $used        = floatVal_($body, 'used_days', 0.0);

    if (!$id) {
        json_err('balance_id is required.');
    }

    $remaining = $entitled + $carriedOver - $used;

    $pdo = getDB();
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

    json_ok(['message' => 'Leave balance updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requirePayrollAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $stmt = getDB()->prepare('DELETE FROM leave_balances WHERE balance_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Leave balance not found.', 404);
    }

    json_ok(['message' => 'Leave balance deleted.']);
}

json_err('Method not allowed.', 405);
