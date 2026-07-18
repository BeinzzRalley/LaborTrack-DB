<?php
// leave_types.php
// GET    → fetch all leave types (admin + employee)
// POST   → create new leave type (admin only)
// PUT    → update leave type (admin only)
// DELETE → delete leave type (admin only)

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

requireAuth();

// GET — return all leave types
if ($method === 'GET') {
    $rows = $pdo->query('SELECT * FROM leave_types ORDER BY leave_type_id')
                ->fetchAll(PDO::FETCH_ASSOC);
    json_ok($rows);
}

// POST — create new leave type (admin only)
if ($method === 'POST') {
    requireSystemAdmin();
    $body   = bodyJson();
    $name   = str($body, 'leave_name');
    $isPaid = isset($body['is_paid']) ? (int)(bool)$body['is_paid'] : 1;

    if ($name === '') json_err('leave_name is required.');

    $stmt = $pdo->prepare('INSERT INTO leave_types (leave_name, is_paid) VALUES (?, ?)');
    $stmt->execute([$name, $isPaid]);
    $id = (int)$pdo->lastInsertId();

    json_ok(['leave_type_id' => $id, 'leave_name' => $name, 'is_paid' => $isPaid], 201);
}

// PUT — update leave type (admin only)
if ($method === 'PUT') {
    requireSystemAdmin();
    $body   = bodyJson();
    $id     = intVal_($body, 'leave_type_id');
    $name   = str($body, 'leave_name');
    $isPaid = isset($body['is_paid']) ? (int)(bool)$body['is_paid'] : 1;

    if (!$id)   json_err('leave_type_id is required.');
    if (!$name) json_err('leave_name is required.');

    $stmt = $pdo->prepare('UPDATE leave_types SET leave_name = ?, is_paid = ? WHERE leave_type_id = ?');
    $stmt->execute([$name, $isPaid, $id]);

    json_ok(['leave_type_id' => $id, 'leave_name' => $name, 'is_paid' => $isPaid]);
}

// DELETE — delete leave type (admin only)
if ($method === 'DELETE') {
    requireSystemAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'leave_type_id');

    if (!$id) json_err('leave_type_id is required.');

    $stmt = $pdo->prepare('DELETE FROM leave_types WHERE leave_type_id = ?');
    $stmt->execute([$id]);

    json_ok(['deleted' => $id]);
}