<?php
/**
 * client/accesos_externos.php
 * -----------------------------------------------------------------------
 * Panel de INSTITUCION: accesos de apps externas (api/api_app_login.php)
 * de TODOS sus usuarios (o filtrados por Grupo/Salon), con estadisticas
 * -que usuario ingresa mas, que app, que IP- y el detalle fila por fila.
 * Version institucional de mis_dispositivos.php (esa es solo para el
 * propio usuario). Datos de api_app_logins (sql/migration_007).
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

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

$grupoId = isset($_GET['grupo_id']) && ctype_digit((string) $_GET['grupo_id']) ? (int) $_GET['grupo_id'] : 0;

$errorCarga = null;
$grupos = [];
$totalAccesos = 0;
$totalUsuarios = 0;
$totalApps = 0;
$totalIps = 0;
$topUsuarios = [];
$topApps = [];
$topIps = [];
$serieDiaria = [];
$serieHoraria = array_fill(0, 24, 0);

try {
    $stmt = db()->prepare("SELECT id, nombre FROM grupos WHERE client_id = ? ORDER BY nombre");
    $stmt->execute([$client['id']]);
    $grupos = $stmt->fetchAll();
    if ($grupoId && !in_array($grupoId, array_column($grupos, 'id'), true)) {
        $grupoId = 0; // grupo invalido o de otra institucion: ignorar el filtro
    }

    // Cada columna se referencia con el alias "aal." (api_app_logins) o
    // "u." (users) de forma explicita, porque el JOIN mezcla columnas
    // con el mismo nombre (user_id/id) - sin el alias, el filtro de
    // grupo queda ambiguo para MySQL (ya nos paso antes con estadisticas.php).
    $where = "aal.client_id = ? AND aal.exitoso = 1";
    $params = [$client['id']];
    if ($grupoId) {
        $where .= " AND u.grupo_id = ?";
        $params[] = $grupoId;
    }
    if ($dias === 'custom') {
        $where .= " AND DATE(aal.creado_en) >= ? AND DATE(aal.creado_en) <= ?";
        $params[] = $fechaDesde;
        $params[] = $fechaHasta;
    } elseif ($dias !== '0') {
        $where .= " AND aal.creado_en >= NOW() - INTERVAL ? DAY";
        $params[] = (int) $dias;
    }

    $baseFrom = "FROM api_app_logins aal
                 JOIN users u ON u.id = aal.user_id
                 LEFT JOIN grupos g ON g.id = u.grupo_id";

    // Exportar CSV: el detalle fila por fila, con los MISMOS filtros de
    // grupo/rango de fechas ya aplicados en pantalla. Se hace ANTES de
    // las consultas agregadas para no perder tiempo calculando graficos
    // que no se van a mostrar. El listado completo NO se muestra en
    // pantalla (seria muy pesado para una institucion con muchos
    // usuarios) - solo queda disponible para descargar.
    if (($_GET['export'] ?? '') === 'csv') {
        $stmt = db()->prepare("SELECT aal.creado_en, u.nombres, u.apellidos, g.nombre AS grupo_nombre,
                                        aal.identificador, aal.nombre_aplicacion, aal.ip
                                 $baseFrom WHERE $where ORDER BY aal.creado_en DESC");
        $stmt->execute($params);
        $filasCsv = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="accesos_externos_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8: Excel respeta las tildes
        fputcsv($out, ['Fecha', 'Usuario', 'Grupo', 'Identificador', 'Aplicacion', 'IP']);
        foreach ($filasCsv as $r) {
            fputcsv($out, [
                $r['creado_en'],
                trim($r['nombres'] . ' ' . $r['apellidos']),
                $r['grupo_nombre'] ?? '-',
                $r['identificador'],
                $r['nombre_aplicacion'],
                $r['ip'] ?? '-',
            ]);
        }
        fclose($out);
        exit;
    }

    $stmt = db()->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT aal.user_id) AS usuarios,
                                    COUNT(DISTINCT aal.nombre_aplicacion) AS apps, COUNT(DISTINCT aal.ip) AS ips
                             $baseFrom WHERE $where");
    $stmt->execute($params);
    $kpis = $stmt->fetch();
    $totalAccesos = (int) $kpis['total'];
    $totalUsuarios = (int) $kpis['usuarios'];
    $totalApps = (int) $kpis['apps'];
    $totalIps = (int) $kpis['ips'];

    // Los usuarios que MAS ingresan via apps externas (lo especifico que
    // pidio la institucion, a diferencia del panel de usuario individual).
    $stmt = db()->prepare("SELECT u.id, u.nombres, u.apellidos, g.nombre AS grupo_nombre, COUNT(*) AS total
                             $baseFrom WHERE $where
                             GROUP BY u.id, u.nombres, u.apellidos ORDER BY total DESC LIMIT 10");
    $stmt->execute($params);
    $topUsuarios = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT aal.nombre_aplicacion, COUNT(*) AS total
                             $baseFrom WHERE $where
                             GROUP BY aal.nombre_aplicacion ORDER BY total DESC");
    $stmt->execute($params);
    $topApps = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT aal.ip, COUNT(*) AS total
                             $baseFrom WHERE $where AND aal.ip IS NOT NULL
                             GROUP BY aal.ip ORDER BY total DESC LIMIT 10");
    $stmt->execute($params);
    $topIps = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT DATE(aal.creado_en) AS dia, COUNT(*) AS total
                             $baseFrom WHERE $where GROUP BY DATE(aal.creado_en) ORDER BY dia");
    $stmt->execute($params);
    $serieDiaria = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT HOUR(aal.creado_en) AS hora, COUNT(*) AS total
                             $baseFrom WHERE $where GROUP BY HOUR(aal.creado_en)");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $serieHoraria[(int) $row['hora']] = (int) $row['total'];
    }
} catch (PDOException $e) {
    $errorCarga = 'No se pudo cargar el historial de accesos en este momento: ' . $e->getMessage();
}

$page_title = 'Accesos Externos';
$active_nav = 'client_accesos_externos';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div>
        <h2 style="margin:0;">Accesos externos de tus usuarios</h2>
        <p style="color:var(--text-muted);font-size:13px;margin:6px 0 0;">
            Cada vez que una app externa (Kivy, Broker, MicroIde) inicia sesion
            con la cuenta de alguno de tus usuarios a traves de
            <code>api/api_app_login.php</code> queda registrado aca.
        </p>
    </div>
    <form method="get" id="filtro-form" style="margin:0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select name="grupo_id" onchange="document.getElementById('filtro-form').submit()">
            <option value="0">Todos los grupos</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= $grupoId === (int) $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="dias" id="select-dias" onchange="onDiasChange()">
            <?php foreach ($RANGOS as $val => $label): ?>
                <option value="<?= $val ?>" <?= $dias === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <span id="rango-personalizado" style="display:<?= $dias === 'custom' ? 'inline-flex' : 'none' ?>;gap:8px;align-items:center;">
            <input type="date" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>" max="<?= $hoy ?>">
            <span style="color:var(--text-muted);">a</span>
            <input type="date" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>" max="<?= $hoy ?>">
            <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
        </span>
    </form>
</div>
<script>
    function onDiasChange() {
        const isCustom = document.getElementById('select-dias').value === 'custom';
        document.getElementById('rango-personalizado').style.display = isCustom ? 'inline-flex' : 'none';
        if (!isCustom) document.getElementById('filtro-form').submit();
    }
</script>

<?php if ($errorCarga): ?>
<div class="card" style="text-align:center;color:var(--text-muted);padding:40px;">
    <?= htmlspecialchars($errorCarga) ?>
</div>
<?php else: ?>

<div class="grid grid-4" style="margin-bottom:20px;">
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Total de accesos</div><div style="font-size:26px;font-weight:800;"><?= $totalAccesos ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Usuarios distintos</div><div style="font-size:26px;font-weight:800;"><?= $totalUsuarios ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Apps distintas</div><div style="font-size:26px;font-weight:800;"><?= $totalApps ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">IPs distintas</div><div style="font-size:26px;font-weight:800;"><?= $totalIps ?></div></div>
</div>

<?php if ($totalAccesos > 0): ?>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>Usuarios que mas ingresan</h3>
        <canvas id="chart-usuarios" height="220"></canvas>
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
        madrugada que no correspondan al uso habitual de tus usuarios.
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

    new Chart(document.getElementById('chart-usuarios'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($r) => trim($r['nombres'] . ' ' . $r['apellidos']), $topUsuarios)) ?>,
            datasets: [{ label: 'Accesos', data: <?= json_encode(array_map(fn($r) => (int) $r['total'], $topUsuarios)) ?>, backgroundColor: palette[0] }],
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

<div class="card">
    <h3>Historial completo</h3>
    <p style="color:var(--text-muted);font-size:13px;">
        El detalle fila por fila (usuario, grupo, identificador, app, IP
        y fecha de cada acceso) no se muestra en pantalla -para una
        institucion con muchos usuarios seria demasiado pesado-, pero
        podes descargarlo completo, con los mismos filtros de arriba ya
        aplicados.
    </p>
    <a class="btn btn-primary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">⬇ Descargar historial (CSV)</a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
