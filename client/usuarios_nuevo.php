<?php
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

$stmt = db()->prepare("SELECT * FROM grupos WHERE client_id = ? ORDER BY nombre");
$stmt->execute([$client['id']]);
$grupos = $stmt->fetchAll();

$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if ($nombres === '' || $apellidos === '') $errors[] = 'Nombres y apellidos son obligatorios.';
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es valido.';
    if (!in_array($grupoId, array_column($grupos, 'id'), true)) $errors[] = 'Selecciona un grupo valido.';
    if (strlen($password) < 6) $errors[] = 'La clave debe tener al menos 6 caracteres.';

    if (!$errors) {
        $stmt = db()->prepare("SELECT id FROM users WHERE correo = ?");
        $stmt->execute([$correo]);
        if ($stmt->fetch()) {
            $errors[] = 'Ya existe una cuenta registrada con ese correo.';
        }
    }
    if (!$errors && $client['plan_tipo'] !== 'premium') {
        $max = (int) ($client['max_usuarios'] ?? 1);
        if (contar_usuarios_activos((int) $client['id']) >= $max) {
            $errors[] = 'Ya alcanzaste el limite de usuarios de tu plan (' . $max . ').';
        }
    }

    if (!$errors) {
        $deviceUid = generate_device_uid();
        // Registro manual hecho por la propia institucion: se activa
        // directo (sin confirmar correo ni esperar aprobacion - ya lo
        // esta dando de alta alguien de la institucion misma).
        $stmt = db()->prepare("INSERT INTO users
            (device_uid, nombres, apellidos, correo, celular, fecha_nacimiento, sexo,
             departamento, provincia, distrito, password_hash, client_id, grupo_id, estado, aprobado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'active', NOW())");
        $stmt->execute([
            $deviceUid, $nombres, $apellidos, $correo, $celular ?: null, $fecha_nac ?: null, $sexo ?: null,
            $depto ?: null, $prov ?: null, $distrito ?: null, password_hash($password, PASSWORD_DEFAULT),
            $client['id'], $grupoId,
        ]);

        flash('success', 'Usuario "' . htmlspecialchars($nombres . ' ' . $apellidos) . '" creado y activado.');
        redirect('/client/usuarios_nuevo.php');
    }
}

$page_title = 'Registro de Usuarios';
$active_nav = 'client_usuarios';
require __DIR__ . '/../includes/header.php';
$subnav_active = 'nuevo';
require __DIR__ . '/_subnav.php';
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<?php if (!$grupos): ?>
    <div class="alert alert-error">
        Todavia no creaste ningun grupo/salon - crea uno primero en
        <a href="<?= BASE_URL ?>/client/grupos.php">Grupos</a>.
    </div>
<?php else: ?>

<div class="card" style="max-width:720px;">
    <h3>Registrar un usuario nuevo</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Esta cuenta queda <strong>activa de inmediato</strong> (no pasa por
        confirmacion de correo ni aprobacion, ya que la estas creando tu mismo).
    </p>

    <form method="post" novalidate>
        <?= csrf_field() ?>

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
                <input type="text" name="celular" value="<?= htmlspecialchars($old['celular'] ?? '') ?>">
            </div>
        </div>

        <div class="field-row">
            <div>
                <label>Fecha de nacimiento</label>
                <input type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($old['fecha_nacimiento'] ?? '') ?>">
            </div>
            <div>
                <label>Sexo</label>
                <select name="sexo">
                    <option value="">-- Selecciona --</option>
                    <option value="varon" <?= ($old['sexo'] ?? '') === 'varon' ? 'selected' : '' ?>>Varon</option>
                    <option value="mujer" <?= ($old['sexo'] ?? '') === 'mujer' ? 'selected' : '' ?>>Mujer</option>
                </select>
            </div>
        </div>

        <label>Grupo / salon</label>
        <select name="grupo_id" required>
            <option value="">-- Selecciona --</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= (int) ($old['grupo_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($g['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="field-row">
            <div>
                <label>Departamento</label>
                <input type="text" name="departamento" value="<?= htmlspecialchars($old['departamento'] ?? '') ?>">
            </div>
            <div>
                <label>Provincia</label>
                <input type="text" name="provincia" value="<?= htmlspecialchars($old['provincia'] ?? '') ?>">
            </div>
            <div>
                <label>Distrito</label>
                <input type="text" name="distrito" value="<?= htmlspecialchars($old['distrito'] ?? '') ?>">
            </div>
        </div>

        <label>Clave</label>
        <input type="text" name="password" required minlength="6" placeholder="minimo 6 caracteres">
        <p style="font-size:12px;color:var(--text-muted);margin-top:-8px;">
            Compartesela al usuario para su primer ingreso.
        </p>

        <button type="submit" class="btn btn-primary btn-block">Crear usuario</button>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
