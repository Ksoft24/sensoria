<?php
/**
 * Stub reutilizable para un modulo de vision que todavia no tiene su
 * detector JS implementado. Deja listo camara + hardware-bridge +
 * rules-engine; solo falta:
 *   1) Subir el .onnx a modules/assets/<slug>/model.onnx
 *   2) Escribir modules/assets/<slug>/<slug>-detector.js con una clase
 *      que exponga: async loadModel(url), async detect(videoEl) ->
 *      {detections, inferenceMs} (detections: [{left,top,width,height,score,classId}]
 *      o el formato que tu logica de dibujo espere).
 *   3) Reemplazar este include por una copia de modules/yolo.php adaptada
 *      (es el modulo ya funcional, sirve de ejemplo de referencia).
 *
 * Variables esperadas antes de incluir este archivo:
 *   $module_slug, $module_title, $module_notes (array de strings)
 */
require_once __DIR__ . '/functions.php';
$user = require_login();

$page_title = $module_title;
$active_nav = $module_slug;
require __DIR__ . '/header.php';
?>

<div class="card">
    <h3><?= htmlspecialchars($module_title) ?> <span class="badge badge-warn">En construccion</span></h3>
    <p style="color:var(--text-muted);">
        Este modulo ya tiene lista la infraestructura comun (camara, hardware-bridge.js,
        rules-engine.js, editor de reglas), solo falta conectar su detector ONNX especifico.
    </p>
    <ul style="color:var(--text-muted);font-size:14px;">
        <?php foreach ($module_notes as $note): ?>
            <li><?= htmlspecialchars($note) ?></li>
        <?php endforeach; ?>
        <li>Usa <code>modules/yolo.php</code> como referencia de un modulo ya funcional (deteccion de objetos).</li>
    </ul>

    <div class="camera-box" style="max-width:480px;margin-top:16px;">
        <video id="video" autoplay playsinline muted></video>
    </div>
</div>

<script>
    (async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: true });
            document.getElementById('video').srcObject = stream;
        } catch (err) {
            console.error('No se pudo acceder a la camara:', err);
        }
    })();
</script>

<?php require __DIR__ . '/footer.php'; ?>
