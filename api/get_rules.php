<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user || $user['estado'] !== 'active') {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$module = $_GET['module'] ?? '';
if ($module === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Falta el parametro module']);
    exit;
}

$stmt = db()->prepare("SELECT * FROM rules WHERE user_id = ? AND module = ? AND active = 1");
$stmt->execute([$user['id'], $module]);
echo json_encode($stmt->fetchAll());
