/**
 * rules-engine.js
 * -----------------------------------------------------------------------
 * Espejo, en el navegador, de core/rule_engine.py de la app Kivy:
 *   - Carga las reglas del usuario para un modulo (api/get_rules.php).
 *   - Mantiene el ultimo snapshot de Entradas Digitales/Analogicas
 *     (llega via HardwareBridge.onInput, sin importar Serial o MQTT).
 *   - evaluate(className, confidencePct) revisa que reglas de ese modulo
 *     coinciden (clase + confianza + condicion digital/analogica) y
 *     dispara la Salida configurada (con cooldown para no saturar).
 *   - evaluateContinuous(pct) es para reglas de ESCALA CONTINUA (modulo
 *     "pinch"): sin clase/confianza, interpola el valor de salida entre
 *     scale_out_min (0%) y scale_out_max (100%) mientras se cumplan las
 *     condiciones Digital/Analogica configuradas.
 *   - Para las Estadisticas (Panel de institucion / Panel de usuario):
 *     LA MISMA MECANICA para las 3 fuentes (OnlineCam, StaticCam,
 *     ApiCam) - solo se registra en inference_events cuando una Regla
 *     SI se activa, con UN solo registro por activacion (con clase,
 *     confianza, tiempo de inferencia y la regla/salida que disparo).
 *     No se registra nada si ninguna regla coincide. Ademas, mientras
 *     la MISMA regla se mantenga activa sin interrupcion (menos de
 *     ACTIVE_GRACE_MS entre coincidencias) no se vuelve a registrar -
 *     solo la primera vez de cada activacion. Cuando deja de cumplirse
 *     y luego vuelve a cumplirse, cuenta como una activacion nueva.
 * -----------------------------------------------------------------------
 */
