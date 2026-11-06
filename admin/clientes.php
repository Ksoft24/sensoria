<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/mailer.php';
$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $nombre    = trim($_POST['nombre'] ?? '');
        $ruc       = trim($_POST['ruc'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $telefono  = trim($_POST['telefono'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $planTipo  = $_POST['plan_tipo'] ?? 'demo';
        $maxUsuarios = null;
        $venceEn = null;

        if ($planTipo === 'demo') {
            $maxUsuarios = 1;
            $venceEn = null;
        } elseif ($planTipo === 'standard') {
            $maxUsuarios = max(1, (int) ($_POST['max_usuarios'] ?? 1));
            $venceEn = trim($_POST['plan_vence_en'] ?? '') ?: null;
        } elseif ($planTipo === 'premium') {
            $maxUsuarios = null; // sigue siendo ilimitado en usuarios
            $venceEn = trim($_POST['plan_vence_en'] ?? '') ?: null;
        } else {
            $planTipo = 'demo';
            $maxUsuarios = 1;
        }

        $errors = [];
        if ($nombre === '' || $ruc === '') $errors[] = 'Nombre y RUC son obligatorios.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es valido.';
        if (strlen($password) < 6) $errors[] = 'La clave debe tener al menos 6 caracteres.';
        if (in_array($planTipo, ['standard', 'premium'], true) && !$venceEn) $errors[] = 'Los clientes Standard/Premium necesitan fecha de vencimiento.';

        if (!$errors) {
            $stmt = db()->prepare("SELECT id FROM clients WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Ya existe un cliente con ese correo.';
            }
        }

        if (!$errors) {
            $token = generate_registro_token();
            db()->prepare("INSERT INTO clients (nombre, ruc, direccion, telefono, email, password_hash, estado, plan_tipo, max_usuarios, plan_vence_en, registro_token)
                            VALUES (?,?,?,?,?,?, 'active', ?,?,?,?)")
                ->execute([$nombre, $ruc, $direccion ?: null, $telefono ?: null, $email,
                    password_hash($password, PASSWORD_DEFAULT), $planTipo, $maxUsuarios, $venceEn, $token]);

            $newId = (int) db()->lastInsertId();
            db()->prepare("INSERT INTO grupos (client_id, nombre, descripcion) VALUES (?, 'Grupo General', 'Salon por defecto')")
                ->execute([$newId]);

            flash('success', 'Cliente creado. Enlace de registro: ' . BASE_URL . '/register.php?c=' . $token);
        } else {
            flash('error', implode(' ', $errors));
        }
        redirect('/admin/clientes.php');
    }

    $cid = (int) ($_POST['client_id'] ?? 0);

    if ($action === 'activar') {
        db()->prepare("UPDATE clients SET estado = 'active' WHERE id = ?")->execute([$cid]);
        flash('success', 'Cliente activado.');
    } elseif ($action === 'suspender') {
        db()->prepare("UPDATE clients SET estado = 'suspended' WHERE id = ?")->execute([$cid]);
        flash('success', 'Cliente suspendido (sus usuarios no podran iniciar sesion en sus modulos, pero si en el panel del cliente para ver el estado).');
    } elseif ($action === 'editar_cliente') {
        $nombre    = trim($_POST['nombre'] ?? '');
        $ruc       = trim($_POST['ruc'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $telefono  = trim($_POST['telefono'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $planTipo  = $_POST['plan_tipo'] ?? 'demo';

        if ($planTipo === 'demo') {
            $maxUsuarios = 1;
            $venceEn = null;
        } elseif ($planTipo === 'standard') {
            $maxUsuarios = max(1, (int) ($_POST['max_usuarios'] ?? 1));
            $venceEn = trim($_POST['plan_vence_en'] ?? '') ?: null;
        } elseif ($planTipo === 'premium') {
            $maxUsuarios = null; // sigue siendo ilimitado en usuarios
            $venceEn = trim($_POST['plan_vence_en'] ?? '') ?: null;
        } else {
            $planTipo = 'demo';
            $maxUsuarios = 1;
            $venceEn = null;
        }

        $errors = [];
        if ($cid <= 0) $errors[] = 'Cliente invalido.';
        if ($nombre === '' || $ruc === '') $errors[] = 'Nombre y RUC son obligatorios.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es valido.';
        if ($password !== '' && strlen($password) < 6) $errors[] = 'La clave debe tener al menos 6 caracteres.';
        if (in_array($planTipo, ['standard', 'premium'], true) && !$venceEn) $errors[] = 'Los clientes Standard/Premium necesitan fecha de vencimiento.';

        if (!$errors) {
            $stmt = db()->prepare("SELECT id FROM clients WHERE email = ? AND id <> ?");
            $stmt->execute([$email, $cid]);
            if ($stmt->fetch()) {
                $errors[] = 'Ya existe otro cliente con ese correo.';
            }
        }

        if (!$errors) {
            if ($password !== '') {
                db()->prepare("UPDATE clients SET nombre=?, ruc=?, direccion=?, telefono=?, email=?, password_hash=?, plan_tipo=?, max_usuarios=?, plan_vence_en=? WHERE id=?")
                    ->execute([$nombre, $ruc, $direccion ?: null, $telefono ?: null, $email,
                        password_hash($password, PASSWORD_DEFAULT), $planTipo, $maxUsuarios, $venceEn, $cid]);
            } else {
                db()->prepare("UPDATE clients SET nombre=?, ruc=?, direccion=?, telefono=?, email=?, plan_tipo=?, max_usuarios=?, plan_vence_en=? WHERE id=?")
                    ->execute([$nombre, $ruc, $direccion ?: null, $telefono ?: null, $email,
                        $planTipo, $maxUsuarios, $venceEn, $cid]);
            }
            flash('success', 'Cliente actualizado correctamente.');
        } else {
            flash('error', implode(' ', $errors));
        }
    }
    redirect('/admin/clientes.php');
}

$stmt = db()->query("SELECT c.*,
                        (SELECT COUNT(*) FROM users u WHERE u.client_id = c.id AND u.estado IN ('pending_client','active')) AS usuarios_activos
                      FROM clients c ORDER BY (c.estado = 'pending') DESC, c.creado_en DESC");
$clientes = $stmt->fetchAll();

$page_title = 'Clientes (instituciones)';
$active_nav = 'admin_clientes';
require __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-2">
    <div class="card">
        <h3>Crear cliente institucional</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="crear">
            <div class="field-row">
                <div>
                    <label>Nombre / Razon social</label>
                    <input type="text" name="nombre" required>
                </div>
                <div>
                    <label>RUC</label>
                    <input type="text" name="ruc" required>
                </div>
            </div>
            <div class="field-row">
                <div>
                    <label>Direccion</label>
                    <input type="text" name="direccion">
                </div>
                <div>
                    <label>Telefono</label>
                    <input type="text" name="telefono">
                </div>
            </div>
            <div class="field-row">
                <div>
                    <label>Correo de acceso</label>
                    <input type="email" name="email" required>
                </div>
                <div>
                    <label>Clave inicial</label>
                    <input type="text" name="password" required minlength="6">
                </div>
            </div>

            <label>Plan</label>
            <select name="plan_tipo" id="plan_tipo_select">
                <option value="demo">Demo (1 usuario, sin vencimiento)</option>
                <option value="standard">Standard (definir usuarios y vencimiento)</option>
                <option value="premium">Premium (usuarios ilimitados, con vencimiento)</option>
            </select>

            <div class="field-row">
                <div id="max-usuarios-field" style="display:none;">
                    <label>Maximo de usuarios</label>
                    <input type="number" name="max_usuarios" min="1" value="10">
                </div>
                <div id="vence-en-field" style="display:none;">
                    <label>Fecha de vencimiento</label>
                    <input type="date" name="plan_vence_en">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Crear cliente</button>
        </form>
    </div>

    <div class="card">
        <h3>¿Como funciona?</h3>
        <ul style="font-size:13px;color:var(--text-muted);padding-left:18px;line-height:1.8;">
            <li><strong>Demo:</strong> 1 usuario, sin vencimiento.</li>
            <li><strong>Standard:</strong> tu defines cuantos usuarios y hasta cuando.</li>
            <li><strong>Premium:</strong> usuarios ilimitados, pero igual con fecha de vencimiento.</li>
            <li>Al crear el cliente se genera automaticamente un grupo "Grupo General" y su enlace de registro propio.</li>
            <li>El cliente inicia sesion en <code>/client_login.php</code> con el correo/clave que le asignes aqui, y desde ahi gestiona sus propios usuarios y grupos.</li>
        </ul>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h3>Clientes registrados</h3>
    <table>
        <thead>
        <tr><th>Nombre</th><th>Correo</th><th>Plan</th><th>Usuarios</th><th>Vence</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($clientes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['nombre']) ?><br>
                    <span style="font-size:11px;color:var(--text-muted);">RUC <?= htmlspecialchars($c['ruc']) ?></span></td>
                <td><?= htmlspecialchars($c['email']) ?></td>
                <td>
                    <?php $planLabel = ['demo'=>'Demo','standard'=>'Standard','premium'=>'Premium'][$c['plan_tipo']] ?? $c['plan_tipo']; ?>
                    <strong><?= htmlspecialchars($planLabel) ?></strong>
                </td>
                <td><?= (int) $c['usuarios_activos'] ?><?= $c['max_usuarios'] !== null ? ' / ' . (int) $c['max_usuarios'] : ' / ilimitado' ?></td>
                <td><?= $c['plan_vence_en'] ?: '-' ?></td>
                <td>
                    <?php $badge = ['pending'=>'warn','active'=>'ok','suspended'=>'danger'][$c['estado']] ?? 'muted'; ?>
                    <span class="badge badge-<?= $badge ?>"><?= client_estado_label($c['estado']) ?></span>
                </td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-ghost btn-sm btn-editar-cliente"
                            data-id="<?= (int) $c['id'] ?>"
                            data-nombre="<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>"
                            data-ruc="<?= htmlspecialchars($c['ruc'], ENT_QUOTES) ?>"
                            data-direccion="<?= htmlspecialchars($c['direccion'] ?? '', ENT_QUOTES) ?>"
                            data-telefono="<?= htmlspecialchars($c['telefono'] ?? '', ENT_QUOTES) ?>"
                            data-email="<?= htmlspecialchars($c['email'], ENT_QUOTES) ?>"
                            data-plan="<?= htmlspecialchars($c['plan_tipo'], ENT_QUOTES) ?>"
                            data-max-usuarios="<?= (int) ($c['max_usuarios'] ?? 10) ?>"
                            data-vence="<?= htmlspecialchars($c['plan_vence_en'] ?? '', ENT_QUOTES) ?>">
                        Editar
                    </button>
                    <?php if ($c['estado'] === 'active'): ?>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('¿Suspender a «<?= htmlspecialchars(addslashes($c['nombre'])) ?>»? Sus usuarios no podran usar la plataforma mientras este suspendido.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
                            <input type="hidden" name="action" value="suspender">
                            <button class="btn btn-danger btn-sm" type="submit">Suspender</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('¿Activar a «<?= htmlspecialchars(addslashes($c['nombre'])) ?>»?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
                            <input type="hidden" name="action" value="activar">
                            <button class="btn btn-primary btn-sm" type="submit">Activar</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$clientes): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);">Todavia no hay clientes.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>


<!-- ===================== Modal: editar cliente completo ===================== -->
<div id="modal-editar-cliente" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3 style="margin:0;">Editar cliente</h3>
            <button type="button" class="modal-close" id="modal-editar-cliente-close">&times;</button>
        </div>
        <form method="post" id="form-editar-cliente"
              onsubmit="return confirm('¿Confirmas guardar los cambios de este cliente?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="editar_cliente">
            <input type="hidden" name="client_id" id="edit_client_id">

            <div class="field-row">
                <div>
                    <label>Nombre / Razon social</label>
                    <input type="text" name="nombre" id="edit_nombre" required>
                </div>
                <div>
                    <label>RUC</label>
                    <input type="text" name="ruc" id="edit_ruc" required>
                </div>
            </div>
            <div class="field-row">
                <div>
                    <label>Direccion</label>
                    <input type="text" name="direccion" id="edit_direccion">
                </div>
                <div>
                    <label>Telefono</label>
                    <input type="text" name="telefono" id="edit_telefono">
                </div>
            </div>
            <div class="field-row">
                <div>
                    <label>Correo de acceso</label>
                    <input type="email" name="email" id="edit_email" required>
                </div>
                <div>
                    <label>Nueva clave (opcional)</label>
                    <input type="text" name="password" id="edit_password" minlength="6" placeholder="Dejar en blanco para no cambiar">
                </div>
            </div>

            <label>Plan</label>
            <select name="plan_tipo" id="edit_plan_tipo">
                <option value="demo">Demo (1 usuario, sin vencimiento)</option>
                <option value="standard">Standard (definir usuarios y vencimiento)</option>
                <option value="premium">Premium (usuarios ilimitados, con vencimiento)</option>
            </select>

            <div class="field-row">
                <div id="edit-max-usuarios-field" style="display:none;">
                    <label>Maximo de usuarios</label>
                    <input type="number" name="max_usuarios" id="edit_max_usuarios" min="1">
                </div>
                <div id="edit-vence-en-field" style="display:none;">
                    <label>Fecha de vencimiento</label>
                    <input type="date" name="plan_vence_en" id="edit_plan_vence_en">
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px;">
                <button type="button" class="btn btn-ghost" id="modal-editar-cliente-cancel">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('plan_tipo_select').addEventListener('change', function () {
        document.getElementById('max-usuarios-field').style.display = this.value === 'standard' ? 'block' : 'none';
        document.getElementById('vence-en-field').style.display = (this.value === 'standard' || this.value === 'premium') ? 'block' : 'none';
    });

    // ---------- Modal de edicion completa de cliente ----------
    var modalEditar = document.getElementById('modal-editar-cliente');
    var editPlanSelect = document.getElementById('edit_plan_tipo');
    var editMaxUsuariosField = document.getElementById('edit-max-usuarios-field');
    var editVenceEnField = document.getElementById('edit-vence-en-field');

    function toggleEditStandardFields() {
        editMaxUsuariosField.style.display = editPlanSelect.value === 'standard' ? 'block' : 'none';
        editVenceEnField.style.display = (editPlanSelect.value === 'standard' || editPlanSelect.value === 'premium') ? 'block' : 'none';
    }
    editPlanSelect.addEventListener('change', toggleEditStandardFields);

    function abrirModalEditar(btn) {
        document.getElementById('edit_client_id').value = btn.dataset.id;
        document.getElementById('edit_nombre').value = btn.dataset.nombre;
        document.getElementById('edit_ruc').value = btn.dataset.ruc;
        document.getElementById('edit_direccion').value = btn.dataset.direccion;
        document.getElementById('edit_telefono').value = btn.dataset.telefono;
        document.getElementById('edit_email').value = btn.dataset.email;
        document.getElementById('edit_password').value = '';
        editPlanSelect.value = btn.dataset.plan;
        document.getElementById('edit_max_usuarios').value = btn.dataset.maxUsuarios || 10;
        document.getElementById('edit_plan_vence_en').value = btn.dataset.vence || '';
        toggleEditStandardFields();
        modalEditar.style.display = 'flex';
    }

    document.querySelectorAll('.btn-editar-cliente').forEach(function (btn) {
        btn.addEventListener('click', function () { abrirModalEditar(btn); });
    });

    function cerrarModalEditar() { modalEditar.style.display = 'none'; }
    document.getElementById('modal-editar-cliente-close').addEventListener('click', cerrarModalEditar);
    document.getElementById('modal-editar-cliente-cancel').addEventListener('click', cerrarModalEditar);
    modalEditar.addEventListener('click', function (e) {
        if (e.target === modalEditar) cerrarModalEditar();
    });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
