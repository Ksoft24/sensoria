# Cambios (sesion de actualizacion)

## Ejecutar en la base de datos (una vez)
`sql/migration_008_puente_escalado.sql` -> columnas de escalado en `mqtt_bridge_config`
y `inference_events.source` pasa de ENUM a VARCHAR(30) (acepta las 11 fuentes de stats_sync).

## 1. Graficos / librerias (REVERTIDO)
- El error `ERR_CERT_AUTHORITY_INVALID` es de la red/PC (bloquea cdn.jsdelivr.net y unpkg.com): se resuelve cambiando de red.
- Todas las paginas (modulos, mqtt, etc.) conservan sus CDN originales. NO se modifico ninguna pagina de modulos.
- Solo el Simulador (pagina nueva) usa archivos locales: `assets/vendor/lucide.min.js`, `assets/vendor/mqtt.min.js`
  y `assets/simulador/sensoria_board.css`.

## 2. Accesos Externos
- `mis_dispositivos.php`: se quita la tabla "Detalle de accesos"; boton "Descargar CSV" (con filtros).
- `client/accesos_externos.php` ya solo descargaba; se agrega BOM UTF-8 al CSV.

## 3. Puente MQTT - Escalar
- Check "Escalar" por entrada + campos min, max, escala_min, escala_max, es_porcentaje, unidad
  y `escala_valor` calculado en vivo (lo que se publica).
- Formula: `escala_min + (valor-min)/(max-min) * (escala_max-escala_min)` (max<min invierte).
- Advertencias: general en la pagina + por fila (min=max, incompleto, fuera de rango, % sin 0-100).
- Codigo: `assets/js/mqtt-bridge.js` (`InputMqttBridge.computeScale`), `puente_mqtt.php`,
  `includes/functions.php` (active_mqtt_bridge_rows).

## 4. Estadisticas
- `rule_id` de apps externas ya NO se cruza con la tabla `rules` (antes se mezclaba con reglas web).
- Nuevo `includes/stats_helpers.php` (lista unica de fuentes + consultas) y
  `includes/stats_externas_view.php` (bloques HTML), usados por `estadisticas.php` y `client/estadisticas.php`.
- Nuevo: totales Panel web vs Apps externas, eventos por las 11 fuentes, por aplicacion,
  por dispositivo (regla mas usada, % activacion, confianza, inferencia) y reglas por dispositivo.
- CSV del cliente: + aplicacion, dispositivo, ID regla, regla activada, eventos.
- `api/stats_sync.php` y `api/api_app_login.php` leen sus listas de includes (sin duplicados).

## 5. Simulador SENSOR-IA Board en la pestaña del usuario
- Menu: **Simulador Sensor-IA Board** (`simulador.php`, iframe a `simulador_board.php`).
- Lee por defecto de la cuenta: broker (Conexion MQTT) + topicos de entradas/comandos/consulta y claves JSON (Hardware).
  Botones **Recargar** (vuelve a leer tu cuenta) y **Restablecer** (deshace cambios de la sesion).
- Habla el mismo protocolo que la web (verificado contra `hardware-bridge.js` real y Mosquitto).
- Tailwind/lucide/mqtt locales (sin CDN). Ver `assets/simulador/LEEME.md`.
- Nuevos: `simulador.php`, `simulador_board.php`, `simulador_config.php`, `includes/simulador_helpers.php`,
  `assets/simulador/*`, `assets/vendor/lucide.min.js`, `tools/build-simulador-css.sh`.
