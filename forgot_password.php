<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/mailer.php';

if (current_user()) {
    redirect('/dashboard.php');
}

$message = '';
$isError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $correo = trim($_POST['correo'] ?? '');

    $stmt = db()->prepare("SELECT * FROM users WHERE correo = ?");
    $stmt->execute([$correo]);
    $user = $stmt->fetch();

    // Por seguridad, siempre mostramos el mismo mensaje exista o no la cuenta.
    $message = 'Si el correo existe en nuestro sistema, te enviamos un enlace para restablecer tu clave.';

    if ($user) {
        $token = generate_token();
        db()->prepare("INSERT INTO password_resets (user_id, token, expires_at)
                        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))")
            ->execute([$user['id'], $token, RESET_TOKEN_HOURS]);

        $link = BASE_URL . '/reset_password.php?token=' . $token;
        send_mail($correo, 'Recuperar clave - ' . APP_NAME, "
            <p>Hola {$user['nombres']},</p>
            <p>Solicitaste restablecer tu clave. Usa el siguiente enlace (valido por " . RESET_TOKEN_HOURS . " horas):</p>
            <p><a href=\"{$link}\">{$link}</a></p>
            <p>Si no fuiste tu, ignora este correo.</p>
        ");
    }
}

$page_title = 'Recuperar clave';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div class="card">
        <div class="auth-logo">SENSOR-IA WEB</div>
        <h2 style="text-align:center;margin-top:0;">Recuperar clave</h2>

        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <label>Correo registrado</label>
            <input type="email" name="correo" required autofocus>
            <button type="submit" class="btn btn-primary btn-block">Enviar enlace</button>
        </form>

        <p style="text-align:center;margin-top:14px;font-size:13px;">
            <a href="<?= BASE_URL ?>/login.php">Volver a iniciar sesion</a>
        </p>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
