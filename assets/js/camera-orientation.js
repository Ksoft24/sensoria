/**
 * camera-orientation.js
 * -----------------------------------------------------------------------
 * Detecta si el dispositivo esta en vertical u horizontal y arma las
 * constraints de getUserMedia con las dimensiones correctas segun la
 * configuracion guardada en /camara.php (Dimension Horizontal /
 * Dimension Vertical).
 *
 * Uso en cada modulo (reemplaza el bloque manual de constraints):
 *
 *   const cam = new CameraOrientation(window.CAMERA_CONFIG);
 *   const stream = await navigator.mediaDevices.getUserMedia(cam.constraints());
 *   cam.onChange(() => location.reload()); // opcional: reiniciar al girar el dispositivo
 *
 * `window.CAMERA_CONFIG` lo inyecta cada pagina PHP con los valores de
 * camera_config del usuario:
 *   window.CAMERA_CONFIG = {
 *     device_id: "...", width_h: 1280, height_h: 720, width_v: 720, height_v: 1280
 *   };
 * -----------------------------------------------------------------------
 */
class CameraOrientation {
    constructor(config) {
        this.config = config || {};
        this._mql = window.matchMedia("(orientation: portrait)");
    }

    isPortrait() {
        return this._mql.matches;
    }

    /** Constraints listas para pasar a navigator.mediaDevices.getUserMedia(). */
    constraints() {
        const c = this.config;
        const portrait = this.isPortrait();
        const width = portrait ? (c.width_v || 720) : (c.width_h || 1280);
        const height = portrait ? (c.height_v || 1280) : (c.height_h || 720);

        const video = {
            width: { ideal: width },
            height: { ideal: height },
        };
        if (c.device_id) {
            video.deviceId = { exact: c.device_id };
        } else {
            video.facingMode = "environment";
        }
        return { video, audio: false };
    }

    /**
     * Llama callback() cada vez que el dispositivo cambia de orientacion
     * (por resize/rotacion). Util para volver a pedir la camara con las
     * dimensiones correctas (ej. recargando la pagina, lo mas simple y
     * confiable entre navegadores).
     */
    onChange(callback) {
        const handler = () => callback(this.isPortrait());
        if (this._mql.addEventListener) {
            this._mql.addEventListener("change", handler);
        } else {
            // Safari viejo
            this._mql.addListener(handler);
        }
        return handler;
    }
}

window.CameraOrientation = CameraOrientation;
