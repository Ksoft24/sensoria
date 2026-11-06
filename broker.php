<?php
require_once __DIR__ . '/includes/functions.php';
$loggedUser = current_user();
$panelUrl = $loggedUser
    ? (!empty($loggedUser['es_admin']) ? '/admin/usuarios.php' : '/dashboard.php')
    : '/login.php';
$CONTACT_EMAIL = 'charlen.calero@gmail.com';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sensor-IA Broker - <?= APP_NAME ?></title>
<meta name="description" content="Sensor-IA Broker: tu propio broker MQTT local, para simplificar las conexiones entre tus dispositivos y las versiones offline de SENSOR-IA.">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<script>
    (function () {
        var t = localStorage.getItem('sensoria-theme') || 'dark';
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
<style>
    html { scroll-behavior: smooth; }
    .prod-nav {
        position: sticky; top: 0; z-index: 30;
        display: flex; align-items: center; justify-content: space-between;
        padding: 16px 6vw; border-bottom: 1px solid var(--border);
        background: var(--bg-elevated); backdrop-filter: blur(6px);
    }
    .prod-nav .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 17px; color: var(--text); }
    .prod-hero { max-width: 1000px; margin: 0 auto; padding: 60px 6vw 20px; text-align: center; }
    .prod-hero .landing-badge {
        display: inline-block; padding: 6px 16px; border-radius: 999px;
        background: rgba(46,204,113,.12); color: var(--ok);
        font-size: 13px; font-weight: 700; margin-bottom: 18px;
    }
    .prod-hero h1 { font-size: 34px; margin: 0 0 14px; }
    .prod-hero p.lead { color: var(--text-muted); font-size: 16px; line-height: 1.65; max-width: 680px; margin: 0 auto 26px; }
    .prod-cta { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
    .prod-section { max-width: 1000px; margin: 0 auto; padding: 50px 6vw; }
    .prod-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 10px; }
    @media (max-width: 800px) { .prod-grid { grid-template-columns: 1fr; } }
    .prod-feature h3 { margin: 0 0 6px; font-size: 15.5px; }
    .prod-feature p { margin: 0; color: var(--text-muted); font-size: 13.5px; line-height: 1.55; }
    .prod-download {
        text-align: center; padding: 40px 6vw; margin: 0 6vw 60px; border-radius: 20px;
        background: var(--panel); border: 1px solid var(--border);
    }
    .prod-download h2 { margin: 0 0 8px; font-size: 22px; }
    .prod-download p { color: var(--text-muted); margin: 0 0 22px; }
    .prod-back { padding: 30px 6vw; text-align: center; }
</style>
</head>
<body>

<nav class="prod-nav">
    <div class="brand"><span>🌐</span> Sensor-IA Broker</div>
    <div style="display:flex;align-items:center;gap:10px;">
        <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/index.php">← Volver al inicio</a>
        <a class="btn btn-primary btn-sm" href="<?= BASE_URL . $panelUrl ?>"><?= $loggedUser ? 'Ir al Panel' : 'Iniciar sesion' ?></a>
    </div>
</nav>

<section class="prod-hero">
    <span class="landing-badge">Parte del ecosistema SENSOR-IA</span>
    <h1>Tu propio broker MQTT, corriendo local</h1>
    <p class="lead">
        Sensor-IA Broker es una app instalable en PC que levanta un broker
        MQTT en tu propia red local, para simplificar y agilizar las
        conexiones entre tus dispositivos (Arduino, microcontroladores,
        sensores) y las versiones <strong>offline</strong> de SENSOR-IA
        (PC y Smart) — sin depender de un broker en la nube ni de
        conexion a internet.
    </p>
    <div class="prod-cta">
        <a class="btn btn-primary" href="#descargar">⬇ Descargar Broker</a>
        <a class="btn btn-ghost" href="#como-funciona">Como funciona</a>
    </div>
</section>

<section class="prod-section" id="como-funciona">
    <div class="section-head green" style="max-width:720px;margin:0 auto 30px;text-align:center;">
        <h2 style="font-size:24px;margin:0 0 10px;">Que resuelve</h2>
        <p style="color:var(--text-muted);">Pensado para laboratorios, aulas y proyectos que no siempre tienen internet estable.</p>
    </div>
    <div class="prod-grid">
        <div class="card prod-feature">
            <h3>📡 Broker MQTT local</h3>
            <p>Levanta un broker MQTT en tu misma red, sin registros ni credenciales de servicios en la nube.</p>
        </div>
        <div class="card prod-feature">
            <h3>🔌 Conexiones simplificadas</h3>
            <p>Tus dispositivos y las versiones offline de SENSOR-IA se conectan entre si sin configuraciones complejas de red.</p>
        </div>
        <div class="card prod-feature">
            <h3>🛰️ Funciona sin internet</h3>
            <p>Ideal para aulas o zonas con conectividad limitada: todo el trafico MQTT queda dentro de tu propia red local.</p>
        </div>
    </div>
</section>

<div class="prod-download" id="descargar">
    <h2>Descargar Sensor-IA Broker</h2>
    <p>Version de escritorio, instalable en Windows.</p>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/downloads/broker_final.zip">⬇ Descargar broker_final.zip</a>
</div>

<div class="prod-back">
    <a class="btn btn-ghost" href="<?= BASE_URL ?>/index.php">← Volver al inicio</a>
</div>

<footer class="landing-footer">
    <?= APP_NAME ?> — Plataforma de Inferencia IA · Educacion STEAM · KINGOSFT
</footer>

<script src="<?= BASE_URL ?>/assets/js/theme.js"></script>
</body>
</html>
