<?php
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

$stmt = db()->prepare("SELECT * FROM grupos WHERE client_id = ? ORDER BY nombre");
$stmt->execute([$client['id']]);
$grupos = $stmt->fetchAll();

$editId = (int) ($_GET['edit'] ?? 0);
$errors = [];

// -------------------------------------------------------------------
// Guardar cambios de un usuario
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $uid = (int) ($_POST['user_id'] ?? 0);

    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND client_id = ?");
    $stmt->execute([$uid, $client['id']]);
    $target = $stmt->fetch();

    if (!$target) {
        flash('error', 'Usuario no encontrado.');
        redirect('/client/usuarios_editar.php');
    }

    $nombres   = trim($_POST['nombres'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $correo    = trim($_POST['correo'] ?? '');
    $celular   = trim($_POST['celular'] ?? '');
    $fecha_nac = trim($_POST['fecha_nacimiento'] ?? '');
    $sexo      = $_POST['sexo'] ?? '';
    $depto     = trim($_POST['departamento'] ?? '');
    $prov      = trim($_POST['provincia'] ?? '');
    $distrito  = trim($_POST['distrito'] ?? '');
    $grupoId   = (int) ($_POST['grupo_id'] ?? 0);
    $nuevaClave = $_POST['nueva_clave'] ?? '';

    if ($nombres === '' || $apellidos === '') $errors[] = 'Nombres y apellidos son obligatorios.';
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es valido.';
    if (!in_array($grupoId, array_column($grupos, 'id'), true)) $errors[] = 'Selecciona un grupo valido.';
    if ($nuevaClave !== '' && strlen($nuevaClave) < 6) $errors[] = 'La clave nueva debe tener al menos 6 caracteres.';

    if (!$errors && $correo !== $target['correo']) {
        $stmt = db()->prepare("SELECT id FROM users WHERE correo = ? AND id != ?");
        $stmt->execute([$correo, $uid]);
        if ($stmt->fetch()) $errors[] = 'Ya existe otra cuenta con ese correo.';
    }

    if (!$errors) {
        if ($nuevaClave !== '') {
            db()->prepare("UPDATE users SET nombres=?, apellidos=?, correo=?, celular=?, fecha_nacimiento=?, sexo=?,
                            departamento=?, provincia=?, distrito=?, grupo_id=?, password_hash=? WHERE id=? AND client_id=?")
                ->execute([$nombres, $apellidos, $correo, $celular ?: null, $fecha_nac ?: null, $sexo ?: null,
                    $depto ?: null, $prov ?: null, $distrito ?: null, $grupoId,
                    password_hash($nuevaClave, PASSWORD_DEFAULT), $uid, $client['id']]);
        } else {
            db()->prepare("UPDATE users SET nombres=?, apellidos=?, correo=?, celular=?, fecha_nacimiento=?, sexo=?,
                            departamento=?, provincia=?, distrito=?, grupo_id=? WHERE id=? AND client_id=?")
                ->execute([$nombres, $apellidos, $correo, $celular ?: null, $fecha_nac ?: null, $sexo ?: null,
                    $depto ?: null, $prov ?: null, $distrito ?: null, $grupoId, $uid, $client['id']]);
        }
        flash('success', 'Usuario actualizado.');
        redirect('/client/usuarios_editar.php');
    }
}

// -------------------------------------------------------------------
// Cargar usuario a editar (si viene ?edit=)
// -------------------------------------------------------------------
$editUser = null;
if ($editId) {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND client_id = ?");
    $stmt->execute([$editId, $client['id']]);
    $editUser = $stmt->fetch();
    if (!$editUser) {
        flash('error', 'Usuario no encontrado.');
        redirect('/client/usuarios_editar.php');
    }
}

// -------------------------------------------------------------------
// Listado con filtros (solo cuando NO se esta editando)
// -------------------------------------------------------------------
$filtroGrupo  = (int) ($_GET['grupo_id'] ?? 0);
$filtroEstado = $_GET['estado'] ?? '';
$busqueda     = trim($_GET['q'] ?? '');
$perPageReq   = (int) ($_GET['per_page'] ?? 25);
$perPage      = in_array($perPageReq, [25, 50, 100], true) ? $perPageReq : 25;
$page         = max(1, (int) ($_GET['page'] ?? 1));

$where = ['u.client_id = ?'];
$params = [$client['id']];
if ($filtroGrupo) { $where[] = 'u.grupo_id = ?'; $params[] = $filtroGrupo; }
if ($filtroEstado) { $where[] = 'u.estado = ?'; $params[] = $filtroEstado; }
if ($busqueda !== '') {
    $where[] = '(u.nombres LIKE ? OR u.apellidos LIKE ? OR u.correo LIKE ?)';
    $like = '%' . $busqueda . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$stmt->execute($params);
$totalUsuarios = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsuarios / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT u.*, g.nombre AS grupo_nombre FROM users u
                        LEFT JOIN grupos g ON g.id = u.grupo_id
                        WHERE $whereSql ORDER BY u.nombres
                        LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

function _qs(array $overrides = []): string
{
    return http_build_query(array_merge($_GET, $overrides));
}

$page_title = 'Editar Usuarios';
$active_nav = 'client_usuarios';
require __DIR__ . '/../includes/header.php';
$subnav_active = 'editar';
require __DIR__ . '/_subnav.php';
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<?php if ($editUser): ?>
    <div class="card" style="max-width:720px;">
        <h3>Editar: <?= htmlspecialchars($editUser['nombres'] . ' ' . $editUser['apellidos']) ?></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">

            <div class="field-row">
                <div>
                    <label>Nombres</label>
                    <input type="text" name="nombres" value="<?= htmlspecialchars($editUser['nombres']) ?>" required>
                </div>
                <div>
                    <label>Apellidos</label>
                    <input type="text" name="apellidos" value="<?= htmlspecialchars($editUser['apellidos']) ?>" required>
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label>Correo</label>
                    <input type="email" name="correo" value="<?= htmlspecialchars($editUser['correo']) ?>" required>
                </div>
                <div>
                    <label>Celular</label>
                    <input type="text" name="celular" value="<?= htmlspecialchars($editUser['celular'] ?? '') ?>">
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label>Fecha de nacimiento</label>
                    <input type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($editUser['fecha_nacimiento'] ?? '') ?>">
                </div>
                <div>
                    <label>Sexo</label>
                    <select name="sexo">
                        <option value="">-- Selecciona --</option>
                        <option value="varon" <?= $editUser['sexo'] === 'varon' ? 'selected' : '' ?>>Varon</option>
                        <option value="mujer" <?= $editUser['sexo'] === 'mujer' ? 'selected' : '' ?>>Mujer</option>
                    </select>
                </div>
            </div>

            <label>Grupo / salon</label>
            <select name="grupo_id" required>
                <?php foreach ($grupos as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === (int) $editUser['grupo_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="field-row">
                <div>
                    <label>Departamento</label>
                    <input type="text" name="departamento" value="<?= htmlspecialchars($editUser['departamento'] ?? '') ?>">
                </div>
                <div>
                    <label>Provincia</label>
                    <input type="text" name="provincia" value="<?= htmlspecialchars($editUser['provincia'] ?? '') ?>">
                </div>
                <div>
                    <label>Distrito</label>
                    <input type="text" name="distrito" value="<?= htmlspecialchars($editUser['distrito'] ?? '') ?>">
                </div>
            </div>

            <label>Nueva clave (opcional)</label>
            <input type="text" name="nueva_clave" minlength="6" placeholder="dejar vacio para no cambiarla">

            <div style="display:flex;gap:10px;margin-top:14px;">
                <button type="submit" class="btn btn-primary" onclick="return confirm('¿Confirmas guardar estos cambios?');">Guardar cambios</button>
                <a class="btn btn-ghost" href="<?= BASE_URL ?>/client/usuarios_editar.php">Cancelar</a>
            </div>
        </form>
    </div>

<?php else: ?>

    <div class="card">
        <h3>Buscar y editar usuarios</h3>
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
            <div>
                <label>Buscar (nombre/correo)</label>
                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" style="margin:0;">
            </div>
            <div>
                <label>Grupo</label>
                <select name="grupo_id" style="margin:0;">
                    <option value="0">Todos</option>
                    <?php foreach ($grupos as $g): ?>
                        <option value="<?= (int) $g['id'] ?>" <?= $filtroGrupo === (int) $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Estado</label>
                <select name="estado" style="margin:0;">
                    <option value="">Todos</option>
                    <option value="pending_email" <?= $filtroEstado === 'pending_email' ? 'selected' : '' ?>>Pendiente correo</option>
                    <option value="pending_client" <?= $filtroEstado === 'pending_client' ? 'selected' : '' ?>>Pendiente aprobacion</option>
                    <option value="active" <?= $filtroEstado === 'active' ? 'selected' : '' ?>>Activo</option>
                    <option value="suspended" <?= $filtroEstado === 'suspended' ? 'selected' : '' ?>>Suspendido</option>
                    <option value="rejected" <?= $filtroEstado === 'rejected' ? 'selected' : '' ?>>Rechazado</option>
                </select>
            </div>
            <div>
                <label>Por pagina</label>
                <select name="per_page" style="margin:0;">
                    <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                </select>
            </div>
            <button type="submit" class="btn btn-ghost">Filtrar</button>
        </form>

        <table>
            <thead><tr><th>Nombre</th><th>Correo</th><th>Grupo</th><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['nombres'] . ' ' . $u['apellidos']) ?></td>
                    <td><?= htmlspecialchars($u['correo']) ?></td>
                    <td><?= htmlspecialchars($u['grupo_nombre'] ?? '-') ?></td>
                    <td>
                        <?php $badge = ['pending_email'=>'muted','pending_client'=>'warn','active'=>'ok','suspended'=>'danger','rejected'=>'danger'][$u['estado']] ?? 'muted'; ?>
                        <span class="badge badge-<?= $badge ?>"><?= estado_label($u['estado']) ?></span>
                    </td>
                    <td><a class="btn btn-ghost btn-sm" href="?<?= _qs(['edit' => $u['id']]) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$usuarios): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--text-muted);">Sin resultados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= _qs(['page' => $page - 1]) ?>">← Anterior</a>
            <span>Pagina <?= $page ?> de <?= $totalPages ?> (<?= $totalUsuarios ?> usuario<?= $totalUsuarios === 1 ? '' : 's' ?>)</span>
            <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= _qs(['page' => $page + 1]) ?>">Siguiente →</a>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
