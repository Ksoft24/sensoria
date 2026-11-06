<?php
require_once __DIR__ . '/includes/functions.php';
// Pagina publica de producto (sin login), igual que index.php.
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
<title>Sensor-IA MicroIde - <?= APP_NAME ?></title>
<meta name="description" content="Sensor-IA MicroIde: el IDE que genera el codigo listo para subir a tu microcontrolador, con configuraciones compatibles con SENSOR-IA Web, PC y Smart.">
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
    .prod-hero {
        max-width: 1000px; margin: 0 auto; padding: 60px 6vw 20px; text-align: center;
    }
    .prod-hero .landing-badge {
        display: inline-block; padding: 6px 16px; border-radius: 999px;
        background: rgba(47,128,237,.12); color: var(--accent);
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
    <div class="brand">
        <span>🧩</span> Sensor-IA MicroIde
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/index.php">← Volver al inicio</a>
        <a class="btn btn-primary btn-sm" href="<?= BASE_URL . $panelUrl ?>"><?= $loggedUser ? 'Ir al Panel' : 'Iniciar sesion' ?></a>
    </div>
</nav>

<section class="prod-hero">
    <span class="landing-badge">Parte del ecosistema SENSOR-IA</span>
    <h1>El IDE que le habla directo a tu microcontrolador</h1>
    <p class="lead">
        Sensor-IA MicroIde genera automaticamente el codigo listo para subir
        a tu Arduino/microcontrolador, junto con las configuraciones
        necesarias para que se conecte sin fricción con
        <strong>SENSOR-IA Web</strong>, <strong>SENSOR-IA PC</strong> y
        <strong>SENSOR-IA Smart</strong> — sin tener que escribir el
        firmware desde cero cada vez.
    </p>
    <div class="prod-cta">
        <a class="btn btn-primary" href="#descargar">⬇ Descargar MicroIde</a>
        <a class="btn btn-ghost" href="#como-funciona">Como funciona</a>
    </div>
</section>

<section class="prod-section" id="como-funciona">
    <div class="section-head blue" style="max-width:720px;margin:0 auto 30px;text-align:center;">
        <h2 style="font-size:24px;margin:0 0 10px;">Que resuelve</h2>
        <p style="color:var(--text-muted);">Pensado para que armar el hardware sea la parte facil del proyecto.</p>
    </div>
    <div class="prod-grid">
        <div class="card prod-feature">
            <h3>🧠 Genera el codigo por vos</h3>
            <p>Elegis las entradas/salidas y el modo de conexion, y MicroIde arma el sketch listo para subir a tu microcontrolador — sin partir de cero.</p>
        </div>
        <div class="card prod-feature">
            <h3>🔗 Configuraciones ya compatibles</h3>
            <p>Exporta la configuracion lista para pegar en SENSOR-IA Web, PC o Smart: mismos pines, mismos topicos MQTT, cero desajustes.</p>
        </div>
        <div class="card prod-feature">
            <h3>⚙️ Serial y MQTT</h3>
            <p>Soporta los dos modos de conexion que usa toda la plataforma SENSOR-IA, para que elijas el que mejor se adapte a tu proyecto.</p>
        </div>
    </div>
</section>

<div class="prod-download" id="descargar">
    <h2>Descargar Sensor-IA MicroIde</h2>
    <p>Version de escritorio, instalable en Windows.</p>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/downloads/microide_final.zip">⬇ Descargar microide_final.zip</a>
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
