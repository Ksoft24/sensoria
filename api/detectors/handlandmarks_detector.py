"""
handlandmarks_detector.py
-----------------------------------------------------------------------
Port a Python/onnxruntime de
modules/assets/handlandmarks/handlandmarks-detector.js. Clasificacion
100% geometrica (sin red de clasificacion) sobre los 21 landmarks de la
mano, identica a la version JS/Python original.
-----------------------------------------------------------------------
"""
import math
import os

import cv2
import numpy as np
import onnxruntime as ort

CLASS_NAMES = [
    "Punio Cerrado", "Mano Abierta", "Pulgar Arriba",
    "Senial de Victoria", "Indice Arriba", "Gesto Desconocido",
]
CLASS_IDS = {name: i for i, name in enumerate(CLASS_NAMES)}

HAND_CONNECTIONS = [
    (0, 1), (1, 2), (2, 3), (3, 4),
    (0, 5), (5, 6), (6, 7), (7, 8),
    (0, 9), (9, 10), (10, 11), (11, 12),
    (0, 13), (13, 14), (14, 15), (15, 16),
    (0, 17), (17, 18), (18, 19), (19, 20),
    (5, 9), (9, 13), (13, 17),
]

INPUT_SIZE = 224
MIN_CONFIDENCE = 0.5


def _sigmoid(x):
    return 1 / (1 + math.exp(-x))


def _dist(p1, p2):
    return math.hypot(p1[0] - p2[0], p1[1] - p2[1])


def _es_mano_abierta(pts):
    for tip, pip in [(8, 6), (12, 10), (16, 14), (20, 18)]:
        if pts[tip][1] >= pts[pip][1]:
            return False
    return _dist(pts[4], pts[5]) >= _dist(pts[3], pts[5])


def _es_pulgar_arriba(pts):
    for tip, pip in [(8, 6), (12, 10), (16, 14), (20, 18)]:
        if pts[tip][1] < pts[pip][1]:
            return False
    if pts[4][1] >= pts[3][1] or pts[4][1] >= pts[2][1]:
        return False
    return pts[4][1] < pts[5][1]


def _es_indice_arriba(pts):
    if pts[8][1] >= pts[6][1]:
        return False
    for tip, pip in [(12, 10), (16, 14), (20, 18)]:
        if pts[tip][1] < pts[pip][1]:
            return False
    return _dist(pts[4], pts[5]) <= _dist(pts[2], pts[5]) * 1.2


def _es_senal_de_victoria(pts):
    if pts[8][1] >= pts[6][1] or pts[12][1] >= pts[10][1]:
        return False
    if pts[16][1] < pts[14][1] or pts[20][1] < pts[18][1]:
        return False
    return _dist(pts[4], pts[5]) <= _dist(pts[2], pts[5]) * 1.2


def _es_punio_cerrado(pts):
    for tip, pip in [(8, 6), (12, 10), (16, 14), (20, 18)]:
        if pts[tip][1] < pts[pip][1]:
            return False
    if pts[4][1] < pts[3][1] and _dist(pts[4], pts[5]) > _dist(pts[3], pts[5]):
        return False
    return True


def classify_gesture(pts):
    if not pts or len(pts) < 21:
        return "Gesto Desconocido"
    if _es_mano_abierta(pts):
        return "Mano Abierta"
    if _es_pulgar_arriba(pts):
        return "Pulgar Arriba"
    if _es_indice_arriba(pts):
        return "Indice Arriba"
    if _es_senal_de_victoria(pts):
        return "Senial de Victoria"
    if _es_punio_cerrado(pts):
        return "Punio Cerrado"
    return "Gesto Desconocido"


class HandLandmarksDetector:
    def __init__(self, model_path: str):
        if not os.path.isfile(model_path):
            raise FileNotFoundError(f"No se encontro el modelo: {model_path}")
        self.session = ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])
        self.input_name = self.session.get_inputs()[0].name
        self.output_names = [o.name for o in self.session.get_outputs()]
        self.coord_mode = None
        self.class_names = CLASS_NAMES

    def get_class_id(self, name):
        return CLASS_IDS.get(name, CLASS_IDS["Gesto Desconocido"])

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

        gesture = classify_gesture(pts)
        return {"pts": pts, "gesture": gesture, "score": hand_score}

    def draw(self, frame: np.ndarray, result):
        out = frame.copy()
        if not result:
            return out
        pts = result["pts"]
        for a, b in HAND_CONNECTIONS:
            pa, pb = pts[a], pts[b]
            cv2.line(out, (int(pa[0]), int(pa[1])), (int(pb[0]), int(pb[1])), (255, 180, 0), 2)
        for x, y in pts:
            cv2.circle(out, (int(x), int(y)), 4, (255, 180, 0), -1)
        cv2.putText(out, f"{result['gesture']} {result['score'] * 100:.0f}%", (16, 30),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 180, 0), 2, cv2.LINE_AA)
        return out

    def to_payload(self, result):
        if not result:
            return []
        return [{
            "class_name": result["gesture"],
            "class_id": self.get_class_id(result["gesture"]),
            "confidence": round(result["score"] * 100, 1),
        }]
