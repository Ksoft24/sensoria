<?php
/**
 * includes/stats_externas_view.php
 * Bloques HTML de Estadisticas: reglas del panel web, aplicaciones
 * externas, dispositivos y reglas de apps externas.
 * Espera: $reglasWeb, $reglasExt, $porApp, $dispositivos, $MODULE_LABELS
 * (los llena stats_cargar_extra() en includes/stats_helpers.php).
 */
$mutedStyle = 'color:var(--text-muted);font-size:13px;';
$smallMuted = 'font-size:12px;color:var(--text-muted);';
$totalPorDevice = [];
foreach ($dispositivos as $d) $totalPorDevice[$d['device']] = (int) $d['total'];
?>

<div class="card" style="margin-bottom:20px;">
    <h3>Reglas del panel web que se activaron</h3>
    <?php if (!$reglasWeb): ?>
        <p style="<?= $mutedStyle ?>">Ninguna regla del panel web se activo en este periodo.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Regla</th><th>Salida</th><th>Veces</th><th>Ultima vez</th></tr></thead>
        <tbody>
            <?php foreach ($reglasWeb as $r): ?>
            <tr>
                <td><?= htmlspecialchars($MODULE_LABELS[$r['module']] ?? $r['module']) ?> -
                    <?= htmlspecialchars($r['scale_out_min'] !== null ? 'Escala continua' : ($r['rule_class'] ?: '(cualquier clase)')) ?></td>
                <td><?= htmlspecialchars($r['action_pin']) ?></td>
                <td><?= (int) $r['veces'] ?></td>
                <td style="<?= $smallMuted ?>"><?= htmlspecialchars($r['ultima']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($porApp): ?>
<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3>Aplicaciones externas</h3>
        <table>
            <thead><tr><th>Aplicacion</th><th>Dispositivos</th><th>Eventos</th><th>Reglas</th><th>Ultima actividad</th></tr></thead>
            <tbody>
                <?php foreach ($porApp as $a): ?>
                <tr>
                    <td><?= htmlspecialchars(stats_app_label($a['app'])) ?></td>
                    <td><?= (int) $a['dispositivos'] ?></td>
                    <td><?= (int) $a['total'] ?></td>
                    <td><?= (int) $a['reglas'] ?></td>
                    <td style="<?= $smallMuted ?>"><?= htmlspecialchars($a['ultima']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3>Reglas mas usadas en apps externas</h3>
        <p style="<?= $mutedStyle ?>margin-top:0;">
            El ID de regla lo asigna cada dispositivo (no es el de la tabla Reglas de esta web).
        </p>
        <?php if (!$reglasExt): ?>
            <p style="<?= $mutedStyle ?>">Ninguna regla se activo en este periodo.</p>
        <?php else: ?>
        <table>
            <thead><tr><th>Dispositivo</th><th>Regla</th><th>Salida</th><th>Veces</th><th>% del disp.</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($reglasExt, 0, 15) as $r):
                    $tot = $totalPorDevice[$r['device']] ?? 0; ?>
                <tr>
                    <td><?= htmlspecialchars($r['device']) ?></td>
                    <td><?= $r['rule_id'] !== null ? '#' . (int) $r['rule_id'] : '(sin ID)' ?> -
                        <?= htmlspecialchars($MODULE_LABELS[$r['module']] ?? $r['module']) ?></td>
                    <td><?= htmlspecialchars((string) $r['action_pin']) ?></td>
                    <td><?= (int) $r['veces'] ?></td>
                    <td><?= $tot ? round($r['veces'] / $tot * 100) . '%' : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3>Dispositivos</h3>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr><th>Dispositivo</th><th>Aplicacion</th><th>Eventos</th><th>Reglas</th><th>% activacion</th>
                <th>Regla mas usada</th><th>Confianza prom.</th><th>Inferencia prom.</th><th>Modelos</th><th>Ultima actividad</th></tr>
        </thead>
        <tbody>
            <?php foreach ($dispositivos as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['device']) ?></td>
                <td><?= htmlspecialchars(stats_app_label($d['app'])) ?></td>
                <td><?= (int) $d['total'] ?></td>
                <td><?= (int) $d['reglas'] ?></td>
                <td><?= $d['total'] > 0 ? round($d['reglas'] / $d['total'] * 100) . '%' : '-' ?></td>
                <td><?= htmlspecialchars(stats_regla_ext_texto($d['top_regla'], $MODULE_LABELS)) ?></td>
                <td><?= $d['conf'] !== null ? $d['conf'] . '%' : '-' ?></td>
                <td><?= $d['ms'] !== null ? $d['ms'] . ' ms' : '-' ?></td>
                <td><?= (int) $d['modulos'] ?></td>
                <td style="<?= $smallMuted ?>"><?= htmlspecialchars($d['ultima']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
