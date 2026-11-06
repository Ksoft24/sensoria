<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();

$MODULOS = [
    'yolo' => 'Deteccion de Objetos (YOLO)',
    'yolo_seg' => 'Segmentacion (zonas ROJO/AZUL/AMARILLO)',
    'movenet' => 'Pose (MoveNet)',
    'handlandmarks' => 'Gestos de Mano',
    'pinch' => 'Control de Pinza',
    'qr' => 'Codigo QR',
    'aruco' => 'Marcador ArUco',
    'colorimeter' => 'Colorimetro',
    'geometric_scan' => 'GeometricScan',
    'apicam_yolo' => 'ApiCam - Deteccion de Objetos',
    'apicam_yolo_seg' => 'ApiCam - Segmentacion',
    'apicam_movenet' => 'ApiCam - Pose (MoveNet)',
    'apicam_handlandmarks' => 'ApiCam - Gestos de Mano',
    'apicam_pinch' => 'ApiCam - Control de Pinza',
    'apicam_qr' => 'ApiCam - Codigo QR',
    'apicam_aruco' => 'ApiCam - Marcador ArUco',
    'apicam_colorimeter' => 'ApiCam - Colorimetro',
    'apicam_geometric_scan' => 'ApiCam - GeometricScan',
];
// Para el desplegable de creacion: agrupados en 2 <optgroup> bien
// diferenciados. "handlandmarks" (en vivo, navegador) y
// "apicam_handlandmarks" (ApiCam, foto manual) tienen nombres MUY
// parecidos pero cargan reglas de forma COMPLETAMENTE separada -
// elegir el grupo equivocado hace que la regla jamas se dispare para
// ese modulo (ver nota en modules/apicam.php).
$MODULOS_LIVE = [
    'yolo' => 'Deteccion de Objetos', 'yolo_seg' => 'Segmentacion', 'movenet' => 'Pose (MoveNet)',
    'handlandmarks' => 'Gestos de Mano', 'pinch' => 'Control de Pinza', 'qr' => 'Codigo QR', 'aruco' => 'Marcador ArUco',
    'colorimeter' => 'Colorimetro', 'geometric_scan' => 'GeometricScan',
];
$MODULOS_APICAM = [
    'apicam_yolo' => 'Deteccion de Objetos', 'apicam_yolo_seg' => 'Segmentacion',
    'apicam_movenet' => 'Pose (MoveNet)', 'apicam_handlandmarks' => 'Gestos de Mano',
    'apicam_pinch' => 'Control de Pinza', 'apicam_qr' => 'Codigo QR', 'apicam_aruco' => 'Marcador ArUco',
    'apicam_colorimeter' => 'Colorimetro', 'apicam_geometric_scan' => 'GeometricScan',
];
$PINCH_MODULES = ['pinch', 'apicam_pinch'];
$DIGITAL_PINS = ['', 'I12', 'I13'];
$ANALOG_PINS  = ['', 'AI0', 'AI1', 'AI2', 'AI3'];
$ACTION_PINS  = ['Q2','Q3','Q4','Q5','Q6','Q7','Q8','Q9','Q10','Q11'];
$OPERADORES   = ['>','<','>=','<=','=='];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'eliminar') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM rules WHERE id = ? AND user_id = ?")->execute([$id, $user['id']]);
        flash('success', 'Regla eliminada.');
        redirect('/reglas.php');
    }

    if ($action === 'alternar_activo') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE rules SET active = 1 - active WHERE id = ? AND user_id = ?")->execute([$id, $user['id']]);
        redirect('/reglas.php');
    }

    if ($action === 'guardar') {
        $module = $_POST['module'] ?? 'yolo';
        $isPinch = in_array($module, $PINCH_MODULES, true);

        $data = [
            'user_id'        => $user['id'],
            'module'         => $module,
            'class_name'     => trim($_POST['class_name'] ?? '') ?: null,
            'min_confidence' => (!$isPinch && $_POST['min_confidence'] !== '') ? (int) $_POST['min_confidence'] : null,
            'digital_pin'    => $_POST['digital_pin'] ?: null,
            'digital_value'  => $_POST['digital_pin'] ? (int) ($_POST['digital_value'] ?? 0) : null,
            'analog_pin'     => $_POST['analog_pin'] ?: null,
            'analog_op'      => $_POST['analog_pin'] ? ($_POST['analog_op'] ?: '>') : null,
            'analog_value'   => $_POST['analog_pin'] !== '' && $_POST['analog_value'] !== '' ? (int) $_POST['analog_value'] : null,
            'action_pin'     => $_POST['action_pin'] ?? 'Q2',
            'action_value'   => $isPinch ? null : (int) ($_POST['action_value'] ?? 1),
            'scale_out_min'  => $isPinch ? (int) ($_POST['scale_out_min'] ?? 0) : null,
            'scale_out_max'  => $isPinch ? (int) ($_POST['scale_out_max'] ?? 255) : null,
            'mqtt_enabled'   => !empty($_POST['mqtt_enabled']) ? 1 : 0,
            'mqtt_topic'     => trim($_POST['mqtt_topic'] ?? '') ?: null,
            'mqtt_tag'       => trim($_POST['mqtt_tag'] ?? '') ?: null,
            'mqtt_key_class_id'   => trim($_POST['mqtt_key_class_id'] ?? '') ?: 'class_id',
            'mqtt_key_class_name' => trim($_POST['mqtt_key_class_name'] ?? '') ?: 'class_name',
            'mqtt_key_confidence' => trim($_POST['mqtt_key_confidence'] ?? '') ?: 'confidence',
            'mqtt_key_value'      => trim($_POST['mqtt_key_value'] ?? '') ?: 'state',
            'mqtt_key_pin'        => trim($_POST['mqtt_key_pin'] ?? '') ?: 'pin',
            'mqtt_key_tag'        => trim($_POST['mqtt_key_tag'] ?? '') ?: 'command',
        ];

        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            $sql = "UPDATE rules SET module=?, class_name=?, min_confidence=?, digital_pin=?, digital_value=?,
                    analog_pin=?, analog_op=?, analog_value=?, action_pin=?, action_value=?,
                    scale_out_min=?, scale_out_max=?,
                    mqtt_enabled=?, mqtt_topic=?, mqtt_tag=?,
                    mqtt_key_class_id=?, mqtt_key_class_name=?, mqtt_key_confidence=?,
                    mqtt_key_value=?, mqtt_key_pin=?, mqtt_key_tag=?
                    WHERE id = ? AND user_id = ?";
            db()->prepare($sql)->execute([
                $data['module'], $data['class_name'], $data['min_confidence'], $data['digital_pin'], $data['digital_value'],
                $data['analog_pin'], $data['analog_op'], $data['analog_value'], $data['action_pin'], $data['action_value'],
                $data['scale_out_min'], $data['scale_out_max'],
                $data['mqtt_enabled'], $data['mqtt_topic'], $data['mqtt_tag'],
                $data['mqtt_key_class_id'], $data['mqtt_key_class_name'], $data['mqtt_key_confidence'],
                $data['mqtt_key_value'], $data['mqtt_key_pin'], $data['mqtt_key_tag'],
                $ruleId, $user['id'],
            ]);
            flash('success', 'Regla actualizada.');
        } else {
            $sql = "INSERT INTO rules (user_id, module, class_name, min_confidence, digital_pin, digital_value,
                    analog_pin, analog_op, analog_value, action_pin, action_value, scale_out_min, scale_out_max,
                    mqtt_enabled, mqtt_topic, mqtt_tag,
                    mqtt_key_class_id, mqtt_key_class_name, mqtt_key_confidence,
                    mqtt_key_value, mqtt_key_pin, mqtt_key_tag)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            db()->prepare($sql)->execute(array_values($data));
            flash('success', 'Regla creada.');
        }
        redirect('/reglas.php');
    }
}

