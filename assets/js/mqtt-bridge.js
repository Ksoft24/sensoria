/**
 * mqtt-bridge.js
 * -----------------------------------------------------------------------
 * "Puente MQTT": mientras `HardwareBridge` este recibiendo lecturas (por
 * Serial o por MQTT, da igual el transporte - ver hardware-bridge.js),
 * este script republica CADA Entrada Digital/Analogica configurada
 * (I12, I13, A0, A1, A2, A3) en su PROPIO topico MQTT, PERO SOLO cuando
 * el valor de esa entrada CAMBIA (no en cada frame/lectura repetida).
 *
 * Cada entrada se publica como un objeto JSON con 2 claves configurables
 * (por defecto "command" y "state"):
 *   {"<clave comando>": "<nombre de la entrada, ej. A1>",
 *    "<clave valor>":   "<valor detectado>"}
 *
 * El "nombre de la entrada" (valor de la clave comando) y el "valor
 * detectado" (valor de la clave valor) NO son editables - lo unico
 * personalizable por entrada es el NOMBRE de esas 2 claves y el topico.
 *
 * Esta pagina que lo incluye debe definir ANTES:
 *   window.MQTT_BRIDGE_CONFIG = [
 *     { input_name: "I12", enabled: true, topic: "device/xxx/bridge/i12",
 *       mqtt_key_command: "command", mqtt_key_state: "state" },
 *     ...
 *   ];
 * Campos OPCIONALES de escalado por entrada (ver sql/migration_008):
 *   scale_enabled (0/1), scale_min, scale_max, scale_out_min,
 *   scale_out_max, scale_percent (0/1), scale_unit.
 * Si scale_enabled = 1 se publica el valor ESCALADO (ver computeScale)
 * en vez del valor crudo:
 *   escala_valor = escala_min + (valor-min)/(max-min) * (escala_max-escala_min)
 * (max puede ser menor que min: invierte el sentido, ej. sensor 4024->0).
 * y debe incluir hardware-bridge.js ANTES que este archivo (usa
 * `window.HardwareBridge.onInput()` para recibir lecturas y
 * `window.HardwareBridge.publishMqtt()` para publicar).
 * -----------------------------------------------------------------------
 */
