"""
pinch_detector.py
-----------------------------------------------------------------------
Port a Python/onnxruntime de modules/assets/pinch/pinch-detector.js.
Calcula un porcentaje continuo de apertura (0-100%) segun el angulo
pulgar-indice, para controlar un servo de forma proporcional.
-----------------------------------------------------------------------
"""
import math
import os

import cv2
import numpy as np
import onnxruntime as ort

from .handlandmarks_detector import HAND_CONNECTIONS  # mismo esqueleto de mano

CLASS_NAMES = ["Apertura de Pinza"]
INPUT_SIZE = 224
MIN_CONFIDENCE = 0.3
ANGLE_MIN = 25
ANGLE_MAX = 100


def _sigmoid(x):
    return 1 / (1 + math.exp(-x))


def _angle_deg(vertex, a, b):
    va = (a[0] - vertex[0], a[1] - vertex[1])
    vb = (b[0] - vertex[0], b[1] - vertex[1])
    dot = va[0] * vb[0] + va[1] * vb[1]
    mag = math.hypot(*va) * math.hypot(*vb)
    if mag < 1e-6:
        return 0
    cos = max(-1.0, min(1.0, dot / mag))
    return round(math.degrees(math.acos(cos)))


class PinchDetector:
    def __init__(self, model_path: str):
        if not os.path.isfile(model_path):
            raise FileNotFoundError(f"No se encontro el modelo: {model_path}")
        self.session = ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])
        self.input_name = self.session.get_inputs()[0].name
        self.output_names = [o.name for o in self.session.get_outputs()]
        self.coord_mode = None
        self.class_names = CLASS_NAMES

    def detect(self, frame: np.ndarray):
        h, w = frame.shape[:2]
        side = min(w, h)
        x0, y0 = (w - side) // 2, (h - side) // 2
        crop = frame[y0:y0 + side, x0:x0 + side]
        mirrored = cv2.flip(crop, 1)
        resized = cv2.resize(mirrored, (INPUT_SIZE, INPUT_SIZE))
        rgb = cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0
        tensor = np.transpose(rgb, (2, 0, 1))[np.newaxis, ...]

        outputs = self.session.run(self.output_names, {self.input_name: tensor})
        xyz_flat = outputs[0].reshape(-1)
        raw_score = float(outputs[1].reshape(-1)[0])
        hand_score = _sigmoid(raw_score) if (raw_score < 0 or raw_score > 1) else raw_score

        if hand_score < MIN_CONFIDENCE:
            return None

        if self.coord_mode is None:
            self.coord_mode = "px" if float(np.max(xyz_flat)) > 1.5 else "norm"

        pts = []
        for i in range(21):
            lx, ly = xyz_flat[i * 3], xyz_flat[i * 3 + 1]
            if self.coord_mode == "px":
                scale = side / INPUT_SIZE
                px = (side - lx * scale) + x0
                py = ly * scale + y0
            else:
                px = (1 - lx) * side + x0
                py = ly * side + y0
            pts.append((max(0, min(w - 1, px)), max(0, min(h - 1, py))))

        angle = _angle_deg(pts[5], pts[4], pts[8])
        angle = max(ANGLE_MIN, min(ANGLE_MAX, angle))
        pct = round(((angle - ANGLE_MIN) / (ANGLE_MAX - ANGLE_MIN)) * 1000) / 10

        return {"pts": pts, "angle": angle, "pct": pct, "score": hand_score}

    def draw(self, frame: np.ndarray, result):
        out = frame.copy()
        if not result:
            return out
        pts = result["pts"]
        for a, b in HAND_CONNECTIONS:
            pa, pb = pts[a], pts[b]
            cv2.line(out, (int(pa[0]), int(pa[1])), (int(pb[0]), int(pb[1])), (0, 255, 150), 2)
        for x, y in pts:
            cv2.circle(out, (int(x), int(y)), 4, (0, 255, 150), -1)
        cv2.putText(out, f"Pinza {result['pct']:.1f}%", (16, 30),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 150), 2, cv2.LINE_AA)
        return out

    def to_payload(self, result):
        """
        A diferencia de los otros modulos (clase discreta), este devuelve
        SIEMPRE la clase "Apertura de Pinza" con confidence = pct, para
        que el motor de reglas del lado PHP/JS use evaluateContinuous()
        (scale_out_min/scale_out_max) igual que en el modulo original.
        """
        if not result:
            return []
        return [{
            "class_name": "Apertura de Pinza",
            "class_id": 0,
            "confidence": result["pct"],  # 0-100, valor continuo
        }]
