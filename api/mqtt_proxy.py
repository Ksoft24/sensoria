"""
mqtt_proxy.py
-----------------------------------------------------------------------
Proxy MQTT para dispositivos donde el navegador NO puede conectar bien
por WebSocket (ej. Android 4.4 con Chrome/WebView viejo). El modulo
ApiCam, en vez de conectar el broker MQTT desde el navegador, le pide a
ESTA API que se conecte ELLA MISMA - recibe los parametros de conexion
en CADA peticion (host/usuario/clave/topicos), porque la API es
compartida por varios usuarios y no guarda ninguna sesion MQTT propia
entre peticiones.

Patron: conexion CORTA por peticion (conectar, hacer lo pedido,
desconectar). Nada de estado compartido ni threads en segundo plano
persistentes entre requests - encaja bien con gunicorn (1 worker
sincrono) y con el plan Free de Render.
-----------------------------------------------------------------------
"""
import json
import ssl
import threading

import certifi
import paho.mqtt.client as mqtt


def _build_client(cfg, client_id_suffix):
    client = mqtt.Client(client_id="sensoria-api-" + client_id_suffix, protocol=mqtt.MQTTv311)
    if cfg.get("username"):
        client.username_pw_set(cfg.get("username"), cfg.get("password") or "")
    if cfg.get("use_tls", True):
        client.tls_set(ca_certs=certifi.where(), cert_reqs=ssl.CERT_REQUIRED)
    return client


def _default_port(cfg):
    return int(cfg.get("port") or (8883 if cfg.get("use_tls", True) else 1883))


def query_state(cfg, input_topic, query_topic=None, timeout_s=4):
    """
    Se conecta al broker, se suscribe a `input_topic`, opcionalmente
    publica un "ping" en `query_topic` (para que el firmware de Arduino
    sepa que debe reportar sus sensores en ese momento), y espera hasta
    `timeout_s` segundos la PRIMERA lectura que llegue.

    Devuelve {"received": bool, "digital": {...}, "analog": {...}, "raw": str|None}
    - "received": False no es un error, solo significa que Arduino no
      contesto a tiempo (puede seguir funcionando con el ultimo estado
      conocido del lado del navegador).
    """
    result = {"received": False, "digital": {}, "analog": {}, "raw": None}
    got_message = threading.Event()

    def on_connect(client, userdata, flags, rc):
        if rc != 0:
            got_message.set()  # liberar el wait; se reporta como "received": False
            return
        client.subscribe(input_topic)
        if query_topic:
            client.publish(query_topic, json.dumps({"query": "status"}))

    def on_message(client, userdata, msg):
        try:
            payload = json.loads(msg.payload.decode("utf-8"))
        except Exception:  # noqa: BLE001
            return  # ignora mensajes que no son JSON valido, sigue esperando
        items = payload if isinstance(payload, list) else [payload]
        for item in items:
            if not isinstance(item, dict):
                continue
            if "command" in item and "state" in item:
                key, val = item["command"], item["state"]
            else:
                pair = next(iter(item.items()), None)
                if not pair:
                    continue
                key, val = pair
            key = str(key).upper()
            try:
                val = int(val)
            except (TypeError, ValueError):
                continue
            if key.startswith("AI"):
                result["analog"][key] = val
            elif key.startswith("A"):
                result["analog"]["AI" + key[1:]] = val
            elif key.startswith("I"):
                result["digital"][key] = val
        result["raw"] = msg.payload.decode("utf-8", errors="replace")
        result["received"] = True
        got_message.set()

    client = _build_client(cfg, "state")
    client.on_connect = on_connect
    client.on_message = on_message

    client.connect_async(cfg["host"], _default_port(cfg), keepalive=int(timeout_s) + 10)
    client.loop_start()
    try:
        got_message.wait(timeout_s)
    finally:
        client.loop_stop()
        client.disconnect()

    return result


def publish(cfg, topic, payload, timeout_s=4):
    """
    Se conecta, publica UN mensaje (QoS 1, espera confirmacion) y corta.

    IMPORTANTE: la espera de conexion y la espera de confirmacion de
    publicacion se hacen DESDE EL HILO PRINCIPAL (no dentro de
    on_connect) - el hilo de red que arranca loop_start() tiene que
    seguir libre para procesar el PUBACK que confirma la publicacion.
    Bloquear ese mismo hilo dentro de on_connect (como en un intento
    anterior) genera un interbloqueo: nunca llega el PUBACK porque el
    hilo que lo procesaria esta esperando el propio PUBACK.
    """
    connected = threading.Event()
    result = {"ok": False, "error": None}

    def on_connect(client, userdata, flags, rc):
        if rc != 0:
            result["error"] = f"No se pudo conectar al broker (codigo {rc})"
        connected.set()

    client = _build_client(cfg, "publish")
    client.on_connect = on_connect

    client.connect_async(cfg["host"], _default_port(cfg), keepalive=int(timeout_s) + 10)
    client.loop_start()
    try:
        if not connected.wait(timeout_s):
            result["error"] = "Tiempo de espera agotado conectando al broker"
            return result
        if result["error"]:
            return result

        body = payload if isinstance(payload, str) else json.dumps(payload)
        info = client.publish(topic, body, qos=1)
        info.wait_for_publish(timeout_s)
        result["ok"] = info.is_published()
        if not result["ok"]:
            result["error"] = "El broker no confirmo la publicacion a tiempo"
    finally:
        client.loop_stop()
        client.disconnect()

    return result
