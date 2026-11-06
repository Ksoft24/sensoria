<?php
/**
 * admin/dispositivos.php
 * -----------------------------------------------------------------------
 * Bajo el modelo de licenciamiento institucional, el acceso YA NO
 * vence por dispositivo: vence (o no) segun el PLAN DEL CLIENTE al que
 * pertenece el usuario dueño del dispositivo (ver clients.plan_vence_en
 * y api/activation_status.php, que es quien realmente decide si un
 * dispositivo puede seguir operando). Aqui solo se aprueba/revoca el
 * ACCESO del equipo en si (que sea un dispositivo legitimo), sin
 * calcular ninguna fecha.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/mailer.php';
$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int) ($_POST['device_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = db()->prepare("SELECT * FROM devices WHERE id = ?");
    $stmt->execute([$id]);
    $device = $stmt->fetch();

    if ($device) {
        switch ($action) {
            case 'aprobar':
                db()->prepare("UPDATE devices SET estado = 'active', aprobado_en = NOW() WHERE id = ?")->execute([$id]);
                flash('success', 'Dispositivo aprobado.');
                _notificar_dueno_dispositivo($id, 'Dispositivo activado - ' . APP_NAME,
                    '<p>Tu dispositivo <strong>' . htmlspecialchars($device['device_code']) . '</strong> ya esta activo.</p>');
                break;
            case 'rechazar':
                db()->prepare("UPDATE devices SET estado = 'rejected' WHERE id = ?")->execute([$id]);
                flash('success', 'Solicitud rechazada.');
                _notificar_dueno_dispositivo($id, 'Solicitud de dispositivo rechazada - ' . APP_NAME,
                    '<p>La solicitud de activacion del dispositivo <strong>' . htmlspecialchars($device['device_code']) .
                    '</strong> fue rechazada. Contacta al administrador para mas informacion.</p>');
                break;
            case 'revocar':
                db()->prepare("UPDATE devices SET estado = 'revoked' WHERE id = ?")->execute([$id]);
                flash('success', 'Acceso del dispositivo revocado.');
                _notificar_dueno_dispositivo($id, 'Acceso de dispositivo revocado - ' . APP_NAME,
                    '<p>Se revoco el acceso del dispositivo <strong>' . htmlspecialchars($device['device_code']) . '</strong>.</p>');
                break;
            case 'reactivar':
                db()->prepare("UPDATE devices SET estado = 'active', aprobado_en = NOW() WHERE id = ?")->execute([$id]);
                flash('success', 'Dispositivo reactivado.');
                _notificar_dueno_dispositivo($id, 'Dispositivo reactivado - ' . APP_NAME,
                    '<p>Tu dispositivo <strong>' . htmlspecialchars($device['device_code']) . '</strong> fue reactivado.</p>');
                break;
        }
    }
    redirect('/admin/dispositivos.php');
}

/**
 * Envia un correo al dueño del dispositivo indicado (por id de `devices`),
 * sin interrumpir el flujo si falla el envio.
 */
function _notificar_dueno_dispositivo(int $deviceId, string $subject, string $body): void
{
    $stmt = db()->prepare("SELECT u.correo, u.nombres FROM devices d
                            JOIN users u ON u.id = d.user_id WHERE d.id = ?");
    $stmt->execute([$deviceId]);
    $u = $stmt->fetch();
    if (!$u) return;
    send_mail($u['correo'], $subject, "<p>Hola {$u['nombres']},</p>" . $body);
}

$stmt = db()->query("SELECT d.*, u.nombres, u.apellidos, u.correo,
                        c.nombre AS client_nombre, c.plan_tipo, c.plan_vence_en
                      FROM devices d
                      JOIN users u ON u.id = d.user_id
                      LEFT JOIN clients c ON c.id = u.client_id
                      ORDER BY (d.estado = 'pending') DESC, d.solicitado_en DESC");
$devices = $stmt->fetchAll();

$page_title = 'Dispositivos';
$active_nav = 'admin_dispositivos';
require __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    El acceso ya no vence por dispositivo: depende del plan de la
    institucion del usuario (columna "Licencia institucional"). Aqui
    solo apruebas/revocas que el EQUIPO en si sea legitimo.
</div>

<div class="card">
    <h3>Solicitudes de activacion de dispositivos</h3>
    <table>
        <thead>
        <tr><th>Codigo</th><th>Usuario</th><th>Institucion</th><th>Licencia institucional</th><th>Estado</th><th>Ult. check</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['device_code']) ?><?= $d['nombre'] ? '<br><span style="font-size:12px;color:var(--text-muted);">'.htmlspecialchars($d['nombre']).'</span>' : '' ?></td>
                <td><?= htmlspecialchars($d['nombres'] . ' ' . $d['apellidos']) ?><br>
                    <span style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($d['correo']) ?></span></td>
                <td><?= htmlspecialchars($d['client_nombre'] ?? '-') ?></td>
                <td style="font-size:12px;">
                    <?php if ($d['plan_tipo']): ?>
                        <?= ucfirst($d['plan_tipo']) ?>
                        <?= $d['plan_vence_en'] ? ' (vence ' . htmlspecialchars($d['plan_vence_en']) . ')' : ' (sin vencimiento)' ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <?php $badge = ['pending'=>'warn','active'=>'ok','rejected'=>'danger','revoked'=>'danger'][$d['estado']] ?? 'muted'; ?>
                    <span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($d['estado']) ?></span>
                </td>
                <td style="font-size:12px;color:var(--text-muted);"><?= $d['ultimo_check_en'] ?: 'Nunca' ?></td>
                <td style="white-space:nowrap;">
                    <?php if ($d['estado'] === 'pending'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
                            <input type="hidden" name="action" value="aprobar">
                            <button class="btn btn-primary btn-sm" type="submit">Aprobar</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
                            <input type="hidden" name="action" value="rechazar">
                            <button class="btn btn-danger btn-sm" type="submit">Rechazar</button>
                        </form>
                    <?php elseif ($d['estado'] === 'active'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
                            <input type="hidden" name="action" value="revocar">
                            <button class="btn btn-danger btn-sm" type="submit">Revocar</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
                            <input type="hidden" name="action" value="reactivar">
                            <button class="btn btn-primary btn-sm" type="submit">Reactivar</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$devices): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);">Sin solicitudes todavia.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
