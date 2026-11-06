<?php
/**
 * client/asociar_investigacion.php
 * -----------------------------------------------------------------------
 * Panel de INSTITUCION: que investigacion tiene ACTIVA cada Grupo. Desde
 * que un grupo queda asociado, todo lo que sus usuarios detecten y las
 * reglas que activen (api/log_inference.php) quedan etiquetadas con esa
 * investigacion (inference_events.id_investigacion).
 *
 * El historial de asociaciones se conserva (grupo_investigaciones): al
 * cambiar la investigacion de un grupo, la anterior queda con activa=0
 * en vez de borrarse.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $grupoId = (int) ($_POST['grupo_id'] ?? 0);
    $invId = (int) ($_POST['investigacion_id'] ?? 0); // 0 = "Ninguna"

    $stmt = db()->prepare("SELECT id FROM grupos WHERE id = ? AND client_id = ?");
    $stmt->execute([$grupoId, $client['id']]);
    if (!$stmt->fetch()) {
        flash('error', 'Grupo no encontrado.');
        redirect('/client/asociar_investigacion.php');
    }

    if ($invId) {
        $stmt = db()->prepare("SELECT id FROM investigaciones WHERE id = ? AND client_id = ?");
        $stmt->execute([$invId, $client['id']]);
        if (!$stmt->fetch()) {
            flash('error', 'Investigacion no encontrada.');
            redirect('/client/asociar_investigacion.php');
        }
    }

    // Se desactiva cualquier asociacion actual de ese grupo (se conserva
    // el historial, no se borra), y si se eligio una investigacion nueva
    // se activa (reactivando la fila si ya existia, o creando una).
    db()->prepare("UPDATE grupo_investigaciones SET activa = 0 WHERE grupo_id = ? AND activa = 1")
        ->execute([$grupoId]);

    if ($invId) {
        $stmt = db()->prepare("SELECT id FROM grupo_investigaciones WHERE grupo_id = ? AND investigacion_id = ?");
        $stmt->execute([$grupoId, $invId]);
        $existing = $stmt->fetch();
        if ($existing) {
            db()->prepare("UPDATE grupo_investigaciones SET activa = 1 WHERE id = ?")->execute([$existing['id']]);
        } else {
            db()->prepare("INSERT INTO grupo_investigaciones (grupo_id, investigacion_id, activa) VALUES (?, ?, 1)")
                ->execute([$grupoId, $invId]);
        }
        flash('success', 'Investigacion asociada al grupo.');
    } else {
        flash('success', 'Se quito la investigacion de ese grupo.');
    }
    redirect('/client/asociar_investigacion.php');
}

$stmt = db()->prepare("SELECT id, nombre FROM investigaciones WHERE client_id = ? ORDER BY fecha_inicio DESC");
$stmt->execute([$client['id']]);
$investigaciones = $stmt->fetchAll();

$stmt = db()->prepare("SELECT g.id, g.nombre, g.docente,
                                (SELECT COUNT(*) FROM users u WHERE u.grupo_id = g.id) AS total_usuarios,
                                gi.investigacion_id AS activa_id, i.nombre AS activa_nombre
                         FROM grupos g
                         LEFT JOIN grupo_investigaciones gi ON gi.grupo_id = g.id AND gi.activa = 1
                         LEFT JOIN investigaciones i ON i.id = gi.investigacion_id
                         WHERE g.client_id = ? ORDER BY g.nombre");
$stmt->execute([$client['id']]);
$grupos = $stmt->fetchAll();

$page_title = 'Asociar Investigacion';
$active_nav = 'client_asociar_investigacion';
require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <h3>Investigacion activa por grupo</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Desde que asocias una investigacion a un grupo, todo lo que sus
        usuarios detecten (y las reglas que activen) queda etiquetado con
        esa investigacion.
    </p>
    <table>
        <thead><tr><th>Grupo</th><th>Usuarios</th><th>Investigacion activa</th><th style="width:1%;white-space:nowrap;">Cambiar</th></tr></thead>
        <tbody>
        <?php foreach ($grupos as $g): ?>
            <tr>
                <td><?= htmlspecialchars($g['nombre']) ?></td>
                <td><?= (int) $g['total_usuarios'] ?></td>
                <td>
                    <?php if ($g['activa_nombre']): ?>
                        <strong><?= htmlspecialchars($g['activa_nombre']) ?></strong>
                    <?php else: ?>
                        <span style="color:var(--text-muted);">Ninguna</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <form method="post" style="display:flex;gap:6px;"
                          onsubmit="return confirm('¿Confirmas cambiar la investigacion activa del grupo «<?= htmlspecialchars(addslashes($g['nombre'])) ?>»?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="grupo_id" value="<?= (int) $g['id'] ?>">
                        <select name="investigacion_id" style="margin:0;">
                            <option value="0">-- Ninguna --</option>
                            <?php foreach ($investigaciones as $inv): ?>
                                <option value="<?= (int) $inv['id'] ?>" <?= $g['activa_id'] == $inv['id'] ? 'selected' : '' ?>><?= htmlspecialchars($inv['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$grupos): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--text-muted);">Todavia no creaste ningun grupo.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php if (!$investigaciones): ?>
        <p style="font-size:12px;color:var(--text-muted);margin-top:10px;">
            Todavia no creaste ninguna investigacion - anda a la pestaña
            <a href="<?= BASE_URL ?>/client/investigaciones.php">Investigacion</a> para crear la primera.
        </p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
