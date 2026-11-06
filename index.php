<?php
require_once __DIR__ . '/includes/functions.php';
// Landing publica: no requiere sesion. Si ya esta logueado, ofrecemos
// acceso directo a su panel en el CTA.
$loggedUser = current_user();
$panelUrl = $loggedUser
    ? (!empty($loggedUser['es_admin']) ? '/admin/usuarios.php' : '/dashboard.php')
    : '/login.php';

$CONTACT_EMAIL = 'charlen.calero@gmail.com';
$CONTACT_PHONE = '998887099';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= APP_NAME ?> - Laboratorio de Inteligencia Artificial e IoT</title>
<meta name="description" content="SENSOR-IA: educacion STEAM aplicada. Vision artificial e IoT corriendo en el navegador para aprender programacion, IA y hardware desde la practica.">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<script>
    (function () {
        var t = localStorage.getItem('sensoria-theme') || 'dark';
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
<style>
    /* =========================================================
       LANDING - estilo moderno por secciones (usa solo variables
       de theme.css, igual que el resto de la plataforma)
       ========================================================= */
    html { scroll-behavior: smooth; }
    .landing-nav {
        position: sticky; top: 0; z-index: 30;
        display: flex; align-items: center; justify-content: space-between;
        padding: 16px 6vw; border-bottom: 1px solid var(--border);
        background: var(--bg-elevated);
        backdrop-filter: blur(6px);
    }
    .landing-nav .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 19px; color: var(--text); }
    .landing-nav .brand .brand-mark {
        width: 34px; height: 34px; border-radius: 10px;
        background: linear-gradient(135deg, var(--accent), var(--ok));
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .landing-nav .brand .brand-mark svg { width: 19px; height: 19px; stroke: #fff; fill: none; stroke-width: 2; }

    /* ---------- Franja STEAM (arriba de todo, antes del hero) ---------- */
    .steam-strip {
        display: flex; align-items: center; justify-content: center; gap: 10px;
        flex-wrap: wrap; text-align: center;
        padding: 10px 6vw;
        background: linear-gradient(90deg, rgba(47,128,237,.14), rgba(46,204,113,.14));
        border-bottom: 1px solid var(--border);
        font-size: 13.5px; color: var(--text);
    }
    .steam-strip strong { color: var(--accent); }
    .steam-strip a { font-weight: 700; }

    section { scroll-margin-top: 70px; }

    /* ---------- Hero ---------- */
    .hero {
        max-width: 1180px; margin: 0 auto; padding: 60px 6vw 30px;
        display: grid; grid-template-columns: 1.05fr 0.95fr; gap: 40px; align-items: center;
    }
    .hero-copy .landing-badge {
        display: inline-block; padding: 6px 16px; border-radius: 999px;
        background: rgba(47,128,237,.12); color: var(--accent);
        font-size: 13px; font-weight: 700; margin-bottom: 18px;
    }
    .hero-copy h1 { font-size: 40px; margin: 0 0 16px; line-height: 1.15; }
    .hero-copy p.lead { color: var(--text-muted); font-size: 16.5px; line-height: 1.65; margin-bottom: 28px; max-width: 540px; }
    .landing-cta { display: flex; gap: 14px; flex-wrap: wrap; }
    .hero-art { display: flex; align-items: center; justify-content: center; }
    .hero-art svg { width: 100%; max-width: 440px; height: auto; }
    .hero-stats { display: flex; gap: 26px; margin-top: 34px; flex-wrap: wrap; }
    .hero-stats div strong { display: block; font-size: 22px; color: var(--accent); }
    .hero-stats div span { font-size: 12.5px; color: var(--text-muted); }

    @media (max-width: 900px) {
        .hero { grid-template-columns: 1fr; text-align: center; padding-top: 40px; }
        .hero-copy p.lead { margin-left: auto; margin-right: auto; }
        .landing-cta { justify-content: center; }
        .hero-stats { justify-content: center; }
        .hero-art { order: -1; }
        .hero-art svg { max-width: 300px; }
    }

    /* ---------- Secciones alternadas (zebra) ---------- */
    .section-block { padding: 64px 6vw; }
    .section-block.alt { background: var(--bg-elevated); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
    .section-inner { max-width: 1120px; margin: 0 auto; }
    .section-head { max-width: 720px; margin: 0 auto 40px; text-align: center; }
    .section-head .landing-badge {
        display: inline-block; padding: 6px 16px; border-radius: 999px;
        font-size: 13px; font-weight: 700; margin-bottom: 14px;
    }
    .section-head.blue .landing-badge { background: rgba(47,128,237,.12); color: var(--accent); }
    .section-head.green .landing-badge { background: rgba(46,204,113,.12); color: var(--ok); }
    .section-head h2 { font-size: 28px; margin: 0 0 12px; }
    .section-head p { color: var(--text-muted); font-size: 15.5px; line-height: 1.65; margin: 0; }

    /* ---------- Features (grid 4) ---------- */
    .landing-features-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
    .landing-feature-card { text-align: left; }
    .landing-icon {
        width: 50px; height: 50px; border-radius: 13px;
        background: var(--accent); display: flex; align-items: center; justify-content: center;
        margin-bottom: 14px; flex-shrink: 0;
    }
    .landing-icon.eco { background: var(--ok); }
    .landing-icon.warn { background: var(--warn); }
    .landing-icon svg { width: 25px; height: 25px; stroke: #fff; fill: none; stroke-width: 2; }
    .landing-feature-card h3 { margin: 0 0 6px; font-size: 16px; }
    .landing-feature-card p { margin: 0; color: var(--text-muted); font-size: 13.5px; line-height: 1.55; }

    /* ---------- STEAM / educacion (imagen + texto) ---------- */
    .steam-grid { display: grid; grid-template-columns: 0.9fr 1.1fr; gap: 46px; align-items: center; }
    .steam-grid .steam-art svg { width: 100%; height: auto; }
    .steam-points { display: grid; gap: 16px; margin-top: 26px; }
    .steam-point { display: flex; gap: 14px; align-items: flex-start; }
    .steam-point .dot {
        width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
        background: rgba(242,183,5,.14); color: var(--warn);
        display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 800;
    }
    .steam-point h4 { margin: 0 0 4px; font-size: 15px; }
    .steam-point p { margin: 0; color: var(--text-muted); font-size: 13.5px; line-height: 1.55; }
    .steam-stat-row { display: flex; gap: 30px; margin-top: 30px; flex-wrap: wrap; }
    .steam-stat-row div strong { display: block; font-size: 26px; color: var(--warn); }
    .steam-stat-row div span { font-size: 12.5px; color: var(--text-muted); }

    @media (max-width: 900px) {
        .steam-grid { grid-template-columns: 1fr; }
        .steam-grid .steam-art { order: -1; max-width: 320px; margin: 0 auto 10px; }
        .landing-features-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 560px) {
        .landing-features-grid { grid-template-columns: 1fr; }
        .hero-copy h1 { font-size: 28px; }
        .section-head h2 { font-size: 22px; }
    }

    /* ---------- Modulos ---------- */
    .landing-modules-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 10px; }
    @media (max-width: 900px) { .landing-modules-grid { grid-template-columns: repeat(2, 1fr); } }
    .landing-module-chip {
        text-align: center; padding: 20px 10px; font-size: 13.5px; font-weight: 600;
        display: flex; flex-direction: column; align-items: center; gap: 8px;
    }
    .landing-module-chip .emoji { font-size: 26px; }
    .landing-module-chip.new { border-color: var(--ok); box-shadow: 0 0 0 1px var(--ok) inset; }
    .landing-module-chip .tag-new {
        font-size: 10px; font-weight: 800; letter-spacing: .04em; color: var(--ok);
        background: rgba(46,204,113,.14); padding: 2px 8px; border-radius: 999px;
    }

    /* ---------- Ecosistema (MicroIde / Broker / Offline) ---------- */
    .landing-ecosystem-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 10px; }
    @media (max-width: 900px) { .landing-ecosystem-grid { grid-template-columns: 1fr; } }
    .landing-ecosystem-card { text-align: left; display: flex; flex-direction: column; height: 100%; }
    .landing-ecosystem-card .eco-emoji { font-size: 30px; margin-bottom: 12px; }
    .landing-ecosystem-card h3 { margin: 0 0 8px; font-size: 17px; }
    .landing-ecosystem-card p { margin: 0 0 18px; color: var(--text-muted); font-size: 13.5px; line-height: 1.6; flex-grow: 1; }
    .landing-ecosystem-card .tags { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
    .landing-ecosystem-card .tags span {
        font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px;
        background: var(--bg-elevated); color: var(--text-muted); border: 1px solid var(--border);
    }

    /* ---------- Impacto social / ecologico ---------- */
    .landing-eco { border-radius: 20px; background: var(--panel); border: 1px solid var(--border); padding: 40px 6vw; }
    .landing-eco-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 10px; }
    @media (max-width: 900px) { .landing-eco-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 560px) { .landing-eco-grid { grid-template-columns: 1fr; } }
    .landing-eco-card { text-align: left; }
    .landing-eco-card h3 { margin: 0 0 6px; font-size: 15px; }
    .landing-eco-card p { margin: 0; color: var(--text-muted); font-size: 13px; line-height: 1.5; }
    .landing-eco-cta {
        margin-top: 30px; text-align: center; padding: 22px; border-radius: 14px;
        background: rgba(46,204,113,.08); border: 1px dashed var(--ok);
    }
    .landing-eco-cta p { color: var(--text); font-size: 14.5px; margin: 0 0 12px; }

    /* ---------- Contacto ---------- */
    .landing-contact { max-width: 700px; margin: 0 auto; text-align: center; }
    .landing-contact h2 { font-size: 24px; margin-bottom: 8px; }
    .landing-contact p.sub { color: var(--text-muted); margin-bottom: 22px; }
    .landing-contact-links { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }

    .landing-footer { text-align: center; padding: 30px 6vw 40px; color: var(--text-muted); font-size: 13px; }
</style>
</head>
<body>

<nav class="landing-nav">
    <div class="brand">
        <span class="brand-mark">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg>
        </span>
        SENSOR-IA WEB
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <button id="theme-toggle" class="theme-toggle-btn">🌙 Modo oscuro</button>
        <?php if ($loggedUser): ?>
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL . $panelUrl ?>">Ir al Panel</a>
        <?php else: ?>
            <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/login.php">Iniciar sesion</a>
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/client_login.php">Soy una institucion</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ---------- Franja superior: Educacion STEAM ---------- -->
<div class="steam-strip">
    🎓 <strong>Educacion STEAM</strong> — Ciencia, Tecnologia, Ingenieria, Arte y Matematicas en accion:
    aprende programacion e Inteligencia Artificial desde la practica.
    <a href="#steam">Ver por que importa →</a>
</div>

<!-- ---------- Hero ---------- -->
<section class="hero" id="inicio">
    <div class="hero-copy">
        <span class="landing-badge">Laboratorio portatil de IA + IoT</span>
        <h1>Vision Artificial que controla tu hardware, en tiempo real, desde el navegador.</h1>
        <p class="lead">
            Detecta personas, objetos, manos, poses, codigos QR y marcadores con
            Inteligencia Artificial corriendo directo en el navegador, y usa
            reglas inteligentes para controlar Arduino por Serial, MQTT o una
            API propia de vision — sin instalar nada.
        </p>
        <div class="landing-cta">
            <a class="btn btn-primary" href="<?= BASE_URL . $panelUrl ?>">
                <?= $loggedUser ? 'Ir al Panel' : 'Comenzar gratis' ?>
            </a>
            <?php if (!$loggedUser): ?>
                <a class="btn btn-ghost" href="<?= BASE_URL ?>/login.php">Ya tengo cuenta</a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="#modulos">Ver los 8 modulos</a>
        </div>
        <div class="hero-stats">
            <div><strong>8</strong><span>Modulos de IA</span></div>
            <div><strong>0</strong><span>Instalaciones necesarias</span></div>
            <div><strong>2</strong><span>Modos de hardware (Serial/MQTT)</span></div>
        </div>
    </div>
    <div class="hero-art">
        <!-- Ilustracion propia (SVG): telefono con overlay de deteccion + chip de IA + placa -->
        <svg viewBox="0 0 420 420" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="gradPhone" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="var(--accent)"/>
                    <stop offset="100%" stop-color="var(--ok)"/>
                </linearGradient>
            </defs>
            <circle cx="210" cy="210" r="190" fill="var(--panel)" stroke="var(--border)"/>
            <!-- placa / arduino -->
            <rect x="235" y="255" width="130" height="90" rx="10" fill="var(--bg-elevated)" stroke="var(--border)" stroke-width="2"/>
            <circle cx="255" cy="275" r="5" fill="var(--warn)"/>
            <circle cx="255" cy="295" r="5" fill="var(--ok)"/>
            <circle cx="255" cy="315" r="5" fill="var(--danger)"/>
            <rect x="280" y="270" width="70" height="55" rx="4" fill="none" stroke="var(--accent)" stroke-width="2"/>
            <path d="M290 280h50M290 292h50M290 304h50M290 316h35" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" opacity=".6"/>
            <!-- lineas de conexion -->
            <path d="M235 300 L150 260" stroke="var(--ok)" stroke-width="2" stroke-dasharray="5 5" fill="none"/>
            <!-- telefono -->
            <rect x="95" y="70" width="160" height="290" rx="26" fill="var(--bg-elevated)" stroke="var(--border)" stroke-width="3"/>
            <rect x="108" y="92" width="134" height="210" rx="6" fill="#0b0f18"/>
            <!-- "video" simulado con caja de deteccion -->
            <rect x="122" y="115" width="60" height="80" rx="4" fill="none" stroke="url(#gradPhone)" stroke-width="2.5"/>
            <text x="122" y="110" font-size="11" fill="var(--ok)" font-family="Arial">persona 96%</text>
            <rect x="192" y="150" width="40" height="40" rx="4" fill="none" stroke="var(--warn)" stroke-width="2.5"/>
            <circle cx="140" cy="230" r="18" fill="none" stroke="var(--accent)" stroke-width="2"/>
            <path d="M140 218v24M128 230h24" stroke="var(--accent)" stroke-width="2"/>
            <rect x="140" y="318" width="30" height="8" rx="4" fill="var(--border)"/>
            <!-- chip IA flotante -->
            <g transform="translate(275,90)">
                <rect x="0" y="0" width="70" height="70" rx="12" fill="var(--bg-elevated)" stroke="var(--accent)" stroke-width="2"/>
                <path d="M18 18h34v34H18z" fill="none" stroke="var(--accent)" stroke-width="2"/>
                <path d="M0 20h18M0 35h18M0 50h18M52 20h18M52 35h18M52 50h18M20 0v18M35 0v18M50 0v18M20 52v18M35 52v18M50 52v18" stroke="var(--accent)" stroke-width="2"/>
                <text x="35" y="40" font-size="14" fill="var(--accent)" font-family="Arial" font-weight="700" text-anchor="middle">IA</text>
            </g>
        </svg>
    </div>
</section>

<!-- ---------- Features ---------- -->
<section class="section-block" id="plataforma">
    <div class="section-inner">
        <div class="section-head blue">
            <span class="landing-badge">Que hace la plataforma</span>
            <h2>Todo el flujo, en un solo lugar</h2>
            <p>Desde la camara hasta el actuador fisico, sin backend pesado ni instalaciones — todo corre en el navegador del alumno o del laboratorio.</p>
        </div>
        <div class="landing-features-grid">
            <div class="card landing-feature-card">
                <div class="landing-icon">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                        <circle cx="12" cy="13" r="4"/>
                    </svg>
                </div>
                <h3>Vision Artificial</h3>
                <p>Objetos, segmentacion, manos, gestos, poses, ArUco y QR — todo corriendo en tu propio navegador.</p>
            </div>
            <div class="card landing-feature-card">
                <div class="landing-icon">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="6" y="6" width="12" height="12" rx="2"/>
                        <path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>
                    </svg>
                </div>
                <h3>Hardware</h3>
                <p>Controla LEDs, reles, sensores y servos por WebSerial o MQTT, cada usuario con su propia configuracion.</p>
            </div>
            <div class="card landing-feature-card">
                <div class="landing-icon">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/>
                        <path d="M9 12l2 2 4-4"/>
                    </svg>
                </div>
                <h3>Motor de Reglas</h3>
                <p>Si detecta una persona, enciende un LED. Si abre la mano, activa un servo — todo configurable, sin programar.</p>
            </div>
            <div class="card landing-feature-card">
                <div class="landing-icon warn">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="4" width="16" height="16" rx="3"/>
                        <path d="M9 9h.01M15 9h.01M8 15c1 1.2 2.4 2 4 2s3-.8 4-2"/>
                    </svg>
                </div>
                <h3>API de Vision (ApiCam)</h3>
                <p>Envia el frame a tu propio servidor Flask con ONNX/OpenCV y recibe la deteccion ya trazada, misma logica de reglas.</p>
            </div>
        </div>
    </div>
</section>

<!-- ---------- STEAM / Educacion tecnologica ---------- -->
<section class="section-block alt" id="steam">
    <div class="section-inner steam-grid">
        <div class="steam-art">
            <!-- Ilustracion propia: gorro de graduacion + circuito + engranaje -->
            <svg viewBox="0 0 320 320" xmlns="http://www.w3.org/2000/svg">
                <circle cx="160" cy="160" r="150" fill="var(--panel)" stroke="var(--border)"/>
                <!-- gorro de graduacion -->
                <g transform="translate(60,60)">
                    <path d="M60 0L120 26 60 52 0 26z" fill="var(--accent)"/>
                    <path d="M20 34v28c0 10 18 18 40 18s40-8 40-18V34" fill="none" stroke="var(--accent)" stroke-width="4" stroke-linecap="round"/>
                    <path d="M110 30v34" stroke="var(--warn)" stroke-width="3" stroke-linecap="round"/>
                    <circle cx="110" cy="68" r="5" fill="var(--warn)"/>
                </g>
                <!-- circuito conectando al engranaje -->
                <path d="M120 150 L120 200 L200 200" fill="none" stroke="var(--ok)" stroke-width="3" stroke-dasharray="6 6"/>
                <circle cx="120" cy="150" r="4" fill="var(--ok)"/>
                <!-- engranaje -->
                <g transform="translate(200,200)">
                    <circle r="34" fill="var(--bg-elevated)" stroke="var(--ok)" stroke-width="3"/>
                    <circle r="12" fill="none" stroke="var(--ok)" stroke-width="3"/>
                    <g stroke="var(--ok)" stroke-width="6" stroke-linecap="round">
                        <path d="M0 -34v-14M0 34v14M-34 0h-14M34 0h14"/>
                        <path d="M-24 -24l-10-10M24 -24l10-10M-24 24l-10 10M24 24l10 10"/>
                    </g>
                </g>
                <!-- llaves de codigo -->
                <text x="55" y="240" font-family="monospace" font-size="30" fill="var(--warn)" font-weight="700">&lt;/&gt;</text>
                <path d="M60 250 L100 220" stroke="var(--warn)" stroke-width="2" stroke-dasharray="4 4" fill="none"/>
            </svg>
        </div>
        <div class="steam-copy">
            <span class="landing-badge" style="background:rgba(242,183,5,.14);color:var(--warn);">Por que STEAM</span>
            <h2 style="font-size:28px;margin:0 0 14px;">La educacion STEAM es la base de las habilidades del futuro</h2>
            <p style="color:var(--text-muted);font-size:15px;line-height:1.65;margin:0;">
                Ciencia, Tecnologia, Ingenieria, Arte y Matematicas (STEAM) dejaron de
                ser materias aisladas: son la forma en que se resuelven problemas
                reales hoy. Aprender <strong>programacion</strong> e
                <strong>Inteligencia Artificial</strong> desde temprano desarrolla
                pensamiento logico, capacidad de resolver problemas y creatividad
                aplicada — habilidades que abren puertas en cualquier carrera,
                no solo en tecnologia.
            </p>

            <div class="steam-points">
                <div class="steam-point">
                    <div class="dot">1</div>
                    <div>
                        <h4>Pensamiento computacional desde la practica</h4>
                        <p>Cada modulo de SENSOR-IA convierte un concepto abstracto (deteccion, reglas, sensores) en algo que se ve y se controla en tiempo real.</p>
                    </div>
                </div>
                <div class="steam-point">
                    <div class="dot">2</div>
                    <div>
                        <h4>Programacion e IA sin barreras de entrada</h4>
                        <p>No hace falta una PC potente ni licencias costosas: el navegador de un celular reciclado ya es un laboratorio completo de IA.</p>
                    </div>
                </div>
                <div class="steam-point">
                    <div class="dot">3</div>
                    <div>
                        <h4>Habilidades tecnologicas que se piden en el mundo real</h4>
                        <p>Vision por computadora, automatizacion, IoT y control de hardware son la puerta de entrada a la robotica, la ingenieria y el desarrollo de software.</p>
                    </div>
                </div>
            </div>

            <div class="steam-stat-row">
                <div><strong>+STEM</strong><span>Ciencia + Tecnologia + Ingenieria + Arte + Matematicas</span></div>
                <div><strong>0 costo</strong><span>Aprender con un celular en desuso</span></div>
                <div><strong>7+1</strong><span>Modulos de IA para practicar</span></div>
            </div>
        </div>
    </div>
</section>

<!-- ---------- Modulos ---------- -->
<section class="section-block" id="modulos">
    <div class="section-inner">
        <div class="section-head blue">
            <span class="landing-badge">Modulos disponibles</span>
            <h2>8 modulos de Inteligencia Artificial listos para usar</h2>
            <p>Cada uno con su propio motor de reglas: define un disparador y una salida, y controla tu hardware sin escribir codigo.</p>
        </div>
        <div class="landing-modules-grid">
            <div class="card landing-module-chip"><span class="emoji">🎯</span>Deteccion de Objetos</div>
            <div class="card landing-module-chip"><span class="emoji">🟠</span>Segmentacion</div>
            <div class="card landing-module-chip"><span class="emoji">🤸</span>Pose (MoveNet)</div>
            <div class="card landing-module-chip"><span class="emoji">✋</span>Gestos de Mano</div>
            <div class="card landing-module-chip"><span class="emoji">🤏</span>Control de Pinza</div>
            <div class="card landing-module-chip"><span class="emoji">🔳</span>Codigo QR</div>
            <div class="card landing-module-chip"><span class="emoji">📍</span>Marcador ArUco</div>
            <div class="card landing-module-chip new"><span class="emoji">🖼️</span>StaticCam (ONNX local)<span class="tag-new">Nuevo</span></div>
            <div class="card landing-module-chip new"><span class="emoji">📡</span>ApiCam<span class="tag-new">Nuevo</span></div>
        </div>
    </div>
</section>

<!-- ---------- Ecosistema SENSOR-IA (MicroIde / Broker / Offline) ---------- -->
<section class="section-block" id="ecosistema">
    <div class="section-inner">
        <div class="section-head blue">
            <span class="landing-badge">Mas alla del navegador</span>
            <h2>El ecosistema SENSOR-IA: de la nube al microcontrolador</h2>
            <p>
                SENSOR-IA Web es la puerta de entrada, pero el ecosistema
                sigue: generá el firmware de tu microcontrolador, montá tu
                propio broker MQTT local, y llevá la misma inteligencia
                artificial a tu PC o celular sin depender de internet.
            </p>
        </div>
        <div class="landing-ecosystem-grid">
            <div class="card landing-ecosystem-card">
                <div class="eco-emoji">🧩</div>
                <div class="tags"><span>IDE</span><span>Microcontroladores</span></div>
                <h3>Sensor-IA MicroIde</h3>
                <p>
                    El IDE que genera el codigo listo para subir a tu
                    microcontrolador, junto con las configuraciones ya
                    compatibles con SENSOR-IA Web, PC y Smart — sin escribir
                    el firmware desde cero.
                </p>
                <a class="btn btn-ghost" href="<?= BASE_URL ?>/microide.php">Conocer MicroIde →</a>
            </div>
            <div class="card landing-ecosystem-card">
                <div class="eco-emoji">🌐</div>
                <div class="tags"><span>MQTT</span><span>Red local</span></div>
                <h3>Sensor-IA Broker</h3>
                <p>
                    Tu propio broker MQTT corriendo en tu red local, para
                    simplificar las conexiones entre tus dispositivos y las
                    versiones offline de SENSOR-IA — sin depender de un
                    broker en la nube.
                </p>
                <a class="btn btn-ghost" href="<?= BASE_URL ?>/broker.php">Conocer Broker →</a>
            </div>
            <div class="card landing-ecosystem-card">
                <div class="eco-emoji">📴</div>
                <div class="tags"><span>PC</span><span>Celular</span><span>Sin internet</span></div>
                <h3>SENSOR-IA Offline (PC y Smart)</h3>
                <p>
                    La misma inteligencia artificial de SENSOR-IA Web,
                    infiriendo 100% local en tu PC o tu celular. Solo hace
                    falta activar la cuenta una vez.
                </p>
                <a class="btn btn-ghost" href="<?= BASE_URL ?>/offline.php">Conocer version Offline →</a>
            </div>
        </div>
    </div>
</section>

<!-- ---------- Impacto social y ecologico ---------- -->
<section class="section-block alt">
    <div class="section-inner">
        <div class="landing-eco">
            <div class="section-head green" style="margin-bottom:24px;">
                <span class="landing-badge">Reciclaje + Educacion STEM</span>
                <h2>Una segunda vida para celulares en desuso, convertidos en laboratorios de IA</h2>
                <p>
                    En el Peru y el mundo hay mas de <strong style="color:var(--ok);">500,000</strong>
                    celulares antiguos guardados sin uso. SENSOR-IA les da una segunda
                    vida: convertirlos en laboratorios portatiles de Inteligencia
                    Artificial para que niños, niñas y jovenes aprendan tecnologia de
                    punta sin depender de una PC cara ni, muchas veces, ni siquiera
                    de conexion a internet.
                </p>
            </div>

            <div class="landing-eco-grid">
                <div class="card landing-eco-card">
                    <div class="landing-icon eco">
                        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4l16 16M20 4L4 20"/>
                            <circle cx="12" cy="12" r="9"/>
                        </svg>
                    </div>
                    <h3>IA en el borde (Edge AI), sin internet</h3>
                    <p>Los modelos de Inteligencia Artificial corren directo en el celular o tablet — se puede enseñar y aprender en zonas rurales o sin conectividad estable.</p>
                </div>
                <div class="card landing-eco-card">
                    <div class="landing-icon eco">
                        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="6" y="6" width="12" height="12" rx="2"/>
                            <path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>
                        </svg>
                    </div>
                    <h3>Arduino, robotica y comunicaciones</h3>
                    <p>Con el celular como "cerebro" de IA, los estudiantes aprenden Arduino, microcontroladores, protocolos de comunicacion (Serial, MQTT, WiFi) y robotica basica en la practica.</p>
                </div>
                <div class="card landing-eco-card">
                    <div class="landing-icon eco">
                        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="14" rx="2"/>
                            <path d="M8 20h8M12 18v2"/>
                        </svg>
                    </div>
                    <h3>Compatible con Raspberry Pi y Jetson Nano</h3>
                    <p>El mismo camino sirve para dar el salto a minicomputadoras: aprender IA, vision por computadora y comunicacion con sensores en Raspberry Pi y Jetson Nano.</p>
                </div>
            </div>

            <div class="landing-eco-cta">
                <p>
                    <strong>¿Tu empresa tiene celulares en desuso?</strong>
                    Contactanos si tu empresa piensa donar telefonos — se usaran para
                    capacitar a niños, niñas y jovenes como labor social, dandole una
                    segunda vida a equipos que hoy estan guardados sin uso.
                    La educacion STEAM temprana en la niñez y la juventud abre
                    puertas reales a oportunidades tecnologicas — y no deberia
                    depender de tener el celular o la computadora mas nueva del
                    mercado.
                </p>
                <a class="btn btn-primary" href="mailto:<?= htmlspecialchars($CONTACT_EMAIL) ?>?subject=Donacion%20de%20equipos%20-%20SENSOR-IA">
                    Quiero donar equipos
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ---------- Contacto ---------- -->
<section class="section-block" id="contacto">
    <div class="section-inner landing-contact">
        <h2>Contacto</h2>
        <p class="sub">¿Preguntas, alianzas, donaciones o quieres llevar SENSOR-IA a tu institucion educativa?</p>
        <div class="landing-contact-links">
            <a class="btn btn-ghost" href="mailto:<?= htmlspecialchars($CONTACT_EMAIL) ?>">✉️ <?= htmlspecialchars($CONTACT_EMAIL) ?></a>
            <a class="btn btn-ghost" href="tel:+51<?= htmlspecialchars($CONTACT_PHONE) ?>">📱 +51 <?= htmlspecialchars($CONTACT_PHONE) ?></a>
        </div>
    </div>
</section>

<footer class="landing-footer">
    <?= APP_NAME ?> — Plataforma de Inferencia IA · Educacion STEAM · KINGOSFT
</footer>

<script src="<?= BASE_URL ?>/assets/js/theme.js"></script>
</body>
</html>
