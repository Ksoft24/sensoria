<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/mailer.php';

if (current_user()) {
    redirect('/dashboard.php');
}

// -------------------------------------------------------------------
// El registro SIEMPRE es a traves del enlace institucional de un
// cliente: /register.php?c=<registro_token>. Sin ese token no hay
// forma de saber a que institucion/plan asociar al usuario nuevo.
// -------------------------------------------------------------------
$token = trim($_GET['c'] ?? ($_POST['c'] ?? ''));
$targetClient = null;
$gateError = '';

if ($token === '') {
    $gateError = 'Este enlace de registro no es valido. Pide a tu institucion el enlace correcto (lo encuentran en su panel).';
} else {
    $stmt = db()->prepare("SELECT * FROM clients WHERE registro_token = ?");
    $stmt->execute([$token]);
    $targetClient = $stmt->fetch();

    if (!$targetClient) {
        $gateError = 'Este enlace de registro no es valido.';
    } elseif ($targetClient['estado'] !== 'active') {
        $gateError = 'Esta institucion todavia no esta activa. Contacta a tu administrador.';
    } elseif ($targetClient['plan_vence_en'] && $targetClient['plan_vence_en'] < date('Y-m-d')) {
        $gateError = 'La licencia de esta institucion vencio. Contacta a tu administrador.';
    } elseif ($targetClient['plan_tipo'] !== 'premium') {
        $max = (int) ($targetClient['max_usuarios'] ?? 1);
        if (contar_usuarios_activos((int) $targetClient['id']) >= $max) {
            $gateError = 'Esta institucion ya alcanzo su limite de usuarios (' . $max . '). Contacta a tu administrador.';
        }
    }
}

$grupos = [];
if ($targetClient && !$gateError) {
    $stmt = db()->prepare("SELECT * FROM grupos WHERE client_id = ? ORDER BY nombre");
    $stmt->execute([$targetClient['id']]);
    $grupos = $stmt->fetchAll();
    if (!$grupos) {
        $gateError = 'Esta institucion todavia no configuro salones/grupos. Contacta a tu administrador antes de registrarte.';
    }
}

$errors = [];
$old = $_POST;

if (!$gateError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

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
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($nombres === '' || $apellidos === '') $errors[] = 'Nombres y apellidos son obligatorios.';
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es valido.';
    if ($celular === '' || !preg_match('/^[0-9+ ]{6,20}$/', $celular)) $errors[] = 'El celular no es valido.';
    if (!$fecha_nac || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_nac)) $errors[] = 'La fecha de nacimiento no es valida.';
    if (!in_array($sexo, ['varon', 'mujer'], true)) $errors[] = 'Selecciona el sexo.';
    if ($depto === '' || $prov === '' || $distrito === '') $errors[] = 'Departamento, provincia y distrito son obligatorios.';
    if (!in_array($grupoId, array_column($grupos, 'id'), true)) $errors[] = 'Selecciona un salon/grupo valido.';
    if (strlen($password) < 6) $errors[] = 'La clave debe tener al menos 6 caracteres.';
    if ($password !== $password2) $errors[] = 'Las claves no coinciden.';

    if (!$errors) {
        $stmt = db()->prepare("SELECT id FROM users WHERE correo = ?");
        $stmt->execute([$correo]);
        if ($stmt->fetch()) {
            $errors[] = 'Ya existe una cuenta registrada con ese correo.';
        }
    }

    // Revalidar el cupo justo antes de insertar (por si alguien mas se
    // registro entre que se cargo el formulario y este submit).
    if (!$errors && $targetClient['plan_tipo'] !== 'premium') {
        $max = (int) ($targetClient['max_usuarios'] ?? 1);
        if (contar_usuarios_activos((int) $targetClient['id']) >= $max) {
            $errors[] = 'Esta institucion ya alcanzo su limite de usuarios. Contacta a tu administrador.';
        }
    }

    if (!$errors) {
        $deviceUid = generate_device_uid();

        $stmt = db()->prepare("INSERT INTO users
            (device_uid, nombres, apellidos, correo, celular, fecha_nacimiento, sexo,
             departamento, provincia, distrito, password_hash, client_id, grupo_id, estado)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending_email')");
        $stmt->execute([
            $deviceUid, $nombres, $apellidos, $correo, $celular, $fecha_nac, $sexo,
            $depto, $prov, $distrito, password_hash($password, PASSWORD_DEFAULT), $targetClient['id'], $grupoId,
        ]);
        $userId = (int) db()->lastInsertId();

        $verifyToken = generate_token();
        $stmt = db()->prepare("INSERT INTO email_verifications (user_id, token, expires_at)
                                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))");
        $stmt->execute([$userId, $verifyToken, EMAIL_TOKEN_HOURS]);

        $link = BASE_URL . '/verify_email.php?token=' . $verifyToken;
        [$sent, $mailError] = send_mail($correo, 'Confirma tu correo - ' . APP_NAME, "
            <p>Hola {$nombres},</p>
            <p>Gracias por registrarte en " . htmlspecialchars($targetClient['nombre']) . " (" . APP_NAME . "). Confirma tu correo con el siguiente enlace:</p>
            <p><a href=\"{$link}\">{$link}</a></p>
            <p>Este enlace vence en " . EMAIL_TOKEN_HOURS . " horas.</p>
        ");

        if ($sent) {
            flash('success', 'Cuenta creada. Revisa tu correo (' . htmlspecialchars($correo) . ') para confirmarla.');
        } else {
            flash('info', 'Cuenta creada, pero el correo de confirmacion no se pudo enviar: ' . $mailError .
                           ' Contacta al administrador.');
        }
        redirect('/login.php');
    }
}

