<?php
/**
 * apicam_config.php
 * -----------------------------------------------------------------------
 * Pestaña propia de configuracion para el modulo ApiCam (menu "Mi
 * configuracion"), separada del modulo en si (modules/apicam.php),
 * siguiendo el mismo patron que /camara.php, /hardware.php y /mqtt.php:
 * un formulario que guarda en su propia tabla (`api_config`).
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();

$ANALYSIS_TYPES = [
    'yolo'          => 'Deteccion de Objetos',
    'yolo_seg'      => 'Segmentacion (zonas)',
    'movenet'       => 'Pose (MoveNet)',
    'handlandmarks' => 'Gestos de Mano',
    'pinch'         => 'Control de Pinza',
    'qr'            => 'Codigo QR',
    'aruco'         => 'Marcador ArUco',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $apiUrl    = rtrim(trim($_POST['api_url'] ?? ''), '/');
    $apiKey    = trim($_POST['api_key'] ?? '');
    $tipo      = $_POST['tipo_analisis'] ?? 'yolo';
    $mqttViaApi = !empty($_POST['mqtt_via_api']) ? 1 : 0;
    if (!array_key_exists($tipo, $ANALYSIS_TYPES)) {
        $tipo = 'yolo';
    }

    db()->prepare("INSERT INTO api_config (user_id, api_url, api_key, tipo_analisis, mqtt_via_api)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE api_url=VALUES(api_url), api_key=VALUES(api_key),
                        tipo_analisis=VALUES(tipo_analisis), mqtt_via_api=VALUES(mqtt_via_api)")
        ->execute([$user['id'], $apiUrl, $apiKey, $tipo, $mqttViaApi]);

    flash('success', 'Conexion APICAM guardada.');
    redirect('/apicam_config.php');
}

$stmt = db()->prepare("SELECT * FROM api_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$cfg = $stmt->fetch() ?: ['api_url' => '', 'api_key' => '', 'tipo_analisis' => 'yolo', 'mqtt_via_api' => 0];

$page_title = 'Conexion APICAM';
$active_nav = 'apicam_config';
require __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width:640px;">
    <h3>Conexion APICAM</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Datos de conexion a tu API Flask propia (carpeta <code>/API</code>
        del proyecto). El modulo <strong>ApiCam</strong> usa esta
        configuracion para mandar la foto que tomes (o elijas de la
        galeria) y recibir el resultado ya anotado.
    </p>

    <form method="post">
        <?= csrf_field() ?>

        <label>URL de la API Flask</label>
        <input type="text" name="api_url" placeholder="http://mi-servidor:5000" value="<?= htmlspecialchars($cfg['api_url']) ?>">
        <p style="font-size:12px;color:var(--text-muted);margin-top:-8px;">
            Sin barra final. Ejemplo: <code>http://127.0.0.1:5000</code> en local,
            o la URL publica de tu servidor Flask en produccion.
        </p>

        <label>API Key (opcional, solo si la API la exige)</label>
        <input type="text" name="api_key" value="<?= htmlspecialchars($cfg['api_key']) ?>">

        <label>Tipo de analisis por defecto</label>
        <select name="tipo_analisis">
            <?php foreach ($ANALYSIS_TYPES as $slug => $label): ?>
                <option value="<?= $slug ?>" <?= $cfg['tipo_analisis'] === $slug ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <p style="font-size:12px;color:var(--text-muted);margin-top:-8px;">
            Se preselecciona en el modulo ApiCam; ahi tambien puedes
            cambiarlo al momento de tomar cada foto.
        </p>

        <div class="checkbox-row" style="margin-top:14px;">
            <input type="checkbox" name="mqtt_via_api" id="mqtt_via_api" <?= !empty($cfg['mqtt_via_api']) ? 'checked' : '' ?>>
            <label for="mqtt_via_api" style="margin:0;">Usar MQTT via API (equipo no compatible con MQTT/WebSocket)</label>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-top:4px;">
            Por defecto, ApiCam conecta MQTT directo desde el navegador
            (igual que los otros modulos) mientras el equipo sea
            compatible. Activa esto SOLO si tu navegador/dispositivo no
            soporta bien MQTT sobre WebSocket (ej. Android 4.4): en ese
            caso, ApiCam deja de conectar el broker desde el navegador y
            le pide a tu API Flask que se conecte ELLA MISMA (recibe
            host/usuario/clave en cada peticion), consulte el estado de
            los sensores de Arduino, y publique el comando si una regla
            se cumple. La configuracion del broker sigue siendo la misma
            de <a href="<?= BASE_URL ?>/mqtt.php">Conexion MQTT</a>, y
            los topicos son los de <a href="<?= BASE_URL ?>/hardware.php">Conexion a Hardware</a>.
        </p>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Guardar</button>
    </form>
</div>

<div class="card" style="max-width:640px;">
    <h3>¿Como funciona "MQTT via API"?</h3>
    <ol style="font-size:13px;color:var(--text-muted);padding-left:18px;line-height:1.8;">
        <li>Al tomar/elegir una foto, ApiCam primero le pide a tu API Flask que consulte el estado actual de Arduino (publica en el "Topico de consulta" y escucha el "Topico de entrada", ambos de <a href="<?= BASE_URL ?>/hardware.php">Conexion a Hardware</a>).</li>
        <li>Con ese estado + el resultado de IA de la foto, se evaluan tus Reglas normalmente (Digital/Analogico + clase/confianza) - todo en el navegador, igual que siempre.</li>
        <li>Si una regla se cumple, ApiCam le pide a la API que PUBLIQUE el comando (mismo "Topico de envio") - Arduino lo recibe igual que si hubiera venido directo del navegador. Esto incluye tambien el "Publicar MQTT" opcional que se configura en cada Regla.</li>
    </ol>
</div>

<div class="card" style="max-width:640px;">
    <h3>¿Como se usa?</h3>
    <ol style="font-size:13px;color:var(--text-muted);padding-left:18px;line-height:1.8;">
        <li>Instala y corre tu API Flask (ver <code>API/README.md</code>).</li>
        <li>Pon aqui su URL (y la API Key si la configuraste con <code>SENSORIA_API_KEY</code>).</li>
        <li>Ve a <a href="<?= BASE_URL ?>/modules/apicam.php">ApiCam</a>, toma una foto o elige una de tu galeria.</li>
        <li>El resultado se compara contra tus <a href="<?= BASE_URL ?>/reglas.php">Reglas</a> (modulo <code>apicam_&lt;tipo&gt;</code>) y activa la salida por Serial o MQTT si corresponde.</li>
    </ol>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
