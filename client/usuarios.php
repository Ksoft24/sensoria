<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/mailer.php';
$client = require_client();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $uid    = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND client_id = ?");
    $stmt->execute([$uid, $client['id']]);
    $target = $stmt->fetch();

    if (!$target) {
        flash('error', 'Usuario no encontrado.');
        redirect('/client/usuarios.php');
    }

    switch ($action) {
        case 'aprobar':
            if ($client['plan_tipo'] !== 'premium') {
                $max = (int) ($client['max_usuarios'] ?? 1);
                if (contar_usuarios_activos((int) $client['id']) > $max) {
                    flash('error', 'Ya alcanzaste el limite de usuarios de tu plan (' . $max . ').');
                    redirect('/client/usuarios.php');
                }
            }
            db()->prepare("UPDATE users SET estado = 'active', aprobado_en = NOW() WHERE id = ? AND estado = 'pending_client'")
                ->execute([$uid]);
            flash('success', 'Usuario aprobado.');
            _notificar($uid, 'Tu cuenta fue aprobada - ' . APP_NAME,
                '<p>Tu cuenta en ' . htmlspecialchars($client['nombre']) . ' (' . APP_NAME . ') ya esta activa. Ya puedes iniciar sesion.</p>');
            break;
        case 'rechazar':
            db()->prepare("UPDATE users SET estado = 'rejected' WHERE id = ?")->execute([$uid]);
            flash('success', 'Usuario rechazado.');
            _notificar($uid, 'Tu solicitud de cuenta fue rechazada - ' . APP_NAME,
                '<p>Tu solicitud de cuenta en ' . htmlspecialchars($client['nombre']) . ' fue rechazada. Si crees que es un error, contacta a tu institucion.</p>');
            break;
        case 'suspender':
            db()->prepare("UPDATE users SET estado = 'suspended' WHERE id = ?")->execute([$uid]);
            flash('success', 'Usuario suspendido.');
            _notificar($uid, 'Tu cuenta fue suspendida - ' . APP_NAME,
                '<p>Tu cuenta en ' . htmlspecialchars($client['nombre']) . ' fue suspendida. Contacta a tu institucion para mas informacion.</p>');
            break;
        case 'reactivar':
            if ($client['plan_tipo'] !== 'premium') {
                $max = (int) ($client['max_usuarios'] ?? 1);
                if (contar_usuarios_activos((int) $client['id']) >= $max) {
                    flash('error', 'Ya alcanzaste el limite de usuarios de tu plan (' . $max . ').');
                    redirect('/client/usuarios.php');
                }
            }
            db()->prepare("UPDATE users SET estado = 'active' WHERE id = ?")->execute([$uid]);
            flash('success', 'Usuario reactivado.');
            _notificar($uid, 'Tu cuenta fue reactivada - ' . APP_NAME,
                '<p>Tu cuenta en ' . htmlspecialchars($client['nombre']) . ' fue reactivada. Ya puedes iniciar sesion.</p>');
            break;
    }
    redirect('/client/usuarios.php' . (!empty($_POST['back_qs']) ? '?' . $_POST['back_qs'] : ''));
}

function _notificar(int $userId, string $subject, string $body): void
{
    $stmt = db()->prepare("SELECT correo, nombres FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) return;
    send_mail($u['correo'], $subject, "<p>Hola {$u['nombres']},</p>" . $body);
}

// -------------------------------------------------------------------
// Filtros + paginacion
// -------------------------------------------------------------------
$filtroGrupo  = (int) ($_GET['grupo_id'] ?? 0);
$filtroEstado = $_GET['estado'] ?? '';
$perPageReq   = (int) ($_GET['per_page'] ?? 25);
$perPage      = in_array($perPageReq, [25, 50, 100], true) ? $perPageReq : 25;
$page         = max(1, (int) ($_GET['page'] ?? 1));

$where = ['u.client_id = ?'];
$params = [$client['id']];
if ($filtroGrupo) {
    $where[] = 'u.grupo_id = ?';
    $params[] = $filtroGrupo;
}
if ($filtroEstado) {
    $where[] = 'u.estado = ?';
    $params[] = $filtroEstado;
}
$whereSql = implode(' AND ', $where);

// Exportar CSV: mismo filtro, SIN paginar
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = db()->prepare("SELECT u.nombres, u.apellidos, u.correo, u.celular, u.fecha_nacimiento, u.sexo,
                                   u.departamento, u.provincia, u.distrito, g.nombre AS grupo, u.estado, u.creado_en
                            FROM users u LEFT JOIN grupos g ON g.id = u.grupo_id
                            WHERE $whereSql ORDER BY u.creado_en DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="usuarios_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nombres', 'Apellidos', 'Correo', 'Celular', 'Fecha nacimiento', 'Sexo', 'Departamento', 'Provincia', 'Distrito', 'Grupo', 'Estado', 'Creado']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['nombres'], $r['apellidos'], $r['correo'], $r['celular'], $r['fecha_nacimiento'], $r['sexo'],
            $r['departamento'], $r['provincia'], $r['distrito'], $r['grupo'] ?? '-', estado_label($r['estado']), $r['creado_en'],
        ]);
    }
    fclose($out);
    exit;
}

