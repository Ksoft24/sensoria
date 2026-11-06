<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();

$INPUTS = mqtt_bridge_inputs(); // ['I12','I13','A0','A1','A2','A3']

// Las columnas de escalado las crea sql/migration_008_puente_escalado.sql
$scaleMigrated = db()->query("SHOW COLUMNS FROM mqtt_bridge_config LIKE 'scale_enabled'")->fetch() !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if ($scaleMigrated) {
        $stmt = db()->prepare("INSERT INTO mqtt_bridge_config
                                    (user_id, input_name, enabled, topic, mqtt_key_command, mqtt_key_state,
                                     scale_enabled, scale_min, scale_max, scale_out_min, scale_out_max, scale_percent, scale_unit)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), topic=VALUES(topic),
                                    mqtt_key_command=VALUES(mqtt_key_command), mqtt_key_state=VALUES(mqtt_key_state),
                                    scale_enabled=VALUES(scale_enabled), scale_min=VALUES(scale_min), scale_max=VALUES(scale_max),
                                    scale_out_min=VALUES(scale_out_min), scale_out_max=VALUES(scale_out_max),
                                    scale_percent=VALUES(scale_percent), scale_unit=VALUES(scale_unit)");
    } else { // sin migration_008: guardar como antes, sin escalado
        $stmt = db()->prepare("INSERT INTO mqtt_bridge_config
                                    (user_id, input_name, enabled, topic, mqtt_key_command, mqtt_key_state)
                                VALUES (?,?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), topic=VALUES(topic),
                                    mqtt_key_command=VALUES(mqtt_key_command), mqtt_key_state=VALUES(mqtt_key_state)");
    }

    // Numero o NULL (acepta coma decimal: "12,5")
    $num = function ($v) {
        $v = str_replace(',', '.', trim((string) $v));
        return ($v !== '' && is_numeric($v)) ? (float) $v : null;
    };

    foreach ($INPUTS as $inputName) {
        $row      = $_POST['rows'][$inputName] ?? [];
        $enabled  = !empty($row['enabled']) ? 1 : 0;
        $topic    = trim($row['topic'] ?? '') ?: default_bridge_topic($user['device_uid'], $inputName);
        $keyCmd   = trim($row['mqtt_key_command'] ?? '') ?: 'command';
        $keyState = trim($row['mqtt_key_state'] ?? '') ?: 'state';

        // --- Escalado (opcional) ---
        $scaleOn = !empty($row['scale_enabled']);
        $sMin = $num($row['scale_min'] ?? ''); $sMax = $num($row['scale_max'] ?? '');
        $oMin = $num($row['scale_out_min'] ?? ''); $oMax = $num($row['scale_out_max'] ?? '');
        $sPct = !empty($row['scale_percent']) ? 1 : 0;
        $sUnitRaw = trim($row['scale_unit'] ?? '');
        $sUnit = (function_exists('mb_substr') ? mb_substr($sUnitRaw, 0, 20) : substr($sUnitRaw, 0, 20)) ?: null;
        if ($sPct) { $oMin = $oMin ?? 0.0; $oMax = $oMax ?? 100.0; } // porcentaje: 0-100 por defecto

        if ($scaleOn) {
            if ($sMin === null || $sMax === null || $oMin === null || $oMax === null) {
                flash('error', "$inputName: para escalar completa min, max, escala_min y escala_max. El escalado quedo DESACTIVADO (se publica el valor crudo).");
                $scaleOn = false;
            } elseif ($sMin === $sMax) {
                flash('error', "$inputName: min y max no pueden ser iguales. El escalado quedo DESACTIVADO (se publica el valor crudo).");
                $scaleOn = false;
            } else {
                if ($oMin === $oMax) {
                    flash('warning', "$inputName: escala_min y escala_max son iguales, el valor publicado sera siempre el mismo.");
                }
                if ($sPct && (min($oMin, $oMax) != 0 || max($oMin, $oMax) != 100)) {
                    flash('warning', "$inputName: marcaste es_porcentaje pero la escala no es 0-100.");
                }
            }
        }
        if ($scaleMigrated) {
            $stmt->execute([$user['id'], $inputName, $enabled, $topic, $keyCmd, $keyState,
                            $scaleOn ? 1 : 0, $sMin, $sMax, $oMin, $oMax, $sPct, $sUnit]);
        } else {
            $stmt->execute([$user['id'], $inputName, $enabled, $topic, $keyCmd, $keyState]);
        }
    }

    flash('success', 'Puente MQTT guardado.');
    redirect('/puente_mqtt.php');
}

