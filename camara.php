<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $deviceId = trim($_POST['device_id'] ?? '');
    $label    = trim($_POST['label'] ?? '');
    $widthH   = (int) ($_POST['width_h'] ?? 1280);
    $heightH  = (int) ($_POST['height_h'] ?? 720);
    $widthV   = (int) ($_POST['width_v'] ?? 720);
    $heightV  = (int) ($_POST['height_v'] ?? 1280);

    db()->prepare("INSERT INTO camera_config (user_id, device_id, label, width_h, height_h, width_v, height_v)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE device_id=VALUES(device_id), label=VALUES(label),
                        width_h=VALUES(width_h), height_h=VALUES(height_h),
                        width_v=VALUES(width_v), height_v=VALUES(height_v)")
        ->execute([$user['id'], $deviceId, $label, $widthH, $heightH, $widthV, $heightV]);

    flash('success', 'Camara guardada. Se usara en todos los modulos ONNX, adaptando el tamaño segun la orientacion del dispositivo.');
    redirect('/camara.php');
}

$stmt = db()->prepare("SELECT * FROM camera_config WHERE user_id = ?");
$stmt->execute([$user['id']]);
$cfg = $stmt->fetch() ?: [
    'device_id' => '', 'label' => '',
    'width_h' => 1280, 'height_h' => 720,
    'width_v' => 720, 'height_v' => 1280,
];

$page_title = 'Camara';
$active_nav = 'camera';
require __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width:640px;">
    <h3>Camara</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Elige que camara usaran los modulos de vision, y el tamaño de
        imagen para cada orientacion del dispositivo. La app detecta sola
        si estas en vertical u horizontal (celular, tablet, iPad) y usa
        el par de valores correspondiente — no hace falta elegir a mano.
    </p>

    <div id="preview" class="camera-box fill" style="max-width:420px;margin:0 auto 16px;">
        <video id="preview-video" autoplay muted playsinline></video>
    </div>

    <form method="post" id="camera-form">
        <?= csrf_field() ?>
        <label>Dispositivo</label>
        <select name="device_id" id="device-select"></select>
        <input type="hidden" name="label" id="device-label">

        <p style="font-weight:700;margin:18px 0 4px;">Dimension Horizontal</p>
        <p style="font-size:12px;color:var(--text-muted);margin-top:0;">Se usa cuando el dispositivo esta acostado (landscape).</p>
        <div class="field-row">
            <div>
                <label>Ancho</label>
                <input type="number" name="width_h" id="width_h" value="<?= (int) $cfg['width_h'] ?>">
            </div>
            <div>
                <label>Alto</label>
                <input type="number" name="height_h" id="height_h" value="<?= (int) $cfg['height_h'] ?>">
            </div>
        </div>

        <p style="font-weight:700;margin:14px 0 4px;">Dimension Vertical</p>
        <p style="font-size:12px;color:var(--text-muted);margin-top:0;">Se usa cuando el dispositivo esta parado (portrait) — celular normal.</p>
        <div class="field-row">
            <div>
                <label>Ancho</label>
                <input type="number" name="width_v" id="width_v" value="<?= (int) $cfg['width_v'] ?>">
            </div>
            <div>
                <label>Alto</label>
                <input type="number" name="height_v" id="height_v" value="<?= (int) $cfg['height_v'] ?>">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Guardar</button>
    </form>
</div>

<script src="<?= BASE_URL ?>/assets/js/camera-orientation.js"></script>
<script>
    const savedDeviceId = <?= json_encode($cfg['device_id']) ?>;
    const select = document.getElementById('device-select');
    const video = document.getElementById('preview-video');
    let currentStream = null;

    function currentConfig() {
        return {
            device_id: select.value,
            width_h: parseInt(document.getElementById('width_h').value, 10) || 1280,
            height_h: parseInt(document.getElementById('height_h').value, 10) || 720,
            width_v: parseInt(document.getElementById('width_v').value, 10) || 720,
            height_v: parseInt(document.getElementById('height_v').value, 10) || 1280,
        };
    }

    async function listCameras() {
        try {
            const tmp = await navigator.mediaDevices.getUserMedia({ video: true });
            tmp.getTracks().forEach(t => t.stop());
        } catch (e) { /* seguimos igual, puede que ya haya permiso */ }

        const devices = await navigator.mediaDevices.enumerateDevices();
        const cams = devices.filter(d => d.kind === 'videoinput');

        select.innerHTML = '';
        cams.forEach((cam, i) => {
            const opt = document.createElement('option');
            opt.value = cam.deviceId;
            opt.textContent = cam.label || ('Camara ' + (i + 1));
            if (cam.deviceId === savedDeviceId) opt.selected = true;
            select.appendChild(opt);
        });

        if (cams.length) startPreview();
    }

    async function startPreview() {
        if (currentStream) currentStream.getTracks().forEach(t => t.stop());
        const camOrient = new CameraOrientation(currentConfig());
        currentStream = await navigator.mediaDevices.getUserMedia(camOrient.constraints());
        video.srcObject = currentStream;
        const opt = select.options[select.selectedIndex];
        document.getElementById('device-label').value = opt ? opt.textContent : '';
    }

    select.addEventListener('change', startPreview);
    ['width_h', 'height_h', 'width_v', 'height_v'].forEach((id) => {
        document.getElementById(id).addEventListener('change', startPreview);
    });

    // Si el usuario gira el dispositivo mientras esta en esta pantalla,
    // la vista previa se re-arma sola con el par de valores correcto.
    const camOrientWatcher = new CameraOrientation(currentConfig());
    camOrientWatcher.onChange(() => startPreview());

    listCameras();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
