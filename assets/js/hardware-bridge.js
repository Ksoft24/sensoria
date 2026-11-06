/**
 * hardware-bridge.js
 * -----------------------------------------------------------------------
 * Puente unico entre las reglas (evaluadas en el navegador) y el hardware
 * real, igual que "Conexion a Hardware" en la app Kivy: modo Serial
 * (WebSerial, solo Chrome/Edge) o modo MQTT (broker propio del usuario,
 * directo desde el navegador O via la API Flask - ver setRemoteMqttProxy).
 *
 * Esta pagina que lo incluye debe definir ANTES:
 *   window.HARDWARE_CONFIG = {
 *     modo: "serial" | "mqtt",
 *     mqtt_input_topic: "device/<uid>/input",
 *     mqtt_command_topic: "device/<uid>/command",
 *     mqtt_key_command: "command",   // nombre de la clave JSON del pin (solo MQTT)
 *     mqtt_key_state: "state",       // nombre de la clave JSON del valor (solo MQTT)
 *     mqtt: { host, port_ws, username, password, use_tls }
 *   };
 *
 * PROTOCOLOS (confirmados con el firmware real - Serial y MQTT usan
 * formatos DISTINTOS en ambos sentidos):
 *   - Serial (USB, WebSerial):
 *     - Escritura (comando):  texto plano "WRITE,Q2=1\n" (ver encodeCommand()).
 *     - Lectura (sensores):   texto plano CSV en una linea, ej.
 *       "Q2=0,Q3=0,...,I12=0,I13=1,AI0=262,AI1=249,AI2=241,AI3=249"
 *       (ver _parsePlainTextLine()).
 *   - MQTT: JSON en ambos sentidos,
 *     {"<mqtt_key_command>":"Qx","<mqtt_key_state>":"y"} (por defecto
 *     {"command":"Qx","state":"y"}).
 * Si tu firmware cambia de formato, ajusta SOLO encodeCommand()
 * (escritura Serial), _parsePlainTextLine() (lectura Serial) o
 * triggerAction()/_parseJsonLine() (MQTT) - el resto del puente no
 * necesita cambios.
 *
 * CONSOLA VISIBLE: este script inyecta solo, en cualquier pagina que lo
 * incluya, un panel flotante abajo a la derecha con el log en vivo de
 * cada conexion/lectura/escritura - asi se puede ver EXACTAMENTE que
 * esta pasando (ej. "Serial no conectado todavia") sin abrir devtools.
 * -----------------------------------------------------------------------
 */
