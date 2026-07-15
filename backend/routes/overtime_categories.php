<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// list all ot cats
if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query(
        'SELECT * FROM overtime_categories ORDER BY overtime_category_id'
    )->fetchAll();

    json_ok(array_map(fn($r) => [
        'overtime_category_id' => (int)$r['overtime_category_id'],
        'category_name'        => $r['category_name'],
        'rate_multiplier'      => (float)$r['rate_multiplier'],
    ], $rows));
}

// create cat
if ($method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $name = str($body, 'category_name');
    if ($name === '') json_err('category_name is required.');

    $multiplier = floatVal_($body, 'rate_multiplier', 1.25);
    if ($multiplier <= 0) json_err('rate_multiplier must be greater than 0.');

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO overtime_categories (category_name, rate_multiplier) VALUES (?, ?)'
    )->execute([$name, $multiplier]);

    json_ok([
        'overtime_category_id' => (int)$pdo->lastInsertId(),
        'message'              => 'Overtime category created.',
    ]);
}

// update cat
if ($method === 'PUT') {
    requireAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'overtime_category_id');
    $name = str($body, 'category_name');
    if (!$id)       json_err('overtime_category_id is required.');
    if ($name === '') json_err('category_name is required.');

    $multiplier = floatVal_($body, 'rate_multiplier', 1.25);
    if ($multiplier <= 0) json_err('rate_multiplier must be greater than 0.');

    $stmt = getDB()->prepare(
        'UPDATE overtime_categories SET category_name = ?, rate_multiplier = ? WHERE overtime_category_id = ?'
    );
    $stmt->execute([$name, $multiplier, $id]);
    if ($stmt->rowCount() === 0) json_err('Overtime category not found.', 404);

    json_ok(['message' => 'Overtime category updated.']);
}

// delete cat
if ($method === 'DELETE') {
    requireAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');

    $pdo = getDB();

    // Block delete if any time logs reference this category
    $chk = $pdo->prepare(
        'SELECT COUNT(*) FROM time_logs WHERE overtime_category_id = ?'
    );
    $chk->execute([$id]);
    if ((int)$chk->fetchColumn() > 0) {
        json_err('Cannot delete an overtime category that is assigned to time logs.');
    }

    // Block delete if any payroll records reference this (via time logs already covered above)
    $stmt = $pdo->prepare('DELETE FROM overtime_categories WHERE overtime_category_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Overtime category not found.', 404);

    json_ok(['message' => 'Overtime category deleted.']);
}

json_err('Method not allowed.', 405);
