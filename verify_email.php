<?php
require_once __DIR__ . '/includes/functions.php';

$token = $_GET['token'] ?? '';
$message = '';
$ok = false;

if ($token) {
    $stmt = db()->prepare("SELECT * FROM email_verifications WHERE token = ? AND used_at IS NULL AND expires_at > NOW()");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if ($row) {
        db()->prepare("UPDATE email_verifications SET used_at = NOW() WHERE id = ?")->execute([$row['id']]);
        db()->prepare("UPDATE users SET estado = 'pending_client' WHERE id = ? AND estado = 'pending_email'")
            ->execute([$row['user_id']]);
        $ok = true;
        $message = 'Correo confirmado. Tu cuenta ahora esta en revision por tu institucion; te avisaremos cuando este activa.';
    } else {
        $message = 'El enlace no es valido o ya vencio. Puedes registrarte de nuevo o contactar al administrador.';
    }
} else {
    $message = 'Falta el token de confirmacion.';
}

$page_title = 'Confirmar correo';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div class="card">
        <div class="auth-logo">SENSOR-IA WEB</div>
        <div class="alert <?= $ok ? 'alert-success' : 'alert-error' ?>"><?= htmlspecialchars($message) ?></div>
        <a class="btn btn-primary btn-block" href="<?= BASE_URL ?>/login.php">Ir a iniciar sesion</a>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
