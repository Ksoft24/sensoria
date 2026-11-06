<?php
/**
 * client/estadisticas_investigacion.php
 * -----------------------------------------------------------------------
 * Panel de INSTITUCION: resultados de una investigacion - un grafico de
 * distribucion de respuestas por cada pregunta de escala Likert, mas la
 * descarga del detalle completo (usuario, pregunta, respuesta, fecha) en CSV.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
$client = require_client();

$stmt = db()->prepare("SELECT id, nombre FROM investigaciones WHERE client_id = ? ORDER BY fecha_inicio DESC");
$stmt->execute([$client['id']]);
$investigaciones = $stmt->fetchAll();

$invId = isset($_GET['investigacion_id']) && ctype_digit((string) $_GET['investigacion_id']) ? (int) $_GET['investigacion_id'] : 0;
if (!$invId && $investigaciones) $invId = (int) $investigaciones[0]['id'];
if ($invId && !in_array($invId, array_column($investigaciones, 'id'), true)) $invId = 0;

// Exportar CSV: el detalle fila por fila de esta investigacion.
if ($invId && ($_GET['export'] ?? '') === 'csv') {
    $stmt = db()->prepare("SELECT r.respondido_en, u.nombres, u.apellidos, g.nombre AS grupo_nombre,
                                    p.texto AS pregunta, p.etiquetas_json, r.valor
                             FROM investigacion_respuestas r
                             JOIN investigacion_preguntas p ON p.id = r.pregunta_id
                             JOIN users u ON u.id = r.user_id
                             LEFT JOIN grupos g ON g.id = u.grupo_id
                             WHERE r.investigacion_id = ?
                             ORDER BY r.respondido_en DESC");
    $stmt->execute([$invId]);
    $filas = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="investigacion_' . $invId . '_resultados_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fecha', 'Usuario', 'Grupo', 'Pregunta', 'Respuesta (texto)', 'Respuesta (valor)']);
    foreach ($filas as $r) {
        $etiquetas = json_decode($r['etiquetas_json'], true) ?: [];
        $textoResp = $etiquetas[$r['valor'] - 1] ?? $r['valor'];
        fputcsv($out, [
            $r['respondido_en'],
            trim($r['nombres'] . ' ' . $r['apellidos']),
            $r['grupo_nombre'] ?? '-',
            $r['pregunta'],
            $textoResp,
            $r['valor'],
        ]);
    }
    fclose($out);
    exit;
}

$preguntasStats = [];
$totalParticipantes = 0;
$totalRespuestas = 0;

if ($invId) {
    $stmt = db()->prepare("SELECT p.* FROM investigacion_preguntas p WHERE p.investigacion_id = ? ORDER BY p.orden, p.id");
    $stmt->execute([$invId]);
    $preguntas = $stmt->fetchAll();

    foreach ($preguntas as $p) {
        $etiquetas = json_decode($p['etiquetas_json'], true) ?: [];
        $conteo = array_fill(1, count($etiquetas), 0);

        $stmt = db()->prepare("SELECT valor, COUNT(*) AS total FROM investigacion_respuestas WHERE pregunta_id = ? GROUP BY valor");
        $stmt->execute([$p['id']]);
        $sumaValores = 0;
        $totalPregunta = 0;
        foreach ($stmt->fetchAll() as $row) {
            $v = (int) $row['valor'];
            if (isset($conteo[$v])) $conteo[$v] = (int) $row['total'];
            $sumaValores += $v * (int) $row['total'];
            $totalPregunta += (int) $row['total'];
        }

        $preguntasStats[] = [
            'id' => $p['id'],
            'texto' => $p['texto'],
            'etiquetas' => $etiquetas,
            'conteo' => array_values($conteo),
            'total' => $totalPregunta,
            'promedio' => $totalPregunta ? round($sumaValores / $totalPregunta, 2) : null,
        ];
    }

    $stmt = db()->prepare("SELECT COUNT(DISTINCT user_id) AS usuarios, COUNT(*) AS total FROM investigacion_respuestas WHERE investigacion_id = ?");
    $stmt->execute([$invId]);
    $kpis = $stmt->fetch();
    $totalParticipantes = (int) $kpis['usuarios'];
    $totalRespuestas = (int) $kpis['total'];
}

$page_title = 'Estadisticas Investigacion';
$active_nav = 'client_estadisticas_investigacion';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div>
        <h2 style="margin:0;">Estadisticas de investigacion</h2>
        <p style="color:var(--text-muted);font-size:13px;margin:6px 0 0;">
            Distribucion de respuestas por pregunta, para la investigacion elegida.
        </p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select onchange="location.href='?investigacion_id=' + this.value">
            <?php foreach ($investigaciones as $inv): ?>
                <option value="<?= (int) $inv['id'] ?>" <?= $invId === (int) $inv['id'] ? 'selected' : '' ?>><?= htmlspecialchars($inv['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($invId): ?>
        <a class="btn btn-ghost" href="?investigacion_id=<?= $invId ?>&export=csv">⬇ Descargar resultados (CSV)</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$investigaciones): ?>
<div class="card" style="text-align:center;color:var(--text-muted);padding:40px;">
    Todavia no creaste ninguna investigacion.
</div>
<?php elseif (!$preguntasStats): ?>
<div class="card" style="text-align:center;color:var(--text-muted);padding:40px;">
    Esta investigacion todavia no tiene preguntas cargadas.
</div>
<?php else: ?>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Participantes</div><div style="font-size:26px;font-weight:800;"><?= $totalParticipantes ?></div></div>
    <div class="card"><div style="font-size:12px;color:var(--text-muted);">Respuestas totales</div><div style="font-size:26px;font-weight:800;"><?= $totalRespuestas ?></div></div>
</div>

<?php if ($totalRespuestas === 0): ?>
<div class="card" style="text-align:center;color:var(--text-muted);padding:40px;">
    Todavia nadie respondio esta investigacion.
</div>
<?php else: ?>

<div class="grid grid-2">
    <?php foreach ($preguntasStats as $i => $ps): ?>
    <div class="card">
        <h3 style="font-size:15px;"><?= $i + 1 ?>. <?= htmlspecialchars($ps['texto']) ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-top:-6px;">
            <?= $ps['total'] ?> respuesta<?= $ps['total'] === 1 ? '' : 's' ?>
            <?php if ($ps['promedio'] !== null): ?> · Promedio: <strong><?= $ps['promedio'] ?></strong> / <?= count($ps['etiquetas']) ?><?php endif; ?>
        </p>
        <canvas id="chart-preg-<?= $ps['id'] ?>" height="200"></canvas>
    </div>
    <?php endforeach; ?>
</div>

<script src="<?= BASE_URL ?>/assets/vendor/chart.umd.min.js"></script>
<script>
(() => {
    const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#888';
    Chart.defaults.color = cssVar('--text-muted');
    Chart.defaults.borderColor = cssVar('--border');

    const preguntas = <?= json_encode($preguntasStats) ?>;
    preguntas.forEach((p) => {
        new Chart(document.getElementById('chart-preg-' + p.id), {
            type: 'bar',
            data: {
                labels: p.etiquetas,
                datasets: [{ label: 'Respuestas', data: p.conteo, backgroundColor: '#4f9dff' }],
            },
            options: { plugins: { legend: { display: false } } },
        });
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