$editing = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT * FROM rules WHERE id = ? AND user_id = ?");
    $stmt->execute([(int) $_GET['edit'], $user['id']]);
    $editing = $stmt->fetch();
}

$stmt = db()->prepare("SELECT * FROM rules WHERE user_id = ? ORDER BY creado_en DESC");
$stmt->execute([$user['id']]);
$rules = $stmt->fetchAll();

$page_title = 'Reglas';
$active_nav = 'rules';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-2">
    <div class="card">
        <h3><?= $editing ? 'Editar regla' : 'Nueva regla' ?></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="guardar">
            <input type="hidden" name="rule_id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <label>Modulo</label>
            <select name="module" id="module_select">
                <optgroup label="🎥 En vivo (navegador, cuadro a cuadro / StaticCam)">
                    <?php foreach ($MODULOS_LIVE as $slug => $label): ?>
                        <option value="<?= $slug ?>" <?= ($editing['module'] ?? 'yolo') === $slug ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="📡 ApiCam (foto manual)">
                    <?php foreach ($MODULOS_APICAM as $slug => $label): ?>
                        <option value="<?= $slug ?>" <?= ($editing['module'] ?? '') === $slug ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
            <p style="font-size:12px;color:var(--text-muted);margin-top:-10px;">
                Importante: elige el grupo correcto. El grupo "En vivo" lo
                usan tanto los 7 modulos en tiempo real como
                <strong>StaticCam</strong> (misma clase/gesto, mismas
                reglas). El grupo "ApiCam" es independiente - una regla
                creada en uno NO se usa en el otro, aunque compartan el
                mismo nombre de gesto/clase.
            </p>

            <label>Clase / Zona / Gesto disparador</label>
            <select id="class_name_select"></select>
            <input type="text" name="class_name" id="class_name_input"
                   value="<?= htmlspecialchars($editing['class_name'] ?? '') ?>" style="display:none;">
            <p id="class_name_hint" style="font-size:12px;color:var(--text-muted);margin-top:-10px;"></p>

            <div id="min_confidence_field">
                <label>Confianza minima (0-100, opcional)</label>
                <input type="number" name="min_confidence" min="0" max="100"
                       value="<?= htmlspecialchars($editing['min_confidence'] ?? '') ?>">
            </div>

            <!-- Solo para el modulo Control de Pinza: escala continua de salida -->
            <div id="scale_fields" style="display:none;">
                <p style="font-size:13px;color:var(--warn);margin-bottom:6px;">
                    Valor en 0% (minimo) y 100% (maximo) de apertura de pinza.
                    Ejemplos: PWM 0-255, angulos 5-50, cualquier escala.
                </p>
                <div class="field-row">
                    <div>
                        <label>Valor en 0%</label>
                        <input type="number" name="scale_out_min" value="<?= htmlspecialchars($editing['scale_out_min'] ?? 0) ?>">
                    </div>
                    <div>
                        <label>Valor en 100%</label>
                        <input type="number" name="scale_out_max" value="<?= htmlspecialchars($editing['scale_out_max'] ?? 255) ?>">
                    </div>
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label>Entrada Digital (condicion, opcional)</label>
                    <select name="digital_pin">
                        <?php foreach ($DIGITAL_PINS as $p): ?>
                            <option value="<?= $p ?>" <?= ($editing['digital_pin'] ?? '') === $p ? 'selected' : '' ?>><?= $p ?: '-- Ninguna --' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Valor esperado (0/1)</label>
                    <input type="number" name="digital_value" min="0" max="1" value="<?= htmlspecialchars($editing['digital_value'] ?? 1) ?>">
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label>Entrada Analogica (condicion, opcional)</label>
                    <select name="analog_pin">
                        <?php foreach ($ANALOG_PINS as $p): ?>
                            <option value="<?= $p ?>" <?= ($editing['analog_pin'] ?? '') === $p ? 'selected' : '' ?>><?= $p ?: '-- Ninguna --' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Operador</label>
                    <select name="analog_op">
                        <?php foreach ($OPERADORES as $op): ?>
                            <option value="<?= $op ?>" <?= ($editing['analog_op'] ?? '>') === $op ? 'selected' : '' ?>><?= $op ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Valor</label>
                    <input type="number" name="analog_value" value="<?= htmlspecialchars($editing['analog_value'] ?? '') ?>">
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label>Salida a activar</label>
                    <select name="action_pin">
                        <?php foreach ($ACTION_PINS as $p): ?>
                            <option value="<?= $p ?>" <?= ($editing['action_pin'] ?? 'Q2') === $p ? 'selected' : '' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="action_value_field">
                    <label>Valor de salida</label>
                    <input type="number" name="action_value" value="<?= htmlspecialchars($editing['action_value'] ?? 1) ?>">
                </div>
            </div>

            <div class="checkbox-row">
                <input type="checkbox" name="mqtt_enabled" id="mqtt_enabled" <?= !empty($editing['mqtt_enabled']) ? 'checked' : '' ?>>
                <label for="mqtt_enabled" style="margin:0;">Tambien publicar por MQTT</label>
            </div>
            <div id="mqtt_fields" style="display:<?= !empty($editing['mqtt_enabled']) ? 'block' : 'none' ?>;">
                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 10px;">
                    Tu ID de dispositivo es <strong style="font-family:monospace;"><?= htmlspecialchars($user['device_uid']) ?></strong>
                    - el topico ya viene precargado con <code>device/<?= htmlspecialchars($user['device_uid']) ?>/command</code>,
                    cambialo solo si quieres publicar en otro topico.
                </p>
                <div class="field-row">
                    <div>
                        <label>Topico MQTT</label>
                        <input type="text" name="mqtt_topic"
                               value="<?= htmlspecialchars($editing['mqtt_topic'] ?? ('device/' . $user['device_uid'] . '/command')) ?>"
                               placeholder="device/<?= htmlspecialchars($user['device_uid']) ?>/command">
                    </div>
                    <div>
                        <label>Tag (valor de la clave "command")</label>
                        <input type="text" name="mqtt_tag" value="<?= htmlspecialchars($editing['mqtt_tag'] ?? '') ?>" placeholder="ej: Sensor">
                    </div>
                </div>

                <p style="font-size:13px;color:var(--text-muted);margin:10px 0 4px;">
                    Mensaje (edita la clave JSON de cada campo)
                </p>
                <?php
                $mqttKeyFields = [
                    'mqtt_key_class_id'   => ['class_id', 'class_id'],
                    'mqtt_key_class_name' => ['class_name', 'class_name'],
                    'mqtt_key_confidence' => ['confidence', 'confidence'],
                    'mqtt_key_value'      => ['value', 'state'],
                    'mqtt_key_pin'        => ['pin', 'pin'],
                    'mqtt_key_tag'        => ['tag', 'command'],
                ];
                foreach ($mqttKeyFields as $name => $pair):
                    [$labelLeft, $default] = $pair;
                ?>
                    <div class="field-row" style="align-items:center;">
                        <div style="flex:0 0 110px;font-weight:700;color:var(--warn);font-size:13px;">
                            <?= $labelLeft ?>
                        </div>
                        <div>
                            <input type="text" name="<?= $name ?>"
                                   value="<?= htmlspecialchars($editing[$name] ?? $default) ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block"><?= $editing ? 'Guardar cambios' : 'Crear regla' ?></button>
            <?php if ($editing): ?>
                <a class="btn btn-ghost btn-block" style="margin-top:8px;" href="<?= BASE_URL ?>/reglas.php">Cancelar edicion</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Mis reglas</h3>
        <?php if (!$rules): ?>
            <p style="color:var(--text-muted);">Aun no tienes reglas creadas.</p>
        <?php endif; ?>
        <?php foreach ($rules as $r): ?>
            <div style="border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <strong><?= htmlspecialchars($MODULOS[$r['module']] ?? $r['module']) ?></strong>
                    <span class="badge badge-<?= $r['active'] ? 'ok' : 'muted' ?>"><?= $r['active'] ? 'Activa' : 'Inactiva' ?></span>
                </div>
                <p style="font-size:13px;color:var(--text-muted);margin:8px 0;">
                    Disparador: <strong><?= htmlspecialchars($r['class_name'] ?: '-') ?></strong>
                    <?= $r['digital_pin'] ? " · Digital {$r['digital_pin']}={$r['digital_value']}" : '' ?>
                    <?= $r['analog_pin'] ? " · {$r['analog_pin']} {$r['analog_op']} {$r['analog_value']}" : '' ?>
                    <?php if (in_array($r['module'], $PINCH_MODULES, true) && $r['scale_out_min'] !== null): ?>
                        → Salida <strong><?= htmlspecialchars($r['action_pin']) ?></strong>
                        escala <?= (int) $r['scale_out_min'] ?>-<?= (int) $r['scale_out_max'] ?>
                    <?php else: ?>
                        → Salida <strong><?= htmlspecialchars($r['action_pin']) ?>=<?= (int) $r['action_value'] ?></strong>
                    <?php endif; ?>
                    <?= $r['mqtt_enabled'] ? ' · MQTT: ' . htmlspecialchars($r['mqtt_topic']) : '' ?>
                </p>
                <div style="display:flex;gap:8px;">
                    <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/reglas.php?edit=<?= (int) $r['id'] ?>">Editar</a>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="alternar_activo">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= $r['active'] ? 'Desactivar' : 'Activar' ?></button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar esta regla?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="eliminar">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="btn btn-danger btn-sm" type="submit">Eliminar</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>


