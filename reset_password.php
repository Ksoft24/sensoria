<?php
require_once __DIR__ . '/includes/functions.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$done = false;

$stmt = db()->prepare("SELECT pr.*, u.correo FROM password_resets pr
                        JOIN users u ON u.id = pr.user_id
                        WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()");
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$reset) {
    $error = 'El enlace no es valido o ya vencio. Solicita uno nuevo.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 6) {
        $error = 'La clave debe tener al menos 6 caracteres.';
    } elseif ($password !== $password2) {
        $error = 'Las claves no coinciden.';
    } else {
        db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        db()->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")->execute([$reset['id']]);
        $done = true;
    }
}

$page_title = 'Restablecer clave';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div class="card">
        <div class="auth-logo">SENSOR-IA WEB</div>
        <h2 style="text-align:center;margin-top:0;">Restablecer clave</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="alert alert-success">Tu clave se actualizo correctamente.</div>
            <a class="btn btn-primary btn-block" href="<?= BASE_URL ?>/login.php">Iniciar sesion</a>
        <?php elseif ($reset): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <label>Cuenta: <?= htmlspecialchars($reset['correo']) ?></label>
                <label>Nueva clave</label>
                <input type="password" name="password" required minlength="6">
                <label>Repetir clave</label>
                <input type="password" name="password2" required minlength="6">
                <button type="submit" class="btn btn-primary btn-block">Guardar clave</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
