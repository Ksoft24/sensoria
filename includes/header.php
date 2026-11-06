<?php
/**
 * Incluir DESPUES de require_once includes/functions.php y de resolver
 * $user = current_user() (usuario/admin) o $client = current_client()
 * (cliente institucional) segun la pagina. Ambos pueden ser null en
 * paginas publicas. Variable opcional $page_title y $active_nav.
 */
$page_title = $page_title ?? APP_NAME;
$active_nav = $active_nav ?? '';
$flashes = get_flashes();
$loggedInUser = isset($user) && $user;
$loggedInClient = isset($client) && $client;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title) ?> - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css?v=<?= @filemtime(__DIR__ . '/../assets/css/theme.css') ?: time() ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
<script>
    // Aplicar el tema guardado ANTES de pintar, para evitar parpadeo
    (function () {
        var t = localStorage.getItem('sensoria-theme') || 'dark';
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
</head>
<body>
<?php if ($loggedInUser || $loggedInClient): ?>
<div class="app-shell" id="app-shell">
    <div class="sidebar-overlay" id="sidebar-overlay"></div>
    <aside class="sidebar">
        <div class="brand">SENSOR-IA WEB</div>

        <?php if ($loggedInClient): ?>
        <!--
            Cuenta CLIENTE institucional (colegio/academia): gestiona sus
            propios usuarios y grupos/salones. Login separado
            (client_login.php), no comparte sesion con users.
        -->
        <div class="nav-group-label">Mi institucion</div>
        <a class="nav-link <?= $active_nav === 'client_usuarios' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/usuarios.php">Usuarios</a>
        <a class="nav-link <?= $active_nav === 'client_grupos' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/grupos.php">Grupos</a>
        <a class="nav-link <?= $active_nav === 'client_estadisticas' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/estadisticas.php">Estadisticas</a>
        <a class="nav-link <?= $active_nav === 'client_accesos_externos' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/accesos_externos.php">Accesos Externos</a>
        <a class="nav-link <?= $active_nav === 'client_investigaciones' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/investigaciones.php">Investigacion</a>
        <a class="nav-link <?= $active_nav === 'client_asociar_investigacion' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/asociar_investigacion.php">Asociar Investigacion</a>
        <a class="nav-link <?= $active_nav === 'client_investigacion_preguntas' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/investigacion_preguntas.php">Preguntas Investigacion</a>
        <a class="nav-link <?= $active_nav === 'client_estadisticas_investigacion' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/estadisticas_investigacion.php">Estadisticas Investigacion</a>
        <a class="nav-link <?= $active_nav === 'client_perfil' ? 'active' : '' ?>" href="<?= BASE_URL ?>/client/perfil.php">Mi institucion</a>
        <div style="margin-top:auto;padding:14px 20px 0;">
            <a class="nav-link" href="<?= BASE_URL ?>/client/logout.php">Cerrar sesion</a>
        </div>

        <?php elseif (!empty($user['es_admin'])): ?>
        <!--
            Cuenta ADMIN de la plataforma SENSOR-IA: administra los
            CLIENTES institucionales (no usuarios individuales, eso lo
            hace cada cliente en su propio panel), dispositivos y
            configuracion global (SMTP).
        -->
        <div class="nav-group-label">Administracion</div>
        <a class="nav-link <?= $active_nav === 'admin_clientes' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/clientes.php">Clientes (instituciones)</a>
        <a class="nav-link <?= $active_nav === 'admin_usuarios' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/usuarios.php">Usuarios (todos)</a>
        <a class="nav-link <?= $active_nav === 'admin_dispositivos' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/dispositivos.php">Dispositivos</a>
        <a class="nav-link <?= $active_nav === 'admin_smtp' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/smtp.php">SMTP</a>

        <?php else: ?>
        <!--
            Cuenta de USUARIO (estudiante/docente operador): cada una con
            su propia camara, hardware (serial/mqtt) y reglas — no
            comparten nada entre si, aunque sean del mismo cliente/grupo.
        -->
        <div class="nav-group-label">General</div>
        <a class="nav-link <?= $active_nav === 'dashboard' ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboard.php">Panel</a>

        <button type="button" class="nav-link nav-group-toggle <?= in_array($active_nav, ['yolo','yolo_seg','movenet','handlandmarks','pinch','qr','aruco','colorimeter','geometric_scan']) ? 'active' : '' ?>" id="onlinecam-toggle">
            <span>OnlineCam (en vivo)</span>
            <span class="nav-group-arrow" id="onlinecam-arrow">▾</span>
        </button>
        <div class="nav-submenu <?= in_array($active_nav, ['yolo','yolo_seg','movenet','handlandmarks','pinch','qr','aruco','colorimeter','geometric_scan']) ? 'open' : '' ?>" id="onlinecam-submenu">
            <a class="nav-link <?= $active_nav === 'yolo' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/yolo.php">Deteccion de Objetos</a>
            <a class="nav-link <?= $active_nav === 'yolo_seg' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/yolo_seg.php">Segmentacion</a>
            <a class="nav-link <?= $active_nav === 'movenet' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/movenet.php">Pose (MoveNet)</a>
            <a class="nav-link <?= $active_nav === 'handlandmarks' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/handlandmarks.php">Gestos de Mano</a>
            <a class="nav-link <?= $active_nav === 'pinch' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/pinch.php">Control de Pinza</a>
            <a class="nav-link <?= $active_nav === 'qr' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/qr.php">Codigo QR</a>
            <a class="nav-link <?= $active_nav === 'aruco' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/aruco.php">Marcador ArUco</a>
            <a class="nav-link <?= $active_nav === 'colorimeter' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/colorimeter.php">Colorimetro</a>
            <a class="nav-link <?= $active_nav === 'geometric_scan' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/geometric_scan.php">GeometricScan</a>
        </div>
        <a class="nav-link <?= $active_nav === 'staticcam' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/staticcam.php">StaticCam</a>
        <a class="nav-link <?= $active_nav === 'apicam' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/apicam.php">ApiCam</a>
        <a class="nav-link <?= $active_nav === 'simulador' ? 'active' : '' ?>" href="<?= BASE_URL ?>/simulador.php">Simulador Sensor-IA Board</a>

        <div class="nav-group-label">Mi configuracion</div>
        <a class="nav-link <?= $active_nav === 'estadisticas' ? 'active' : '' ?>" href="<?= BASE_URL ?>/estadisticas.php">Estadisticas</a>
        <a class="nav-link <?= $active_nav === 'encuestas' ? 'active' : '' ?>" href="<?= BASE_URL ?>/encuestas.php">Encuestas</a>
        <a class="nav-link <?= $active_nav === 'rules' ? 'active' : '' ?>" href="<?= BASE_URL ?>/reglas.php">Reglas</a>
        <a class="nav-link <?= $active_nav === 'hardware' ? 'active' : '' ?>" href="<?= BASE_URL ?>/hardware.php">Hardware (Serial/MQTT)</a>
        <a class="nav-link <?= $active_nav === 'mqtt' ? 'active' : '' ?>" href="<?= BASE_URL ?>/mqtt.php">Conexion MQTT</a>
        <a class="nav-link <?= $active_nav === 'puente_mqtt' ? 'active' : '' ?>" href="<?= BASE_URL ?>/puente_mqtt.php">Puente MQTT</a>
        <a class="nav-link <?= $active_nav === 'camera' ? 'active' : '' ?>" href="<?= BASE_URL ?>/camara.php">Camara</a>
        <a class="nav-link <?= $active_nav === 'apicam_config' ? 'active' : '' ?>" href="<?= BASE_URL ?>/apicam_config.php">Conexion APICAM</a>
        <a class="nav-link <?= $active_nav === 'devices' ? 'active' : '' ?>" href="<?= BASE_URL ?>/mis_dispositivos.php">Accesos Externos</a>
        <?php endif; ?>

        <?php if (!$loggedInClient): ?>
        <div style="margin-top:auto;padding:14px 20px 0;">
            <a class="nav-link" href="<?= BASE_URL ?>/perfil.php">Mi perfil</a>
            <a class="nav-link" href="<?= BASE_URL ?>/logout.php">Cerrar sesion</a>
        </div>
        <?php endif; ?>
    </aside>

    <div class="main-area">
        <div class="topbar">
            <div style="display:flex;align-items:center;">
                <button class="hamburger-btn" id="hamburger-btn" aria-label="Abrir menu">☰</button>
                <div class="title"><?= htmlspecialchars($page_title) ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:14px;">
                <span style="font-size:13px;color:var(--text-muted);">
                    <?php if ($loggedInClient): ?>
                        <?= htmlspecialchars($client['nombre']) ?>
                        <span class="badge badge-<?= $client['plan_tipo'] === 'premium' ? 'ok' : ($client['plan_tipo'] === 'standard' ? 'warn' : 'muted') ?>">
                            <?= ucfirst($client['plan_tipo']) ?>
                        </span>
                    <?php else: ?>
                        <?= htmlspecialchars($user['nombres']) ?>
                        <?php if (!empty($user['es_admin'])): ?>
                            <span class="badge badge-warn">Administrador</span>
                        <?php else: ?>
                            <span class="badge badge-muted"><?= htmlspecialchars($user['client_nombre'] ?? '-') ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
                <button id="theme-toggle" class="theme-toggle-btn">🌙 Modo oscuro</button>
            </div>
        </div>
        <div class="content">
<?php else: ?>
<div class="auth-wrap">
    <div style="position:fixed;top:16px;right:16px;">
        <button id="theme-toggle" class="theme-toggle-btn">🌙 Modo oscuro</button>
    </div>
<?php endif; ?>

<?php foreach (($flashes['error'] ?? []) as $m): ?>
    <div class="alert alert-error"><?= htmlspecialchars($m) ?></div>
<?php endforeach; ?>
<?php foreach (($flashes['success'] ?? []) as $m): ?>
    <div class="alert alert-success"><?= htmlspecialchars($m) ?></div>
<?php endforeach; ?>
<?php foreach (($flashes['warning'] ?? []) as $m): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($m) ?></div>
<?php endforeach; ?>
<?php foreach (($flashes['info'] ?? []) as $m): ?>
    <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
<?php endforeach; ?>
