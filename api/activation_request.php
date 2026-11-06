<?php
/**
 * POST /api/activation_request.php
 * body: correo, password, device_code, [nombre]
 * respuesta: {"message": "..."}  status 200 si se registro/ya existia la solicitud.
 *
 * Se pide correo+password de nuevo (no hay token de sesion todavia) para
 * saber a que usuario asociar el device_code.
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$correo      = trim($input['correo'] ?? '');
$password    = $input['password'] ?? '';
$device_code = trim($input['device_code'] ?? '');
$nombre      = trim($input['nombre'] ?? '') ?: null;

if ($correo === '' || $password === '' || $device_code === '') {
    http_response_code(400);
    echo json_encode(['message' => 'Faltan datos (correo, password, device_code)']);
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
    echo json_encode(['message' => 'Cuenta ' . estado_label($user['estado'])]);
    exit;
}

$stmt = db()->prepare("SELECT * FROM devices WHERE device_code = ?");
$stmt->execute([$device_code]);
$device = $stmt->fetch();

if ($device && (int) $device['user_id'] !== (int) $user['id']) {
    http_response_code(409);
    echo json_encode(['message' => 'Ese codigo de dispositivo ya esta asociado a otra cuenta']);
    exit;
}

if ($device) {
    if ($device['estado'] === 'active') {
        echo json_encode(['message' => 'Este dispositivo ya esta activo']);
        exit;
    }
    if ($device['estado'] === 'pending') {
        echo json_encode(['message' => 'La solicitud ya esta pendiente de aprobacion']);
        exit;
    }
    // rejected / revoked -> se puede volver a solicitar
    db()->prepare("UPDATE devices SET estado = 'pending', solicitado_en = NOW(), nombre = COALESCE(?, nombre)
                    WHERE id = ?")->execute([$nombre, $device['id']]);
    echo json_encode(['message' => 'Solicitud de activacion reenviada. Esperando aprobacion.']);
    exit;
}

// Dispositivo nuevo: el modelo institucional ya no limita equipos por
// usuario (el cupo del plan ahora es de USUARIOS por cliente, no de
// equipos por usuario - ver clients.max_usuarios), asi que se crea
// directo la solicitud, sin validar ningun conteo aqui.
db()->prepare("INSERT INTO devices (user_id, device_code, nombre, estado) VALUES (?, ?, ?, 'pending')")
    ->execute([$user['id'], $device_code, $nombre]);

echo json_encode(['message' => 'Solicitud enviada. Esperando aprobacion del administrador.']);
