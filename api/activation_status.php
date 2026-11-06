<?php
/**
 * GET /api/activation_status.php?correo=...&device_code=...
 * respuesta: {"status": "active"|"pending"|"expired"|"rejected", "expiry": "YYYY-MM-DD"|null}
 *
 * Contrato esperado por core/auth_manager.py -> check_activation() de la app Kivy.
 *
 * Modelo institucional: el vencimiento YA NO se guarda por dispositivo
 * (ver admin/dispositivos.php) - se deriva del PLAN DEL CLIENTE al que
 * pertenece el usuario dueño del dispositivo (clients.plan_vence_en).
 * Si la institucion completa vence, TODOS sus dispositivos vencen a la
 * vez (no hay que tocar cada equipo uno por uno).
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

$correo      = trim($_GET['correo'] ?? '');
$device_code = trim($_GET['device_code'] ?? '');

if ($correo === '' || $device_code === '') {
    http_response_code(400);
    echo json_encode(['status' => 'pending', 'expiry' => null]);
    exit;
}

$stmt = db()->prepare("SELECT d.*, u.estado AS user_estado, c.plan_vence_en, c.estado AS client_estado
                        FROM devices d
                        JOIN users u ON u.id = d.user_id
                        LEFT JOIN clients c ON c.id = u.client_id
                        WHERE u.correo = ? AND d.device_code = ?");
$stmt->execute([$correo, $device_code]);
$device = $stmt->fetch();

if (!$device) {
    echo json_encode(['status' => 'pending', 'expiry' => null]);
    exit;
}

db()->prepare("UPDATE devices SET ultimo_check_en = NOW() WHERE id = ?")->execute([$device['id']]);

// 'revoked'/'rejected' se reportan como 'rejected'.
$statusMap = [
    'pending'  => 'pending',
    'active'   => 'active',
    'rejected' => 'rejected',
    'revoked'  => 'rejected',
];
$status = $statusMap[$device['estado']] ?? 'pending';

// Si el equipo esta activo pero el USUARIO ya no lo esta (suspendido
// por su institucion) o la INSTITUCION ya no esta activa/vencio, se
// reporta como rechazado - el cliente Kivy corta el acceso igual que
// si el dispositivo mismo hubiera sido revocado.
if ($status === 'active') {
    if ($device['user_estado'] !== 'active') {
        $status = 'rejected';
    } elseif ($device['client_estado'] !== 'active') {
        $status = 'rejected';
    } elseif ($device['plan_vence_en'] && $device['plan_vence_en'] < date('Y-m-d')) {
        $status = 'rejected';
    }
}

echo json_encode([
    'status' => $status,
    'expiry' => $device['plan_vence_en'],
]);