(function () {
    function InputMqttBridge(configs) {
        // Solo interesan las filas activas y con topico definido.
        this.configs = (configs || []).filter((c) => c && c.enabled && c.topic);
        this.lastValues = {}; // input_name -> ultimo valor PUBLICADO (para detectar cambios)
        this.lastWarning = {}; // input_name -> ultima advertencia de escalado ya avisada
    }

    /**
     * Calcula el valor escalado. `c` = { min, max, out_min, out_max, percent, unit }.
     * Devuelve { ok, value, text, warnings[] }:
     *   ok=false  -> configuracion incompleta/invalida o lectura no numerica
     *                (en ese caso el puente NO escala y publica el valor crudo).
     *   value     -> numero redondeado a 2 decimales (lo que se publica).
     *   text      -> value con "%" o con la unidad, solo para mostrar en pantalla.
     * Si la lectura cae fuera de [min, max] se LIMITA al rango (0-100% de la
     * escala) y se avisa en warnings.
     */
    InputMqttBridge.computeScale = function (raw, c) {
        const n = (v) => (v === null || v === undefined || v === "" ? NaN : Number(v));
        const x = n(raw), min = n(c.min), max = n(c.max);
        let oMin = n(c.out_min), oMax = n(c.out_max);
        const percent = !!Number(c.percent);
        const warnings = [];

        if (percent) { // es_porcentaje: si no pusieron escala, usa 0-100
            if (isNaN(oMin)) oMin = 0;
            if (isNaN(oMax)) oMax = 100;
        }
        if (isNaN(min) || isNaN(max) || isNaN(oMin) || isNaN(oMax)) {
            return { ok: false, warnings: ["Completa min, max, escala_min y escala_max."] };
        }
        if (min === max) {
            return { ok: false, warnings: ["min y max no pueden ser iguales (division entre cero)."] };
        }
        if (oMin === oMax) warnings.push("escala_min y escala_max son iguales: el valor publicado sera siempre el mismo.");
        if (percent && (Math.min(oMin, oMax) !== 0 || Math.max(oMin, oMax) !== 100)) {
            warnings.push("Marcaste es_porcentaje pero la escala no es 0-100.");
        }
        if (isNaN(x)) {
            return { ok: false, warnings: warnings.concat("Todavia no llega una lectura numerica.") };
        }

        let ratio = (x - min) / (max - min);
        if (ratio < 0 || ratio > 1) {
            warnings.push(`La lectura ${x} esta fuera del rango [${min}, ${max}]: se limita al rango.`);
            ratio = Math.min(1, Math.max(0, ratio));
        }
        const value = Math.round((oMin + ratio * (oMax - oMin)) * 100) / 100;
        const unit = percent ? "%" : String(c.unit || "").trim();
        const text = percent ? value + "%" : (unit ? value + " " + unit : String(value));
        return { ok: true, value, text, warnings };
    };

    /**
     * Traduce el nombre de entrada tal como lo ve el usuario (I12, I13,
     * A0-A3) a la clave que realmente usa HardwareBridge.onInput() en su
     * objeto { digital, analog } (A0-A3 se normalizan a AI0-AI3, igual
     * que en hardware-bridge.js -> _assignReading()).
     */
    InputMqttBridge.prototype._readingKey = function (inputName) {
        const up = String(inputName || "").toUpperCase();
        if (up.startsWith("AI")) return { group: "analog", key: up };
        if (up.startsWith("A")) return { group: "analog", key: "AI" + up.slice(1) };
        return { group: "digital", key: up };
    };

    /**
     * Callback que se registra en HardwareBridge.onInput(). Recibe cada
     * lectura entrante (venga por Serial o por MQTT) y publica solo las
     * entradas configuradas cuyo valor cambio respecto a la anterior.
     */
    InputMqttBridge.prototype.handleReading = function (reading) {
        const digital = (reading && reading.digital) || {};
        const analog = (reading && reading.analog) || {};

        this.configs.forEach((cfg) => {
            const { group, key } = this._readingKey(cfg.input_name);
            const bucket = group === "analog" ? analog : digital;
            if (!(key in bucket)) return; // esta lectura no trajo esta entrada

            const raw = bucket[key];
            let value = raw;
            if (Number(cfg.scale_enabled)) {
                const res = InputMqttBridge.computeScale(raw, {
                    min: cfg.scale_min, max: cfg.scale_max,
                    out_min: cfg.scale_out_min, out_max: cfg.scale_out_max,
                    percent: cfg.scale_percent, unit: cfg.scale_unit,
                });
                if (res.ok) value = res.value; // si no, publica el crudo y avisa
                const w = res.warnings.join(" ");
                if (w && this.lastWarning[cfg.input_name] !== w) {
                    this.lastWarning[cfg.input_name] = w;
                    if (typeof this.onWarning === "function") this.onWarning(cfg.input_name, w);
                }
            }
            // Se compara el valor YA PUBLICADO (escalado): si la lectura cruda
            // cambia pero el valor escalado queda igual, no se republica.
            const prev = this.lastValues[cfg.input_name];
            if (prev !== undefined && prev === value) return; // sin cambio, no publica
            this.lastValues[cfg.input_name] = value;

            const payload = {};
            payload[cfg.mqtt_key_command || "command"] = cfg.input_name;
            payload[cfg.mqtt_key_state || "state"] = String(value);

            if (window.HardwareBridge) {
                Promise.resolve(window.HardwareBridge.publishMqtt(cfg.topic, payload)).catch((err) => {
                    window.HardwareBridge.log(
                        "error",
                        `Puente MQTT (${cfg.input_name}): ${err.message}`
                    );
                });
            }

            if (typeof this.onPublish === "function") {
                this.onPublish(cfg.input_name, value, cfg.topic, payload);
            }
        });
    };

    /** Se suscribe a HardwareBridge.onInput() - llamar despues de conectar Serial/MQTT. */
    InputMqttBridge.prototype.start = function () {
        if (!window.HardwareBridge) {
            console.error("[InputMqttBridge] Falta cargar hardware-bridge.js antes de mqtt-bridge.js");
            return;
        }
        window.HardwareBridge.onInput(this.handleReading.bind(this));
    };

    window.InputMqttBridge = InputMqttBridge;
})();
