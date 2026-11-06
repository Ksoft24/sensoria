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
$porFuente = ['onlinecam' => 0, 'staticcam' => 0, 'apicam' => 0];
$reglasPorFuente = ['onlinecam' => 0, 'staticcam' => 0, 'apicam' => 0];
$totalEventos = 0;
$totalReglas = 0;
$porModulo = [];
$topClases = [];
$reglasActivadas = [];
$topUsuarios = [];
$serieDiaria = [];

try {
    $stmt = db()->prepare("SELECT id, nombre FROM grupos WHERE client_id = ? ORDER BY nombre");
    $stmt->execute([$client['id']]);
    $grupos = $stmt->fetchAll();
    if ($grupoId && !in_array($grupoId, array_column($grupos, 'id'), true)) {
        $grupoId = 0; 
    }

    $where = "ie.client_id = ?";
    $params = [$client['id']];
    if ($grupoId) {
        $where .= " AND ie.grupo_id = ?";
        $params[] = $grupoId;
    }
    
    // Ajuste de fechas tolerante para evitar que se oculten registros por desfase de horas/días
    if ($dias === 'custom') {
        $where .= " AND DATE(ie.created_at) >= ? AND DATE(ie.created_at) <= ?";
        $params[] = $fechaDesde;
        $params[] = $fechaHasta;
    } elseif ($dias !== '0') {
        $where .= " AND ie.created_at >= NOW() - INTERVAL ? DAY";
        $params[] = (int) $dias;
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

    $stmt = db()->prepare("SELECT r.id, r.module, r.class_name AS rule_class, r.action_pin, r.scale_out_min,
                                   COUNT(*) AS veces, MAX(ie.created_at) AS ultima
                           FROM inference_events ie
                           JOIN rules r ON r.id = ie.rule_id
                           WHERE $where AND ie.rule_matched = 1
                           GROUP BY r.id ORDER BY veces DESC LIMIT 15");
    $stmt->execute($params);
    $reglasActivadas = $stmt->fetchAll();

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
];
$FUENTE_LABELS = ['onlinecam' => 'OnlineCam', 'staticcam' => 'StaticCam', 'apicam' => 'ApiCam'];

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
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">OnlineCam (en vivo)</div><div style="font-size:26px;font-weight:800;"><?= $porFuente['onlinecam'] ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">StaticCam</div><div style="font-size:26px;font-weight:800;"><?= $porFuente['staticcam'] ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">ApiCam</div><div style="font-size:26px;font-weight:800;"><?= $porFuente['apicam'] ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Reglas activadas</div><div style="font-size:26px;font-weight:800;color:var(--accent);"><?= $totalReglas ?></div></div>
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
        <div style="display:flex;justify-content:space-around;margin-top:10px;font-size:13px;">
            <?php foreach ($reglasPorFuente as $fuente => $n): ?>
                <div style="text-align:center;">
                    <div style="color:var(--text-muted);font-size:11px;"><?= $FUENTE_LABELS[$fuente] ?></div>
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

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>Reglas que se activaron</h3>
        <?php if (!$reglasActivadas): ?>
            <p style="color:var(--text-muted);font-size:13px;">Ninguna regla se activo en este periodo.</p>
        <?php else: ?>
        <table>
            <thead><tr><th>Regla</th><th>Salida</th><th>Veces</th></tr></thead>
            <tbody>
                <?php foreach ($reglasActivadas as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($MODULE_LABELS[$r['module']] ?? $r['module']) ?> -
                        <?= htmlspecialchars($r['scale_out_min'] !== null ? 'Escala continua' : ($r['rule_class'] ?: '(cualquier clase)')) ?></td>
                    <td><?= htmlspecialchars($r['action_pin']) ?></td>
                    <td><?= (int) $r['veces'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
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

<script src="<?= BASE_URL ?>/assets/vendor/chart.umd.min.js"></script>
<script>
(() => {
    const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#888';
    Chart.defaults.color = cssVar('--text-muted');
    Chart.defaults.borderColor = cssVar('--border');
    const palette = ['#4f9dff', '#2ecc71', '#f2b705', '#e74c3c', '#9b59b6', '#1abc9c', '#e67e22'];

    new Chart(document.getElementById('chart-fuente'), {
        type: 'doughnut',
        data: {
            labels: ['OnlineCam', 'StaticCam', 'ApiCam'],
            datasets: [{ data: [<?= $porFuente['onlinecam'] ?>, <?= $porFuente['staticcam'] ?>, <?= $porFuente['apicam'] ?>], backgroundColor: palette }],
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
    new Chart(document.getElementById('chart-reglas-origen'), {
        type: 'doughnut',
        data: {
            labels: ['OnlineCam', 'StaticCam', 'ApiCam'],
            datasets: [{ data: [<?= $reglasPorFuente['onlinecam'] ?>, <?= $reglasPorFuente['staticcam'] ?>, <?= $reglasPorFuente['apicam'] ?>], backgroundColor: palette }],
        },
        options: { plugins: { legend: { position: 'bottom' } } },
    });
    <?php endif; ?>
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
