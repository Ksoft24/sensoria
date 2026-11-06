<?php

require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

// Apps externas autorizadas: lista unica en includes/functions.php -> api_apps_permitidas()
$APPS_PERMITIDAS = api_apps_permitidas();

function api_app_login_response(array $overrides = []): array
{
    return array_merge([
        'acces'                  => false,
        'mensaje'                => '',
        'estado'                 => null,
        'idcliente'              => null,
        'nombrecliente'          => null,
        'plantipo'               => null,
        'planvenceen'            => null,
        'id_grupo'               => null,
        'nombre_grupo'           => null,
        'docente_grupo'          => null,
        'device_uide'            => null,
        'nombreapellidosusuario' => null,
        'emailusuario'           => null,
        'hw_modo'                => null,
        'hw_topico_entrada'      => null,
        'hw_topico_salida'       => null,
        'hw_topico_consulta'     => null,
        'hw_clave_pin'           => null,
        'hw_clave_valor'         => null,
        'mqtt_servidor'          => null,
        'mqtt_puerto_tcp'        => null,
        'mqtt_puerto_socket'     => null,
        'mqtt_usuario'           => null,
        'mqtt_clave'             => null,
    ], $overrides);
}

/** Guarda el intento (exitoso o no) en el historial general. */
function api_app_login_log(string $identificador, string $app, ?string $correo, ?int $userId, ?int $clientId, bool $exitoso, string $motivo): void
{
    db()->prepare("INSERT INTO api_app_logins (identificador, nombre_aplicacion, correo_intento, user_id, client_id, exitoso, motivo, ip)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$identificador, $app, $correo, $userId, $clientId, $exitoso ? 1 : 0, $motivo, $_SERVER['REMOTE_ADDR'] ?? null]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$correo         = trim($input['correo'] ?? $input['usuario'] ?? '');
$password       = (string) ($input['password'] ?? $input['clave'] ?? '');
$identificador  = trim($input['identificador'] ?? '');
$nombreApp      = trim($input['nombre_aplicacion'] ?? $input['aplicacion'] ?? '');

// El identificador y el nombre de la app son obligatorios SIEMPRE (son la
// clave del historial) - sin ellos no hay forma de registrar el intento.
if ($identificador === '' || $nombreApp === '') {
    http_response_code(400);
    echo json_encode(api_app_login_response(['mensaje' => 'Faltan identificador o nombre_aplicacion.']));
    exit;
}

// Si la app no esta en la lista de autorizadas, se rechaza de una - ni
// siquiera se revisa la clave (evita gastar password_verify() en apps
// no autorizadas, y deja registrado el intento para poder detectarlas).
if (!in_array($nombreApp, $APPS_PERMITIDAS, true)) {
    $motivo = 'La aplicacion "' . $nombreApp . '" no esta autorizada.';
    api_app_login_log($identificador, $nombreApp, $correo ?: null, null, null, false, $motivo);
    echo json_encode(api_app_login_response(['mensaje' => $motivo]));
    exit;
}

if ($correo === '' || $password === '') {
    api_app_login_log($identificador, $nombreApp, $correo ?: null, null, null, false, 'Faltan correo o password');
    echo json_encode(api_app_login_response(['mensaje' => 'Faltan correo o password.']));
    exit;
}

$stmt = db()->prepare("SELECT * FROM users WHERE correo = ?");
$stmt->execute([$correo]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    api_app_login_log($identificador, $nombreApp, $correo, $user['id'] ?? null, $user['client_id'] ?? null, false, 'Credenciales invalidas');
    echo json_encode(api_app_login_response(['mensaje' => 'Usuario o clave incorrectos.']));
    exit;
}

// A partir de aca las credenciales SI son correctas: se arma la
// respuesta completa con los datos de institucion/grupo, y luego se
// decide "acces" segun estado + licencia.
$client = null;
if ($user['client_id']) {
    $stmt = db()->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$user['client_id']]);
    $client = $stmt->fetch() ?: null;
}

