<?php
/**
 * SENSOR-IA WEB - Configuracion central.
 * Copia este archivo (o edita directamente en produccion) con los datos
 * reales de tu hosting/cPanel antes de subirlo.
 */

// ---------------------------------------------------------------------
// Datos de conexion a MySQL (cPanel: normalmente localhost)
// ---------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'bd_sensoria_web');
define('DB_USER', 'root');
define('DB_PASS', 'rootleon');

// ---------------------------------------------------------------------
// URL base del sitio (sin barra final). Se usa para construir los links
// de verificacion de correo / recuperacion de clave.
// ---------------------------------------------------------------------
define('BASE_URL', 'http://localhost/sensoria_web');

// Nombre visible de la plataforma
define('APP_NAME', 'SENSOR-IA WEB');

// Duracion de los tokens (horas)
define('EMAIL_TOKEN_HOURS', 48);
define('RESET_TOKEN_HOURS', 2);

// Duracion de la cookie "Recordarme" (dias)
define('REMEMBER_DAYS', 30);

// ---------------------------------------------------------------------
define('APP_ENCRYPTION_KEY', 'SEN-S0RI@-2020-KINGS@FT');

// ---------------------------------------------------------------------
// ---------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');   // en produccion: apagado (revisar logs)
date_default_timezone_set('America/Lima');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
