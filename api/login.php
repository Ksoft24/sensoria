<?php
/**
 * POST /api/login.php
 * body (JSON o form): correo, password
 * respuesta: {"message": "..."}  con status 200 si OK, 401/403 si no.
 *
 * Contrato esperado por core/auth_manager.py -> login() de la app Kivy:
 *   resp = requests.post(f"{AUTH_BASE_URL}/login", json={...})
 *   if resp.status_code == 200: usar resp.json()["message"]
 *   else: usar resp.json()["message"] como motivo de error
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$correo   = trim($input['correo'] ?? '');
$password = $input['password'] ?? '';

if ($correo === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['message' => 'Faltan correo o clave']);
    exit;
}

$stmt = db()->prepare("SELECT * FROM users WHERE correo = ?");
$stmt->execute([$correo]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['message' => 'Usuario o clave incorrectos']);
    exit;
}

if ($user['estado'] !== 'active') {
    http_response_code(403);
    echo json_encode(['message' => 'Cuenta ' . estado_label($user['estado']) . '. Contacta al administrador.']);
    exit;
}

echo json_encode(['message' => 'Login correcto']);
