<?php
/**
 * client/investigacion_preguntas.php
 * -----------------------------------------------------------------------
 * Panel de INSTITUCION: preguntas de escala Likert de una investigacion.
 * Por defecto se ofrece la escala Likert de 5 puntos estandar, pero cada
 * pregunta puede personalizar el texto de cada punto de su escala.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

const DEFAULT_LIKERT = [
    'Totalmente en desacuerdo', 'En desacuerdo', 'Neutral', 'De acuerdo', 'Totalmente de acuerdo',
];

$stmt = db()->prepare("SELECT id, nombre FROM investigaciones WHERE client_id = ? ORDER BY fecha_inicio DESC");
$stmt->execute([$client['id']]);
$investigaciones = $stmt->fetchAll();

$invId = isset($_GET['investigacion_id']) && ctype_digit((string) $_GET['investigacion_id']) ? (int) $_GET['investigacion_id'] : 0;
if (!$invId && $investigaciones) $invId = (int) $investigaciones[0]['id'];
if ($invId && !in_array($invId, array_column($investigaciones, 'id'), true)) $invId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $postInvId = (int) ($_POST['investigacion_id'] ?? 0);

    // Siempre se re-valida que la investigacion sea de esta institucion.
    $stmt = db()->prepare("SELECT id FROM investigaciones WHERE id = ? AND client_id = ?");
    $stmt->execute([$postInvId, $client['id']]);
    if (!$stmt->fetch()) {
        flash('error', 'Investigacion no encontrada.');
        redirect('/client/investigacion_preguntas.php');
    }

    if ($action === 'agregar' || $action === 'editar') {
        $texto = trim($_POST['texto'] ?? '');
        $etiquetas = [];
        foreach ($_POST['etiquetas'] ?? [] as $et) {
            $et = trim((string) $et);
            if ($et !== '') $etiquetas[] = $et;
        }
        if ($texto === '') {
            flash('error', 'El texto de la pregunta es obligatorio.');
        } elseif (count($etiquetas) < 2) {
            flash('error', 'La escala necesita al menos 2 opciones.');
        } else {
            $etiquetasJson = json_encode(array_values($etiquetas));
            if ($action === 'agregar') {
                $stmt = db()->prepare("SELECT COALESCE(MAX(orden), 0) + 1 FROM investigacion_preguntas WHERE investigacion_id = ?");
                $stmt->execute([$postInvId]);
                $orden = (int) $stmt->fetchColumn();
                db()->prepare("INSERT INTO investigacion_preguntas (investigacion_id, texto, orden, etiquetas_json) VALUES (?,?,?,?)")
                    ->execute([$postInvId, $texto, $orden, $etiquetasJson]);
                flash('success', 'Pregunta agregada.');
            } else {
                $pregId = (int) ($_POST['pregunta_id'] ?? 0);
                $stmt = db()->prepare("UPDATE investigacion_preguntas SET texto=?, etiquetas_json=? WHERE id=? AND investigacion_id=?");
                $stmt->execute([$texto, $etiquetasJson, $pregId, $postInvId]);
                flash('success', 'Pregunta actualizada.');
            }
        }
    } elseif ($action === 'eliminar') {
        $pregId = (int) ($_POST['pregunta_id'] ?? 0);
        $stmt = db()->prepare("SELECT COUNT(*) FROM investigacion_respuestas WHERE pregunta_id = ?");
        $stmt->execute([$pregId]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash('error', 'Esta pregunta ya tiene respuestas y no se puede eliminar.');
        } else {
            db()->prepare("DELETE FROM investigacion_preguntas WHERE id = ? AND investigacion_id = ?")->execute([$pregId, $postInvId]);
            flash('success', 'Pregunta eliminada.');
        }
    }
    redirect('/client/investigacion_preguntas.php?investigacion_id=' . $postInvId);
}

$preguntas = [];
if ($invId) {
    $stmt = db()->prepare("SELECT p.*, (SELECT COUNT(*) FROM investigacion_respuestas r WHERE r.pregunta_id = p.id) AS total_respuestas
                             FROM investigacion_preguntas p WHERE p.investigacion_id = ? ORDER BY p.orden, p.id");
    $stmt->execute([$invId]);
    $preguntas = $stmt->fetchAll();
}

$page_title = 'Preguntas Investigacion';
$active_nav = 'client_investigacion_preguntas';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin-bottom:20px;">
    <label>Investigacion</label>
    <select id="select-investigacion" onchange="location.href='?investigacion_id=' + this.value">
        <?php foreach ($investigaciones as $inv): ?>
            <option value="<?= (int) $inv['id'] ?>" <?= $invId === (int) $inv['id'] ? 'selected' : '' ?>><?= htmlspecialchars($inv['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (!$investigaciones): ?>
        <p style="font-size:12px;color:var(--text-muted);margin-top:10px;">
            Todavia no creaste ninguna investigacion - anda a la pestaña
            <a href="<?= BASE_URL ?>/client/investigaciones.php">Investigacion</a> para crear la primera.
        </p>
    <?php endif; ?>
</div>

<?php if ($invId): ?>
<div class="grid grid-2">
    <div class="card">
        <h3>Nueva pregunta</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="agregar">
            <input type="hidden" name="investigacion_id" value="<?= $invId ?>">

            <label>Texto de la pregunta</label>
            <input type="text" name="texto" required placeholder="Ej: Me resulto facil usar la plataforma">

            <label>Escala (edita los textos si queres personalizarla)</label>
            <?php foreach (DEFAULT_LIKERT as $i => $label): ?>
                <input type="text" name="etiquetas[]" value="<?= htmlspecialchars($label) ?>" style="margin-bottom:6px;">
            <?php endforeach; ?>

            <button type="submit" class="btn btn-primary btn-block">Agregar pregunta</button>
        </form>
    </div>

    <div class="card">
        <h3>¿Como funciona?</h3>
        <ul style="font-size:13px;color:var(--text-muted);padding-left:18px;line-height:1.8;">
            <li>Cada pregunta usa una escala tipo Likert (por defecto 5 puntos, de "Totalmente en desacuerdo" a "Totalmente de acuerdo").</li>
            <li>Podes cambiar el texto de cada punto de la escala, o quitar/agregar opciones (minimo 2).</li>
            <li>Tus usuarios responderan estas preguntas desde su pestaña <strong>Encuestas</strong>.</li>
            <li>Una vez que una pregunta tiene respuestas, no se puede eliminar (si se puede seguir editando el texto).</li>
        </ul>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Preguntas de esta investigacion</h3>
    <table>
        <thead><tr><th>#</th><th>Pregunta</th><th>Escala</th><th>Respuestas</th><th style="width:1%;white-space:nowrap;">Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($preguntas as $i => $p): ?>
            <?php $etiquetas = json_decode($p['etiquetas_json'], true) ?: []; ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($p['texto']) ?></td>
                <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars(implode(' / ', $etiquetas)) ?></td>
                <td><?= (int) $p['total_respuestas'] ?></td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-ghost btn-sm btn-editar-preg"
                            data-id="<?= (int) $p['id'] ?>"
                            data-texto="<?= htmlspecialchars($p['texto'], ENT_QUOTES) ?>"
                            data-etiquetas='<?= htmlspecialchars($p['etiquetas_json'], ENT_QUOTES) ?>'>
                        Editar
                    </button>
                    <?php if ((int) $p['total_respuestas'] === 0): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar esta pregunta?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="eliminar">
                        <input type="hidden" name="investigacion_id" value="<?= $invId ?>">
                        <input type="hidden" name="pregunta_id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                    </form>
                    <?php else: ?>
                    <button type="button" class="btn btn-danger btn-sm" disabled title="Tiene respuestas">Eliminar</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$preguntas): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted);">Esta investigacion todavia no tiene preguntas.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ===================== Modal: editar pregunta ===================== -->
<div id="modal-editar-preg" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3 style="margin:0;">Editar pregunta</h3>
            <button type="button" class="modal-close" id="modal-editar-preg-close">&times;</button>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="investigacion_id" value="<?= $invId ?>">
            <input type="hidden" name="pregunta_id" id="edit_preg_id">

            <label>Texto de la pregunta</label>
            <input type="text" name="texto" id="edit_preg_texto" required>

            <label>Escala</label>
            <div id="edit_preg_etiquetas"></div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px;">
                <button type="button" class="btn btn-ghost" id="modal-editar-preg-cancel">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    var modalEditarPreg = document.getElementById('modal-editar-preg');
    var etiquetasContainer = document.getElementById('edit_preg_etiquetas');

    function abrirModalEditarPreg(btn) {
        document.getElementById('edit_preg_id').value = btn.dataset.id;
        document.getElementById('edit_preg_texto').value = btn.dataset.texto;
        var etiquetas = JSON.parse(btn.dataset.etiquetas || '[]');
        etiquetasContainer.innerHTML = '';
        etiquetas.forEach(function (et) {
            var input = document.createElement('input');
            input.type = 'text';
            input.name = 'etiquetas[]';
            input.value = et;
            input.style.marginBottom = '6px';
            etiquetasContainer.appendChild(input);
        });
        modalEditarPreg.style.display = 'flex';
    }

    document.querySelectorAll('.btn-editar-preg').forEach(function (btn) {
        btn.addEventListener('click', function () { abrirModalEditarPreg(btn); });
    });

    function cerrarModalEditarPreg() { modalEditarPreg.style.display = 'none'; }
    document.getElementById('modal-editar-preg-close').addEventListener('click', cerrarModalEditarPreg);
    document.getElementById('modal-editar-preg-cancel').addEventListener('click', cerrarModalEditarPreg);
    modalEditarPreg.addEventListener('click', function (e) {
        if (e.target === modalEditarPreg) cerrarModalEditarPreg();
    });
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
