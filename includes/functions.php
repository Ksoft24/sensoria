<?php
require_once __DIR__ . '/../config/config.php';

// =====================================================================
// Sesion / usuario actual (actor "usuario": estudiante/docente operador,
// o el admin de la plataforma con es_admin=1)
// =====================================================================
function current_user(): ?array
{
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare("SELECT u.*, c.nombre AS client_nombre, c.plan_tipo, g.nombre AS grupo_nombre
                                FROM users u
                                LEFT JOIN clients c ON c.id = u.client_id
                                LEFT JOIN grupos g ON g.id = u.grupo_id
                                WHERE u.id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) return $user;
    }

    // Intentar "recordarme" via cookie si no hay sesion activa
    if (!empty($_COOKIE['remember_token'])) {
        $stmt = db()->prepare("SELECT u.*, c.nombre AS client_nombre, c.plan_tipo, g.nombre AS grupo_nombre
                                FROM users u
                                LEFT JOIN clients c ON c.id = u.client_id
                                LEFT JOIN grupos g ON g.id = u.grupo_id
                                WHERE u.remember_token = ? AND u.remember_expires > NOW()");
        $stmt->execute([$_COOKIE['remember_token']]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            return $user;
        }
    }
    return null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php');
    }
    if ($user['estado'] !== 'active') {
        session_unset();
        flash('error', 'Tu cuenta no esta activa (' . estado_label($user['estado']) . ').');
        redirect('/login.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!$user['es_admin']) {
        http_response_code(403);
        die('Acceso restringido al administrador.');
    }
    return $user;
}

function require_operator(): array
{
    $user = require_login();
    if (!empty($user['es_admin'])) {
        // La cuenta admin no opera camara/hardware/reglas propias: eso es
        // exclusivo de cada cuenta de usuario, que pertenece a un cliente
        // institucional.
        redirect('/admin/usuarios.php');
    }
    return $user;
}

function estado_label(string $estado): string
{
    return [
        'pending_email'  => 'pendiente de confirmar correo',
        'pending_client' => 'pendiente de aprobacion de la institucion',
        'active'         => 'activa',
        'suspended'      => 'suspendida',
        'rejected'       => 'rechazada',
    ][$estado] ?? $estado;
}

// =====================================================================
// Sesion / cliente actual (actor "cliente": institucion, login separado
// del de los usuarios, ve /client/*)
// =====================================================================
function current_client(): ?array
{
    if (empty($_SESSION['client_id'])) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$_SESSION['client_id']]);
    return $stmt->fetch() ?: null;
}

function require_client(): array
{
    $client = current_client();
    if (!$client) {
        redirect('/client_login.php');
    }
    if ($client['estado'] !== 'active') {
        session_unset();
        flash('error', 'Tu cuenta institucional no esta activa (' . client_estado_label($client['estado']) . ').');
        redirect('/client_login.php');
    }
    return $client;
}

function client_estado_label(string $estado): string
{
    return [
        'pending'   => 'pendiente de aprobacion',
        'active'    => 'activa',
        'suspended' => 'suspendida',
    ][$estado] ?? $estado;
}

// =====================================================================
// Flash messages (un solo uso, sobreviven un redirect)
// =====================================================================
function flash(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

// =====================================================================
// CSRF
// =====================================================================
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!$sent || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(400);
        die('Token de seguridad invalido. Vuelve a intentarlo.');
    }
}

// =====================================================================
// Tokens (verificacion de correo / recuperacion de clave)
// =====================================================================
function generate_token(): string
{
    return bin2hex(random_bytes(32));
}

// =====================================================================
// ID unico por usuario, para armar los topicos MQTT propios de cada
// cuenta: device/{device_uid}/input, device/{device_uid}/command, etc.
// Se genera UNA sola vez al registrarse (ver register.php) y no cambia.
// =====================================================================
function generate_device_uid(): string
{
    do {
        $uid = bin2hex(random_bytes(4)); // 8 caracteres hex, ej. "a1b2c3d4"
        $stmt = db()->prepare("SELECT 1 FROM users WHERE device_uid = ?");
        $stmt->execute([$uid]);
    } while ($stmt->fetch());
    return $uid;
}

/**
 * Defaults de topicos MQTT ya armados con el ID del usuario, para
 * mostrar como placeholder/precarga en Hardware, Reglas, etc. cuando el
 * usuario todavia no escribio nada propio.
 */
function default_mqtt_topics(string $deviceUid): array
{
    return [
        'input'   => "device/{$deviceUid}/input",
        'command' => "device/{$deviceUid}/command",
        'query'   => "device/{$deviceUid}/query",
    ];
}

// =====================================================================
// Puente MQTT: republica cada Entrada Digital/Analogica (I12,I13,A0-A3)
// en su PROPIO topico, cada vez que cambia de valor (ver /puente_mqtt.php
// y assets/js/mqtt-bridge.js). Las entradas son fijas, una fila por cada
// una en `mqtt_bridge_config`.
// =====================================================================
function mqtt_bridge_inputs(): array
{
    return ['I12', 'I13', 'A0', 'A1', 'A2', 'A3'];
}

