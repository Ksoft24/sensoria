<?php
/**
 * includes/simulador_helpers.php
 * -----------------------------------------------------------------------
 * Configuracion que el Simulador SENSOR-IA Board lee "por defecto" de la
 * cuenta del usuario:
 *   - CONEXION: broker de "Conexion MQTT" (tabla mqtt_config)
 *   - ENTRADAS / SALIDAS / CONSULTA: topicos y claves de "Hardware (Serial/MQTT)"
 *     (tabla hardware_config), o los topicos por defecto device/<uid>/...
 *     si el usuario todavia no guardo nada.
 * La usan simulador_board.php (la inyecta al abrir) y simulador_config.php
 * (boton "Recargar" del simulador).
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/functions.php';

function simulador_user_config(array $user): array
{
    $topics = default_mqtt_topics($user['device_uid']);

    $stmt = db()->prepare("SELECT * FROM hardware_config WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $hw = $stmt->fetch() ?: [];

    $stmt = db()->prepare("SELECT * FROM mqtt_config WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $mq = $stmt->fetch() ?: [];

    return [
        'ok'         => true,
        'device_uid' => $user['device_uid'],
        'hardware'   => [
            'modo'          => ($hw['modo'] ?? 'serial') === 'mqtt' ? 'mqtt' : 'serial',
            'input_topic'   => trim($hw['mqtt_input_topic'] ?? '') ?: $topics['input'],
            'command_topic' => trim($hw['mqtt_command_topic'] ?? '') ?: $topics['command'],
            'query_topic'   => trim($hw['mqtt_query_topic'] ?? '') ?: $topics['query'],
            'key_command'   => trim($hw['mqtt_key_command'] ?? '') ?: 'command',
            'key_state'     => trim($hw['mqtt_key_state'] ?? '') ?: 'state',
        ],
        'mqtt'       => [
            'host'     => trim($mq['host'] ?? ''),
            'port'     => (int) ($mq['port'] ?? 8883),
            'ws_port'  => (int) ($mq['ws_port'] ?? 8884),
            'username' => $mq['username'] ?? '',
            'password' => $mq['password'] ?? '',
            'use_tls'  => !empty($mq['use_tls']) ? 1 : 0,
        ],
        'config_url' => BASE_URL . '/simulador_config.php',
    ];
}
