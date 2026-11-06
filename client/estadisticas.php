<?php
/**
 * client/estadisticas.php
 * -----------------------------------------------------------------------
 * Panel de INSTITUCION: estadisticas agregadas de TODOS sus usuarios
 * (o filtradas por Grupo/Salon), separadas por fuente (OnlineCam en
 * vivo, StaticCam, ApiCam), con modelos usados, etiquetas detectadas,
 * reglas activadas (y de que fuente vino cada una), tiempo de
 * inferencia, confianza, y el ranking de usuarios mas activos.
 * Datos de inference_events.
 *
 * Nota sobre OnlineCam (los 7 modulos en vivo): NO se registra cada
 * inferencia (seria demasiado volumen a 10-30 cuadros/seg) - solo se
 * registra cuando una Regla se activa, y una sola vez por activacion
 * (ver assets/js/rules-engine.js). StaticCam y ApiCam si registran cada
 * captura individual, incluso si ninguna regla se activo.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/stats_helpers.php';
$client = require_client();

$RANGOS = [
    '7' => 'Ultimos 7 dias', '30' => 'Ultimos 30 dias', '90' => 'Ultimos 90 dias',
    '0' => 'Todo el historial', 'custom' => 'Personalizado...',
];
$dias = $_GET['dias'] ?? '0'; // Por defecto '0' (Todo el historial) para que no oculte registros por fechas relativas
if (!array_key_exists($dias, $RANGOS)) $dias = '0';

$hoy = date('Y-m-d');
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fechaHasta = $_GET['fecha_hasta'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) $fechaDesde = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) $fechaHasta = $hoy;
if ($fechaDesde > $fechaHasta) [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];

$grupoId = isset($_GET['grupo_id']) && ctype_digit((string) $_GET['grupo_id']) ? (int) $_GET['grupo_id'] : 0;

$statsError = null;
$grupos = [];
$porFuente = array_fill_keys(stats_fuentes_validas(), 0);
$reglasPorFuente = array_fill_keys(stats_fuentes_validas(), 0);
$porApp = $dispositivos = $reglasWeb = $reglasExt = [];
$totalEventos = 0;
$totalReglas = 0;
$porModulo = [];
$topClases = [];
$topUsuarios = [];
$serieDiaria = [];

try {
    $stmt = db()->prepare("SELECT id, nombre FROM grupos WHERE client_id = ? ORDER BY nombre");
    $stmt->execute([$client['id']]);
    $grupos = $stmt->fetchAll();
    if ($grupoId && !in_array($grupoId, array_column($grupos, 'id'), true)) {
        $grupoId = 0; // grupo invalido o de otra institucion: ignorar el filtro
    }

    // Cada columna se referencia siempre con el alias "ie." (inference_events)
    // porque varias de las consultas hacen JOIN con "users"/"rules", que
    // tienen columnas con el MISMO nombre (grupo_id, user_id) - sin el
    // alias explicito, el filtro de grupo queda ambiguo para MySQL.
    $where = "ie.client_id = ?";
    $params = [$client['id']];
    if ($grupoId) {
        $where .= " AND ie.grupo_id = ?";
        $params[] = $grupoId;
    }
    // Ajuste de fechas tolerante para evitar que se oculten registros por desfase de horas/dias
    if ($dias === 'custom') {
        $where .= " AND DATE(ie.created_at) >= ? AND DATE(ie.created_at) <= ?";
        $params[] = $fechaDesde;
        $params[] = $fechaHasta;
    } elseif ($dias !== '0') {
        $where .= " AND ie.created_at >= NOW() - INTERVAL ? DAY";
        $params[] = (int) $dias;
    }

    // Exportar CSV: el detalle fila por fila (cada activacion de regla),
    // con los MISMOS filtros de grupo/rango de fechas ya aplicados en
    // pantalla. Se hace ANTES de las consultas agregadas para no perder
    // tiempo calculando graficos que no se van a mostrar.
    if (($_GET['export'] ?? '') === 'csv') {
        $stmt = db()->prepare("SELECT ie.created_at, u.nombres, u.apellidos, g.nombre AS grupo_nombre,
                                        ie.source, ie.module, ie.class_name, ie.confidence, ie.inference_ms, ie.action_pin,
                                        ie.nombre_aplicacion, ie.device_identificador, ie.rule_id, ie.rule_matched, ie.events_count
                                 FROM inference_events ie
                                 JOIN users u ON u.id = ie.user_id
                                 LEFT JOIN grupos g ON g.id = ie.grupo_id
                                 WHERE $where
                                 ORDER BY ie.created_at DESC");
        $stmt->execute($params);
        $filasCsv = $stmt->fetchAll();

        $MODULE_LABELS_CSV = [
            'yolo' => 'Deteccion de Objetos', 'yolo_seg' => 'Segmentacion', 'movenet' => 'Pose (MoveNet)',
            'handlandmarks' => 'Gestos de Mano', 'pinch' => 'Control de Pinza', 'qr' => 'Codigo QR', 'aruco' => 'Marcador ArUco',
    'colorimeter' => 'Colorimetro', 'geometric_scan' => 'GeometricScan',
        ];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="estadisticas_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Fecha', 'Usuario', 'Grupo', 'Fuente', 'Modelo', 'Regla/Etiqueta activada', 'Confianza (%)', 'Tiempo de inferencia (ms)', 'Salida', 'Aplicacion', 'Dispositivo', 'ID regla (del dispositivo si es app externa)', 'Regla activada', 'Eventos']);
        foreach ($filasCsv as $r) {
            fputcsv($out, [
                $r['created_at'],
                trim($r['nombres'] . ' ' . $r['apellidos']),
                $r['grupo_nombre'] ?? '-',
                stats_fuente_label($r['source']),
                $MODULE_LABELS_CSV[$r['module']] ?? $r['module'],
                $r['class_name'] ?? '-',
                $r['confidence'] ?? '-',
                $r['inference_ms'] ?? '-',
                $r['action_pin'] ?? '-',
                $r['nombre_aplicacion'] ?? 'panel web',
                $r['device_identificador'] ?? '-',
                $r['rule_id'] ?? '-',
                $r['rule_matched'] ? 'Si' : 'No',
                $r['events_count'],
            ]);
        }
        fclose($out);
        exit;
    }

    $stmt = db()->prepare("SELECT source,
                                    SUM(events_count) AS total,
                                    SUM(CASE WHEN rule_matched = 1 THEN events_count ELSE 0 END) AS reglas
                             FROM inference_events ie WHERE $where GROUP BY source");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $porFuente[$row['source']] = (int) $row['total'];
        $reglasPorFuente[$row['source']] = (int) $row['reglas'];
    }
    $totalEventos = array_sum($porFuente);
    $totalReglas  = array_sum($reglasPorFuente);
    $totalWeb = 0;
    foreach (stats_fuentes_web() as $fw) $totalWeb += $porFuente[$fw];
    $totalExterno = $totalEventos - $totalWeb;

    $stmt = db()->prepare("SELECT module,
                                    SUM(events_count) AS total,
                                    ROUND(AVG(confidence), 1) AS conf,
                                    ROUND(AVG(inference_ms), 1) AS ms
                             FROM inference_events ie WHERE $where GROUP BY module ORDER BY total DESC");
    $stmt->execute($params);
    $porModulo = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT class_name, SUM(events_count) AS total
                             FROM inference_events ie WHERE $where AND ie.class_name IS NOT NULL
                             GROUP BY class_name ORDER BY total DESC LIMIT 10");
    $stmt->execute($params);
    $topClases = $stmt->fetchAll();

    // Reglas / apps / dispositivos (rule_id externo NO se cruza con la tabla rules)
    ['porApp' => $porApp, 'dispositivos' => $dispositivos, 'reglasWeb' => $reglasWeb, 'reglasExt' => $reglasExt] = stats_cargar_extra($where, $params);

    // Ranking de usuarios mas activos (con su grupo, para verlo aunque no se filtre)
    $stmt = db()->prepare("SELECT u.id, u.nombres, u.apellidos, MAX(g.nombre) AS grupo_nombre,
                                    SUM(ie.events_count) AS total,
                                    SUM(CASE WHEN ie.rule_matched = 1 THEN ie.events_count ELSE 0 END) AS reglas
                             FROM inference_events ie
                             JOIN users u ON u.id = ie.user_id
                             LEFT JOIN grupos g ON g.id = ie.grupo_id
                             WHERE $where
                             GROUP BY u.id, u.nombres, u.apellidos
                             ORDER BY total DESC LIMIT 15");
    $stmt->execute($params);
    $topUsuarios = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT DATE(created_at) AS dia, SUM(events_count) AS total
                             FROM inference_events ie WHERE $where GROUP BY DATE(ie.created_at) ORDER BY dia");
    $stmt->execute($params);
    $serieDiaria = $stmt->fetchAll();
} catch (PDOException $e) {
    $statsError = 'No se pudieron cargar las Estadisticas en este momento: ' . $e->getMessage();
}

$MODULE_LABELS = [
    'yolo' => 'Deteccion de Objetos', 'yolo_seg' => 'Segmentacion', 'movenet' => 'Pose (MoveNet)',
    'handlandmarks' => 'Gestos de Mano', 'pinch' => 'Control de Pinza', 'qr' => 'Codigo QR', 'aruco' => 'Marcador ArUco',
    'colorimeter' => 'Colorimetro', 'geometric_scan' => 'GeometricScan',
];
$page_title = 'Estadisticas';
$active_nav = 'client_estadisticas';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div>
        <h2 style="margin:0;">Estadisticas de la institucion</h2>
        <p style="color:var(--text-muted);font-size:13px;margin:6px 0 0;">
            Actividad de todos tus usuarios (o de un grupo/salon en particular).
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
        <a class="btn btn-ghost" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">⬇ Descargar CSV</a>
    </form>
</div>
<script>
    function onDiasChange() {
        const isCustom = document.getElementById('select-dias').value === 'custom';
        document.getElementById('rango-personalizado').style.display = isCustom ? 'inline-flex' : 'none';
        if (!isCustom) document.getElementById('filtro-form').submit();
    }
</script>

<?php if ($statsError): ?>
<div class="card" style="text-align:center;color:var(--text-muted);padding:40px;">
    <?= htmlspecialchars($statsError) ?>
</div>
<?php else: ?>

<div class="grid grid-5" style="margin-bottom:20px;">
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Total de eventos</div><div style="font-size:26px;font-weight:800;"><?= $totalEventos ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Panel web</div><div style="font-size:26px;font-weight:800;"><?= $totalWeb ?></div>
        <div style="font-size:11px;color:var(--text-muted);">OnlineCam <?= $porFuente['onlinecam'] ?> &middot; StaticCam <?= $porFuente['staticcam'] ?> &middot; ApiCam <?= $porFuente['apicam'] ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Apps externas</div><div style="font-size:26px;font-weight:800;"><?= $totalExterno ?></div>
        <div style="font-size:11px;color:var(--text-muted);"><?= count($porApp) ?> app(s) &middot; <?= count($dispositivos) ?> dispositivo(s)</div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Reglas activadas</div><div style="font-size:26px;font-weight:800;color:var(--accent);"><?= $totalReglas ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">% de activacion</div><div style="font-size:26px;font-weight:800;"><?= $totalEventos ? round($totalReglas / $totalEventos * 100) : 0 ?>%</div></div>
</div>

<?php if ($totalEventos === 0): ?>
<div class="card" style="text-align:center;color:var(--text-muted);padding:40px;">
    Todavia no hay datos <?= $grupoId ? 'para ese grupo' : 'de tu institucion' ?> en este periodo.
</div>
<?php else: ?>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>Eventos por fuente</h3>
        <canvas id="chart-fuente" height="220"></canvas>
    </div>
    <div class="card">
        <h3>Eventos por dia</h3>
        <canvas id="chart-diario" height="220"></canvas>
    </div>
</div>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>Modelos usados</h3>
        <canvas id="chart-modulo" height="240"></canvas>
    </div>
    <div class="card">
        <h3>Etiquetas mas detectadas</h3>
        <canvas id="chart-clases" height="240"></canvas>
    </div>
</div>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>Reglas activadas por origen</h3>
        <?php if ($totalReglas === 0): ?>
            <p style="color:var(--text-muted);font-size:13px;">Ninguna regla se activo en este periodo.</p>
        <?php else: ?>
        <canvas id="chart-reglas-origen" height="220"></canvas>
        <div style="display:flex;justify-content:space-around;flex-wrap:wrap;gap:8px;margin-top:10px;font-size:13px;">
            <?php foreach (array_filter($reglasPorFuente) as $fuente => $n): ?>
                <div style="text-align:center;">
                    <div style="color:var(--text-muted);font-size:11px;"><?= htmlspecialchars(stats_fuente_label($fuente)) ?></div>
                    <div style="font-weight:700;"><?= $n ?> (<?= $totalReglas ? round($n / $totalReglas * 100) : 0 ?>%)</div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3>Detalle por modelo</h3>
        <table>
            <thead><tr><th>Modelo</th><th>Eventos</th><th>Confianza prom.</th><th>Inferencia prom.</th></tr></thead>
            <tbody>
                <?php foreach ($porModulo as $m): ?>
                <tr>
                    <td><?= htmlspecialchars($MODULE_LABELS[$m['module']] ?? $m['module']) ?></td>
                    <td><?= (int) $m['total'] ?></td>
                    <td><?= $m['conf'] !== null ? $m['conf'] . '%' : '-' ?></td>
                    <td><?= $m['ms'] !== null ? $m['ms'] . ' ms' : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/stats_externas_view.php'; ?>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>Usuarios mas activos</h3>
        <table>
            <thead><tr><th>Usuario</th><th>Grupo</th><th>Eventos</th><th>Reglas</th></tr></thead>
            <tbody>
                <?php foreach ($topUsuarios as $u): ?>
                <tr>
                    <td><?= htmlspecialchars(trim($u['nombres'] . ' ' . $u['apellidos'])) ?></td>
                    <td><?= htmlspecialchars($u['grupo_nombre'] ?? '-') ?></td>
                    <td><?= (int) $u['total'] ?></td>
                    <td><?= (int) $u['reglas'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
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
    const palette = ['#4f9dff', '#2ecc71', '#f2b705', '#e74c3c', '#9b59b6', '#1abc9c', '#e67e22', '#34495e', '#fd79a8', '#00b894', '#6c5ce7'];

    <?php $__f = array_filter($porFuente); ?>
    new Chart(document.getElementById('chart-fuente'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_map('stats_fuente_label', array_keys($__f))) ?>,
            datasets: [{ data: <?= json_encode(array_values($__f)) ?>, backgroundColor: palette }],
        },
        options: { plugins: { legend: { position: 'bottom' } } },
    });

    new Chart(document.getElementById('chart-diario'), {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map(fn($r) => $r['dia'], $serieDiaria)) ?>,
            datasets: [{ label: 'Eventos', data: <?= json_encode(array_map(fn($r) => (int) $r['total'], $serieDiaria)) ?>, borderColor: palette[0], backgroundColor: 'transparent', tension: 0.3 }],
        },
        options: { plugins: { legend: { display: false } } },
    });

    new Chart(document.getElementById('chart-modulo'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($m) => $MODULE_LABELS[$m['module']] ?? $m['module'], $porModulo)) ?>,
            datasets: [{ label: 'Eventos', data: <?= json_encode(array_map(fn($m) => (int) $m['total'], $porModulo)) ?>, backgroundColor: palette[1] }],
        },
        options: { plugins: { legend: { display: false } }, indexAxis: 'y' },
    });

    new Chart(document.getElementById('chart-clases'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($c) => $c['class_name'], $topClases)) ?>,
            datasets: [{ label: 'Detecciones', data: <?= json_encode(array_map(fn($c) => (int) $c['total'], $topClases)) ?>, backgroundColor: palette[2] }],
        },
        options: { plugins: { legend: { display: false } }, indexAxis: 'y' },
    });

    <?php if ($totalReglas > 0): ?>
    <?php $__f = array_filter($reglasPorFuente); ?>
    new Chart(document.getElementById('chart-reglas-origen'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_map('stats_fuente_label', array_keys($__f))) ?>,
            datasets: [{ data: <?= json_encode(array_values($__f)) ?>, backgroundColor: palette }],
        },
        options: { plugins: { legend: { position: 'bottom' } } },
    });
    <?php endif; ?>
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
