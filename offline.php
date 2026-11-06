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
<title>SENSOR-IA Offline (PC y Smart) - <?= APP_NAME ?></title>
<meta name="description" content="SENSOR-IA Offline: la misma inteligencia artificial de SENSOR-IA Web, corriendo 100% local en tu PC o celular, sin necesidad de conexion a internet.">
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
        background: rgba(242,183,5,.14); color: var(--warn);
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
    .prod-download-grid {
        max-width: 1000px; margin: 0 auto 60px; padding: 0 6vw;
        display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px;
    }
    @media (max-width: 700px) { .prod-download-grid { grid-template-columns: 1fr; } }
    .prod-download {
        text-align: center; padding: 34px 24px; border-radius: 20px;
        background: var(--panel); border: 1px solid var(--border);
    }
    .prod-download h2 { margin: 0 0 8px; font-size: 19px; }
    .prod-download p { color: var(--text-muted); margin: 0 0 20px; font-size: 13.5px; }
    .prod-note {
        max-width: 1000px; margin: 0 auto 50px; padding: 0 6vw;
    }
    .prod-note .box {
        border-radius: 14px; padding: 18px 22px; background: rgba(47,128,237,.08);
        border: 1px dashed var(--accent); font-size: 13.5px; color: var(--text);
    }
    .prod-back { padding: 30px 6vw; text-align: center; }
</style>
</head>
<body>

<nav class="prod-nav">
    <div class="brand"><span>📴</span> SENSOR-IA Offline</div>
    <div style="display:flex;align-items:center;gap:10px;">
        <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/index.php">← Volver al inicio</a>
        <a class="btn btn-primary btn-sm" href="<?= BASE_URL . $panelUrl ?>"><?= $loggedUser ? 'Ir al Panel' : 'Iniciar sesion' ?></a>
    </div>
</nav>

<section class="prod-hero">
    <span class="landing-badge">Parte del ecosistema SENSOR-IA</span>
    <h1>La misma IA de SENSOR-IA Web, sin necesitar internet</h1>
    <p class="lead">
        SENSOR-IA Offline es la version que infiere <strong>100% local</strong>,
        tanto en <strong>PC</strong> como en <strong>celular</strong> (Smart):
        deteccion, reglas y control de hardware corriendo directo en tu
        equipo, sin depender de una conexion a internet estable — ideal
        para laboratorios y aulas con conectividad limitada. Solo hace
        falta activar tu cuenta una vez.
    </p>
    <div class="prod-cta">
        <a class="btn btn-primary" href="#descargar">⬇ Descargar</a>
        <a class="btn btn-ghost" href="#como-funciona">Como funciona</a>
    </div>
</section>

<section class="prod-section" id="como-funciona">
    <div class="section-head" style="max-width:720px;margin:0 auto 30px;text-align:center;">
        <h2 style="font-size:24px;margin:0 0 10px;">Que resuelve</h2>
        <p style="color:var(--text-muted);">Todo lo que ya conoces de SENSOR-IA Web, sin depender de la nube.</p>
    </div>
    <div class="prod-grid">
        <div class="card prod-feature">
            <h3>🧠 Inferencia 100% local</h3>
            <p>Los modelos de IA corren directo en tu PC o celular — nada viaja a internet durante la deteccion.</p>
        </div>
        <div class="card prod-feature">
            <h3>💻📱 Dos versiones, un solo ecosistema</h3>
            <p>SENSOR-IA PC para computadoras y SENSOR-IA Smart para celulares, compatibles con las mismas reglas y configuraciones.</p>
        </div>
        <div class="card prod-feature">
            <h3>🔑 Solo activar la cuenta</h3>
            <p>Una sola activacion inicial (para validar tu licencia) y despues funciona sin conexion a internet.</p>
        </div>
    </div>
</section>

<div class="prod-note">
    <div class="box">
        ℹ️ Para usar SENSOR-IA Offline necesitas una cuenta activa en
        SENSOR-IA (la misma que usas en la version web). La activacion
        inicial requiere conexion a internet una unica vez; despues, la
        app funciona de forma local.
    </div>
</div>

<div class="prod-download-grid" id="descargar">
    <div class="prod-download">
        <h2>💻 SENSOR-IA PC</h2>
        <p>Version de escritorio, instalable en Windows.</p>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/downloads/sensoria_pc_final.zip">⬇ Descargar sensoria_pc_final.zip</a>
    </div>
    <div class="prod-download">
        <h2>📱 SENSOR-IA Smart</h2>
        <p>Version para celular (Android).</p>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/downloads/sensoria_smart_final.zip">⬇ Descargar sensoria_smart_final.zip</a>
    </div>
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
