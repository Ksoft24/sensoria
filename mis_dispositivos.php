<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_operator();

// ---------------------------------------------------------------------
// Accesos de apps externas (api/api_app_login.php): identificador, app,
// ip y fecha de cada login SATISFACTORIO en esta cuenta (ver
// sql/migration_007_api_app_logins.sql). Por defecto, ultimos 30 dias.
// ---------------------------------------------------------------------
$RANGOS = [
    '7' => 'Ultimos 7 dias', '30' => 'Ultimos 30 dias', '90' => 'Ultimos 90 dias',
    '0' => 'Todo el historial', 'custom' => 'Personalizado...',
];
$dias = $_GET['dias'] ?? '30';
if (!array_key_exists($dias, $RANGOS)) $dias = '30';

$hoy = date('Y-m-d');
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fechaHasta = $_GET['fecha_hasta'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) $fechaDesde = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) $fechaHasta = $hoy;
if ($fechaDesde > $fechaHasta) [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];

$where = "user_id = ? AND exitoso = 1";
$params = [$user['id']];
if ($dias === 'custom') {
    $where .= " AND DATE(creado_en) >= ? AND DATE(creado_en) <= ?";
    $params[] = $fechaDesde;
    $params[] = $fechaHasta;
} elseif ($dias !== '0') {
    $where .= " AND creado_en >= NOW() - INTERVAL ? DAY";
    $params[] = (int) $dias;
}

$appLoginsError = null;
$totalAccesos = 0;
$totalIdentificadores = 0;
$totalApps = 0;
$totalIps = 0;
$topIdentificadores = [];
$topApps = [];
$topIps = [];
$serieDiaria = [];
$serieHoraria = array_fill(0, 24, 0);