$stmt = db()->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$stmt->execute($params);
$totalUsuarios = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsuarios / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT u.*, g.nombre AS grupo_nombre FROM users u
                        LEFT JOIN grupos g ON g.id = u.grupo_id
                        WHERE $whereSql
                        ORDER BY (u.estado = 'pending_client') DESC, u.creado_en DESC
                        LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

$grupos = db()->prepare("SELECT * FROM grupos WHERE client_id = ? ORDER BY nombre");
$grupos->execute([$client['id']]);
$grupos = $grupos->fetchAll();

$usadosCupo = contar_usuarios_activos((int) $client['id']);
$maxUsuarios = $client['plan_tipo'] === 'premium' ? null : (int) ($client['max_usuarios'] ?? 1);
$registroUrl = BASE_URL . '/register.php?c=' . $client['registro_token'];

// Para reconstruir los links de filtro/paginacion conservando los parametros actuales
function _qs(array $overrides = []): string
{
    return http_build_query(array_merge($_GET, $overrides));
}

$page_title = 'Usuarios';
$active_nav = 'client_usuarios';
require __DIR__ . '/../includes/header.php';
$subnav_active = 'usuarios';
require __DIR__ . '/_subnav.php';
?>

<div class="card" style="margin-bottom:20px;">
    <h3>Enlace de registro de tu institucion</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Comparte este enlace con tus estudiantes/docentes para que se
        registren directamente asociados a <?= htmlspecialchars($client['nombre']) ?>.
    </p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="text" readonly value="<?= htmlspecialchars($registroUrl) ?>" style="flex:1;min-width:260px;" id="registro-url">
        <button type="button" class="btn btn-ghost" onclick="navigator.clipboard.writeText(document.getElementById('registro-url').value); this.textContent='Copiado!'; setTimeout(() => this.textContent='Copiar', 1500);">Copiar</button>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin-top:10px;">
        Plan <strong><?= ucfirst($client['plan_tipo']) ?></strong> -
        Cupo usado: <strong><?= $usadosCupo ?></strong><?= $maxUsuarios !== null ? ' / ' . $maxUsuarios : ' (ilimitado)' ?>
        <?php if ($client['plan_vence_en']): ?>
            - Vence: <strong><?= htmlspecialchars($client['plan_vence_en']) ?></strong>
        <?php endif; ?>
    </p>
    <?php if (!$grupos): ?>
        <div class="alert alert-error" style="margin-top:10px;">
            Todavia no creaste ningun grupo/salon - nadie podra registrarse hasta que crees al menos uno en
            <a href="<?= BASE_URL ?>/client/grupos.php">Grupos</a>.
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Usuarios de tu institucion</h3>

    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
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
        <a class="btn btn-primary" href="?<?= _qs(['export' => 'csv']) ?>">⬇ Descargar CSV</a>
    </form>

    <table>
        <thead>
        <tr>
            <th>Nombre</th><th>Correo</th><th>Grupo</th><th>Estado</th><th>Acciones</th>
        </tr>
        </thead>
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
                <td style="white-space:nowrap;">
                    <?php if ($u['estado'] === 'pending_client'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="action" value="aprobar">
                            <input type="hidden" name="back_qs" value="<?= htmlspecialchars(_qs()) ?>">
                            <button class="btn btn-primary btn-sm" type="submit">Aprobar</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="action" value="rechazar">
                            <input type="hidden" name="back_qs" value="<?= htmlspecialchars(_qs()) ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Rechazar</button>
                        </form>
                    <?php elseif ($u['estado'] === 'active'): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Suspender a <?= htmlspecialchars(addslashes($u['nombres'])) ?>?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="action" value="suspender">
                            <input type="hidden" name="back_qs" value="<?= htmlspecialchars(_qs()) ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Suspender</button>
                        </form>
                    <?php elseif ($u['estado'] === 'suspended'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="action" value="reactivar">
                            <input type="hidden" name="back_qs" value="<?= htmlspecialchars(_qs()) ?>">
                            <button class="btn btn-primary btn-sm" type="submit">Reactivar</button>
                        </form>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12px;">-</span>
                    <?php endif; ?>
                </td>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
