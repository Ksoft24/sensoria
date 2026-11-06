<?php
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'datos') {
        $nombre    = trim($_POST['nombre'] ?? '');
        $ruc       = trim($_POST['ruc'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $telefono  = trim($_POST['telefono'] ?? '');
        $logoUrl   = trim($_POST['logo_url'] ?? '');

        if ($nombre === '' || $ruc === '') {
            $error = 'Nombre y RUC son obligatorios.';
        } else {
            db()->prepare("UPDATE clients SET nombre=?, ruc=?, direccion=?, telefono=?, logo_url=? WHERE id=?")
                ->execute([$nombre, $ruc, $direccion ?: null, $telefono ?: null, $logoUrl ?: null, $client['id']]);
            $success = 'Datos actualizados.';
            $client = current_client();
        }
    } elseif ($action === 'clave') {
        $actual = $_POST['actual'] ?? '';
        $nueva  = $_POST['nueva'] ?? '';
        $nueva2 = $_POST['nueva2'] ?? '';

        if (!password_verify($actual, $client['password_hash'])) {
            $error = 'Tu clave actual no es correcta.';
        } elseif (strlen($nueva) < 6) {
            $error = 'La clave nueva debe tener al menos 6 caracteres.';
        } elseif ($nueva !== $nueva2) {
            $error = 'Las claves nuevas no coinciden.';
        } else {
            db()->prepare("UPDATE clients SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $client['id']]);
            $success = 'Tu clave se actualizo correctamente.';
        }
    }
}

$page_title = 'Mi institucion';
$active_nav = 'client_perfil';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <h3>Datos de la institucion</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="datos">
            <label>Nombre / Razon social</label>
            <input type="text" name="nombre" value="<?= htmlspecialchars($client['nombre']) ?>" required>
            <label>RUC</label>
            <input type="text" name="ruc" value="<?= htmlspecialchars($client['ruc']) ?>" required>
            <label>Direccion</label>
            <input type="text" name="direccion" value="<?= htmlspecialchars($client['direccion'] ?? '') ?>">
            <label>Telefono</label>
            <input type="text" name="telefono" value="<?= htmlspecialchars($client['telefono'] ?? '') ?>">
            <label>URL del logo</label>
            <input type="text" name="logo_url" value="<?= htmlspecialchars($client['logo_url'] ?? '') ?>" placeholder="https://...">
            <p style="font-size:12px;color:var(--text-muted);">Correo de acceso: <?= htmlspecialchars($client['email']) ?> (para cambiarlo, contacta a SENSOR-IA)</p>
            <button type="submit" class="btn btn-primary btn-block">Guardar datos</button>
        </form>
    </div>

    <div class="card">
        <h3>Plan actual</h3>
        <p><strong>Tipo:</strong> <?= ucfirst($client['plan_tipo']) ?></p>
        <p><strong>Maximo de usuarios:</strong> <?= $client['max_usuarios'] !== null ? (int) $client['max_usuarios'] : 'Ilimitado' ?></p>
        <p><strong>Vencimiento:</strong> <?= $client['plan_vence_en'] ?: 'Sin vencimiento' ?></p>
        <p style="font-size:12px;color:var(--text-muted);">Para cambiar de plan, contacta a SENSOR-IA.</p>

        <hr style="border-color:var(--border);margin:18px 0;">

        <h3 style="margin-top:0;">Cambiar clave</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clave">
            <label>Clave actual</label>
            <input type="password" name="actual" required>
            <label>Clave nueva</label>
            <input type="password" name="nueva" required minlength="6">
            <label>Repetir clave nueva</label>
            <input type="password" name="nueva2" required minlength="6">
            <button type="submit" class="btn btn-ghost btn-block">Cambiar clave</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
