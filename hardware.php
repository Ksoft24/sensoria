<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();
$topics = default_mqtt_topics($user['device_uid']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $modo         = ($_POST['modo'] ?? 'serial') === 'mqtt' ? 'mqtt' : 'serial';
    $inputTopic   = trim($_POST['mqtt_input_topic'] ?? '') ?: $topics['input'];
    $commandTopic = trim($_POST['mqtt_command_topic'] ?? '') ?: $topics['command'];
    $queryTopic   = trim($_POST['mqtt_query_topic'] ?? '') ?: $topics['query'];
    $keyCommand   = trim($_POST['mqtt_key_command'] ?? '') ?: 'command';
    $keyState     = trim($_POST['mqtt_key_state'] ?? '') ?: 'state';
    $baud         = (int) ($_POST['serial_baudrate'] ?? 9600);

    db()->prepare("INSERT INTO hardware_config
                    (user_id, modo, mqtt_input_topic, mqtt_command_topic, mqtt_query_topic,
                     mqtt_key_command, mqtt_key_state, serial_baudrate)
                    VALUES (?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE modo=VALUES(modo), mqtt_input_topic=VALUES(mqtt_input_topic),
                        mqtt_command_topic=VALUES(mqtt_command_topic), mqtt_query_topic=VALUES(mqtt_query_topic),
                        mqtt_key_command=VALUES(mqtt_key_command), mqtt_key_state=VALUES(mqtt_key_state),
                        serial_baudrate=VALUES(serial_baudrate)")
        ->execute([$user['id'], $modo, $inputTopic, $commandTopic, $queryTopic, $keyCommand, $keyState, $baud]);

    flash('success', 'Configuracion de hardware guardada.');
    redirect('/hardware.php');
}

$stmt = db()->prepare("SELECT * FROM hardware_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$cfg = $stmt->fetch() ?: [
    'modo' => 'serial', 'mqtt_input_topic' => $topics['input'], 'mqtt_command_topic' => $topics['command'],
    'mqtt_query_topic' => $topics['query'], 'mqtt_key_command' => 'command', 'mqtt_key_state' => 'state',
    'serial_baudrate' => 9600,
];

$stmt = db()->prepare("SELECT * FROM mqtt_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$mqttCfg = $stmt->fetch();

$page_title = 'Conexion a Hardware';
$active_nav = 'hardware';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-2">
    <div class="card">
        <h3>Conexion a Hardware</h3>
        <p style="color:var(--text-muted);font-size:13px;">
            Igual que en la app: elige si las Entradas Digitales/Analogicas y
            las Salidas Q vienen por <strong>Serial (WebSerial, Chrome/Edge)</strong>
            o por el <strong>broker MQTT</strong> configurado a la derecha.
        </p>

        <div class="alert alert-info" style="font-size:13px;">
            Tu ID unico de dispositivo es <strong style="font-family:monospace;font-size:15px;"><?= htmlspecialchars($user['device_uid']) ?></strong>.
            Se usa para armar tus topicos MQTT (<code>device/<?= htmlspecialchars($user['device_uid']) ?>/...</code>)
            y para que Arduino/tu firmware sepa a que cuenta responder.
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <label>Modo</label>
            <select name="modo" id="modo-select">
                <option value="serial" <?= $cfg['modo'] === 'serial' ? 'selected' : '' ?>>Serial (WebSerial)</option>
                <option value="mqtt" <?= $cfg['modo'] === 'mqtt' ? 'selected' : '' ?>>MQTT</option>
            </select>

            <div id="serial-fields">
                <label>Baudrate</label>
                <input type="number" name="serial_baudrate" value="<?= (int) $cfg['serial_baudrate'] ?>">
                <p style="font-size:12px;color:var(--text-muted);">
                    El navegador pedira permiso para elegir el puerto USB la primera vez
                    (requiere Chrome o Edge; Firefox/Safari no soportan WebSerial todavia).
                </p>
                <button type="button" class="btn btn-ghost btn-block" id="btn-test-serial">Probar conexion Serial</button>
                <p id="serial-test-result" style="font-size:12px;color:var(--text-muted);"></p>
            </div>

            <div id="mqtt-fields">
                <label>Topico de entrada <span style="color:var(--text-muted);">(I12,I13,A0-A3)</span></label>
                <input type="text" name="mqtt_input_topic" placeholder="<?= htmlspecialchars($topics['input']) ?>"
                       value="<?= htmlspecialchars($cfg['mqtt_input_topic']) ?>">

                <label>Topico de envio <span style="color:var(--text-muted);">(salidas Q2-Q11)</span></label>
                <input type="text" name="mqtt_command_topic" placeholder="<?= htmlspecialchars($topics['command']) ?>"
                       value="<?= htmlspecialchars($cfg['mqtt_command_topic']) ?>">

                <label>Topico de consulta <span style="color:var(--text-muted);">(usado por ApiCam para preguntarle a Arduino su estado)</span></label>
                <input type="text" name="mqtt_query_topic" placeholder="<?= htmlspecialchars($topics['query']) ?>"
                       value="<?= htmlspecialchars($cfg['mqtt_query_topic']) ?>">

                <div class="field-row">
                    <div>
                        <label>Clave JSON del pin <span style="color:var(--text-muted);">(default "command")</span></label>
                        <input type="text" name="mqtt_key_command" value="<?= htmlspecialchars($cfg['mqtt_key_command']) ?>" placeholder="command">
                    </div>
                    <div>
                        <label>Clave JSON del valor <span style="color:var(--text-muted);">(default "state")</span></label>
                        <input type="text" name="mqtt_key_state" value="<?= htmlspecialchars($cfg['mqtt_key_state']) ?>" placeholder="state">
                    </div>
                </div>
                <p style="font-size:12px;color:var(--text-muted);">
                    El payload que se envia/lee es <code>{"&lt;clave del pin&gt;": "Q2", "&lt;clave del valor&gt;": "1"}</code> -
                    cambia estos nombres solo si tu firmware espera otras claves.
                </p>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Guardar</button>
        </form>
    </div>

    <div class="card">
        <h3>Broker MQTT</h3>
        <p style="color:var(--text-muted);font-size:13px;">
            Para usarlo desde el navegador, el broker debe soportar
            <strong>MQTT sobre WebSocket</strong> (ej. HiveMQ Cloud: mismo host,
            puerto 8884, TLS). Configuralo en <a href="<?= BASE_URL ?>/mqtt.php">Conexion MQTT</a>.
        </p>
        <?php if ($mqttCfg && $mqttCfg['host']): ?>
            <p><strong>Host:</strong> <?= htmlspecialchars($mqttCfg['host']) ?>:<?= (int) $mqttCfg['port'] ?></p>
            <p><strong>TLS:</strong> <?= $mqttCfg['use_tls'] ? 'Si' : 'No' ?></p>
        <?php else: ?>
            <p style="color:var(--text-muted);">Todavia no configuras un broker MQTT propio.</p>
        <?php endif; ?>
        <a class="btn btn-ghost btn-block" href="<?= BASE_URL ?>/mqtt.php">Editar broker MQTT</a>

        <hr style="border-color:var(--border);margin:18px 0;">
        <h3 style="margin-top:0;">Consola de Hardware</h3>
        <p style="color:var(--text-muted);font-size:13px;">
            Aparece automaticamente abajo a la derecha en esta y en las
            paginas de los modulos - muestra en vivo cada intento de
            conexion, lectura y escritura (Serial o MQTT), para que
            puedas ver exactamente que esta pasando en vez de adivinar.
        </p>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/hardware-bridge.js"></script>
<script>
    document.getElementById('modo-select').addEventListener('change', function () {
        document.getElementById('serial-fields').style.display = this.value === 'serial' ? 'block' : 'none';
        document.getElementById('mqtt-fields').style.display = this.value === 'mqtt' ? 'block' : 'none';
    });
    document.getElementById('modo-select').dispatchEvent(new Event('change'));

    document.getElementById('btn-test-serial').addEventListener('click', async function () {
        const result = document.getElementById('serial-test-result');
        try {
            await window.HardwareBridge.connectSerial();
            result.textContent = 'Puerto conectado correctamente.';
            result.style.color = 'var(--ok)';
        } catch (err) {
            result.textContent = 'Error: ' + err.message;
            result.style.color = 'var(--danger)';
        }
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
