/**
 * colorimeter-detector.js
 * -----------------------------------------------------------------------
 * Version en el navegador de api/detectors/colorimeter_detector.py:
 * dibuja un cuadrado en el centro del frame (~60% del lado mas chico de
 * la imagen) y clasifica el color promedio de esa zona en uno de 9
 * colores basicos, convirtiendo a HSV y aplicando las MISMAS reglas de
 * matiz que la version Python (para que OnlineCam/StaticCam y ApiCam
 * clasifiquen igual el mismo color).
 *
 * No usa ningun modelo ONNX - es puro procesamiento de pixeles, por eso
 * detect() no es realmente async, pero se mantiene esa firma para
 * seguir el mismo patron que los demas detectores (YoloDetector,
 * MoveNetDetector, etc.), que si cargan un modelo.
 * -----------------------------------------------------------------------
 */
(function () {
    const CLASS_NAMES = ["Negro", "Blanco", "Gris", "Rojo", "Naranja", "Amarillo", "Verde", "Azul", "Morado"];

    // Rangos de Matiz (H, escala OpenCV 0-179) para cada color cromatico.
    // El rojo envuelve el 0 (rueda de color), por eso tiene dos rangos.
    const HUE_RANGES = [
        ["Rojo", [[0, 10], [170, 179]]],
        ["Naranja", [[11, 25]]],
        ["Amarillo", [[26, 35]]],
        ["Verde", [[36, 85]]],
        ["Azul", [[86, 130]]],
        ["Morado", [[131, 169]]],
    ];

    const SQUARE_RATIO = 0.60;
    const DEFAULT_MIN_SATURATION = 40;

    /** RGB (0-255 cada canal) -> HSV en escala OpenCV: H 0-179, S 0-255, V 0-255. */
    function rgbToHsvOpenCV(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        const d = max - min;
        let h = 0;
        if (d !== 0) {
            if (max === r) h = ((g - b) / d) % 6;
            else if (max === g) h = (b - r) / d + 2;
            else h = (r - g) / d + 4;
            h *= 60;
            if (h < 0) h += 360;
        }
        const s = max === 0 ? 0 : d / max;
        const v = max;
        return [h / 2, s * 255, v * 255];
    }

    /**
     * Mismas reglas que _classify() en colorimeter_detector.py, con los
     * mismos umbrales (v_mean<50 -> Negro, s_mean<minSaturation ->
     * Blanco/Gris, si no, matiz -> color cromatico).
     */
    function classifyColor(hMean, sMean, vMean, hStd, minSaturation) {
        if (vMean < 50) {
            return { name: "Negro", confidence: Math.min(1, 0.5 + (50 - vMean) / 50) };
        }
        if (sMean < minSaturation) {
            if (vMean > 180) {
                return { name: "Blanco", confidence: Math.min(1, 0.6 + ((vMean - 180) / 75) * 0.4) };
            }
            return { name: "Gris", confidence: Math.min(1, 0.6 + ((minSaturation - sMean) / Math.max(1, minSaturation)) * 0.3) };
        }
        for (const [colorName, ranges] of HUE_RANGES) {
            for (const [lo, hi] of ranges) {
                if (hMean >= lo && hMean <= hi) {
                    const center = (lo + hi) / 2;
                    const span = Math.max(1, (hi - lo) / 2);
                    const closeness = 1 - Math.min(1, Math.abs(hMean - center) / span);
                    const uniformity = Math.max(0, 1 - hStd / 40);
                    const confidence = 0.5 + 0.3 * closeness + 0.2 * uniformity;
                    return { name: colorName, confidence: Math.max(0, Math.min(1, confidence)) };
                }
            }
        }
        return { name: "Gris", confidence: 0.4 };
    }

    /** Promedia H/S/V (y desvio de H) de un buffer RGBA (ImageData.data). */
    function averageHsv(rgba) {
        let sumH = 0, sumS = 0, sumV = 0;
        const n = rgba.length / 4;
        const hs = new Float64Array(n);
        for (let i = 0, p = 0; i < rgba.length; i += 4, p++) {
            const [h, s, v] = rgbToHsvOpenCV(rgba[i], rgba[i + 1], rgba[i + 2]);
            hs[p] = h;
            sumH += h; sumS += s; sumV += v;
        }
        const hMean = sumH / n, sMean = sumS / n, vMean = sumV / n;
        let sumSqDiff = 0;
        for (let p = 0; p < n; p++) sumSqDiff += (hs[p] - hMean) ** 2;
        const hStd = Math.sqrt(sumSqDiff / n);
        return { hMean, sMean, vMean, hStd };
    }

    class ColorimeterDetector {
        constructor(options = {}) {
            this.classNames = CLASS_NAMES;
            this.minSaturation = options.minSaturation ?? DEFAULT_MIN_SATURATION;
            this._canvas = document.createElement("canvas");
            this._ctx = this._canvas.getContext("2d", { willReadFrequently: true });
        }

        getClassId(name) {
            const idx = this.classNames.indexOf(name);
            return idx === -1 ? 0 : idx;
        }

        /** Coordenadas del cuadrado central (mismo criterio que _square_coords() en Python). */
        squareCoords(w, h) {
            const side = Math.max(4, Math.round(Math.min(w, h) * SQUARE_RATIO));
            const cx = Math.floor(w / 2);
            const cy = Math.floor(h / 2);
            const x1 = cx - Math.floor(side / 2);
            const y1 = cy - Math.floor(side / 2);
            return { x1, y1, x2: x1 + side, y2: y1 + side, side };
        }

        /**
         * @param {HTMLVideoElement|HTMLCanvasElement} source - video en
         *        vivo (OnlineCam) o canvas "video-like" con
         *        videoWidth/videoHeight (StaticCam, foto de galeria).
         */
        async detect(source) {
            const t0 = performance.now();
            const w = source.videoWidth;
            const h = source.videoHeight;
            const { x1, y1, x2, y2, side } = this.squareCoords(w, h);

            this._canvas.width = side;
            this._canvas.height = side;
            this._ctx.drawImage(source, x1, y1, side, side, 0, 0, side, side);
            const { data } = this._ctx.getImageData(0, 0, side, side);

            const { hMean, sMean, vMean, hStd } = averageHsv(data);
            const { name, confidence } = classifyColor(hMean, sMean, vMean, hStd, this.minSaturation);
            const inferenceMs = performance.now() - t0;

            return { box: [x1, y1, x2, y2], className: name, confidence, inferenceMs };
        }
    }

    window.ColorimeterDetector = ColorimeterDetector;
    window.COLORIMETER_CLASS_NAMES = CLASS_NAMES;
    // Exportados solo para pruebas unitarias en Node (ver herramientas de test) - no se usan en el navegador.
    if (typeof module !== "undefined" && module.exports) {
        module.exports = { rgbToHsvOpenCV, classifyColor, averageHsv, CLASS_NAMES };
    }
})();