<script>
    // Mismas clases que reportan los detectores JS de cada modulo
    // (ver modules/assets/<modulo>/*.js). Si agregas/cambias un modelo,
    // actualiza esta lista para que coincida con lo que ese modulo envia
    // a rules-engine.js.
    const MODULE_CLASSES = {
        yolo: <?= json_encode(json_decode(file_get_contents(__DIR__ . '/modules/assets/yolo/classes.json'))) ?>,
        yolo_seg: ["ROJO", "AZUL", "AMARILLO"],
        movenet: ["Brazos Arriba", "Brazo Izquierdo", "Brazo Derecho", "Brazo Centro", "Postura Desconocida"],
        handlandmarks: ["Punio Cerrado", "Mano Abierta", "Pulgar Arriba", "Senial de Victoria", "Indice Arriba", "Gesto Desconocido"],
        pinch: ["Apertura de Pinza"],
        colorimeter: ["Negro", "Blanco", "Gris", "Rojo", "Naranja", "Amarillo", "Verde", "Azul", "Morado"],
        geometric_scan: ["Triangulo", "Rectangulo", "Cuadrado", "Pentagono", "Hexagono", "Circulo"],
    };
    // El modulo ApiCam reusa exactamente las mismas clases que su
    // contraparte en el navegador (la API Flask devuelve los mismos
    // nombres de clase), solo cambia el "module" guardado en la regla.
    MODULE_CLASSES.apicam_yolo = MODULE_CLASSES.yolo;
    MODULE_CLASSES.apicam_yolo_seg = MODULE_CLASSES.yolo_seg;
    MODULE_CLASSES.apicam_movenet = MODULE_CLASSES.movenet;
    MODULE_CLASSES.apicam_handlandmarks = MODULE_CLASSES.handlandmarks;
    MODULE_CLASSES.apicam_pinch = MODULE_CLASSES.pinch;
    MODULE_CLASSES.apicam_colorimeter = MODULE_CLASSES.colorimeter;
    MODULE_CLASSES.apicam_geometric_scan = MODULE_CLASSES.geometric_scan;

    // Modulos cuyo disparador es dinamico (no hay una lista fija de clases)
    const FREE_TEXT_HINTS = {
        qr: "Texto exacto que codifica el QR (distingue mayusculas/minusculas).",
        aruco: "ID numerico del marcador ArUco detectado (ej. 4).",
        apicam_qr: "Texto exacto que codifica el QR (distingue mayusculas/minusculas).",
        apicam_aruco: "Texto tal cual lo manda la API, ej. \"ID 4\".",
    };

    const moduleSelect = document.getElementById("module_select");
    const classSelect = document.getElementById("class_name_select");
    const classInput = document.getElementById("class_name_input");
    const classHint = document.getElementById("class_name_hint");
    const minConfidenceField = document.getElementById("min_confidence_field");
    const scaleFields = document.getElementById("scale_fields");
    const actionValueField = document.getElementById("action_value_field");
    const OLD_CLASS_NAME = <?= json_encode($editing['class_name'] ?? '') ?>;

    function refreshClassField() {
        const mod = moduleSelect.value;
        const classes = MODULE_CLASSES[mod];
        if (classes) {
            classSelect.style.display = "";
            classInput.style.display = "none";
            classHint.textContent = "";
            classSelect.innerHTML = "";
            classes.forEach((c) => {
                const opt = document.createElement("option");
                opt.value = c;
                opt.textContent = c;
                if (c === OLD_CLASS_NAME) opt.selected = true;
                classSelect.appendChild(opt);
            });
            classInput.value = classSelect.value;
        } else {
            classSelect.style.display = "none";
            classInput.style.display = "";
            classHint.textContent = FREE_TEXT_HINTS[mod] || "";
        }

        // Control de Pinza: escala continua en vez de confianza/valor fijo de salida
        const isPinch = mod === "pinch" || mod === "apicam_pinch";
        scaleFields.style.display = isPinch ? "block" : "none";
        minConfidenceField.style.display = isPinch ? "none" : "block";
        actionValueField.style.display = isPinch ? "none" : "block";

        // Pinza solo controla servos/PWM en Q8-Q11 (igual que en la app)
        const actionPinSelect = document.querySelector('select[name="action_pin"]');
        const pinchAllowedPins = ["Q8", "Q9", "Q10", "Q11"];
        let currentStillValid = false;
        Array.from(actionPinSelect.options).forEach((opt) => {
            const allowed = !isPinch || pinchAllowedPins.includes(opt.value);
            opt.hidden = !allowed;
            opt.disabled = !allowed;
            if (allowed && opt.selected) currentStillValid = true;
        });
        if (isPinch && !currentStillValid) {
            actionPinSelect.value = "Q8";
        }
    }

    classSelect.addEventListener("change", () => { classInput.value = classSelect.value; });
    moduleSelect.addEventListener("change", refreshClassField);
    refreshClassField();

    document.getElementById("mqtt_enabled").addEventListener("change", function () {
        document.getElementById("mqtt_fields").style.display = this.checked ? "block" : "none";
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
