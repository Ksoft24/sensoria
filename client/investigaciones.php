<?php
/**
 * client/investigaciones.php
 * -----------------------------------------------------------------------
 * Panel de INSTITUCION: crear/editar/eliminar Investigaciones (nombre,
 * fecha de inicio/fin, descripcion). Una investigacion solo se puede
 * eliminar si NO tiene ningun evento relacionado en inference_events
 * (la FK inference_events.id_investigacion es ON DELETE RESTRICT, asi
 * que ademas de validarlo aca con un mensaje claro, la base de datos lo
 * impide igual si algo se escapa).
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear' || $action === 'editar') {
        $nombre      = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $fechaInicio = trim($_POST['fecha_inicio'] ?? '');
        $fechaFin    = trim($_POST['fecha_fin'] ?? '');

        $errors = [];
        if ($nombre === '') $errors[] = 'El nombre es obligatorio.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) $errors[] = 'Fecha de inicio invalida.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) $errors[] = 'Fecha de fin invalida.';
        if (!$errors && $fechaInicio > $fechaFin) $errors[] = 'La fecha de inicio no puede ser posterior a la de fin.';

        if ($errors) {
            flash('error', implode(' ', $errors));
        } elseif ($action === 'crear') {
            db()->prepare("INSERT INTO investigaciones (client_id, nombre, descripcion, fecha_inicio, fecha_fin) VALUES (?,?,?,?,?)")
                ->execute([$client['id'], $nombre, $descripcion ?: null, $fechaInicio, $fechaFin]);
            flash('success', 'Investigacion creada.');
        } else {
            $invId = (int) ($_POST['investigacion_id'] ?? 0);
            $stmt = db()->prepare("UPDATE investigaciones SET nombre=?, descripcion=?, fecha_inicio=?, fecha_fin=? WHERE id=? AND client_id=?");
            $stmt->execute([$nombre, $descripcion ?: null, $fechaInicio, $fechaFin, $invId, $client['id']]);
            flash('success', 'Investigacion actualizada.');
        }
    } elseif ($action === 'eliminar') {
        $invId = (int) ($_POST['investigacion_id'] ?? 0);

        $stmt = db()->prepare("SELECT id FROM investigaciones WHERE id = ? AND client_id = ?");
        $stmt->execute([$invId, $client['id']]);
        if (!$stmt->fetch()) {
            flash('error', 'Investigacion no encontrada.');
        } else {
            $stmt = db()->prepare("SELECT COUNT(*) FROM inference_events WHERE id_investigacion = ?");
            $stmt->execute([$invId]);
            if ((int) $stmt->fetchColumn() > 0) {
                flash('error', 'Esta investigacion ya tiene datos registrados (detecciones/reglas) y no se puede eliminar.');
            } else {
                db()->prepare("DELETE FROM investigaciones WHERE id = ? AND client_id = ?")->execute([$invId, $client['id']]);
                flash('success', 'Investigacion eliminada.');
            }
        }
    }
    redirect('/client/investigaciones.php');
}

$stmt = db()->prepare("SELECT i.*,
                                (SELECT COUNT(*) FROM investigacion_preguntas p WHERE p.investigacion_id = i.id) AS total_preguntas,
                                (SELECT COUNT(*) FROM inference_events ie WHERE ie.id_investigacion = i.id) AS total_eventos
                         FROM investigaciones i WHERE i.client_id = ? ORDER BY i.fecha_inicio DESC");
$stmt->execute([$client['id']]);
$investigaciones = $stmt->fetchAll();

$page_title = 'Investigacion';
$active_nav = 'client_investigaciones';
require __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-2">
    <div class="card">
        <h3>Nueva investigacion</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="crear">

            <label>Nombre</label>
            <input type="text" name="nombre" required>

            <div class="field-row">
                <div>
                    <label>Fecha de inicio</label>
                    <input type="date" name="fecha_inicio" required>
                </div>
                <div>
                    <label>Fecha de fin</label>
                    <input type="date" name="fecha_fin" required>
                </div>
            </div>

            <label>Descripcion</label>
            <input type="text" name="descripcion" placeholder="opcional">

            <button type="submit" class="btn btn-primary btn-block">Crear investigacion</button>
        </form>
    </div>

    <div class="card">
        <h3>¿Como funciona?</h3>
        <ul style="font-size:13px;color:var(--text-muted);padding-left:18px;line-height:1.8;">
            <li>Una investigacion se define con un nombre, un rango de fechas y una descripcion opcional.</li>
            <li>Despues la asocias a uno o mas Grupos en la pestaña <strong>Asociar Investigacion</strong>: todo lo que ese grupo detecte y las reglas que active quedaran etiquetadas con esa investigacion.</li>
            <li>En <strong>Preguntas Investigacion</strong> le agregas preguntas de escala Likert, que tus usuarios responderan desde su pestaña <strong>Encuestas</strong>.</li>
            <li>Una investigacion con datos ya registrados (detecciones/reglas) no se puede eliminar.</li>
        </ul>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Tus investigaciones</h3>
    <table>
        <thead>
        <tr><th>Nombre</th><th>Periodo</th><th>Preguntas</th><th>Eventos registrados</th><th style="width:1%;white-space:nowrap;">Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($investigaciones as $inv): ?>
            <tr>
                <td><?= htmlspecialchars($inv['nombre']) ?></td>
                <td><?= htmlspecialchars($inv['fecha_inicio']) ?> a <?= htmlspecialchars($inv['fecha_fin']) ?></td>
                <td><?= (int) $inv['total_preguntas'] ?></td>
                <td><?= (int) $inv['total_eventos'] ?></td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-ghost btn-sm btn-editar-inv"
                            data-id="<?= (int) $inv['id'] ?>"
                            data-nombre="<?= htmlspecialchars($inv['nombre'], ENT_QUOTES) ?>"
                            data-descripcion="<?= htmlspecialchars($inv['descripcion'] ?? '', ENT_QUOTES) ?>"
                            data-inicio="<?= htmlspecialchars($inv['fecha_inicio']) ?>"
                            data-fin="<?= htmlspecialchars($inv['fecha_fin']) ?>">
                        Editar
                    </button>
                    <?php if ((int) $inv['total_eventos'] === 0): ?>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('¿Eliminar la investigacion «<?= htmlspecialchars(addslashes($inv['nombre'])) ?>»? Esto tambien borra sus preguntas y respuestas.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="eliminar">
                        <input type="hidden" name="investigacion_id" value="<?= (int) $inv['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                    </form>
                    <?php else: ?>
                    <button type="button" class="btn btn-danger btn-sm" disabled title="Tiene datos registrados">Eliminar</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$investigaciones): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted);">Todavia no creaste ninguna investigacion.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ===================== Modal: editar investigacion ===================== -->
<div id="modal-editar-inv" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3 style="margin:0;">Editar investigacion</h3>
            <button type="button" class="modal-close" id="modal-editar-inv-close">&times;</button>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="investigacion_id" id="edit_inv_id">

            <label>Nombre</label>
            <input type="text" name="nombre" id="edit_inv_nombre" required>

            <div class="field-row">
                <div>
                    <label>Fecha de inicio</label>
                    <input type="date" name="fecha_inicio" id="edit_inv_inicio" required>
                </div>
                <div>
                    <label>Fecha de fin</label>
                    <input type="date" name="fecha_fin" id="edit_inv_fin" required>
                </div>
            </div>

            <label>Descripcion</label>
            <input type="text" name="descripcion" id="edit_inv_descripcion" placeholder="opcional">

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px;">
                <button type="button" class="btn btn-ghost" id="modal-editar-inv-cancel">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    var modalEditarInv = document.getElementById('modal-editar-inv');

    function abrirModalEditarInv(btn) {
        document.getElementById('edit_inv_id').value = btn.dataset.id;
        document.getElementById('edit_inv_nombre').value = btn.dataset.nombre;
        document.getElementById('edit_inv_descripcion').value = btn.dataset.descripcion;
        document.getElementById('edit_inv_inicio').value = btn.dataset.inicio;
        document.getElementById('edit_inv_fin').value = btn.dataset.fin;
        modalEditarInv.style.display = 'flex';
    }

    document.querySelectorAll('.btn-editar-inv').forEach(function (btn) {
        btn.addEventListener('click', function () { abrirModalEditarInv(btn); });
    });

    function cerrarModalEditarInv() { modalEditarInv.style.display = 'none'; }
    document.getElementById('modal-editar-inv-close').addEventListener('click', cerrarModalEditarInv);
    document.getElementById('modal-editar-inv-cancel').addEventListener('click', cerrarModalEditarInv);
    modalEditarInv.addEventListener('click', function (e) {
        if (e.target === modalEditarInv) cerrarModalEditarInv();
    });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
