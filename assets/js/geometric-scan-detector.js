/**
 * geometric-scan-detector.js
 * -----------------------------------------------------------------------
 * Version en el navegador de api/detectors/geometric_scan_detector.py,
 * SIN OpenCV (no existe en el navegador): en vez de Canny + findContours
 * de OpenCV, esta version:
 *   1) Convierte a escala de grises y aplica un umbral de Otsu (separa
 *      automaticamente la figura del fondo, sea cual sea el mas oscuro).
 *   2) Etiqueta componentes conexas (flood fill) sobre la mascara binaria
 *      para aislar cada figura.
 *   3) Traza el contorno externo de cada componente (algoritmo de
 *      seguimiento de vecinos de Moore, el mismo tipo de tecnica que usa
 *      OpenCV internamente para findContours).
 *   4) Simplifica ese contorno con Douglas-Peucker (equivalente a
 *      cv2.approxPolyDP) usando el MISMO epsilon relativo (2% del
 *      perimetro) que la version Python, y clasifica por cantidad de
 *      vertices con las MISMAS reglas (Triangulo/Cuadrado o Rectangulo
 *      segun aspecto/Pentagono/Hexagono/Circulo por circularidad).
 *
 * Pensado para figuras solidas bien contrastadas contra el fondo (una
 * figura dibujada/impresa sobre una hoja, por ejemplo) - no reemplaza
 * Canny en escenas complejas, pero clasifica igual que la version
 * Python en el caso de uso tipico de este modulo.
 * -----------------------------------------------------------------------
 */