// Config ya guardada por entrada (una fila por cada una en mqtt_bridge_config)
$stmt = db()->prepare("SELECT * FROM mqtt_bridge_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$existing = [];
foreach ($stmt->fetchAll() as $row) {
    $existing[$row['input_name']] = $row;
}

$rows = [];
foreach ($INPUTS as $inputName) {
    $rows[$inputName] = $existing[$inputName] ?? [
        'input_name'       => $inputName,
        'enabled'          => 0,
        'topic'            => default_bridge_topic($user['device_uid'], $inputName),
        'mqtt_key_command' => 'command',
        'mqtt_key_state'   => 'state',
    ];
    // Campos de escalado (si aun no se ejecuto migration_008, quedan vacios)
    $rows[$inputName] += [
        'scale_enabled' => 0, 'scale_min' => null, 'scale_max' => null,
        'scale_out_min' => null, 'scale_out_max' => null, 'scale_percent' => 0, 'scale_unit' => null,
    ];
}

// Mismo patron que hardware.php/modulos: hace falta saber por donde
// llegan las lecturas (Serial o MQTT) y si hay un broker propio para
// poder PUBLICAR el Puente (independiente del modo de Hardware, igual
// que ya hacen las Reglas con MQTT propio).
$topics = default_mqtt_topics($user['device_uid']);
$stmt = db()->prepare("SELECT * FROM hardware_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$hwCfg = $stmt->fetch() ?: [
    'modo' => 'serial', 'mqtt_input_topic' => $topics['input'], 'mqtt_command_topic' => $topics['command'],
    'mqtt_query_topic' => $topics['query'], 'mqtt_key_command' => 'command', 'mqtt_key_state' => 'state',
    'serial_baudrate' => 9600,
];

$stmt = db()->prepare("SELECT * FROM mqtt_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$mqttCfg = $stmt->fetch() ?: ['host' => '', 'ws_port' => 8884, 'username' => '', 'password' => '', 'use_tls' => 1];
$wsPort = $mqttCfg['ws_port'] ?? 8884;

$page_title = 'Puente MQTT';
$active_nav = 'puente_mqtt';
require __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h3>Puente MQTT</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Republica cada Entrada Digital/Analogica (<strong>I12, I13, A0, A1, A2, A3</strong>)
        en su <strong>propio topico</strong>, cada vez que su valor <strong>cambia</strong>
        (no en cada lectura repetida). Funciona igual sea que las entradas
        lleguen por <strong>Serial</strong> o por <strong>MQTT</strong>
        (lo que tengas configurado en <a href="<?= BASE_URL ?>/hardware.php">Conexion a Hardware</a>).
    </p>
    <div class="alert alert-info" style="font-size:13px;">
        El mensaje publicado en cada topico es
        <code>{"&lt;clave comando&gt;": "&lt;nombre de la entrada, ej. A1&gt;", "&lt;clave valor&gt;": "&lt;valor detectado&gt;"}</code>.
        Por defecto las claves son <code>command</code> y <code>state</code> - el
        <em>nombre</em> de esas claves es lo unico personalizable por entrada, el
        <em>contenido</em> (nombre de la entrada y valor leido) siempre lo llena el sistema.
    </div>

    <div class="alert alert-warning" style="font-size:13px;">
        <strong>Advertencia sobre "Escalar":</strong> al activarlo, el topico recibe el valor
        <strong>calculado</strong> (no la lectura cruda del sensor). Todo lo que escuche ese topico
        (dashboards, otras apps, otro microcontrolador) vera el valor ya escalado. Si el escalado esta
        incompleto o es invalido se publica el valor crudo y se te avisa aqui.
    </div>
    <?php if (!$scaleMigrated): ?>
    <div class="alert alert-error" style="font-size:13px;">
        Falta ejecutar <code>sql/migration_008_puente_escalado.sql</code>: sin ella el escalado no se puede guardar.
    </div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Entrada</th>
                    <th>Activar</th>
                    <th>Valor actual</th>
                    <th>Escalar</th>
                    <th>Topico</th>
                    <th>Clave comando</th>
                    <th>Clave valor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $inputName => $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($inputName) ?></strong></td>
                    <td>
                        <input type="checkbox" name="rows[<?= htmlspecialchars($inputName) ?>][enabled]"
                               value="1" <?= $row['enabled'] ? 'checked' : '' ?>>
                    </td>
                    <td>
                        <span class="badge badge-muted" id="live-<?= htmlspecialchars($inputName) ?>">-</span>
                    </td>
                    <td>
                        <input type="checkbox" class="scale-toggle" data-input="<?= htmlspecialchars($inputName) ?>"
                               name="rows[<?= htmlspecialchars($inputName) ?>][scale_enabled]"
                               value="1" <?= !empty($row['scale_enabled']) ? 'checked' : '' ?>>
                    </td>
                    <td>
                        <input type="text" style="min-width:220px;"
                               name="rows[<?= htmlspecialchars($inputName) ?>][topic]"
                               value="<?= htmlspecialchars($row['topic']) ?>"
                               placeholder="<?= htmlspecialchars(default_bridge_topic($user['device_uid'], $inputName)) ?>">
                    </td>
                    <td>
                        <input type="text" style="min-width:110px;"
                               name="rows[<?= htmlspecialchars($inputName) ?>][mqtt_key_command]"
                               value="<?= htmlspecialchars($row['mqtt_key_command']) ?>" placeholder="command">
                    </td>
                    <td>
                        <input type="text" style="min-width:110px;"
                               name="rows[<?= htmlspecialchars($inputName) ?>][mqtt_key_state]"
                               value="<?= htmlspecialchars($row['mqtt_key_state']) ?>" placeholder="state">
                    </td>
                </tr>
                <?php
                    $in = htmlspecialchars($inputName);
                    $fld = fn($k) => $row[$k] !== null && $row[$k] !== '' ? rtrim(rtrim(number_format((float) $row[$k], 4, '.', ''), '0'), '.') : '';
                ?>
                <tr class="scale-row" id="scale-<?= $in ?>" data-input="<?= $in ?>" style="display:<?= !empty($row['scale_enabled']) ? 'table-row' : 'none' ?>;">
                    <td colspan="7" style="background:var(--bg-soft, rgba(127,127,127,.07));">
                        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                            <label style="font-size:12px;">min<br>
                                <input type="text" inputmode="decimal" style="width:90px;" data-f="min"
                                       name="rows[<?= $in ?>][scale_min]" value="<?= $fld('scale_min') ?>"></label>
                            <label style="font-size:12px;">max<br>
                                <input type="text" inputmode="decimal" style="width:90px;" data-f="max"
                                       name="rows[<?= $in ?>][scale_max]" value="<?= $fld('scale_max') ?>"></label>
                            <label style="font-size:12px;">escala_min<br>
                                <input type="text" inputmode="decimal" style="width:90px;" data-f="out_min"
                                       name="rows[<?= $in ?>][scale_out_min]" value="<?= $fld('scale_out_min') ?>"></label>
                            <label style="font-size:12px;">escala_max<br>
                                <input type="text" inputmode="decimal" style="width:90px;" data-f="out_max"
                                       name="rows[<?= $in ?>][scale_out_max]" value="<?= $fld('scale_out_max') ?>"></label>
                            <label style="font-size:12px;">es_porcentaje<br>
                                <input type="checkbox" data-f="percent" name="rows[<?= $in ?>][scale_percent]" value="1"
                                       <?= !empty($row['scale_percent']) ? 'checked' : '' ?>></label>
                            <label style="font-size:12px;">unidad<br>
                                <input type="text" maxlength="20" style="width:90px;" data-f="unit"
                                       name="rows[<?= $in ?>][scale_unit]" value="<?= htmlspecialchars((string) ($row['scale_unit'] ?? '')) ?>"
                                       placeholder="C°, V, OH..."></label>
                            <div style="font-size:12px;">escala_valor (se publica)<br>
                                <span class="badge badge-muted" id="scaled-<?= $in ?>" style="font-size:14px;">-</span></div>
                        </div>
                        <div id="scale-warn-<?= $in ?>" style="font-size:12px;color:var(--warn);margin-top:6px;"></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:16px;">Guardar</button>
    </form>
</div>

<div class="card">
    <h3>Conexion</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Lee las entradas por <strong><?= htmlspecialchars($hwCfg['modo']) ?></strong>
        (definido en <a href="<?= BASE_URL ?>/hardware.php">Conexion a Hardware</a>) y publica
        con el broker de <a href="<?= BASE_URL ?>/mqtt.php">Conexion MQTT</a>.
    </p>
    <div class="status-row" style="display:flex;justify-content:space-between;margin-bottom:8px;">
        <span>Estado</span><span id="bridge-status" class="badge badge-muted">Inicializando...</span>
    </div>
    <?php if ($hwCfg['modo'] === 'serial'): ?>
    <button class="btn btn-ghost btn-block" id="btn-connect-serial">Conectar Arduino (WebSerial)</button>
    <?php endif; ?>
</div>

<?php if ($mqttCfg['host']): ?>
<script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>
<?php endif; ?>

<script>
    window.BASE_URL = <?= json_encode(BASE_URL) ?>;
    window.HARDWARE_CONFIG = {
        modo: <?= json_encode($hwCfg['modo']) ?>,
        mqtt_input_topic: <?= json_encode($hwCfg['mqtt_input_topic']) ?>,
        mqtt_command_topic: <?= json_encode($hwCfg['mqtt_command_topic'] ?? $topics['command']) ?>,
        mqtt_key_command: <?= json_encode($hwCfg['mqtt_key_command'] ?? 'command') ?>,
        mqtt_key_state: <?= json_encode($hwCfg['mqtt_key_state'] ?? 'state') ?>,
        mqtt: {
            host: <?= json_encode($mqttCfg['host']) ?>,
            port_ws: <?= json_encode((int) $wsPort) ?>,
            username: <?= json_encode($mqttCfg['username']) ?>,
            password: <?= json_encode($mqttCfg['password']) ?>,
            use_tls: <?= json_encode((bool) $mqttCfg['use_tls']) ?>,
        },
    };
    // Configuracion del Puente: una fila por entrada, solo las que estan
    // activas y con topico se usan de verdad (ver assets/js/mqtt-bridge.js).
    window.MQTT_BRIDGE_CONFIG = <?= json_encode(array_values($rows)) ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/hardware-bridge.js"></script>
<script src="<?= BASE_URL ?>/assets/js/mqtt-bridge.js"></script>

<script>
(() => {
    const bridgeStatus = document.getElementById("bridge-status");

    function setBadge(el, text, kind) {
        el.textContent = text;
        el.className = "badge badge-" + kind;
    }

    // Config normalizada para el constructor (PHP ya manda snake_case).
    const bridgeConfigs = (window.MQTT_BRIDGE_CONFIG || []).map((r) => ({
        input_name: r.input_name,
        enabled: !!Number(r.enabled),
        topic: r.topic,
        mqtt_key_command: r.mqtt_key_command,
        mqtt_key_state: r.mqtt_key_state,
        scale_enabled: r.scale_enabled, scale_min: r.scale_min, scale_max: r.scale_max,
        scale_out_min: r.scale_out_min, scale_out_max: r.scale_out_max,
        scale_percent: r.scale_percent, scale_unit: r.scale_unit,
    }));

    const bridge = new window.InputMqttBridge(bridgeConfigs);
    bridge.onPublish = (inputName, value, topic) => {
        window.HardwareBridge.log("success", `Puente MQTT: ${inputName}=${value} -> "${topic}"`);
    };
    bridge.onWarning = (inputName, msg) => {
        window.HardwareBridge.log("warn", `Puente MQTT (${inputName}): ${msg}`);
    };
    bridge.start();

    // ---- Escalado: mostrar/ocultar campos y vista previa en vivo ----
    const lastRaw = {}; // entrada (I12, A0...) -> ultima lectura cruda
    function scaleCfg(name) {
        const row = document.getElementById("scale-" + name);
        const g = (f) => { const el = row.querySelector('[data-f="' + f + '"]'); return el ? el : null; };
        const val = (f) => (g(f).value || "").replace(",", ".");
        return { min: val("min"), max: val("max"), out_min: val("out_min"), out_max: val("out_max"),
                 percent: g("percent").checked ? 1 : 0, unit: g("unit").value };
    }
    function refreshScale(name) {
        const row = document.getElementById("scale-" + name);
        const toggle = document.querySelector('.scale-toggle[data-input="' + name + '"]');
        if (!row || !toggle) return;
        row.style.display = toggle.checked ? "table-row" : "none";
        if (!toggle.checked) return;
        const res = window.InputMqttBridge.computeScale(lastRaw[name], scaleCfg(name));
        const out = document.getElementById("scaled-" + name);
        out.textContent = res.ok ? res.text : "-";
        out.className = "badge badge-" + (res.ok ? "ok" : "muted");
        const warn = document.getElementById("scale-warn-" + name);
        warn.textContent = res.warnings.length ? "\u26A0 " + res.warnings.join(" ") : "";
    }
    document.querySelectorAll(".scale-toggle").forEach((t) => {
        t.addEventListener("change", () => refreshScale(t.dataset.input));
    });
    document.querySelectorAll(".scale-row input").forEach((el) => {
        const name = el.closest(".scale-row").dataset.input;
        el.addEventListener("input", () => refreshScale(name));
        el.addEventListener("change", () => refreshScale(name));
    });
    document.querySelectorAll(".scale-toggle").forEach((t) => refreshScale(t.dataset.input));

    // Pinta el valor "en vivo" de cada entrada (todas, aunque no esten
    // activas en el puente, para poder ver que esta llegando).
    window.HardwareBridge.onInput((reading) => {
        const digital = reading.digital || {};
        const analog = reading.analog || {};
        Object.keys(digital).forEach((k) => {
            const el = document.getElementById("live-" + k);
            if (el) { el.textContent = digital[k]; el.className = "badge badge-ok"; }
            lastRaw[k] = digital[k]; refreshScale(k);
        });
        Object.keys(analog).forEach((k) => {
            // AI0..AI3 -> etiqueta visible A0..A3
            const label = k.startsWith("AI") ? "A" + k.slice(2) : k;
            const el = document.getElementById("live-" + label);
            if (el) { el.textContent = analog[k]; el.className = "badge badge-ok"; }
            lastRaw[label] = analog[k]; refreshScale(label);
        });
    });

    async function setup() {
        if (window.HARDWARE_CONFIG.mqtt.host) {
            try {
                await window.HardwareBridge.connectMqtt(window.HARDWARE_CONFIG.mqtt);
                setBadge(bridgeStatus, "MQTT conectado", "ok");
            } catch (err) {
                setBadge(bridgeStatus, "MQTT: error", "danger");
                console.error(err);
            }
        } else {
            setBadge(bridgeStatus, "Falta configurar un broker MQTT", "warn");
        }

        const btn = document.getElementById("btn-connect-serial");
        if (btn) {
            btn.addEventListener("click", async () => {
                try {
                    await window.HardwareBridge.connectSerial(<?= (int) ($hwCfg['serial_baudrate'] ?? 9600) ?>);
                    setBadge(bridgeStatus, "Serial conectado", "ok");
                } catch (err) {
                    setBadge(bridgeStatus, "Serial: error", "danger");
                    alert(err.message);
                }
            });
        }
    }

    window.addEventListener("DOMContentLoaded", setup);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
