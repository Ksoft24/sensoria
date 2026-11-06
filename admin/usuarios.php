<?php
/**
 * admin/usuarios.php
 * -----------------------------------------------------------------------
 * Bajo el modelo de licenciamiento institucional, aprobar/suspender
 * usuarios es tarea del CLIENTE (institucion) en /client/usuarios.php.
 * Esta pantalla es una vista de SOPORTE/SUPERVISION para el admin de la
 * plataforma: ver todos los usuarios de todos los clientes, y una
 * accion de suspension de emergencia (ej. abuso, cuenta comprometida)
 * que no depende de que el cliente este disponible.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $uid    = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'suspender') {
        db()->prepare("UPDATE users SET estado = 'suspended' WHERE id = ? AND es_admin = 0")->execute([$uid]);
        flash('success', 'Usuario suspendido.');
    } elseif ($action === 'reactivar') {
        db()->prepare("UPDATE users SET estado = 'active' WHERE id = ? AND es_admin = 0")->execute([$uid]);
        flash('success', 'Usuario reactivado.');
    }
    redirect('/admin/usuarios.php');
}

$filtroClient = (int) ($_GET['client_id'] ?? 0);
$filtroEstado = $_GET['estado'] ?? '';

$sql = "SELECT u.*, c.nombre AS client_nombre, g.nombre AS grupo_nombre
        FROM users u
        LEFT JOIN clients c ON c.id = u.client_id
        LEFT JOIN grupos g ON g.id = u.grupo_id
        WHERE u.es_admin = 0";
$params = [];
if ($filtroClient) {
    $sql .= " AND u.client_id = ?";
    $params[] = $filtroClient;
}
if ($filtroEstado) {
    $sql .= " AND u.estado = ?";
    $params[] = $filtroEstado;
}
$sql .= " ORDER BY u.creado_en DESC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

$clientes = db()->query("SELECT id, nombre FROM clients ORDER BY nombre")->fetchAll();

$page_title = 'Usuarios (todos)';
$active_nav = 'admin_usuarios';
require __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    Aprobar y suspender usuarios normalmente lo hace cada
    <strong>institucion</strong> desde su propio panel. Aqui puedes ver
    todos los usuarios de todas las instituciones y suspender de
    emergencia si hace falta.
</div>

<div class="card" style="margin-bottom:20px;">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label>Cliente</label>
            <select name="client_id" style="margin:0;">
                <option value="0">Todos</option>
                <?php foreach ($clientes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $filtroClient === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Estado</label>
            <select name="estado" style="margin:0;">
                <option value="">Todos</option>
                <option value="pending_email" <?= $filtroEstado === 'pending_email' ? 'selected' : '' ?>>Pendiente correo</option>
                <option value="pending_client" <?= $filtroEstado === 'pending_client' ? 'selected' : '' ?>>Pendiente institucion</option>
                <option value="active" <?= $filtroEstado === 'active' ? 'selected' : '' ?>>Activo</option>
                <option value="suspended" <?= $filtroEstado === 'suspended' ? 'selected' : '' ?>>Suspendido</option>
                <option value="rejected" <?= $filtroEstado === 'rejected' ? 'selected' : '' ?>>Rechazado</option>
            </select>
        </div>
        <button type="submit" class="btn btn-ghost">Filtrar</button>
    </form>
</div>

<div class="card">
    <table>
        <thead>
        <tr><th>Nombre</th><th>Correo</th><th>Institucion</th><th>Grupo</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nombres'] . ' ' . $u['apellidos']) ?></td>
                <td><?= htmlspecialchars($u['correo']) ?></td>
                <td><?= htmlspecialchars($u['client_nombre'] ?? '-') ?></td>
                <td><?= htmlspecialchars($u['grupo_nombre'] ?? '-') ?></td>
                <td>
                    <?php $badge = ['pending_email'=>'muted','pending_client'=>'warn','active'=>'ok','suspended'=>'danger','rejected'=>'danger'][$u['estado']] ?? 'muted'; ?>
                    <span class="badge badge-<?= $badge ?>"><?= estado_label($u['estado']) ?></span>
                </td>
                <td>
                    <?php if ($u['estado'] === 'active'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="action" value="suspender">
                            <button class="btn btn-danger btn-sm" type="submit">Suspender</button>
                        </form>
                    <?php elseif ($u['estado'] === 'suspended'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="action" value="reactivar">
                            <button class="btn btn-primary btn-sm" type="submit">Reactivar</button>
                        </form>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12px;">-</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$usuarios): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">Sin resultados.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
