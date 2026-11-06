<?php
/**
 * encuestas.php
 * -----------------------------------------------------------------------
 * Panel de USUARIO: responde las preguntas de escala Likert de la
 * investigacion que su Grupo tenga ACTIVA (client/asociar_investigacion.php).
 * Una vez respondida una pregunta, queda bloqueada para siempre (no se
 * puede editar) - la UNIQUE (pregunta_id, user_id) en la base de datos
 * lo impide ademas a nivel de datos.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();

// La investigacion ACTIVA del grupo del usuario (si tiene grupo y esa
// investigacion tiene asociacion activa).
$investigacion = null;
if ($user['grupo_id']) {
    $stmt = db()->prepare("SELECT i.* FROM grupo_investigaciones gi
                             JOIN investigaciones i ON i.id = gi.investigacion_id
                             WHERE gi.grupo_id = ? AND gi.activa = 1 LIMIT 1");
    $stmt->execute([$user['grupo_id']]);
    $investigacion = $stmt->fetch() ?: null;
}

$preguntas = [];
$respuestasPrevias = [];
$yaCompleto = false;

if ($investigacion) {
    $stmt = db()->prepare("SELECT * FROM investigacion_preguntas WHERE investigacion_id = ? ORDER BY orden, id");
    $stmt->execute([$investigacion['id']]);
    $preguntas = $stmt->fetchAll();

    if ($preguntas) {
        $stmt = db()->prepare("SELECT pregunta_id, valor FROM investigacion_respuestas WHERE investigacion_id = ? AND user_id = ?");
        $stmt->execute([$investigacion['id'], $user['id']]);
        foreach ($stmt->fetchAll() as $r) {
            $respuestasPrevias[(int) $r['pregunta_id']] = (int) $r['valor'];
        }
        $yaCompleto = count($respuestasPrevias) >= count($preguntas);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $investigacion && !$yaCompleto) {
    csrf_verify();
    $respuestas = $_POST['respuesta'] ?? [];

    $faltantes = [];
    foreach ($preguntas as $p) {
        if (!isset($respuestas[$p['id']]) || $respuestas[$p['id']] === '') {
            $faltantes[] = $p['texto'];
        }
    }

    if ($faltantes) {
        flash('error', 'Falta responder: ' . implode(', ', $faltantes));
    } else {
        foreach ($preguntas as $p) {
            $etiquetas = json_decode($p['etiquetas_json'], true) ?: [];
            $valor = (int) $respuestas[$p['id']];
            if ($valor < 1 || $valor > count($etiquetas)) continue; // valor fuera de rango: se ignora esa pregunta

            // INSERT IGNORE respeta la UNIQUE (pregunta_id, user_id): si
            // por algun motivo ya existia una respuesta, no la pisa.
            db()->prepare("INSERT IGNORE INTO investigacion_respuestas (investigacion_id, pregunta_id, user_id, valor) VALUES (?,?,?,?)")
                ->execute([$investigacion['id'], $p['id'], $user['id'], $valor]);
        }
        flash('success', 'Gracias, tus respuestas quedaron registradas.');
        redirect('/encuestas.php');
    }
}

$page_title = 'Encuestas';
$active_nav = 'encuestas';
require __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h2 style="margin:0;">Encuestas</h2>
    <p style="color:var(--text-muted);font-size:13px;margin:6px 0 0;">
        Preguntas de la investigacion en la que participa tu grupo.
    </p>
</div>

<?php if (!$investigacion): ?>
<div class="card" style="margin-top:16px;text-align:center;color:var(--text-muted);padding:40px;">
    Tu grupo no tiene ninguna investigacion activa en este momento.
</div>
<?php elseif (!$preguntas): ?>
<div class="card" style="margin-top:16px;text-align:center;color:var(--text-muted);padding:40px;">
    La investigacion "<?= htmlspecialchars($investigacion['nombre']) ?>" todavia no tiene preguntas cargadas.
</div>
<?php else: ?>

<div class="card" style="margin-top:16px;">
    <h3 style="margin:0 0 6px;"><?= htmlspecialchars($investigacion['nombre']) ?></h3>
    <?php if ($investigacion['descripcion']): ?>
        <p style="color:var(--text-muted);font-size:13px;"><?= nl2br(htmlspecialchars($investigacion['descripcion'])) ?></p>
    <?php endif; ?>

    <?php if ($yaCompleto): ?>
        <p style="color:var(--ok);font-size:13px;font-weight:700;">✓ Ya respondiste esta encuesta. Estas son tus respuestas:</p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <?php foreach ($preguntas as $i => $p): ?>
            <?php
                $etiquetas = json_decode($p['etiquetas_json'], true) ?: [];
                $valorPrevio = $respuestasPrevias[$p['id']] ?? null;
            ?>
            <div style="padding:16px 0;<?= $i > 0 ? 'border-top:1px solid var(--border);' : '' ?>">
                <label style="font-weight:700;"><?= $i + 1 ?>. <?= htmlspecialchars($p['texto']) ?></label>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;">
                    <?php foreach ($etiquetas as $idx => $etiqueta): ?>
                        <?php $val = $idx + 1; ?>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px;<?= $yaCompleto ? 'opacity:.8;' : '' ?>">
                            <input type="radio" name="respuesta[<?= (int) $p['id'] ?>]" value="<?= $val ?>"
                                   <?= $valorPrevio === $val ? 'checked' : '' ?> <?= $yaCompleto ? 'disabled' : '' ?> required>
                            <?= htmlspecialchars($etiqueta) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$yaCompleto): ?>
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:10px;"
                    onclick="return confirm('Una vez enviadas, tus respuestas no se podran editar. ¿Confirmas enviar?');">
                Enviar respuestas
            </button>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
