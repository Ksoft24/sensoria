<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $actual    = $_POST['actual'] ?? '';
    $nueva     = $_POST['nueva'] ?? '';
    $nueva2    = $_POST['nueva2'] ?? '';

    if (!password_verify($actual, $user['password_hash'])) {
        $error = 'Tu clave actual no es correcta.';
    } elseif (strlen($nueva) < 6) {
        $error = 'La clave nueva debe tener al menos 6 caracteres.';
    } elseif ($nueva !== $nueva2) {
        $error = 'Las claves nuevas no coinciden.';
    } else {
        db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $user['id']]);
        $success = 'Tu clave se actualizo correctamente.';
    }
}

$page_title = 'Mi perfil';
$active_nav = 'perfil';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-2">
    <div class="card">
        <h3>Mis datos</h3>
        <p><strong>Nombre:</strong> <?= htmlspecialchars($user['nombres'] . ' ' . $user['apellidos']) ?></p>
        <p><strong>Correo:</strong> <?= htmlspecialchars($user['correo']) ?></p>
        <p><strong>Celular:</strong> <?= htmlspecialchars($user['celular']) ?></p>
        <p><strong>Ubicacion:</strong> <?= htmlspecialchars($user['distrito'] . ', ' . $user['provincia'] . ', ' . $user['departamento']) ?></p>
        <p><strong>Institucion:</strong> <?= htmlspecialchars($user['client_nombre'] ?? '-') ?></p>
        <p><strong>Grupo/salon:</strong> <?= htmlspecialchars($user['grupo_nombre'] ?? '-') ?></p>
        <p><strong>ID de dispositivo (MQTT):</strong> <code><?= htmlspecialchars($user['device_uid']) ?></code></p>
        <p style="color:var(--text-muted);font-size:12px;">
            Cuenta creada el <?= htmlspecialchars($user['creado_en']) ?>
        </p>
    </div>

    <div class="card">
        <h3>Cambiar clave</h3>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label>Clave actual</label>
            <input type="password" name="actual" required>
            <label>Clave nueva</label>
            <input type="password" name="nueva" required minlength="6">
            <label>Repetir clave nueva</label>
            <input type="password" name="nueva2" required minlength="6">
            <button type="submit" class="btn btn-primary btn-block">Actualizar clave</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