(function () {
    const COOLDOWN_MS = 1200;             // minimo entre reactivaciones de la Salida fisica (Serial/MQTT)
    const CONTINUOUS_INTERVAL_MS = 150;   // limite de envio para reglas de escala continua
    const ACTIVE_GRACE_MS = 2500;         // si una regla no vuelve a coincidir en este lapso, la proxima vez cuenta como activacion NUEVA (se registra de nuevo)

    class RulesEngine {
        /**
         * @param {string} moduleName - "yolo", "yolo_seg", "movenet",
         *        "handlandmarks", "pinch", "qr" o "aruco".
         * @param {string} [source] - de donde vienen las inferencias,
         *        para las Estadisticas: "onlinecam" (los 7 modulos en
         *        vivo, cuadro a cuadro - valor por defecto), "staticcam"
         *        o "apicam" (captura manual). Las 3 usan la misma
         *        mecanica de registro (solo cuando se activa una regla).
         */
        constructor(moduleName, source = "onlinecam") {
            this.moduleName = moduleName;
            this.source = source;
            this.rules = [];
            this.io = { digital: {}, analog: {} };
            this._lastFired = {};      // rule.id -> timestamp del ultimo refuerzo de Salida (reglas discretas)
            this._lastContinuous = {}; // rule.id -> timestamp (reglas de escala continua)
            this._activeUntil = {};    // rule.id -> hasta que timestamp se considera "ya activa" (no se vuelve a registrar)

            if (window.HardwareBridge) {
                window.HardwareBridge.onInput((snapshot) => {
                    Object.assign(this.io.digital, snapshot.digital);
                    Object.assign(this.io.analog, snapshot.analog);
                });
            }
        }

        async load() {
            const resp = await fetch(`${window.BASE_URL || ""}/api/get_rules.php?module=${encodeURIComponent(this.moduleName)}`, {
                credentials: "include",
            });
            if (!resp.ok) throw new Error("No se pudieron cargar las reglas (" + resp.status + ")");
            this.rules = await resp.json();
            return this.rules;
        }

        _digitalMatches(rule) {
            if (!rule.digital_pin) return true;
            const val = this.io.digital[rule.digital_pin];
            return val !== undefined && Number(val) === Number(rule.digital_value);
        }

        _analogMatches(rule) {
            if (!rule.analog_pin) return true;
            const val = this.io.analog[rule.analog_pin];
            if (val === undefined) return false;
            const target = Number(rule.analog_value);
            switch (rule.analog_op) {
                case ">": return val > target;
                case "<": return val < target;
                case ">=": return val >= target;
                case "<=": return val <= target;
                case "==": return val === target;
                default: return false;
            }
        }

        _sendLog(payload) {
            const url = `${window.BASE_URL || ""}/api/log_inference.php`;
            const body = JSON.stringify(payload);
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(url, new Blob([body], { type: "application/json" }));
                    return;
                }
            } catch (err) { /* seguir con fetch como respaldo */ }
            fetch(url, { method: "POST", body, credentials: "include", keepalive: true })
                .catch((err) => console.error("[RulesEngine] Error registrando estadistica:", err));
        }

        /**
         * @param {string} className - clase/zona/gesto detectado en este frame.
         * @param {number} confidencePct - 0-100.
         * @param {number} [classId] - id numerico de la clase (igual a
         *        detector.getClassId(className) en el modulo que llama).
         *        Se publica en el payload MQTT para el mismo ciclo que
         *        usa rule_engine.py (class_id, class_name, confidence...).
         * @param {number} [inferenceMs] - tiempo de inferencia del
         *        detector en este frame/captura (para Estadisticas, solo
         *        se guarda si esta activacion termina disparando una regla).
         */
        evaluate(className, confidencePct, classId = 0, inferenceMs = null) {
            const now = performance.now();
            for (const rule of this.rules) {
                if (!rule.active) continue;
                if (rule.scale_out_min !== null && rule.scale_out_min !== undefined) continue; // es una regla de escala, no discreta
                if (rule.class_name && rule.class_name !== className) continue;
                if (rule.min_confidence && confidencePct < rule.min_confidence) continue;
                if (!this._digitalMatches(rule)) continue;
                if (!this._analogMatches(rule)) continue;

                // La regla SI coincide en este ciclo: si no coincidia
                // desde hace mas de ACTIVE_GRACE_MS, es una activacion
                // NUEVA (se registra). Si ya estaba activa, se extiende
                // la ventana pero NO se vuelve a registrar.
                const isNewActivation = (this._activeUntil[rule.id] || 0) <= now;
                this._activeUntil[rule.id] = now + ACTIVE_GRACE_MS;

                const last = this._lastFired[rule.id] || 0;
                if (now - last < COOLDOWN_MS) continue; // igual se refuerza la Salida fisica cada COOLDOWN_MS
                this._lastFired[rule.id] = now;

                this._fire(rule, className, confidencePct, classId, rule.action_value, isNewActivation, inferenceMs);
            }
        }

        /**
         * Reglas de ESCALA CONTINUA (module = "pinch"): sin disparador
         * discreto, se interpola linealmente el valor de salida entre
         * scale_out_min (0%) y scale_out_max (100%) segun pct, y se envia
         * mientras se cumplan las condiciones Digital/Analogica (si estan
         * configuradas). Limitado a CONTINUOUS_INTERVAL_MS para no saturar.
         *
         * @param {number} pct - 0-100, porcentaje actual (ej. apertura de pinza).
         * @param {string} [className] - para el payload MQTT (ej. "Apertura de Pinza").
         * @param {number} [classId]
         * @param {number} [inferenceMs] - tiempo de inferencia (Estadisticas).
         */
        evaluateContinuous(pct, className = "", classId = 0, inferenceMs = null) {
            const now = performance.now();
            for (const rule of this.rules) {
                if (!rule.active) continue;
                if (rule.scale_out_min === null || rule.scale_out_min === undefined) continue; // no es de escala
                if (rule.class_name && className && rule.class_name !== className) continue;
                if (!this._digitalMatches(rule)) continue;
                if (!this._analogMatches(rule)) continue;

                const isNewActivation = (this._activeUntil[rule.id] || 0) <= now;
                this._activeUntil[rule.id] = now + ACTIVE_GRACE_MS;

                const last = this._lastContinuous[rule.id] || 0;
                if (now - last < CONTINUOUS_INTERVAL_MS) continue;
                this._lastContinuous[rule.id] = now;

                const min = Number(rule.scale_out_min);
                const max = Number(rule.scale_out_max);
                const value = Math.round(min + (Math.max(0, Math.min(100, pct)) / 100) * (max - min));

                this._fire(rule, className || rule.class_name || "", pct, classId, value, isNewActivation, inferenceMs);
            }
        }

        /**
         * Mismo ciclo que rule_engine.py al cumplirse una regla:
         *   1) Activa la Salida (Serial o MQTT segun HardwareBridge) -
         *      esto pasa SIEMPRE que se llega aca, cada COOLDOWN_MS,
         *      para mantener la Salida fisica sincronizada.
         *   2) Si la regla tiene MQTT propio activado, publica con las
         *      claves JSON personalizables (mqtt_key_*), igual que la
         *      pantalla "Publicar MQTT" de la app.
         *   3) Registra UN solo evento en las Estadisticas -con clase,
         *      confianza, tiempo de inferencia y la regla/salida que
         *      disparo, todo junto- pero SOLO si shouldLog es true
         *      (activacion nueva). Mientras la regla se mantenga activa
         *      sin interrupcion no se vuelve a registrar.
         */
        _fire(rule, className, confidencePct, classId, outputValue, shouldLog = true, inferenceMs = null) {
            if (window.HardwareBridge) {
                window.HardwareBridge.triggerAction(rule.action_pin, outputValue).catch((err) => {
                    console.error("[RulesEngine] Error al activar salida:", err);
                });
            }
            if (rule.mqtt_enabled && rule.mqtt_topic && window.HardwareBridge) {
                const payload = {};
                payload[rule.mqtt_key_class_id || "class_id"] = classId;
                payload[rule.mqtt_key_class_name || "class_name"] = className;
                payload[rule.mqtt_key_confidence || "confidence"] = Math.round(confidencePct);
                payload[rule.mqtt_key_value || "state"] = String(outputValue);
                payload[rule.mqtt_key_pin || "pin"] = rule.action_pin;
                payload[rule.mqtt_key_tag || "command"] = rule.mqtt_tag || rule.action_pin;
                // publishMqtt es async (puede ir por MQTT del navegador o
                // por el proxy remoto via API - ver hardware-bridge.js),
                // por eso se captura el error con .catch() en vez de
                // try/catch (que no atraparia un rechazo de promesa).
                Promise.resolve(window.HardwareBridge.publishMqtt(rule.mqtt_topic, payload)).catch((err) => {
                    console.error("[RulesEngine] Error al publicar MQTT de la regla:", err);
                });
            }
            if (!shouldLog) return;
            this._sendLog({
                source: this.source,
                // ApiCam usa nombres de modulo con prefijo ("apicam_handlandmarks")
                // para tener su PROPIO set de reglas, independiente del de
                // OnlineCam/StaticCam - pero para las Estadisticas siempre
                // se guarda el nombre "limpio" (sin el prefijo), porque
                // "source" ya distingue de donde vino (onlinecam/staticcam/
                // apicam) y api/log_inference.php solo acepta los 7 nombres
                // de modulo canonicos.
                module: this.moduleName.replace(/^apicam_/, ""),
                class_name: className,
                confidence: typeof confidencePct === "number" ? Math.round(confidencePct * 100) / 100 : null,
                inference_ms: typeof inferenceMs === "number" && !Number.isNaN(inferenceMs) ? Math.round(inferenceMs * 100) / 100 : null,
                rule_matched: 1,
                rule_id: rule.id,
                action_pin: rule.action_pin,
                // Si la regla exigia una condicion de Entrada Digital y/o
                // Analogica para activarse (pueden ser las dos a la vez).
                used_digital_input: !!rule.digital_pin,
                used_analog_input: !!rule.analog_pin,
                events_count: 1,
            });
        }
    }

    window.RulesEngine = RulesEngine;
})();
