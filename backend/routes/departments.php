<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
// list all depts
if ($method === 'GET') {
    requireAuth();
    $pdo  = getDB();
    $search = $_GET['search'] ?? '';
    $sql = 'SELECT d.*, COUNT(e.employee_id) AS employee_count
            FROM   departments d
            LEFT   JOIN employees e ON e.department_id = d.department_id
            WHERE  1=1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (d.department_name LIKE ? OR d.department_code LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    $sql .= ' GROUP BY d.department_id ORDER BY d.department_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    json_ok(array_map(fn($r) => [
        'department_id'         => (int)$r['department_id'],
        'department_name'       => $r['department_name'],
        'department_code'       => $r['department_code'],
        'labor_cost_allocation' => (float)($r['labor_cost_allocation'] ?? 0),
        'employee_count'        => (int)$r['employee_count'],
    ], $rows));
}
//create dept
if ($method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $name = str($body, 'department_name');
    $code = str($body, 'department_code');
    if ($name === '') json_err('department_name is required.');
    if ($code === '') json_err('department_code is required.');

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO departments (department_name, department_code, labor_cost_allocation) VALUES (?, ?, ?)'
    );
    $stmt->execute([$name, strtoupper($code), floatVal_($body, 'labor_cost_allocation')]);
    json_ok(['department_id' => (int)$pdo->lastInsertId(), 'message' => 'Department created.']);
}
//update dept
if ($method === 'PUT') {
    requireAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'department_id');
    if (!$id) json_err('department_id is required.');

    $pdo = getDB();
    $chk = $pdo->prepare('SELECT department_id FROM departments WHERE department_id = ?');
    $chk->execute([$id]);
    if (!$chk->fetch()) json_err('Department not found.', 404);

    $pdo->prepare(
        'UPDATE departments SET department_name = ?, department_code = ?, labor_cost_allocation = ? WHERE department_id = ?'
    )->execute([
        str($body, 'department_name'),
        strtoupper(str($body, 'department_code')),
        floatVal_($body, 'labor_cost_allocation'),
        $id,
    ]);
    json_ok(['message' => 'Department updated.']);
}
//delete dept
if ($method === 'DELETE') {
    requireAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');

    $pdo = getDB();
    $emp = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE department_id = ?');
    $emp->execute([$id]);
    if ((int)$emp->fetchColumn() > 0) {
        json_err('Cannot delete a department that still has employees assigned.');
    }

    $stmt = $pdo->prepare('DELETE FROM departments WHERE department_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Department not found.', 404);
    json_ok(['message' => 'Department deleted.']);
}

json_err('Method not allowed.', 405);