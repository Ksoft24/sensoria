"""
movenet_detector.py
-----------------------------------------------------------------------
Port a Python/onnxruntime de modules/assets/movenet/movenet-detector.js.
Entrada NHWC int32 SIN normalizar (0-255), espejado horizontal (igual
que la camara frontal en el navegador), 256x256.
-----------------------------------------------------------------------
"""
import os

import cv2
import numpy as np
import onnxruntime as ort

MN_L_SHOULDER, MN_R_SHOULDER = 5, 6
MN_L_WRIST, MN_R_WRIST = 9, 10

POSE_CONNECTIONS = [
    (MN_L_SHOULDER, MN_R_SHOULDER),
    (MN_L_SHOULDER, 7), (7, MN_L_WRIST),
    (MN_R_SHOULDER, 8), (8, MN_R_WRIST),
    (MN_L_SHOULDER, 11), (MN_R_SHOULDER, 12),
    (11, 12),
]

CLASS_NAMES = ["Brazos Arriba", "Brazo Centro", "Brazo Izquierdo", "Brazo Derecho", "Postura Desconocida"]
CLASS_IDS = {name: i for i, name in enumerate(CLASS_NAMES)}
DEFAULT_CLASS_ID = 3

INPUT_SIZE = 256
MIN_CONFIDENCE = 0.3


def _classify_pose(pts):
    try:
        l_sh, r_sh = pts[MN_L_SHOULDER], pts[MN_R_SHOULDER]
        l_wr, r_wr = pts[MN_L_WRIST], pts[MN_R_WRIST]

        l_arriba = l_wr[1] < l_sh[1]
        r_arriba = r_wr[1] < r_sh[1]

        if l_arriba and not r_arriba:
            return "Brazo Izquierdo"
        if r_arriba and not l_arriba:
            return "Brazo Derecho"
        if l_arriba and r_arriba:
            return "Brazos Arriba"

        l_abajo = l_wr[1] > l_sh[1]
        r_abajo = r_wr[1] > r_sh[1]
        dist_x = abs(l_wr[0] - r_wr[0])
        if l_abajo and r_abajo and dist_x < 150:
            return "Brazo Centro"
    except Exception:  # noqa: BLE001
        return "Postura Desconocida"
    return "Postura Desconocida"


class MoveNetDetector:
    def __init__(self, model_path: str):
        if not os.path.isfile(model_path):
            raise FileNotFoundError(f"No se encontro el modelo: {model_path}")
        self.session = ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])
        self.input_name = self.session.get_inputs()[0].name
        self.output_name = self.session.get_outputs()[0].name
        self.class_names = CLASS_NAMES

    def get_class_id(self, name):
        return CLASS_IDS.get(name, DEFAULT_CLASS_ID)

    def detect(self, frame: np.ndarray):
        h, w = frame.shape[:2]
        mirrored = cv2.flip(frame, 1)
        resized = cv2.resize(mirrored, (INPUT_SIZE, INPUT_SIZE))
        rgb = cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.int32)
        tensor = rgb[np.newaxis, ...]  # NHWC int32, sin normalizar

        outputs = self.session.run([self.output_name], {self.input_name: tensor})[0]
        predictions = outputs.reshape(-1)

        stride = 56
        scores = [predictions[i * stride + 55] for i in range(6)]
        best_idx = int(np.argmax(scores))
        best_score = float(scores[best_idx])

        if best_score < MIN_CONFIDENCE:
            return None

        base = best_idx * stride
        pts = []
        for idx in range(17):
            y_norm = predictions[base + idx * 3]
            x_norm = 1 - predictions[base + idx * 3 + 1]  # espejado
            pts.append((x_norm * w, y_norm * h))

        pose_name = _classify_pose(pts)
        return {"pts": pts, "pose_name": pose_name, "score": best_score}

    def draw(self, frame: np.ndarray, result):
        out = frame.copy()
        if not result:
            return out
        pts = result["pts"]
        for a, b in POSE_CONNECTIONS:
            pa, pb = pts[a], pts[b]
            cv2.line(out, (int(pa[0]), int(pa[1])), (int(pb[0]), int(pb[1])), (0, 200, 255), 2)
        for x, y in pts:
            cv2.circle(out, (int(x), int(y)), 4, (0, 200, 255), -1)
        cv2.putText(out, f"{result['pose_name']} {result['score'] * 100:.0f}%", (16, 30),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 200, 255), 2, cv2.LINE_AA)
        return out

    def to_payload(self, result):
        if not result:
            return []
        return [{
            "class_name": result["pose_name"],
            "class_id": self.get_class_id(result["pose_name"]),
            "confidence": round(result["score"] * 100, 1),
        }]
