"""
detectors/geometric_scan_detector.py
--------------------------------------
"GeometricScan": detecta figuras geometricas basicas (Triangulo,
Rectangulo, Cuadrado, Pentagono, Hexagono, Circulo) usando OpenCV puro
(sin modelos de IA): bordes (Canny) + contornos + aproximacion poligonal
(approxPolyDP), clasificando por cantidad de vertices; el caso de
4 vertices se distingue entre Cuadrado/Rectangulo por relacion de
aspecto, y Circulo se confirma ademas con un test de circularidad.

Sigue el MISMO contrato que el resto de detectores de esta API
(detect()/draw()/to_payload()), igual que qr_detector.py/aruco_detector.py,
para que api/app.py lo pueda invocar sin casos especiales. Puede detectar
VARIAS figuras a la vez en un mismo frame (a diferencia de Colorimetro,
que siempre reporta una sola zona).
"""
import cv2
import numpy as np

CLASS_NAMES = ["Triangulo", "Rectangulo", "Cuadrado", "Pentagono", "Hexagono", "Circulo"]

_MIN_AREA = 1200            # descarta ruido/contornos diminutos
_MAX_AREA_RATIO = 0.45      # una figura no deberia ocupar mas del 45% del frame (si no, es probablemente el fondo)
_MIN_SOLIDITY = 0.82        # area real del contorno / area de su envolvente convexa - bajo esto es una silueta irregular (cara, ropa, textura), no una figura solida
_BORDER_MARGIN = 2          # px de margen para considerar que un contorno "toca" el borde del frame (ruido tipico de bordes/esquinas de la imagen)
_SQUARE_ASPECT_TOL = 0.12   # |ancho/alto - 1| menor a esto -> Cuadrado, si no -> Rectangulo
_CIRCLE_MIN_CIRCULARITY = 0.80


def _circularity(area, perimeter):
    if perimeter <= 0:
        return 0.0
    return float(4 * np.pi * area / (perimeter * perimeter))


class GeometricScanDetector:
    def __init__(self, min_area=_MIN_AREA, canny_low=50, canny_high=150):
        self.class_names = list(CLASS_NAMES)
        self.min_area = min_area
        self.canny_low = canny_low
        self.canny_high = canny_high

    def get_class_id(self, name):
        try:
            return self.class_names.index(name)
        except ValueError:
            return 0

    # ------------------------------------------------------------------
    def _classify_contour(self, cnt, frame_w, frame_h):
        perimeter = cv2.arcLength(cnt, True)
        if perimeter <= 0:
            return None

        area = cv2.contourArea(cnt)
        if area < self.min_area or area > frame_w * frame_h * _MAX_AREA_RATIO:
            return None

        x, y, w, h = cv2.boundingRect(cnt)
        # Un contorno que toca el borde del frame casi siempre es fondo
        # cortado por el encuadre (una pared, una esquina oscura, un
        # mueble), no un objeto sostenido frente a la camara.
        if x <= _BORDER_MARGIN or y <= _BORDER_MARGIN or (x + w) >= frame_w - 1 - _BORDER_MARGIN or (y + h) >= frame_h - 1 - _BORDER_MARGIN:
            return None

        # Prueba de solidez: una figura solida (impresa/dibujada) llena
        # casi toda su envolvente convexa. Una silueta irregular (cara,
        # pelo, ropa, textura de fondo) tiene huecos/entrantes y su area
        # real es bastante menor que la de su envolvente convexa.
        hull = cv2.convexHull(cnt)
        hull_area = cv2.contourArea(hull)
        if hull_area <= 0 or (area / hull_area) < _MIN_SOLIDITY:
            return None

        circularity = _circularity(area, perimeter)
        approx = cv2.approxPolyDP(cnt, 0.02 * perimeter, True)
        vertices = len(approx)
        x, y, w, h = cv2.boundingRect(approx)

        if vertices == 3:
            name = "Triangulo"
            confidence = 0.6 + 0.3 * min(1.0, area / (self.min_area * 4))
        elif vertices == 4:
            aspect = (w / float(h)) if h else 0
            is_square = abs(aspect - 1.0) <= _SQUARE_ASPECT_TOL
            name = "Cuadrado" if is_square else "Rectangulo"
            closeness = 1.0 - min(1.0, abs(aspect - 1.0))
            confidence = 0.55 + 0.35 * (closeness if is_square else (1.0 - closeness))
        elif vertices == 5:
            name = "Pentagono"
            confidence = 0.55 + 0.3 * min(1.0, area / (self.min_area * 4))
        elif vertices == 6:
            name = "Hexagono"
            confidence = 0.55 + 0.3 * min(1.0, area / (self.min_area * 4))
        elif vertices >= 7 and circularity >= _CIRCLE_MIN_CIRCULARITY:
            name = "Circulo"
            confidence = min(1.0, circularity)
        else:
            return None  # forma no identificada de forma confiable: se ignora

        confidence = max(0.0, min(1.0, confidence))
        return {"box": (x, y, x + w, y + h), "class_name": name, "confidence": confidence}

    # ------------------------------------------------------------------
    def detect(self, frame: np.ndarray):
        """Devuelve una lista de {box, class_name, confidence(0-1)}, una por figura encontrada."""
        frame_h, frame_w = frame.shape[:2]
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        blurred = cv2.GaussianBlur(gray, (5, 5), 0)
        edges = cv2.Canny(blurred, self.canny_low, self.canny_high)
        edges = cv2.dilate(edges, None, iterations=1)

        contours, _ = cv2.findContours(edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        results = []
        for cnt in contours:
            result = self._classify_contour(cnt, frame_w, frame_h)
            if result is not None:
                results.append(result)
        return results

    def draw(self, frame: np.ndarray, results):
        out = frame.copy()
        for r in results:
            x1, y1, x2, y2 = r["box"]
            label = f"{r['class_name']} ({r['confidence'] * 100:.0f}%)"
            cv2.rectangle(out, (x1, y1), (x2, y2), (0, 255, 0), 2)
            (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.6, 2)
            ly = y1 - th - 10 if y1 - th - 10 >= 0 else y1
            cv2.rectangle(out, (x1, ly), (x1 + tw + 8, ly + th + 8), (0, 255, 0), -1)
            cv2.putText(out, label, (x1 + 4, ly + th), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 0, 0), 2, cv2.LINE_AA)
        return out

    def to_payload(self, results):
        return [{
            "class_name": r["class_name"],
            "class_id": self.get_class_id(r["class_name"]),
            "confidence": round(r["confidence"] * 100, 1),
        } for r in results]
