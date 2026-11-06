<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();

$stmt = db()->prepare("SELECT * FROM devices WHERE user_id = ? ORDER BY solicitado_en DESC");
$stmt->execute([$user['id']]);
$devices = $stmt->fetchAll();

$stmt = db()->prepare("SELECT COUNT(*) FROM rules WHERE user_id = ?");
$stmt->execute([$user['id']]);
$totalRules = (int) $stmt->fetchColumn();

$activos = count(array_filter($devices, fn($d) => $d['estado'] === 'active'));

$page_title = 'Panel';
$active_nav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-3">
    <div class="card">
        <h3>Institucion</h3>
        <p style="font-size:20px;font-weight:700;color:var(--accent);"><?= htmlspecialchars($user['client_nombre'] ?? '-') ?></p>
        <p style="color:var(--text-muted);font-size:13px;">
            Plan <?= htmlspecialchars(ucfirst($user['plan_tipo'] ?? '-')) ?><br>
            Grupo: <?= htmlspecialchars($user['grupo_nombre'] ?? '-') ?>
        </p>
    </div>
    <div class="card">
        <h3>Dispositivos</h3>
        <p style="font-size:22px;font-weight:700;"><?= $activos ?></p>
        <p style="color:var(--text-muted);font-size:13px;">Equipo(s) activo(s)</p>
        <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/mis_dispositivos.php">Ver dispositivos</a>
    </div>
    <div class="card">
        <h3>Reglas</h3>
        <p style="font-size:22px;font-weight:700;"><?= $totalRules ?></p>
        <p style="color:var(--text-muted);font-size:13px;">Reglas configuradas en total</p>
        <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/reglas.php">Administrar reglas</a>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Dispositivos recientes</h3>
    <?php if (!$devices): ?>
        <p style="color:var(--text-muted);">Aun no has solicitado la activacion de ningun dispositivo desde la app.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Codigo</th><th>Nombre</th><th>Estado</th><th>Solicitado</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($devices, 0, 5) as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['device_code']) ?></td>
                    <td><?= htmlspecialchars($d['nombre'] ?? '-') ?></td>
                    <td>
                        <?php
                        $badge = ['pending'=>'warn','active'=>'ok','rejected'=>'danger','revoked'=>'danger'][$d['estado']] ?? 'muted';
                        ?>
                        <span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($d['estado']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($d['solicitado_en']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
