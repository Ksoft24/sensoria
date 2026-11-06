<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/stats_helpers.php';
header('Content-Type: application/json; charset=utf-8');

const STATS_SYNC_MAX_EVENTS = 50;

function stats_sync_log_attempt(string $identificador, string $app, ?string $correo, ?int $userId, ?int $clientId, bool $exitoso, string $motivo): void
{
    db()->prepare("INSERT INTO api_app_logins (identificador, nombre_aplicacion, correo_intento, user_id, client_id, exitoso, motivo, ip)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$identificador, $app, $correo, $userId, $clientId, $exitoso ? 1 : 0, $motivo, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function stats_sync_fail(int $httpCode, string $error): void
{
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

// ------------------------------------------------------------------
// CAPTURA GLOBAL DE ERRORES / EXCEPCIONES (Para errores 500)
// ------------------------------------------------------------------
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        stats_sync_fail(400, 'JSON invalido.');
    }

    $correo        = trim((string) ($input['correo'] ?? $input['usuario'] ?? ''));
    $password      = (string) ($input['password'] ?? $input['clave'] ?? '');
    $identificador = trim((string) ($input['identificador'] ?? ''));
    $nombreApp     = trim((string) ($input['nombre_aplicacion'] ?? $input['aplicacion'] ?? ''));

    if ($identificador === '' || $nombreApp === '') {
        stats_sync_fail(401, 'Faltan identificador o nombre_aplicacion.');
    }

    if (!in_array($nombreApp, api_apps_permitidas(), true)) {
        $motivo = 'La aplicacion "' . $nombreApp . '" no esta autorizada.';
        stats_sync_log_attempt($identificador, $nombreApp, $correo ?: null, null, null, false, $motivo);
        stats_sync_fail(401, $motivo);
    }

    if ($correo === '' || $password === '') {
        stats_sync_log_attempt($identificador, $nombreApp, $correo ?: null, null, null, false, 'Faltan correo o password');
        stats_sync_fail(401, 'Faltan correo o password.');
    }

    $stmt = db()->prepare("SELECT * FROM users WHERE correo = ?");
    $stmt->execute([$correo]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        stats_sync_log_attempt($identificador, $nombreApp, $correo, $user['id'] ?? null, $user['client_id'] ?? null, false, 'Credenciales invalidas');
        stats_sync_fail(401, 'Usuario o clave incorrectos.');
    }

    if ($user['estado'] !== 'active') {
        $motivo = 'Cuenta ' . estado_label($user['estado']) . '.';
        stats_sync_log_attempt($identificador, $nombreApp, $correo, $user['id'], $user['client_id'], false, $motivo);
        stats_sync_fail(401, $motivo);
    }

    stats_sync_log_attempt($identificador, $nombreApp, $correo, $user['id'], $user['client_id'], true, 'OK (stats_sync)');

    $events = $input['events'] ?? null;
    if (!is_array($events) || $events === []) {
        stats_sync_fail(400, 'Falta "events" (lista de 1 a ' . STATS_SYNC_MAX_EVENTS . ' eventos).');
    }
    if (count($events) > STATS_SYNC_MAX_EVENTS) {
        stats_sync_fail(400, 'Maximo ' . STATS_SYNC_MAX_EVENTS . ' eventos por peticion (llegaron ' . count($events) . ').');
    }

    $VALID_SOURCES = stats_fuentes_validas(); // lista unica en includes/stats_helpers.php
    $VALID_MODULES = ['yolo', 'yolo_seg', 'movenet', 'handlandmarks', 'pinch', 'qr', 'aruco', 'colorimeter', 'geometric_scan'];

    $source = (string) ($input['source'] ?? '');
    if (!in_array($source, $VALID_SOURCES, true)) {
        stats_sync_fail(400, 'Falta "source" o no es valido (' . implode(', ', $VALID_SOURCES) . ').');
    }

    $idInvestigacion = null;
    if ($user['grupo_id']) {
        $stmt = db()->prepare("SELECT investigacion_id FROM grupo_investigaciones WHERE grupo_id = ? AND activa = 1 LIMIT 1");
        $stmt->execute([$user['grupo_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $idInvestigacion = (int) $row['investigacion_id'];
        }
    }

    $insertStmt = db()->prepare("INSERT IGNORE INTO inference_events
        (user_id, client_id, grupo_id, device_identificador, nombre_aplicacion, id_remoto, `source`, `module`, class_name, confidence, inference_ms,
         rule_matched, rule_id, used_digital_input, used_analog_input, id_investigacion, action_pin, events_count, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $now          = date('Y-m-d H:i:s');
    $minCreatedAt = date('Y-m-d H:i:s', strtotime('-2 years'));

    $inserted   = 0;
    $duplicates = 0;
    $errors     = [];

    foreach ($events as $i => $ev) {
        if (!is_array($ev)) {
            $errors[] = ['index' => $i, 'error' => 'Debe ser un objeto JSON.'];
            continue;
        }

        $module = $ev['module'] ?? '';
        if (!in_array($module, $VALID_MODULES, true)) {
            $errors[] = ['index' => $i, 'error' => 'module invalido.'];
            continue;
        }

        $idRemoto = isset($ev['id_remoto']) ? trim((string) $ev['id_remoto']) : null;
        if ($idRemoto !== null) {
            $idRemoto = mb_substr($idRemoto, 0, 100);
            if ($idRemoto === '') $idRemoto = null;
        }

        $className = isset($ev['class_name']) ? mb_substr(trim((string) $ev['class_name']), 0, 150) : null;
        if ($className === '') $className = null;

        $confidence = null;
        if (isset($ev['confidence']) && $ev['confidence'] !== null && $ev['confidence'] !== '') {
            if (!is_numeric($ev['confidence'])) {
                $errors[] = ['index' => $i, 'error' => 'confidence debe ser numerico.'];
                continue;
            }
            $confidence = max(0, min(100, (float) $ev['confidence']));
        }

        $inferenceMs = null;
        if (isset($ev['inference_ms']) && $ev['inference_ms'] !== null && $ev['inference_ms'] !== '') {
            if (!is_numeric($ev['inference_ms'])) {
                $errors[] = ['index' => $i, 'error' => 'inference_ms debe ser numerico.'];
                continue;
            }
            $inferenceMs = max(0, (float) $ev['inference_ms']);
        }

        $ruleMatched = !empty($ev['rule_matched']) ? 1 : 0;

        $ruleId = null;
        if (isset($ev['rule_id']) && $ev['rule_id'] !== null && $ev['rule_id'] !== '') {
            if (is_numeric($ev['rule_id'])) {
                $ruleId = (int) $ev['rule_id'];
            }
        }

        $actionPin = isset($ev['action_pin']) ? mb_substr(trim((string) $ev['action_pin']), 0, 20) : null;
        if ($actionPin === '') $actionPin = null;

        $eventsCount = 1;
        if (isset($ev['events_count']) && $ev['events_count'] !== null && $ev['events_count'] !== '') {
            if (!is_numeric($ev['events_count'])) {
                $errors[] = ['index' => $i, 'error' => 'events_count debe ser numerico.'];
                continue;
            }
            $eventsCount = max(1, (int) $ev['events_count']);
        }

        $usedDigital = !empty($ev['used_digital_input']) ? 1 : 0;
        $usedAnalog  = !empty($ev['used_analog_input']) ? 1 : 0;

        $createdAt = $now;
        if (!empty($ev['created_at'])) {
            $ts = strtotime((string) $ev['created_at']);
            if ($ts === false) {
                $errors[] = ['index' => $i, 'error' => 'created_at invalido.'];
                continue;
            }
            $candidate = date('Y-m-d H:i:s', $ts);
            if ($candidate < $minCreatedAt || $candidate > $now) {
                $errors[] = ['index' => $i, 'error' => 'created_at fuera de rango.'];
                continue;
            }
            $createdAt = $candidate;
        }

        $insertStmt->execute([
            $user['id'], $user['client_id'], $user['grupo_id'], $identificador, $nombreApp, $idRemoto,
            $source, $module, $className, $confidence, $inferenceMs,
            $ruleMatched, $ruleId, $usedDigital, $usedAnalog, $idInvestigacion, $actionPin, $eventsCount,
            $createdAt,
        ]);

        if ($insertStmt->rowCount() > 0) {
            $inserted++;
        } else {
            $duplicates++;
        }
    }

    echo json_encode([
        'ok'         => true,
        'received'   => count($events),
        'inserted'   => $inserted,
        'duplicates' => $duplicates,
        'errors'     => $errors,
    ]);

} catch (Throwable $e) {
    // Si ocurre cualquier error inesperado o de base de datos (Error 500)
    stats_sync_fail(500, 'Error interno del servidor: ' . $e->getMessage());
}