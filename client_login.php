<?php
require_once __DIR__ . '/includes/functions.php';

if (current_client()) {
    redirect('/client/usuarios.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare("SELECT * FROM clients WHERE email = ?");
    $stmt->execute([$email]);
    $client = $stmt->fetch();

    if (!$client || !password_verify($password, $client['password_hash'])) {
        $error = 'Correo o clave incorrectos.';
    } elseif ($client['estado'] === 'pending') {
        $error = 'Tu cuenta institucional esta pendiente de aprobacion. Contacta a SENSOR-IA.';
    } elseif ($client['estado'] === 'suspended') {
        $error = 'Tu cuenta institucional esta suspendida. Contacta a SENSOR-IA.';
    } else {
        $_SESSION['client_id'] = $client['id'];
        redirect('/client/usuarios.php');
    }

    // Post/Redirect/Get, igual que login.php - evita el dialogo de
    // "reenviar formulario" al recargar despues de un intento fallido.
    if ($error) {
        flash('error', $error);
        redirect('/client_login.php');
    }
}

$page_title = 'Ingreso institucional';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div class="card">
        <div class="auth-logo">SENSOR-IA WEB</div>
        <h2 style="text-align:center;margin-top:0;">Panel de tu institucion</h2>
        <p style="text-align:center;color:var(--text-muted);font-size:13px;margin-top:-10px;">
            Aqui inician sesion los colegios/academias para gestionar sus
            propios usuarios y grupos.
        </p>

        <form method="post">
            <?= csrf_field() ?>
            <label>Correo institucional</label>
            <input type="email" name="email" required autofocus>

            <label>Clave</label>
            <input type="password" name="password" required>

            <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
        </form>

        <p style="text-align:center;margin-top:14px;font-size:13px;">
            ¿Eres un usuario (estudiante/docente)? <a href="<?= BASE_URL ?>/login.php">Inicia sesion aqui</a>
        </p>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