try {
    // Exportar CSV: el detalle fila por fila con los MISMOS filtros de fechas
    // aplicados en pantalla. El listado ya NO se muestra en la pagina (solo
    // se descarga). BOM UTF-8 para que Excel respete las tildes.
    if (($_GET['export'] ?? '') === 'csv') {
        $stmt = db()->prepare("SELECT creado_en, identificador, nombre_aplicacion, ip
                                 FROM api_app_logins WHERE $where ORDER BY creado_en DESC");
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mis_accesos_externos_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Fecha', 'Identificador', 'Aplicacion', 'IP']);
        foreach ($stmt->fetchAll() as $r) {
            fputcsv($out, [$r['creado_en'], $r['identificador'], $r['nombre_aplicacion'], $r['ip'] ?? '-']);
        }
        fclose($out);
        exit;
    }

    $stmt = db()->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT identificador) AS identificadores,
                                    COUNT(DISTINCT nombre_aplicacion) AS apps, COUNT(DISTINCT ip) AS ips
                             FROM api_app_logins WHERE $where");
    $stmt->execute($params);
    $kpis = $stmt->fetch();
    $totalAccesos = (int) $kpis['total'];
    $totalIdentificadores = (int) $kpis['identificadores'];
    $totalApps = (int) $kpis['apps'];
    $totalIps = (int) $kpis['ips'];

    $stmt = db()->prepare("SELECT identificador, COUNT(*) AS total FROM api_app_logins
                             WHERE $where GROUP BY identificador ORDER BY total DESC LIMIT 10");
    $stmt->execute($params);
    $topIdentificadores = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT nombre_aplicacion, COUNT(*) AS total FROM api_app_logins
                             WHERE $where GROUP BY nombre_aplicacion ORDER BY total DESC");
    $stmt->execute($params);
    $topApps = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT ip, COUNT(*) AS total FROM api_app_logins
                             WHERE $where AND ip IS NOT NULL GROUP BY ip ORDER BY total DESC LIMIT 10");
    $stmt->execute($params);
    $topIps = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT DATE(creado_en) AS dia, COUNT(*) AS total FROM api_app_logins
                             WHERE $where GROUP BY DATE(creado_en) ORDER BY dia");
    $stmt->execute($params);
    $serieDiaria = $stmt->fetchAll();

    // Accesos por hora del dia (0-23): util para notar patrones raros,
    // por ejemplo accesos de madrugada que no correspondan al uso normal.
    $stmt = db()->prepare("SELECT HOUR(creado_en) AS hora, COUNT(*) AS total FROM api_app_logins
                             WHERE $where GROUP BY HOUR(creado_en)");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $serieHoraria[(int) $row['hora']] = (int) $row['total'];
    }
} catch (PDOException $e) {
    $appLoginsError = 'No se pudo cargar el historial de accesos en este momento.';
}

$page_title = 'Accesos Externos';
$active_nav = 'devices';
require __DIR__ . '/includes/header.php';
?>

<div class="card" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div>
        <h2 style="margin:0;">Accesos de apps externas</h2>
        <p style="color:var(--text-muted);font-size:13px;margin:6px 0 0;">
            Cada vez que una app externa (Kivy, Broker, MicroIde) inicia sesion
            con tu cuenta a traves de <code>api/api_app_login.php</code> queda
            registrado aca: desde que identificador, con que app, desde que IP y cuando.
        </p>
    </div>
    <form method="get" id="filtro-accesos-form" style="margin:0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select name="dias" id="select-dias-accesos" onchange="onDiasAccesosChange()">
            <?php foreach ($RANGOS as $val => $label): ?>
                <option value="<?= $val ?>" <?= $dias === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <span id="rango-accesos-personalizado" style="display:<?= $dias === 'custom' ? 'inline-flex' : 'none' ?>;gap:8px;align-items:center;">
            <input type="date" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>" max="<?= $hoy ?>">
            <span style="color:var(--text-muted);">a</span>
            <input type="date" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>" max="<?= $hoy ?>">
            <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
        </span>
        <a class="btn btn-ghost" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">&#11015; Descargar CSV</a>
    </form>
</div>
<script>
    function onDiasAccesosChange() {
        const isCustom = document.getElementById('select-dias-accesos').value === 'custom';
        document.getElementById('rango-accesos-personalizado').style.display = isCustom ? 'inline-flex' : 'none';
        if (!isCustom) document.getElementById('filtro-accesos-form').submit();
    }
</script>

<?php if ($appLoginsError): ?>
<div class="card" style="text-align:center;color:var(--text-muted);padding:40px;">
    <?= htmlspecialchars($appLoginsError) ?>
</div>
<?php else: ?>

<div class="grid grid-4" style="margin-bottom:20px;">
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Total de accesos</div><div style="font-size:26px;font-weight:800;"><?= $totalAccesos ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Identificadores distintos</div><div style="font-size:26px;font-weight:800;"><?= $totalIdentificadores ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Apps distintas</div><div style="font-size:26px;font-weight:800;"><?= $totalApps ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">IPs distintas</div><div style="font-size:26px;font-weight:800;"><?= $totalIps ?></div></div>
</div>

<?php if ($totalAccesos > 0): ?>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>Identificadores que mas ingresan</h3>
        <canvas id="chart-identificadores" height="220"></canvas>
    </div>
    <div class="card">
        <h3>Apps que mas ingresan</h3>
        <canvas id="chart-apps" height="220"></canvas>
    </div>
</div>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>IPs con mas accesos</h3>
        <canvas id="chart-ips" height="220"></canvas>
    </div>
    <div class="card">
        <h3>Accesos por dia</h3>
        <canvas id="chart-diario" height="220"></canvas>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3>Accesos por hora del dia</h3>
    <p style="font-size:12px;color:var(--text-muted);margin-top:-6px;">
        Util para detectar patrones raros - por ejemplo, accesos de
        madrugada que no correspondan a tu uso habitual.
    </p>
    <canvas id="chart-horas" height="140"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(() => {
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('canvas').forEach(c => c.insertAdjacentHTML('afterend', '<p style="color:var(--text-muted);font-size:13px;">No se pudo cargar la libreria de graficos (assets/vendor/chart.umd.min.js).</p>'));
        return;
    }
    const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#888';
    Chart.defaults.color = cssVar('--text-muted');
    Chart.defaults.borderColor = cssVar('--border');
    const palette = ['#4f9dff', '#2ecc71', '#f2b705', '#e74c3c', '#9b59b6', '#1abc9c', '#e67e22'];

    new Chart(document.getElementById('chart-identificadores'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($r) => $r['identificador'], $topIdentificadores)) ?>,
            datasets: [{ label: 'Accesos', data: <?= json_encode(array_map(fn($r) => (int) $r['total'], $topIdentificadores)) ?>, backgroundColor: palette[0] }],
        },
        options: { plugins: { legend: { display: false } }, indexAxis: 'y' },
    });

    new Chart(document.getElementById('chart-apps'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_map(fn($r) => $r['nombre_aplicacion'], $topApps)) ?>,
            datasets: [{ data: <?= json_encode(array_map(fn($r) => (int) $r['total'], $topApps)) ?>, backgroundColor: palette }],
        },
        options: { plugins: { legend: { position: 'bottom' } } },
    });

    new Chart(document.getElementById('chart-ips'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($r) => $r['ip'], $topIps)) ?>,
            datasets: [{ label: 'Accesos', data: <?= json_encode(array_map(fn($r) => (int) $r['total'], $topIps)) ?>, backgroundColor: palette[2] }],
        },
        options: { plugins: { legend: { display: false } }, indexAxis: 'y' },
    });

    new Chart(document.getElementById('chart-diario'), {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map(fn($r) => $r['dia'], $serieDiaria)) ?>,
            datasets: [{ label: 'Accesos', data: <?= json_encode(array_map(fn($r) => (int) $r['total'], $serieDiaria)) ?>, borderColor: palette[0], backgroundColor: 'transparent', tension: 0.3 }],
        },
        options: { plugins: { legend: { display: false } } },
    });

    new Chart(document.getElementById('chart-horas'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($h) => $h . 'h', range(0, 23))) ?>,
            datasets: [{ label: 'Accesos', data: <?= json_encode(array_values($serieHoraria)) ?>, backgroundColor: palette[4] }],
        },
        options: { plugins: { legend: { display: false } } },
    });
})();
</script>
<?php endif; ?>

<div class="card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div>
        <h3 style="margin:0;">Detalle de accesos</h3>
        <p style="color:var(--text-muted);font-size:13px;margin:6px 0 0;">
            <?= $totalAccesos > 0
                ? number_format($totalAccesos) . ' acceso(s) en este periodo. El detalle (fecha, identificador, aplicacion e IP) se descarga en CSV.'
                : 'Ninguna app externa inicio sesion con tu cuenta en este periodo.' ?>
        </p>
    </div>
    <?php if ($totalAccesos > 0): ?>
    <a class="btn btn-primary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">&#11015; Descargar CSV</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
