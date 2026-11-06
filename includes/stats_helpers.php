<?php
/**
 * includes/stats_helpers.php
 * -----------------------------------------------------------------------
 * Logica compartida de Estadisticas (estadisticas.php y
 * client/estadisticas.php) y lista unica de FUENTES validas
 * (usada tambien por api/stats_sync.php).
 *
 * IMPORTANTE sobre rule_id:
 *   - Eventos del PANEL WEB (device_identificador IS NULL): rule_id es el
 *     id de la tabla `rules` de esta base de datos.
 *   - Eventos de APPS EXTERNAS (device_identificador NOT NULL): rule_id es
 *     el id que la propia app/dispositivo le puso a su regla. NO existe en
 *     la tabla `rules`, asi que NUNCA se hace JOIN con ella para esos
 *     eventos; solo sirve para contar que regla se usa mas en cada
 *     dispositivo.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/functions.php';

/** source => [etiqueta, es_del_panel_web] */
function stats_fuentes(): array
{
    return [
        'onlinecam'         => ['OnlineCam (en vivo)', true],
        'staticcam'         => ['StaticCam', true],
        'apicam'            => ['ApiCam', true],
        'app_kivy'          => ['App Kivy', false],
        'win_kivy'          => ['Kivy Windows', false],
        'app_tk_win'        => ['Tk Windows', false],
        'app_tk_mac'        => ['Tk macOS', false],
        'app_tk_linux'      => ['Tk Linux', false],
        'app_android_light' => ['Android Light', false],
        'app_ios_light'     => ['iOS Light', false],
        'app_tk_arm'        => ['Tk ARM', false],
    ];
}

function stats_fuentes_validas(): array { return array_keys(stats_fuentes()); }

function stats_fuentes_web(): array
{
    return array_keys(array_filter(stats_fuentes(), fn($f) => $f[1]));
}

function stats_fuente_label(string $source): string
{
    return stats_fuentes()[$source][0] ?? $source;
}

/** nombre_aplicacion => etiqueta (las claves salen de api_apps_permitidas()) */
function stats_app_label(?string $app): string
{
    static $labels = [
        'app_kivy_sensoria'     => 'Kivy',
        'app_broker_sensoria'   => 'Broker',
        'app_microide_sensoria' => 'MicroIDE',
        'app_tk_sensoria'       => 'Tk (escritorio)',
        'app_ios_sensoria'      => 'iOS',
        'app_android_sensoria'  => 'Android',
    ];
    if ($app === null || $app === '') return '(sin nombre)';
    return $labels[$app] ?? $app;
}

/**
 * Consultas de Estadisticas que dependen de si la regla es del panel web
 * o de una app externa. $where / $params son los mismos que usa la pagina
 * (siempre con alias "ie." sobre inference_events).
 */
function stats_cargar_extra(string $where, array $params): array
{
    $pdo = db();
    $out = ['porApp' => [], 'dispositivos' => [], 'reglasWeb' => [], 'reglasExt' => []];

    // Por aplicacion externa
    $stmt = $pdo->prepare("SELECT ie.nombre_aplicacion AS app,
                                  COUNT(DISTINCT ie.device_identificador) AS dispositivos,
                                  SUM(ie.events_count) AS total,
                                  SUM(CASE WHEN ie.rule_matched = 1 THEN ie.events_count ELSE 0 END) AS reglas,
                                  MAX(ie.created_at) AS ultima
                           FROM inference_events ie
                           WHERE $where AND ie.device_identificador IS NOT NULL
                           GROUP BY ie.nombre_aplicacion ORDER BY total DESC");
    $stmt->execute($params);
    $out['porApp'] = $stmt->fetchAll();

    // Por dispositivo
    $stmt = $pdo->prepare("SELECT ie.device_identificador AS device, ie.nombre_aplicacion AS app,
                                  MAX(ie.source) AS source,
                                  SUM(ie.events_count) AS total,
                                  SUM(CASE WHEN ie.rule_matched = 1 THEN ie.events_count ELSE 0 END) AS reglas,
                                  ROUND(AVG(ie.confidence), 1) AS conf,
                                  ROUND(AVG(ie.inference_ms), 1) AS ms,
                                  COUNT(DISTINCT ie.module) AS modulos,
                                  MAX(ie.created_at) AS ultima
                           FROM inference_events ie
                           WHERE $where AND ie.device_identificador IS NOT NULL
                           GROUP BY ie.device_identificador, ie.nombre_aplicacion
                           ORDER BY total DESC LIMIT 50");
    $stmt->execute($params);
    $out['dispositivos'] = $stmt->fetchAll();

    // Reglas de apps externas: se agrupan por el rule_id PROPIO del dispositivo
    $stmt = $pdo->prepare("SELECT ie.device_identificador AS device, ie.nombre_aplicacion AS app,
                                  ie.rule_id, ie.module, ie.action_pin,
                                  SUM(ie.events_count) AS veces, MAX(ie.created_at) AS ultima
                           FROM inference_events ie
                           WHERE $where AND ie.rule_matched = 1 AND ie.device_identificador IS NOT NULL
                           GROUP BY ie.device_identificador, ie.nombre_aplicacion, ie.rule_id, ie.module, ie.action_pin
                           ORDER BY veces DESC LIMIT 300");
    $stmt->execute($params);
    $out['reglasExt'] = $stmt->fetchAll();

    // Regla mas usada por dispositivo (la lista ya viene ordenada por veces DESC)
    $top = [];
    foreach ($out['reglasExt'] as $r) {
        if (!isset($top[$r['device']])) $top[$r['device']] = $r;
    }
    foreach ($out['dispositivos'] as &$d) {
        $d['top_regla'] = $top[$d['device']] ?? null;
    }
    unset($d);

    // Reglas del panel web: aqui SI es el id de la tabla rules (y del mismo usuario)
    $stmt = $pdo->prepare("SELECT r.id, r.module, r.class_name AS rule_class, r.action_pin, r.scale_out_min,
                                  SUM(ie.events_count) AS veces, MAX(ie.created_at) AS ultima
                           FROM inference_events ie
                           JOIN rules r ON r.id = ie.rule_id AND r.user_id = ie.user_id
                           WHERE $where AND ie.rule_matched = 1 AND ie.device_identificador IS NULL
                           GROUP BY r.id, r.module, r.class_name, r.action_pin, r.scale_out_min
                           ORDER BY veces DESC LIMIT 15");
    $stmt->execute($params);
    $out['reglasWeb'] = $stmt->fetchAll();

    return $out;
}

/** "Regla #12 - Deteccion de Objetos -> Q2 (x5)" para mostrar la regla externa mas usada */
function stats_regla_ext_texto(?array $r, array $moduleLabels): string
{
    if (!$r) return '-';
    $id = $r['rule_id'] !== null ? '#' . $r['rule_id'] : '(sin ID)';
    $mod = $moduleLabels[$r['module']] ?? $r['module'];
    return "Regla $id - $mod" . ($r['action_pin'] ? " -> {$r['action_pin']}" : '') . ' (' . (int) $r['veces'] . ')';
}