(function () {
    const state = {
        serialPort: null,
        serialWriter: null,
        mqttClient: null,
        remoteMqttProxy: null, // ver setRemoteMqttProxy()
        onInputCallbacks: [],
    };

    // ------------------------------------------------------------------
    // Consola visible (panel flotante) - inyectada sola en el DOM
    // ------------------------------------------------------------------
    const LOG_MAX_LINES = 300;
    let logListEl = null;
    let logCountEl = null;

    function _buildConsoleUI() {
        if (document.getElementById("hb-console")) return;

        const style = document.createElement("style");
        style.textContent = `
            #hb-console { position: fixed; right: 14px; bottom: 14px; z-index: 99999;
                font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; }
            #hb-console-toggle { background: #10151f; color: #d7dee8; border: 1px solid #2a3242;
                border-radius: 999px; padding: 8px 14px; cursor: pointer; box-shadow: 0 4px 14px rgba(0,0,0,.35);
                display: flex; align-items: center; gap: 8px; }
            #hb-console-toggle .dot { width: 8px; height: 8px; border-radius: 50%; background: #5a6472; }
            #hb-console-toggle .dot.ok { background: #2ecc71; }
            #hb-console-toggle .dot.error { background: #e5484d; }
            #hb-console-panel { display: none; width: 360px; max-width: 90vw; height: 260px;
                background: #10151f; border: 1px solid #2a3242; border-radius: 10px; margin-top: 8px;
                box-shadow: 0 8px 24px rgba(0,0,0,.4); flex-direction: column; overflow: hidden; }
            #hb-console-panel.open { display: flex; }
            #hb-console-header { padding: 8px 10px; border-bottom: 1px solid #2a3242; color: #8b95a5;
                display: flex; justify-content: space-between; align-items: center; }
            #hb-console-header button { background: none; border: none; color: #8b95a5; cursor: pointer; font-size: 12px; }
            #hb-console-list { flex: 1; overflow-y: auto; padding: 6px 10px; color: #d7dee8; }
            #hb-console-list div { padding: 2px 0; border-bottom: 1px solid #1a2130; word-break: break-word; }
            #hb-console-list .lvl-info { color: #9db4d1; }
            #hb-console-list .lvl-success { color: #2ecc71; }
            #hb-console-list .lvl-warn { color: #f2b705; }
            #hb-console-list .lvl-error { color: #e5484d; }
            #hb-console-list .ts { color: #5a6472; margin-right: 6px; }
        `;
        document.head.appendChild(style);

        const wrap = document.createElement("div");
        wrap.id = "hb-console";
        wrap.innerHTML = `
            <div id="hb-console-panel">
                <div id="hb-console-header">
                    <span>Consola de Hardware</span>
                    <button type="button" id="hb-console-clear">limpiar</button>
                </div>
                <div id="hb-console-list"></div>
            </div>
            <button type="button" id="hb-console-toggle">
                <span class="dot" id="hb-console-dot"></span>
                🔌 Log Hardware <span id="hb-console-count">(0)</span>
            </button>
        `;
        document.body.appendChild(wrap);

        logListEl = document.getElementById("hb-console-list");
        logCountEl = document.getElementById("hb-console-count");
        const panel = document.getElementById("hb-console-panel");
        document.getElementById("hb-console-toggle").addEventListener("click", () => {
            panel.classList.toggle("open");
        });
        document.getElementById("hb-console-clear").addEventListener("click", () => {
            logListEl.innerHTML = "";
        });
    }

    let logCount = 0;
    function _log(level, msg) {
        const prefix = "[HardwareBridge]";
        if (level === "error") console.error(prefix, msg);
        else if (level === "warn") console.warn(prefix, msg);
        else console.log(prefix, msg);

        if (!logListEl) return;
        logCount++;
        const line = document.createElement("div");
        line.className = "lvl-" + level;
        const ts = new Date().toLocaleTimeString();
        line.innerHTML = `<span class="ts">${ts}</span>${_escapeHtml(String(msg))}`;
        logListEl.appendChild(line);
        while (logListEl.children.length > LOG_MAX_LINES) {
            logListEl.removeChild(logListEl.firstChild);
        }
        logListEl.scrollTop = logListEl.scrollHeight;
        if (logCountEl) logCountEl.textContent = `(${logCount})`;
        const dot = document.getElementById("hb-console-dot");
        if (dot) {
            dot.className = "dot " + (level === "error" ? "error" : level === "success" ? "ok" : "");
        }
    }

    function _escapeHtml(s) {
        return s.replace(/[&<>]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c]));
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", _buildConsoleUI);
    } else {
        _buildConsoleUI();
    }

    // ------------------------------------------------------------------
    // Claves configurables del payload JSON (command/state por defecto)
    // ------------------------------------------------------------------
    function _keyCommand() {
        return (window.HARDWARE_CONFIG && window.HARDWARE_CONFIG.mqtt_key_command) || "command";
    }
    function _keyState() {
        return (window.HARDWARE_CONFIG && window.HARDWARE_CONFIG.mqtt_key_state) || "state";
    }

    /**
     * Formato de texto plano que espera el firmware por Serial (USB):
     * "WRITE,Q2=1\n" - NO es JSON. Si tu firmware cambia de formato,
     * este es el UNICO lugar que hay que tocar (writeSerial() ya llama
     * a esta funcion).
     */
    function encodeCommand(pin, value) {
        return `WRITE,${pin}=${value}\n`;
    }

    // ------------------------------------------------------------------
    // WebSerial
    // ------------------------------------------------------------------
    async function connectSerial(baudRate) {
        if (!("serial" in navigator)) {
            _log("error", "Este navegador no soporta WebSerial (usa Chrome o Edge).");
            throw new Error("Este navegador no soporta WebSerial (usa Chrome o Edge).");
        }
        _log("info", "Pidiendo puerto Serial al usuario...");
        const port = await navigator.serial.requestPort();
        await port.open({ baudRate: baudRate || 9600 });
        state.serialPort = port;
        state.serialWriter = port.writable.getWriter();
        _log("success", `Serial conectado (baudrate ${baudRate || 9600}).`);

        // Lector en segundo plano: reenvia lineas JSON de lecturas (I12,I13,A0-A3)
        // a los mismos callbacks que usa el modo MQTT, para que las reglas no
        // necesiten saber de que transporte vinieron los datos.
        (async () => {
            const decoder = new TextDecoderStream();
            const readable = port.readable.pipeThrough(decoder);
            const reader = readable.getReader();
            let buffer = "";
            try {
                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    buffer += value;
                    let idx;
                    while ((idx = buffer.indexOf("\n")) >= 0) {
                        const line = buffer.slice(0, idx).trim();
                        buffer = buffer.slice(idx + 1);
                        if (line) _handleIncomingLine(line);
                    }
                }
            } catch (err) {
                _log("error", "Error leyendo Serial: " + err.message);
            }
        })();

        return port;
    }

    async function writeSerial(pin, value) {
        if (!state.serialWriter) {
            _log("error", `Intento de escribir ${pin}=${value} por Serial, pero Serial NO esta conectado en esta pagina (hay que darle "Conectar Arduino" aqui, la conexion no se comparte entre paginas).`);
            throw new Error("Serial no conectado todavia.");
        }
        const encoder = new TextEncoder();
        const line = encodeCommand(pin, value);
        await state.serialWriter.write(encoder.encode(line));
        _log("success", `Serial escrito: ${line.trim()}`);
    }

    // ------------------------------------------------------------------
    // MQTT sobre WebSocket, directo desde el navegador
    // (usa mqtt.js local -> assets/vendor/mqtt.min.js)
    // ------------------------------------------------------------------
    function connectMqtt(cfg) {
        return new Promise((resolve, reject) => {
            if (typeof mqtt === "undefined") {
                _log("error", "Falta cargar mqtt.js antes de hardware-bridge.js");
                reject(new Error("Falta cargar mqtt.js antes de hardware-bridge.js"));
                return;
            }
            const scheme = cfg.use_tls ? "wss" : "ws";
            const url = `${scheme}://${cfg.host}:${cfg.port_ws}/mqtt`;
            _log("info", `Conectando MQTT (navegador) a ${url}...`);

            const client = mqtt.connect(url, {
                username: cfg.username || undefined,
                password: cfg.password || undefined,
                clientId: "web-" + Math.random().toString(16).slice(2),
                reconnectPeriod: 3000,
            });

            client.on("connect", () => {
                state.mqttClient = client;
                const inputTopic = window.HARDWARE_CONFIG.mqtt_input_topic || "device/input";
                client.subscribe(inputTopic);
                _log("success", `MQTT conectado. Suscrito a "${inputTopic}".`);
                resolve(client);
            });

            client.on("message", (topic, payload) => {
                _handleIncomingLine(payload.toString());
            });

            client.on("error", (err) => {
                _log("error", "Error MQTT (navegador): " + err.message);
                reject(err);
            });
        });
    }

    function publishMqttLocal(topic, payload) {
        if (!state.mqttClient) {
            _log("error", `Intento de publicar en "${topic}", pero MQTT (navegador) NO esta conectado.`);
            throw new Error("MQTT no conectado todavia.");
        }
        const body = typeof payload === "string" ? payload : JSON.stringify(payload);
        state.mqttClient.publish(topic, body);
        _log("success", `MQTT publicado en "${topic}": ${body}`);
    }

    // ------------------------------------------------------------------
    // Proxy MQTT REMOTO (via API Flask) - para navegadores/Android viejos
    // que no soportan bien MQTT sobre WebSocket. En vez de conectar el
    // broker aca, se le pide a la API que se conecte ELLA MISMA (recibe
    // los parametros de conexion en cada peticion, porque la API es
    // compartida por varios usuarios - no guarda ninguna sesion MQTT).
    // ------------------------------------------------------------------
    /**
     * @param {object} cfg
     *   apiUrl: URL base de la API Flask (sin barra final)
     *   apiKey: opcional, header X-API-Key
     *   mqtt: { host, port, username, password, use_tls } (puerto TCP, NO el de WebSocket)
     *   input_topic, command_topic, query_topic: strings
     *   timeout_s: opcional, default 4
     */
    function setRemoteMqttProxy(cfg) {
        state.remoteMqttProxy = cfg;
        _log("info", `Proxy MQTT remoto activado (via ${cfg.apiUrl}). Todo el trafico MQTT de esta pagina pasa por la API.`);
    }

    function clearRemoteMqttProxy() {
        state.remoteMqttProxy = null;
    }

    async function _proxyFetch(path, body) {
        const proxy = state.remoteMqttProxy;
        const headers = { "Content-Type": "application/json" };
        if (proxy.apiKey) headers["X-API-Key"] = proxy.apiKey;
        const resp = await fetch(proxy.apiUrl + path, {
            method: "POST",
            headers,
            body: JSON.stringify(body),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || !data.ok) {
            throw new Error(data.error || `Error ${resp.status} consultando la API`);
        }
        return data;
    }

    /**
     * Le pide a la API que consulte el estado actual de Arduino: publica
     * un "ping" en el topico de consulta y escucha el topico de entrada
     * un rato corto. El resultado se reenvia a los mismos callbacks que
     * usan Serial/MQTT normal (RulesEngine.io se actualiza igual).
     */
    async function queryRemoteMqttState() {
        const proxy = state.remoteMqttProxy;
        if (!proxy) throw new Error("No hay proxy MQTT remoto configurado.");
        _log("info", "Consultando estado de sensores via API (MQTT remoto)...");
        let data;
        try {
            data = await _proxyFetch("/mqtt/state", {
                ...proxy.mqtt,
                input_topic: proxy.input_topic,
                query_topic: proxy.query_topic || undefined,
                timeout_s: proxy.timeout_s || 4,
            });
        } catch (err) {
            _log("error", "Error consultando MQTT via API: " + err.message);
            throw err;
        }

        if (data.received) {
            _log("success", `Estado recibido via API: digital=${JSON.stringify(data.digital)} analog=${JSON.stringify(data.analog)}`);
        } else {
            _log("warn", "La API no recibio respuesta de Arduino dentro del tiempo de espera (sigue con el ultimo estado conocido).");
        }
        state.onInputCallbacks.forEach((cb) => cb({ digital: data.digital || {}, analog: data.analog || {} }));
        return data;
    }

    async function _publishViaProxy(topic, payload) {
        _log("info", `Publicando via API en "${topic}"...`);
        await _proxyFetch("/mqtt/publish", { ...state.remoteMqttProxy.mqtt, topic, payload });
        _log("success", `Publicado via API en "${topic}": ${JSON.stringify(payload)}`);
    }

    // ------------------------------------------------------------------
    // publishMqtt: punto unico usado por RulesEngine (la regla propia
    // "Tambien publicar por MQTT") - decide solo si usa el broker del
    // navegador o el proxy remoto.
    // ------------------------------------------------------------------
    async function publishMqtt(topic, payload) {
        if (state.remoteMqttProxy) {
            await _publishViaProxy(topic, payload);
        } else {
            publishMqttLocal(topic, payload);
        }
    }

    // ------------------------------------------------------------------
    // Entradas (Digitales/Analogicas) - comunes a Serial y MQTT
    // ------------------------------------------------------------------
    /**
     * Lecturas entrantes (Entradas Digitales/Analogicas). Los DOS
     * transportes mandan formatos DISTINTOS (confirmado con el
     * firmware real):
     *   - Serial: texto plano CSV en una linea, ej.
     *     "Q2=0,Q3=0,...,I12=0,I13=1,AI0=262,AI1=249,AI2=241,AI3=249"
     *     (incluye tambien las salidas Q2-Q11 como eco; se ignoran aca,
     *     solo interesan las entradas I../AI..).
     *   - MQTT: JSON {"command":"Ix","state":"y"} (o la clave que se
     *     haya configurado en mqtt_key_command/mqtt_key_state).
     */
    function _handleIncomingLine(line) {
        line = (line || "").trim();
        if (!line) return;

        const digital = {};
        const analog = {};

        if (line[0] === "{" || line[0] === "[") {
            _parseJsonLine(line, digital, analog);
        } else {
            _parsePlainTextLine(line, digital, analog);
        }

        if (Object.keys(digital).length === 0 && Object.keys(analog).length === 0) return;
        state.onInputCallbacks.forEach((cb) => cb({ digital, analog }));
    }

    function _parseJsonLine(line, digital, analog) {
        let data;
        try {
            data = JSON.parse(line);
        } catch (e) {
            return;
        }
        const items = Array.isArray(data) ? data : [data];
        const cmdKey = _keyCommand();
        const stateKey = _keyState();

        items.forEach((item) => {
            if (!item || typeof item !== "object") return;
            let key, val;
            if (cmdKey in item && stateKey in item) {
                key = item[cmdKey]; val = item[stateKey];
            } else if ("command" in item && "state" in item) {
                key = item.command; val = item.state;
            } else {
                const pair = Object.entries(item)[0];
                if (!pair) return;
                [key, val] = pair;
            }
            _assignReading(String(key), val, digital, analog);
        });
    }

    function _parsePlainTextLine(line, digital, analog) {
        // "Q2=0,Q3=0,...,I12=0,I13=1,AI0=262,..." - separado por comas,
        // cada par "CLAVE=valor". Las claves Qx (salidas, eco) se
        // ignoran: _assignReading() solo guarda las que empiezan con
        // "I" o "A".
        line.split(",").forEach((pair) => {
            const eq = pair.indexOf("=");
            if (eq === -1) return;
            const key = pair.slice(0, eq).trim();
            const val = pair.slice(eq + 1).trim();
            if (!key) return;
            _assignReading(key, val, digital, analog);
        });
    }

    function _assignReading(rawKey, rawVal, digital, analog) {
        const key = rawKey.toUpperCase();
        const val = parseInt(rawVal, 10);
        if (Number.isNaN(val)) return;

        if (key.startsWith("AI")) analog[key] = val;
        else if (key.startsWith("A")) analog["AI" + key.slice(1)] = val;
        else if (key.startsWith("I")) digital[key] = val;
        // Q... (salidas, eco de estado) se ignora aca a proposito: no es
        // una entrada para evaluar reglas.
    }

    function onInput(callback) {
        state.onInputCallbacks.push(callback);
    }

    // ------------------------------------------------------------------
    // API publica: dispara una salida (Q2-Q11) usando el modo activo
    // ------------------------------------------------------------------
    async function triggerAction(pin, value) {
        const modo = (window.HARDWARE_CONFIG && window.HARDWARE_CONFIG.modo) || "serial";
        _log("info", `Regla cumplida -> disparar ${pin}=${value} (modo ${modo}${state.remoteMqttProxy ? " + proxy API" : ""}).`);
        if (modo === "mqtt") {
            const topic = (window.HARDWARE_CONFIG && window.HARDWARE_CONFIG.mqtt_command_topic) || "device/command";
            const payload = {};
            payload[_keyCommand()] = pin;
            payload[_keyState()] = String(value);
            await publishMqtt(topic, payload);
        } else {
            await writeSerial(pin, value);
        }
    }

    window.HardwareBridge = {
        connectSerial,
        connectMqtt,
        writeSerial,
        publishMqtt,
        onInput,
        triggerAction,
        setRemoteMqttProxy,
        clearRemoteMqttProxy,
        queryRemoteMqttState,
        log: _log, // para que las paginas puedan escribir sus propias lineas en la misma consola
    };
})();