$grupo = null;
if ($user['grupo_id']) {
    $stmt = db()->prepare("SELECT * FROM grupos WHERE id = ?");
    $stmt->execute([$user['grupo_id']]);
    $grupo = $stmt->fetch() ?: null;
}

// Configuracion de "Conexion a Hardware" (hardware.php): si el usuario
// nunca la guardo, se usan los mismos valores por defecto que usaria esa
// pagina (topicos armados con su device_uid, modo "serial").
$stmt = db()->prepare("SELECT * FROM hardware_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$hwCfg = $stmt->fetch();
$topics = default_mqtt_topics($user['device_uid']);
if (!$hwCfg) {
    $hwCfg = [
        'modo' => 'serial',
        'mqtt_input_topic' => $topics['input'],
        'mqtt_command_topic' => $topics['command'],
        'mqtt_query_topic' => $topics['query'],
        'mqtt_key_command' => 'command',
        'mqtt_key_state' => 'state',
    ];
}

// Configuracion del broker MQTT (mqtt.php): a diferencia de "Conexion a
// Hardware", aca no hay un default razonable sin un host configurado -
// si el usuario nunca guardo su broker, estos campos quedan en null.
$stmt = db()->prepare("SELECT * FROM mqtt_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$mqttCfg = $stmt->fetch();

$resp = api_app_login_response([
    'estado'                 => $user['estado'],
    'idcliente'              => $client['id'] ?? null,
    'nombrecliente'          => $client['nombre'] ?? null,
    'plantipo'               => $client['plan_tipo'] ?? null,
    'planvenceen'            => $client['plan_vence_en'] ?? null,
    'id_grupo'               => $grupo['id'] ?? null,
    'nombre_grupo'           => $grupo['nombre'] ?? null,
    'docente_grupo'          => $grupo['docente'] ?? null,
    'device_uide'            => $user['device_uid'],
    'nombreapellidosusuario' => trim($user['nombres'] . ' ' . $user['apellidos']),
    'emailusuario'           => $user['correo'],
    'hw_modo'                => $hwCfg['modo'],
    'hw_topico_entrada'      => $hwCfg['mqtt_input_topic'],
    'hw_topico_salida'       => $hwCfg['mqtt_command_topic'],
    'hw_topico_consulta'     => $hwCfg['mqtt_query_topic'],
    'hw_clave_pin'           => $hwCfg['mqtt_key_command'],
    'hw_clave_valor'         => $hwCfg['mqtt_key_state'],
    'mqtt_servidor'          => $mqttCfg['host'] ?? null,
    'mqtt_puerto_tcp'        => $mqttCfg ? (int) $mqttCfg['port'] : null,
    'mqtt_puerto_socket'     => $mqttCfg ? (int) $mqttCfg['ws_port'] : null,
    'mqtt_usuario'           => $mqttCfg['username'] ?? null,
    'mqtt_clave'             => $mqttCfg['password'] ?? null,
]);

// Motivos de rechazo, en orden de prioridad (el primero que aplique
// gana). Si ninguno aplica, el acceso se concede.
$motivoRechazo = null;
if ($user['estado'] !== 'active') {
    $motivoRechazo = 'Cuenta ' . estado_label($user['estado']) . '.';
} elseif (!$client) {
    $motivoRechazo = 'El usuario no tiene una institucion asociada.';
} elseif ($client['estado'] !== 'active') {
    $motivoRechazo = 'La institucion esta ' . client_estado_label($client['estado']) . '.';
} elseif (!empty($client['plan_vence_en']) && $client['plan_vence_en'] < date('Y-m-d')) {
    $motivoRechazo = 'La licencia de la institucion vencio el ' . $client['plan_vence_en'] . '.';
}

$acceso = $motivoRechazo === null;
$resp['acces']   = $acceso;
$resp['mensaje'] = $acceso ? 'Acceso concedido.' : $motivoRechazo;

api_app_login_log($identificador, $nombreApp, $correo, $user['id'], $client['id'] ?? null, $acceso, $acceso ? 'OK' : $motivoRechazo);

echo json_encode($resp);