$page_title = 'Crear cuenta';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card wide">
    <div class="card">
        <div class="auth-logo">
            <?php if ($targetClient && !empty($targetClient['logo_url'])): ?>
                <img src="<?= htmlspecialchars($targetClient['logo_url']) ?>" alt="" style="max-height:48px;display:block;margin:0 auto 8px;">
            <?php endif; ?>
            SENSOR-IA WEB
        </div>
        <?php if ($targetClient): ?>
            <p style="text-align:center;color:var(--text-muted);margin-top:-8px;">
                Registro para <strong><?= htmlspecialchars($targetClient['nombre']) ?></strong>
            </p>
        <?php endif; ?>
        <h2 style="text-align:center;margin-top:0;">Crear cuenta</h2>

        <?php if ($gateError): ?>
            <div class="alert alert-error"><?= htmlspecialchars($gateError) ?></div>
            <p style="text-align:center;font-size:13px;">
                ¿Ya tienes cuenta? <a href="<?= BASE_URL ?>/login.php">Inicia sesion</a>
            </p>
        <?php else: ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="c" value="<?= htmlspecialchars($token) ?>">

            <div class="field-row">
                <div>
                    <label>Nombres</label>
                    <input type="text" name="nombres" value="<?= htmlspecialchars($old['nombres'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Apellidos</label>
                    <input type="text" name="apellidos" value="<?= htmlspecialchars($old['apellidos'] ?? '') ?>" required>
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label>Correo</label>
                    <input type="email" name="correo" value="<?= htmlspecialchars($old['correo'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Celular</label>
                    <input type="text" name="celular" value="<?= htmlspecialchars($old['celular'] ?? '') ?>" required>
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label>Fecha de nacimiento</label>
                    <input type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($old['fecha_nacimiento'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Sexo</label>
                    <select name="sexo" required>
                        <option value="">-- Selecciona --</option>
                        <option value="varon" <?= ($old['sexo'] ?? '') === 'varon' ? 'selected' : '' ?>>Varon</option>
                        <option value="mujer" <?= ($old['sexo'] ?? '') === 'mujer' ? 'selected' : '' ?>>Mujer</option>
                    </select>
                </div>
            </div>

            <label>Salon / grupo</label>
            <select name="grupo_id" required>
                <option value="">-- Selecciona --</option>
                <?php foreach ($grupos as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= (int) ($old['grupo_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['nombre']) ?><?= $g['docente'] ? ' - ' . htmlspecialchars($g['docente']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="field-row">
                <div>
                    <label>Departamento</label>
                    <select name="departamento" id="sel-departamento" required></select>
                </div>
                <div>
                    <label>Provincia</label>
                    <select name="provincia" id="sel-provincia" required></select>
                </div>
                <div>
                    <label>Distrito</label>
                    <input type="text" name="distrito" id="inp-distrito"
                           value="<?= htmlspecialchars($old['distrito'] ?? '') ?>" required>
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label>Clave</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div>
                    <label>Repetir clave</label>
                    <input type="password" name="password2" required minlength="6">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Crear cuenta</button>
        </form>

        <p style="text-align:center;margin-top:14px;font-size:13px;">
            ¿Ya tienes cuenta? <a href="<?= BASE_URL ?>/login.php">Inicia sesion</a>
        </p>
        <?php endif; ?>
    </div>
</div>

<?php if (!$gateError): ?>
<script>
    // Ubigeo Peru: departamento -> provincias (viene de includes/ubigeo.php)
    var UBIGEO = <?php
        $data = require __DIR__ . '/includes/ubigeo.php';
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    ?>;
    var OLD_DEPTO = <?= json_encode($old['departamento'] ?? '') ?>;
    var OLD_PROV  = <?= json_encode($old['provincia'] ?? '') ?>;

    var selDepto = document.getElementById('sel-departamento');
    var selProv  = document.getElementById('sel-provincia');

    function fillDepartamentos() {
        selDepto.innerHTML = '<option value="">-- Selecciona --</option>';
        Object.keys(UBIGEO).forEach(function (d) {
            var opt = document.createElement('option');
            opt.value = d; opt.textContent = d;
            if (d === OLD_DEPTO) opt.selected = true;
            selDepto.appendChild(opt);
        });
        fillProvincias(selDepto.value);
    }

    function fillProvincias(depto) {
        selProv.innerHTML = '<option value="">-- Selecciona --</option>';
        (UBIGEO[depto] || []).forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p; opt.textContent = p;
            if (p === OLD_PROV) opt.selected = true;
            selProv.appendChild(opt);
        });
    }

    selDepto.addEventListener('change', function () { fillProvincias(this.value); });
    fillDepartamentos();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
