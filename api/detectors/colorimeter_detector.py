"""
detectors/colorimeter_detector.py
------------------------------------
"Colorimetro": dibuja un cuadrado en el centro del frame (~60% del lado
mas chico de la imagen) y clasifica el color promedio de esa zona en uno
de 9 colores basicos, usando OpenCV (conversion a HSV + reglas de matiz).

No usa ningun modelo de IA -- es puro procesamiento de imagen (liviano,
sin dependencias extra). Sigue el MISMO contrato que el resto de
detectores de esta API (detect()/draw()/to_payload()), igual que
qr_detector.py/aruco_detector.py, para que api/app.py lo pueda invocar
sin casos especiales.
"""
import cv2
import numpy as np

CLASS_NAMES = ["Negro", "Blanco", "Gris", "Rojo", "Naranja", "Amarillo",
               "Verde", "Azul", "Morado"]

# Rangos de Matiz (H, escala OpenCV 0-179) para cada color cromatico.
# El rojo envuelve el 0 (rueda de color), por eso tiene dos rangos.
_HUE_RANGES = {
    "Rojo":     [(0, 10), (170, 179)],
    "Naranja":  [(11, 25)],
    "Amarillo": [(26, 35)],
    "Verde":    [(36, 85)],
    "Azul":     [(86, 130)],
    "Morado":   [(131, 169)],
}

_SQUARE_RATIO = 0.60  # ~60% del lado mas chico del frame
_DEFAULT_MIN_SATURATION = 40  # umbral por defecto para distinguir acromaticos de colores


class ColorimeterDetector:
    def __init__(self, min_saturation=_DEFAULT_MIN_SATURATION):
        self.class_names = list(CLASS_NAMES)
        # Umbral minimo de Saturacion (0-255) para considerar que un pixel
        # tiene color (en vez de ser Blanco/Gris/Negro). En ambientes muy
        # iluminados/deslavados conviene subirlo, en ambientes tenues
        # conviene bajarlo.
        self.min_saturation = min_saturation

    def get_class_id(self, name):
        try:
            return self.class_names.index(name)
        except ValueError:
            return 0

    # ------------------------------------------------------------------
    def _square_coords(self, frame):
        h, w = frame.shape[:2]
        side = max(4, int(min(h, w) * _SQUARE_RATIO))
        cx, cy = w // 2, h // 2
        x1, y1 = cx - side // 2, cy - side // 2
        x2, y2 = x1 + side, y1 + side
        return x1, y1, x2, y2

    def _classify(self, hsv_roi):
        h_ch = hsv_roi[:, :, 0].astype(np.float32)
        s_ch = hsv_roi[:, :, 1].astype(np.float32)
        v_ch = hsv_roi[:, :, 2].astype(np.float32)
        h_mean, s_mean, v_mean = float(np.mean(h_ch)), float(np.mean(s_ch)), float(np.mean(v_ch))
        h_std = float(np.std(h_ch))

        # Acromaticos primero: bajo brillo -> Negro; bajo saturacion -> Blanco/Gris.
        if v_mean < 50:
            return "Negro", min(1.0, 0.5 + (50 - v_mean) / 50)

        if s_mean < self.min_saturation:
            if v_mean > 180:
                return "Blanco", min(1.0, 0.6 + (v_mean - 180) / 75 * 0.4)
            return "Gris", min(1.0, 0.6 + (self.min_saturation - s_mean) / max(1, self.min_saturation) * 0.3)

        for color_name, ranges in _HUE_RANGES.items():
            for lo, hi in ranges:
                if lo <= h_mean <= hi:
                    center = (lo + hi) / 2
                    span = max(1.0, (hi - lo) / 2)
                    closeness = 1.0 - min(1.0, abs(h_mean - center) / span)
                    uniformity = max(0.0, 1.0 - h_std / 40.0)
                    confidence = 0.5 + 0.3 * closeness + 0.2 * uniformity
                    return color_name, max(0.0, min(1.0, confidence))

        # Ningun rango de matiz encajo (caso raro/borde): se reporta Gris
        # con confianza baja en vez de fallar.
        return "Gris", 0.4

    # ------------------------------------------------------------------
    def detect(self, frame: np.ndarray):
        """
        Devuelve un dict {box, class_name, confidence(0-1)} con el color
        detectado en el cuadrado central, o None si el frame es invalido.
        """
        x1, y1, x2, y2 = self._square_coords(frame)
        roi = frame[y1:y2, x1:x2]
        if roi.size == 0:
            return None

        hsv_roi = cv2.cvtColor(roi, cv2.COLOR_BGR2HSV)
        name, confidence = self._classify(hsv_roi)
        return {"box": (x1, y1, x2, y2), "class_name": name, "confidence": confidence}

    def draw(self, frame: np.ndarray, result):
        out = frame.copy()
        if result is None:
            return out
        x1, y1, x2, y2 = result["box"]
        label = f"{result['class_name']} ({result['confidence'] * 100:.0f}%)"
        cv2.rectangle(out, (x1, y1), (x2, y2), (0, 255, 0), 2)
        (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.7, 2)
        ly = y1 - th - 12 if y1 - th - 12 >= 0 else y1
        cv2.rectangle(out, (x1, ly), (x1 + tw + 10, ly + th + 10), (0, 255, 0), -1)
        cv2.putText(out, label, (x1 + 5, ly + th + 2), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 0, 0), 2, cv2.LINE_AA)
        return out

    def to_payload(self, result):
        if result is None:
            return []
        return [{
            "class_name": result["class_name"],
            "class_id": self.get_class_id(result["class_name"]),
            "confidence": round(result["confidence"] * 100, 1),
        }]
