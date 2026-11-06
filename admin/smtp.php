<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/mailer.php';
$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'probar') {
        [$sent, $err] = send_mail($user['correo'], 'Correo de prueba - ' . APP_NAME,
            '<p>Si recibiste este correo, tu configuracion SMTP funciona correctamente.</p>');
        flash($sent ? 'success' : 'error', $sent ? 'Correo de prueba enviado a ' . $user['correo'] : $err);
        redirect('/admin/smtp.php');
    }

    $host       = trim($_POST['host'] ?? '');
    $port       = (int) ($_POST['port'] ?? 587);
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $encryption = $_POST['encryption'] ?? 'tls';
    $from_name  = trim($_POST['from_name'] ?? APP_NAME);
    $from_email = trim($_POST['from_email'] ?? '');

    // Si dejan la clave en blanco, se conserva la actual (para no forzar
    // a re-escribirla cada vez que se edita otro campo)
    if ($password === '') {
        $current = db()->query("SELECT password FROM smtp_config WHERE id = 1")->fetchColumn();
        $password = $current ?: '';
    }

    db()->prepare("UPDATE smtp_config SET host=?, port=?, username=?, password=?, encryption=?, from_name=?, from_email=? WHERE id = 1")
        ->execute([$host, $port, $username, $password, $encryption, $from_name, $from_email]);

    flash('success', 'Configuracion SMTP guardada.');
    redirect('/admin/smtp.php');
}

$cfg = db()->query("SELECT * FROM smtp_config WHERE id = 1")->fetch();

$page_title = 'Configuracion SMTP';
$active_nav = 'admin_smtp';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:560px;">
    <h3>Configuracion SMTP</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        Usado para enviar los correos de confirmacion de registro y recuperacion de clave.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <label>Host SMTP</label>
        <input type="text" name="host" value="<?= htmlspecialchars($cfg['host']) ?>" placeholder="smtp.gmail.com" required>

        <div class="field-row">
            <div>
                <label>Puerto</label>
                <input type="number" name="port" value="<?= (int) $cfg['port'] ?>" required>
            </div>
            <div>
                <label>Encriptacion</label>
                <select name="encryption">
                    <option value="tls" <?= $cfg['encryption'] === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS, 587)</option>
                    <option value="ssl" <?= $cfg['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                    <option value="none" <?= $cfg['encryption'] === 'none' ? 'selected' : '' ?>>Ninguna</option>
                </select>
            </div>
        </div>

        <label>Usuario SMTP</label>
        <input type="text" name="username" value="<?= htmlspecialchars($cfg['username']) ?>" required>

        <label>Clave SMTP <span style="color:var(--text-muted);">(dejar en blanco para no cambiarla)</span></label>
        <input type="password" name="password" placeholder="••••••••">

        <div class="field-row">
            <div>
                <label>Nombre del remitente</label>
                <input type="text" name="from_name" value="<?= htmlspecialchars($cfg['from_name']) ?>">
            </div>
            <div>
                <label>Correo del remitente</label>
                <input type="email" name="from_email" value="<?= htmlspecialchars($cfg['from_email']) ?>">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Guardar configuracion</button>
    </form>

    <form method="post" style="margin-top:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="probar">
        <button type="submit" class="btn btn-ghost btn-block">Enviar correo de prueba a mi cuenta</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
