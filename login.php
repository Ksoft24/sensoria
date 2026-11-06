<?php
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    $u = current_user();
    redirect(!empty($u['es_admin']) ? '/admin/usuarios.php' : '/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $correo   = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';
    $recordar = !empty($_POST['recordar']);

    $stmt = db()->prepare("SELECT * FROM users WHERE correo = ?");
    $stmt->execute([$correo]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Correo o clave incorrectos.';
    } elseif ($user['estado'] === 'pending_email') {
        $error = 'Todavia no confirmas tu correo. Revisa tu bandeja de entrada.';
    } elseif ($user['estado'] === 'pending_client') {
        $error = 'Tu cuenta esta pendiente de aprobacion por tu institucion.';
    } elseif ($user['estado'] === 'suspended') {
        $error = 'Tu cuenta esta suspendida. Contacta al administrador.';
    } elseif ($user['estado'] === 'rejected') {
        $error = 'Tu solicitud de cuenta fue rechazada.';
    } else {
        $_SESSION['user_id'] = $user['id'];

        if ($recordar) {
            $token = generate_token();
            db()->prepare("UPDATE users SET remember_token = ?, remember_expires = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                ->execute([$token, REMEMBER_DAYS, $user['id']]);
            setcookie('remember_token', $token, [
                'expires'  => time() + REMEMBER_DAYS * 86400,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        redirect(!empty($user['es_admin']) ? '/admin/usuarios.php' : '/dashboard.php');
    }

    // IMPORTANTE: si el login FALLA, tambien hay que redirigir (patron
    // Post/Redirect/Get) en vez de volver a pintar el formulario como
    // respuesta directa del POST. Si no, al recargar la pagina el
    // navegador muestra el dialogo de "reenviar formulario" (Confirmar
    // reenvio / Cancelar) porque cree que hay que repetir el POST - eso
    // es lo que se veia como "se actualiza y muestra las opciones".
    if ($error) {
        flash('error', $error);
        redirect('/login.php');
    }
}

$page_title = 'Iniciar sesion';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div class="card">
        <div class="auth-logo">SENSOR-IA WEB</div>
        <h2 style="text-align:center;margin-top:0;">Iniciar sesion</h2>

        <form method="post">
            <?= csrf_field() ?>
            <label>Correo</label>
            <input type="email" name="correo" required autofocus>

            <label>Clave</label>
            <input type="password" name="password" required>

            <div class="checkbox-row">
                <input type="checkbox" name="recordar" id="recordar">
                <label for="recordar" style="margin:0;">Recordar sesion</label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
        </form>

        <p style="text-align:center;margin-top:14px;font-size:13px;">
            <a href="<?= BASE_URL ?>/forgot_password.php">¿Olvidaste tu clave?</a>
        </p>
        <p style="text-align:center;font-size:13px;">
            ¿Tu institucion te dio un enlace de registro? Usalo directo para crear tu cuenta.
        </p>
        <p style="text-align:center;font-size:13px;">
            ¿Eres una institucion? <a href="<?= BASE_URL ?>/client_login.php">Inicia sesion aqui</a>
        </p>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