(function () {
    const CLASS_NAMES = ["Triangulo", "Rectangulo", "Cuadrado", "Pentagono", "Hexagono", "Circulo"];

    const MIN_AREA = 1200;
    const SQUARE_ASPECT_TOL = 0.12;
    const CIRCLE_MIN_CIRCULARITY = 0.80;
    const MAX_WORK_WIDTH = 480; // se reduce la imagen para que el trazado de contornos sea rapido

    function circularity(area, perimeter) {
        if (perimeter <= 0) return 0;
        return (4 * Math.PI * area) / (perimeter * perimeter);
    }

    // ------------------------------------------------------------------
    // 1) Escala de grises + umbral de Otsu
    // ------------------------------------------------------------------
    function toGrayscale(rgba, w, h) {
        const gray = new Uint8ClampedArray(w * h);
        for (let i = 0, p = 0; i < rgba.length; i += 4, p++) {
            gray[p] = (0.299 * rgba[i] + 0.587 * rgba[i + 1] + 0.114 * rgba[i + 2]) | 0;
        }
        return gray;
    }

    function otsuThreshold(gray) {
        const hist = new Array(256).fill(0);
        for (let i = 0; i < gray.length; i++) hist[gray[i]]++;
        const total = gray.length;

        let sum = 0;
        for (let t = 0; t < 256; t++) sum += t * hist[t];

        let sumB = 0, wB = 0, maxVar = 0, threshold = 127;
        for (let t = 0; t < 256; t++) {
            wB += hist[t];
            if (wB === 0) continue;
            const wF = total - wB;
            if (wF === 0) break;
            sumB += t * hist[t];
            const mB = sumB / wB;
            const mF = (sum - sumB) / wF;
            const varBetween = wB * wF * (mB - mF) * (mB - mF);
            if (varBetween > maxVar) {
                maxVar = varBetween;
                threshold = t;
            }
        }
        return threshold;
    }

    /**
     * Mascara binaria (1 = figura, 0 = fondo). Como no sabemos de
     * antemano si la figura es mas oscura o mas clara que el fondo, se
     * asume que el fondo es la clase con MAS pixeles (caso tipico: una
     * figura chica sobre una hoja/superficie uniforme) y la figura es la
     * clase minoritaria.
     */
    function binarize(gray, threshold) {
        let darkCount = 0;
        for (let i = 0; i < gray.length; i++) if (gray[i] <= threshold) darkCount++;
        const darkIsMinority = darkCount < gray.length / 2;

        const mask = new Uint8Array(gray.length);
        for (let i = 0; i < gray.length; i++) {
            const isDark = gray[i] <= threshold;
            mask[i] = (isDark === darkIsMinority) ? 1 : 0;
        }
        return mask;
    }

    // ------------------------------------------------------------------
    // 2) Componentes conexas (BFS/flood fill, conectividad-4)
    // ------------------------------------------------------------------
    function connectedComponents(mask, w, h) {
        const visited = new Uint8Array(mask.length);
        const components = [];

        for (let start = 0; start < mask.length; start++) {
            if (mask[start] === 0 || visited[start]) continue;

            const pixels = [];
            const queue = [start];
            visited[start] = 1;
            let minX = w, minY = h, maxX = 0, maxY = 0;

            while (queue.length) {
                const idx = queue.pop();
                const x = idx % w, y = (idx / w) | 0;
                pixels.push(idx);
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;

                const neighbors = [
                    x > 0 ? idx - 1 : -1,
                    x < w - 1 ? idx + 1 : -1,
                    y > 0 ? idx - w : -1,
                    y < h - 1 ? idx + w : -1,
                ];
                for (const n of neighbors) {
                    if (n >= 0 && mask[n] === 1 && !visited[n]) {
                        visited[n] = 1;
                        queue.push(n);
                    }
                }
            }

            components.push({ pixels, bbox: { minX, minY, maxX, maxY } });
        }
        return components;
    }

    // ------------------------------------------------------------------
    // 3) Trazado de contorno externo (seguimiento de vecinos de Moore)
    // ------------------------------------------------------------------
    // Los 8 vecinos en sentido horario, empezando por el de la izquierda.
    const MOORE_DIRS = [
        [-1, 0], [-1, -1], [0, -1], [1, -1],
        [1, 0], [1, 1], [0, 1], [-1, 1],
    ];

    function inMask(mask, w, h, x, y) {
        if (x < 0 || y < 0 || x >= w || y >= h) return false;
        return mask[y * w + x] === 1;
    }

    /** Punto inicial: el pixel mas arriba-izquierda de la componente (borde externo). */
    function traceBoundary(mask, w, h, bbox) {
        let start = null;
        outer: for (let y = bbox.minY; y <= bbox.maxY; y++) {
            for (let x = bbox.minX; x <= bbox.maxX; x++) {
                if (inMask(mask, w, h, x, y)) {
                    start = [x, y];
                    break outer;
                }
            }
        }
        if (!start) return [];

        const contour = [start];
        let current = start;
        let backtrackDir = 0; // se entro al pixel inicial "desde la izquierda"
        const maxSteps = mask.length * 4;
        let steps = 0;

        while (steps++ < maxSteps) {
            let found = false;
            for (let k = 0; k < 8; k++) {
                const dir = (backtrackDir + k) % 8;
                const [dx, dy] = MOORE_DIRS[dir];
                const nx = current[0] + dx, ny = current[1] + dy;
                if (inMask(mask, w, h, nx, ny)) {
                    current = [nx, ny];
                    contour.push(current);
                    backtrackDir = (dir + 5) % 8; // retomar busqueda desde "detras" del paso que se dio
                    found = true;
                    break;
                }
            }
            if (!found) break; // pixel aislado (componente de 1 solo pixel)
            if (current[0] === start[0] && current[1] === start[1] && contour.length > 2) break;
        }
        return contour;
    }

    // ------------------------------------------------------------------
    // 3b) Envolvente convexa (Andrew monotone chain). Las 6 figuras que
    // reconoce este modulo son todas convexas: calcular primero la
    // envolvente convexa del contorno "en escalera" (inevitable al
    // trazar un contorno pixel a pixel) elimina el ruido de rasterizado
    // ANTES de simplificar, dejando solo los vertices reales de la
    // figura (mas los "escalones" del borde, que Douglas-Peucker si
    // puede colapsar con un contorno ya convexo y mucho mas corto).
    // ------------------------------------------------------------------
    function cross(o, a, b) {
        return (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
    }

    function convexHull(points) {
        const pts = points.slice().sort((a, b) => (a[0] - b[0]) || (a[1] - b[1]));
        const n = pts.length;
        if (n < 3) return pts;

        const lower = [];
        for (const p of pts) {
            while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop();
            lower.push(p);
        }
        const upper = [];
        for (let i = n - 1; i >= 0; i--) {
            const p = pts[i];
            while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop();
            upper.push(p);
        }
        upper.pop();
        lower.pop();
        return lower.concat(upper);
    }

    // ------------------------------------------------------------------
    // 4) Simplificacion Douglas-Peucker + metricas de poligono
    // ------------------------------------------------------------------
    function perimeterOf(points) {
        let p = 0;
        for (let i = 0; i < points.length; i++) {
            const [x1, y1] = points[i];
            const [x2, y2] = points[(i + 1) % points.length];
            p += Math.hypot(x2 - x1, y2 - y1);
        }
        return p;
    }

    // Formula del "shoelace" (area de poligono cerrado por coordenadas).
    function areaOf(points) {
        let a = 0;
        for (let i = 0; i < points.length; i++) {
            const [x1, y1] = points[i];
            const [x2, y2] = points[(i + 1) % points.length];
            a += x1 * y2 - x2 * y1;
        }
        return Math.abs(a) / 2;
    }

    function pointSegmentDistance(p, a, b) {
        const [px, py] = p, [ax, ay] = a, [bx, by] = b;
        const dx = bx - ax, dy = by - ay;
        const lenSq = dx * dx + dy * dy;
        if (lenSq === 0) return Math.hypot(px - ax, py - ay);
        let t = ((px - ax) * dx + (py - ay) * dy) / lenSq;
        t = Math.max(0, Math.min(1, t));
        const cx = ax + t * dx, cy = ay + t * dy;
        return Math.hypot(px - cx, py - cy);
    }

    function douglasPeucker(points, epsilon) {
        if (points.length < 3) return points.slice();
        let maxDist = 0, index = 0;
        const end = points.length - 1;
        for (let i = 1; i < end; i++) {
            const d = pointSegmentDistance(points[i], points[0], points[end]);
            if (d > maxDist) { maxDist = d; index = i; }
        }
        if (maxDist > epsilon) {
            const left = douglasPeucker(points.slice(0, index + 1), epsilon);
            const right = douglasPeucker(points.slice(index), epsilon);
            return left.slice(0, -1).concat(right);
        }
        return [points[0], points[end]];
    }

    /** Douglas-Peucker para un poligono CERRADO (ej. una envolvente convexa): parte el lazo en 2 arcos usando los 2 puntos mas separados, simplifica cada arco por separado y los vuelve a unir. */
    function simplifyClosedPolygon(loop, epsilon) {
        if (loop.length < 4) return loop.slice();

        let iA = 0, iB = 1, maxDist = -1;
        for (let i = 0; i < loop.length; i++) {
            for (let j = i + 1; j < loop.length; j++) {
                const d = Math.hypot(loop[i][0] - loop[j][0], loop[i][1] - loop[j][1]);
                if (d > maxDist) { maxDist = d; iA = i; iB = j; }
            }
        }
        const arc1 = iA < iB ? loop.slice(iA, iB + 1) : loop.slice(iA).concat(loop.slice(0, iB + 1));
        const arc2 = iA < iB ? loop.slice(iB).concat(loop.slice(0, iA + 1)) : loop.slice(iB, iA + 1);

        const simp1 = douglasPeucker(arc1, epsilon);
        const simp2 = douglasPeucker(arc2, epsilon);
        return simp1.slice(0, -1).concat(simp2.slice(0, -1));
    }

    // ------------------------------------------------------------------
    // Clasificacion por vertices - MISMAS reglas que _classify_contour()
    // en geometric_scan_detector.py.
    // ------------------------------------------------------------------
    function classifyPolygon(approx, area, perimeter, minArea) {
        const vertices = approx.length;
        const xs = approx.map((p) => p[0]);
        const ys = approx.map((p) => p[1]);
        const x = Math.min(...xs), y = Math.min(...ys);
        const w = Math.max(...xs) - x, h = Math.max(...ys) - y;
        const circ = circularity(area, perimeter);

        let name, confidence;
        if (vertices === 3) {
            name = "Triangulo";
            confidence = 0.6 + 0.3 * Math.min(1, area / (minArea * 4));
        } else if (vertices === 4) {
            const aspect = h ? w / h : 0;
            const isSquare = Math.abs(aspect - 1.0) <= SQUARE_ASPECT_TOL;
            name = isSquare ? "Cuadrado" : "Rectangulo";
            const closeness = 1 - Math.min(1, Math.abs(aspect - 1.0));
            confidence = 0.55 + 0.35 * (isSquare ? closeness : (1 - closeness));
        } else if (vertices === 5) {
            name = "Pentagono";
            confidence = 0.55 + 0.3 * Math.min(1, area / (minArea * 4));
        } else if (vertices === 6) {
            name = "Hexagono";
            confidence = 0.55 + 0.3 * Math.min(1, area / (minArea * 4));
        } else if (vertices >= 7 && circ >= CIRCLE_MIN_CIRCULARITY) {
            name = "Circulo";
            confidence = Math.min(1, circ);
        } else {
            return null;
        }
        confidence = Math.max(0, Math.min(1, confidence));
        return { box: [x, y, x + w, y + h], className: name, confidence };
    }

    class GeometricScanDetector {
        constructor(options = {}) {
            this.classNames = CLASS_NAMES;
            this.minArea = options.minArea ?? MIN_AREA;
            this._canvas = document.createElement("canvas");
            this._ctx = this._canvas.getContext("2d", { willReadFrequently: true });
        }

        getClassId(name) {
            const idx = this.classNames.indexOf(name);
            return idx === -1 ? 0 : idx;
        }

        /**
         * @param {HTMLVideoElement|HTMLCanvasElement} source
         * @returns {{shapes: Array<{box, className, confidence}>, inferenceMs: number}}
         */
        async detect(source) {
            const t0 = performance.now();
            const fullW = source.videoWidth;
            const fullH = source.videoHeight;
            const scale = fullW > MAX_WORK_WIDTH ? MAX_WORK_WIDTH / fullW : 1;
            const w = Math.max(1, Math.round(fullW * scale));
            const h = Math.max(1, Math.round(fullH * scale));

            this._canvas.width = w;
            this._canvas.height = h;
            this._ctx.drawImage(source, 0, 0, w, h);
            const { data } = this._ctx.getImageData(0, 0, w, h);

            const gray = toGrayscale(data, w, h);
            const threshold = otsuThreshold(gray);
            const mask = binarize(gray, threshold);
            const minAreaScaled = this.minArea * scale * scale; // el area minima tambien se reduce con el trabajo a menor resolucion
            const components = connectedComponents(mask, w, h).filter((c) => c.pixels.length >= minAreaScaled);

            const shapes = [];
            for (const comp of components) {
                const contour = traceBoundary(mask, w, h, comp.bbox);
                if (contour.length < 3) continue;
                const hull = convexHull(contour);
                if (hull.length < 3) continue;
                const perimeter = perimeterOf(hull);
                if (perimeter <= 0) continue;
                const approx = simplifyClosedPolygon(hull, 0.02 * perimeter);
                const area = areaOf(hull);
                const shape = classifyPolygon(approx, area, perimeter, minAreaScaled);
                if (!shape) continue;
                // Reescalar la caja a la resolucion original del frame.
                shape.box = shape.box.map((v) => Math.round(v / scale));
                shapes.push(shape);
            }

            const inferenceMs = performance.now() - t0;
            return { shapes, inferenceMs };
        }
    }

    window.GeometricScanDetector = GeometricScanDetector;
    window.GEOMETRIC_SCAN_CLASS_NAMES = CLASS_NAMES;
    // Exportados solo para pruebas unitarias en Node - no se usan en el navegador.
    if (typeof module !== "undefined" && module.exports) {
        module.exports = {
            toGrayscale, otsuThreshold, binarize, connectedComponents,
            traceBoundary, convexHull, douglasPeucker, simplifyClosedPolygon,
            areaOf, perimeterOf, classifyPolygon, CLASS_NAMES,
        };
    }
})();
