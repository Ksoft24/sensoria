<?php
/**
 * api/log_inference.php
 * -----------------------------------------------------------------------
 * Recibe, desde el navegador (rules-engine.js, apicam.php, staticcam.php),
 * un evento de "inferencia" o de "regla activada" y lo guarda en
 * inference_events, para las Estadisticas del panel de usuario y del
 * panel de institucion.
 *
 * No confia en client_id/grupo_id que pudiera mandar el navegador: los
 * resuelve siempre desde la sesion del usuario autenticado.
 *
 * Acepta tanto fetch() normal (Content-Type: application/json) como
 * navigator.sendBeacon() (que en Chrome manda "text/plain;charset=UTF-8"),
 * por eso siempre se lee el cuerpo crudo y se decodifica como JSON.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user || $user['estado'] !== 'active') {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON invalido']);
    exit;
}

$VALID_SOURCES = ['onlinecam', 'staticcam', 'apicam'];
$VALID_MODULES = ['yolo', 'yolo_seg', 'movenet', 'handlandmarks', 'pinch', 'qr', 'aruco', 'colorimeter', 'geometric_scan'];

$source = $data['source'] ?? '';
$module = $data['module'] ?? '';
if (!in_array($source, $VALID_SOURCES, true) || !in_array($module, $VALID_MODULES, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'source/module invalido']);
    exit;
}

$className   = isset($data['class_name']) ? mb_substr(trim((string) $data['class_name']), 0, 150) : null;
$confidence  = isset($data['confidence']) && is_numeric($data['confidence']) ? max(0, min(100, (float) $data['confidence'])) : null;
$inferenceMs = isset($data['inference_ms']) && is_numeric($data['inference_ms']) ? max(0, (float) $data['inference_ms']) : null;
$ruleMatched = !empty($data['rule_matched']) ? 1 : 0;
$ruleId      = isset($data['rule_id']) && ctype_digit((string) $data['rule_id']) ? (int) $data['rule_id'] : null;
$actionPin   = isset($data['action_pin']) ? mb_substr(trim((string) $data['action_pin']), 0, 20) : null;
$eventsCount = isset($data['events_count']) && is_numeric($data['events_count']) ? max(1, (int) $data['events_count']) : 1;
$usedDigital = !empty($data['used_digital_input']) ? 1 : 0;
$usedAnalog  = !empty($data['used_analog_input']) ? 1 : 0;

if ($className === '') $className = null;
if ($actionPin === '') $actionPin = null;

// Si la regla referenciada no es del usuario (o ya no existe), se guarda
// sin rule_id en vez de fallar toda la insercion.
if ($ruleId !== null) {
    $stmt = db()->prepare("SELECT id FROM rules WHERE id = ? AND user_id = ?");
    $stmt->execute([$ruleId, $user['id']]);
    if (!$stmt->fetch()) {
        $ruleId = null;
    }
}

// A que investigacion pertenece este evento: la que la institucion tenga
// asociada como ACTIVA al Grupo del usuario (client/asociar_investigacion.php).
// Si el usuario no tiene grupo, o su grupo no tiene ninguna investigacion
// activa, queda en null (evento "libre", no asociado a ningun estudio).
$idInvestigacion = null;
if ($user['grupo_id']) {
    $stmt = db()->prepare("SELECT investigacion_id FROM grupo_investigaciones WHERE grupo_id = ? AND activa = 1 LIMIT 1");
    $stmt->execute([$user['grupo_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $idInvestigacion = (int) $row['investigacion_id'];
    }
}

db()->prepare("INSERT INTO inference_events
        (user_id, client_id, grupo_id, source, module, class_name, confidence, inference_ms, rule_matched, rule_id, used_digital_input, used_analog_input, id_investigacion, action_pin, events_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([
        $user['id'], $user['client_id'], $user['grupo_id'],
        $source, $module, $className, $confidence, $inferenceMs,
        $ruleMatched, $ruleId, $usedDigital, $usedAnalog, $idInvestigacion, $actionPin, $eventsCount,
    ]);

echo json_encode(['ok' => true]);
