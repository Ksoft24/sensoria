<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $host    = trim($_POST['host'] ?? '');
    $port    = (int) ($_POST['port'] ?? 8883);
    $wsPort  = (int) ($_POST['ws_port'] ?? 8884);
    $usern   = trim($_POST['username'] ?? '');
    $passw   = $_POST['password'] ?? '';
    $useTls  = !empty($_POST['use_tls']) ? 1 : 0;

    if ($passw === '') {
        $stmt = db()->prepare("SELECT password FROM mqtt_config WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $passw = $stmt->fetchColumn() ?: '';
    }

    db()->prepare("INSERT INTO mqtt_config (user_id, host, port, ws_port, username, password, use_tls)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE host=VALUES(host), port=VALUES(port), ws_port=VALUES(ws_port),
                        username=VALUES(username), password=VALUES(password), use_tls=VALUES(use_tls)")
        ->execute([$user['id'], $host, $port, $wsPort, $usern, $passw, $useTls]);

    flash('success', 'Configuracion MQTT guardada.');
    redirect('/mqtt.php');
}

$stmt = db()->prepare("SELECT * FROM mqtt_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$cfg = $stmt->fetch() ?: ['host'=>'','port'=>8883,'ws_port'=>8884,'username'=>'','use_tls'=>1];
$wsPort = $cfg['ws_port'] ?? 8884;

$page_title = 'Conexion MQTT';
$active_nav = 'mqtt';
require __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width:560px;">
    <h3>Conexion MQTT</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Este broker se usa tanto para el modo MQTT de Hardware como para que
        las reglas publiquen sus acciones (igual que en la app Kivy).
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <label>Host</label>
        <input type="text" name="host" value="<?= htmlspecialchars($cfg['host']) ?>" placeholder="xxxx.s1.eu.hivemq.cloud" required>

        <div class="field-row">
            <div>
                <label>Puerto (TCP, apps Kivy)</label>
                <input type="number" name="port" value="<?= (int) $cfg['port'] ?>">
            </div>
            <div>
                <label>Puerto WebSocket (navegador)</label>
                <input type="number" name="ws_port" value="<?= (int) $wsPort ?>">
            </div>
        </div>

        <label>Usuario</label>
        <input type="text" name="username" value="<?= htmlspecialchars($cfg['username']) ?>">

        <label>Clave <span style="color:var(--text-muted);">(dejar en blanco para no cambiarla)</span></label>
        <input type="password" name="password" placeholder="••••••••">

        <div class="checkbox-row">
            <input type="checkbox" name="use_tls" id="use_tls" <?= $cfg['use_tls'] ? 'checked' : '' ?>>
            <label for="use_tls" style="margin:0;">Usar TLS</label>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Guardar</button>
    </form>

    <button type="button" class="btn btn-ghost btn-block" id="btn-test-mqtt" style="margin-top:10px;">
        Probar conexion
    </button>
    <p id="mqtt-test-result" style="font-size:13px;margin-top:8px;"></p>
</div>

<!-- Valor ya guardado, para poder probar sin tener que re-escribir la clave -->
<input type="hidden" id="stored_password" value="<?= htmlspecialchars($cfg['password'] ?? '') ?>">

<script src="<?= BASE_URL ?>/assets/vendor/mqtt.min.js"></script>
<script>
(() => {
    const btn = document.getElementById("btn-test-mqtt");
    const result = document.getElementById("mqtt-test-result");
    const storedPassword = document.getElementById("stored_password").value;

    function setResult(text, ok) {
        result.textContent = text;
        result.style.color = ok ? "var(--ok)" : "var(--danger)";
    }

    btn.addEventListener("click", () => {
        const host = document.querySelector('input[name="host"]').value.trim();
        const wsPort = parseInt(document.querySelector('input[name="ws_port"]').value, 10) || 8884;
        const username = document.querySelector('input[name="username"]').value.trim();
        const typedPassword = document.querySelector('input[name="password"]').value;
        const password = typedPassword !== "" ? typedPassword : storedPassword;
        const useTls = document.getElementById("use_tls").checked;

        if (!host) {
            setResult("Escribe el host del broker antes de probar.", false);
            return;
        }

        setResult("Conectando...", true);
        btn.disabled = true;

        const scheme = useTls ? "wss" : "ws";
        const url = `${scheme}://${host}:${wsPort}/mqtt`;
        let finished = false;

        const client = mqtt.connect(url, {
            username: username || undefined,
            password: password || undefined,
            clientId: "web-test-" + Math.random().toString(16).slice(2),
            connectTimeout: 8000,
            reconnectPeriod: 0, // no reintentar solo, es una prueba puntual
        });

        const timeout = setTimeout(() => {
            if (finished) return;
            finished = true;
            setResult("Tiempo de espera agotado. Revisa host/puerto WebSocket/TLS.", false);
            btn.disabled = false;
            client.end(true);
        }, 9000);

        client.on("connect", () => {
            if (finished) return;
            client.publish("sensoria/test", JSON.stringify({ ping: Date.now() }), {}, (err) => {
                finished = true;
                clearTimeout(timeout);
                btn.disabled = false;
                if (err) {
                    setResult("Conecto, pero fallo al publicar: " + err.message, false);
                } else {
                    setResult("Conexion exitosa: se publico un mensaje de prueba en 'sensoria/test'.", true);
                }
                client.end(true);
            });
        });

        client.on("error", (err) => {
            if (finished) return;
            finished = true;
            clearTimeout(timeout);
            btn.disabled = false;
            setResult("Error de conexion: " + err.message, false);
            client.end(true);
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
