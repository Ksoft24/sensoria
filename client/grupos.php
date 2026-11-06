<?php
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');
        $doc    = trim($_POST['docente'] ?? '');
        if ($nombre === '') {
            flash('error', 'El nombre del grupo es obligatorio.');
        } else {
            db()->prepare("INSERT INTO grupos (client_id, nombre, descripcion, docente) VALUES (?,?,?,?)")
                ->execute([$client['id'], $nombre, $desc ?: null, $doc ?: null]);
            flash('success', 'Grupo creado.');
        }
    } elseif ($action === 'editar') {
        $gid    = (int) ($_POST['grupo_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');
        $doc    = trim($_POST['docente'] ?? '');
        if ($nombre === '') {
            flash('error', 'El nombre del grupo es obligatorio.');
        } else {
            db()->prepare("UPDATE grupos SET nombre=?, descripcion=?, docente=? WHERE id=? AND client_id=?")
                ->execute([$nombre, $desc ?: null, $doc ?: null, $gid, $client['id']]);
            flash('success', 'Grupo actualizado.');
        }
    } elseif ($action === 'eliminar') {
        $gid = (int) ($_POST['grupo_id'] ?? 0);
        $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE grupo_id = ?");
        $stmt->execute([$gid]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash('error', 'No puedes eliminar un grupo que todavia tiene usuarios asociados. Cambialos de grupo primero.');
        } else {
            db()->prepare("DELETE FROM grupos WHERE id = ? AND client_id = ?")->execute([$gid, $client['id']]);
            flash('success', 'Grupo eliminado.');
        }
    }
    redirect('/client/grupos.php');
}

$stmt = db()->prepare("SELECT g.*, (SELECT COUNT(*) FROM users u WHERE u.grupo_id = g.id) AS total_usuarios
                        FROM grupos g WHERE g.client_id = ? ORDER BY g.nombre");
$stmt->execute([$client['id']]);
$grupos = $stmt->fetchAll();

$page_title = 'Grupos';
$active_nav = 'client_grupos';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin-bottom:20px;">
    <h3>Crear grupo / salon</h3>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="crear">
        <div style="flex:1;min-width:160px;">
            <label>Nombre</label>
            <input type="text" name="nombre" required placeholder="ej: 5to A">
        </div>
        <div style="flex:1;min-width:160px;">
            <label>Descripcion</label>
            <input type="text" name="descripcion" placeholder="opcional">
        </div>
        <div style="flex:1;min-width:160px;">
            <label>Docente</label>
            <input type="text" name="docente" placeholder="opcional">
        </div>
        <button type="submit" class="btn btn-primary" style="height:42px;">Crear grupo</button>
    </form>
</div>

<div class="card">
    <h3>Tus grupos</h3>
    <table>
        <thead>
        <tr><th>Nombre</th><th>Docente</th><th>Descripcion</th><th>Usuarios</th><th style="width:1%;white-space:nowrap;">Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($grupos as $g): ?>
            <tr>
                <td><?= htmlspecialchars($g['nombre']) ?></td>
                <td><?= htmlspecialchars($g['docente'] ?? '-') ?></td>
                <td><?= htmlspecialchars($g['descripcion'] ?? '-') ?></td>
                <td><?= (int) $g['total_usuarios'] ?></td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-ghost btn-sm btn-editar-grupo"
                            data-id="<?= (int) $g['id'] ?>"
                            data-nombre="<?= htmlspecialchars($g['nombre'], ENT_QUOTES) ?>"
                            data-docente="<?= htmlspecialchars($g['docente'] ?? '', ENT_QUOTES) ?>"
                            data-descripcion="<?= htmlspecialchars($g['descripcion'] ?? '', ENT_QUOTES) ?>">
                        Editar
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$grupos): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted);">Todavia no creaste ningun grupo.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top:14px;">
<?php foreach ($grupos as $g): ?>
    <?php if ((int) $g['total_usuarios'] === 0): ?>
    <form method="post" style="display:inline;" class="grupo-delete-form"
          onsubmit="return confirm('¿Eliminar el grupo «<?= htmlspecialchars(addslashes($g['nombre'])) ?>»? Esta accion no se puede deshacer.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="eliminar">
        <input type="hidden" name="grupo_id" value="<?= (int) $g['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="margin:4px 4px 0 0;">Eliminar «<?= htmlspecialchars($g['nombre']) ?>»</button>
    </form>
    <?php endif; ?>
<?php endforeach; ?>
<?php if ($grupos): ?>
    <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">
        Solo se puede eliminar un grupo cuando ya no tiene ningun usuario asociado.
    </p>
<?php endif; ?>
</div>

<!-- ===================== Modal: editar grupo ===================== -->
<div id="modal-editar-grupo" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3 style="margin:0;">Editar grupo</h3>
            <button type="button" class="modal-close" id="modal-editar-grupo-close">&times;</button>
        </div>
        <form method="post" onsubmit="return confirm('¿Confirmas guardar los cambios de este grupo?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="grupo_id" id="edit_grupo_id">

            <label>Nombre</label>
            <input type="text" name="nombre" id="edit_grupo_nombre" required>

            <label>Docente</label>
            <input type="text" name="docente" id="edit_grupo_docente" placeholder="opcional">

            <label>Descripcion</label>
            <input type="text" name="descripcion" id="edit_grupo_descripcion" placeholder="opcional">

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px;">
                <button type="button" class="btn btn-ghost" id="modal-editar-grupo-cancel">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    var modalEditarGrupo = document.getElementById('modal-editar-grupo');

    function abrirModalEditarGrupo(btn) {
        document.getElementById('edit_grupo_id').value = btn.dataset.id;
        document.getElementById('edit_grupo_nombre').value = btn.dataset.nombre;
        document.getElementById('edit_grupo_docente').value = btn.dataset.docente;
        document.getElementById('edit_grupo_descripcion').value = btn.dataset.descripcion;
        modalEditarGrupo.style.display = 'flex';
    }

    document.querySelectorAll('.btn-editar-grupo').forEach(function (btn) {
        btn.addEventListener('click', function () { abrirModalEditarGrupo(btn); });
    });

    function cerrarModalEditarGrupo() { modalEditarGrupo.style.display = 'none'; }
    document.getElementById('modal-editar-grupo-close').addEventListener('click', cerrarModalEditarGrupo);
    document.getElementById('modal-editar-grupo-cancel').addEventListener('click', cerrarModalEditarGrupo);
    modalEditarGrupo.addEventListener('click', function (e) {
        if (e.target === modalEditarGrupo) cerrarModalEditarGrupo();
    });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