/**
 * Topico por defecto para cada entrada del Puente MQTT, ej.
 * "device/{uid}/bridge/i12". Solo se usa como placeholder/precarga;
 * el usuario puede escribir el suyo propio por entrada.
 */
function default_bridge_topic(string $deviceUid, string $inputName): string
{
    return "device/{$deviceUid}/bridge/" . strtolower($inputName);
}

/**
 * Apps EXTERNAS (moviles/ARM/Kivy) autorizadas a usar los endpoints que
 * se autentican con correo+clave en cada peticion (sin sesion/cookies):
 * api/api_app_login.php y api/stats_sync.php. Agrega/quita nombres aca
 * - ambos endpoints leen de la misma lista, no hace falta tocar los 2.
 */
function api_apps_permitidas(): array
{
    return ['app_kivy_sensoria', 'app_broker_sensoria', 'app_microide_sensoria', 'app_tk_sensoria', 'app_ios_sensoria', 'app_android_sensoria'];
}

/**
 * Filas YA ACTIVADAS del Puente MQTT (ver /puente_mqtt.php) para un
 * usuario, listas para `window.MQTT_BRIDGE_CONFIG`.
 * OJO: HOY ninguna pagina de modulos llama a esta funcion; el Puente
 * (assets/js/mqtt-bridge.js) solo funciona mientras /puente_mqtt.php
 * esta abierta (ahi se arma MQTT_BRIDGE_CONFIG con todas las filas).
 * Incluye los campos de escalado (migration_008): si scale_enabled = 1,
 * mqtt-bridge.js publica el valor ESCALADO en vez del valor crudo.
 */
function active_mqtt_bridge_rows(int $userId): array
{
    try {
        $stmt = db()->prepare("SELECT input_name, topic, mqtt_key_command, mqtt_key_state,
                                      scale_enabled, scale_min, scale_max, scale_out_min, scale_out_max,
                                      scale_percent, scale_unit
                                FROM mqtt_bridge_config
                                WHERE user_id = ? AND enabled = 1 AND topic <> ''");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        // Aun no se ejecuto sql/migration_008_puente_escalado.sql: sin escalado.
        $stmt = db()->prepare("SELECT input_name, topic, mqtt_key_command, mqtt_key_state
                                FROM mqtt_bridge_config
                                WHERE user_id = ? AND enabled = 1 AND topic <> ''");
        $stmt->execute([$userId]);
    }
    return $stmt->fetchAll();
}

// =====================================================================
// Licenciamiento INSTITUCIONAL: el token identifica el enlace publico
// de registro de un cliente (/register.php?c=TOKEN). Se genera UNA sola
// vez al crear el cliente (ver admin/clientes.php) y no cambia.
// =====================================================================
function generate_registro_token(): string
{
    do {
        $token = bin2hex(random_bytes(6)); // 12 caracteres hex
        $stmt = db()->prepare("SELECT 1 FROM clients WHERE registro_token = ?");
        $stmt->execute([$token]);
    } while ($stmt->fetch());
    return $token;
}

/**
 * Cuenta los usuarios de un cliente que "ocupan cupo" del plan
 * (pending_client + active - igual criterio que antes con equipos:
 * no se puede acumular solicitudes pendientes para saltarse el limite).
 */
function contar_usuarios_activos(int $client_id): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE client_id = ? AND estado IN ('pending_client', 'active')");
    $stmt->execute([$client_id]);
    return (int) $stmt->fetchColumn();
}

// =====================================================================
// Ubigeo (Peru): departamentos/provincias. Ver includes/ubigeo.php
// =====================================================================
function ubigeo_departamentos(): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/ubigeo.php';
    }
    return array_keys($data);
}

function ubigeo_provincias(string $departamento): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/ubigeo.php';
    }
    return $data[$departamento] ?? [];
}

// =====================================================================
// Cifrado reversible (AES-256-CBC) para datos sensibles que en algun
// momento necesitemos poder LEER despues con nuestro propio codigo (a
// diferencia de password_hash()/password_verify(), que son de UNA VIA y
// nunca se pueden revertir - sirven para VERIFICAR una clave, no para
// verla). Utilidad general, disponible para si hace falta en el futuro.
//
// Requiere la constante APP_ENCRYPTION_KEY (config/config.php).
// =====================================================================
function app_encrypt(string $plaintext): string
{
    $key = hash('sha256', APP_ENCRYPTION_KEY, true); // deriva una clave de 32 bytes (AES-256)
    $iv = random_bytes(16); // IV al azar en CADA cifrado, para que el mismo texto nunca de el mismo resultado
    $cifrado = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cifrado); // el IV se guarda junto al cifrado (no es secreto, hace falta para descifrar)
}

function app_decrypt(string $valorCifrado): ?string
{
    $raw = base64_decode($valorCifrado, true);
    if ($raw === false || strlen($raw) <= 16) {
        return null;
    }
    $iv     = substr($raw, 0, 16);
    $cifrado = substr($raw, 16);
    $key    = hash('sha256', APP_ENCRYPTION_KEY, true);
    $plano  = openssl_decrypt($cifrado, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plano === false ? null : $plano;
}
