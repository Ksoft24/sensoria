"""
yolo_seg_detector.py
-----------------------------------------------------------------------
Port a Python/onnxruntime de modules/assets/yolo_seg/yolo-seg-worker.js:
  - Resize directo a 320x320 (sin letterbox), NCHW, normalizado 0-1.
  - Solo evalua la clase "persona" (id 0) igual que el worker JS.
  - Mascara = sigmoid(coeffs . proto) por pixel del mapa de prototipos.
  - 3 zonas fijas (ROJO/AZUL/AMARILLO) definidas por circulos en el
    frame; se considera "tocada" si mas de N pixeles de la mascara caen
    dentro del circulo.
-----------------------------------------------------------------------
"""
import os

import cv2
import numpy as np
import onnxruntime as ort

from .base import sigmoid

INPUT_SIZE = 320
CONF_THRES = 0.40
IOU_THRES = 0.45
ZONE_RADIUS = 40
ZONE_MARGIN = 20
ZONE_TOUCH_PIXELS = 3
ZONE_NAMES = ["ROJO", "AZUL", "AMARILLO"]
PERSON_CLASS_ID = 0

ZONE_COLORS = {"ROJO": (0, 0, 255), "AZUL": (255, 120, 0), "AMARILLO": (0, 220, 255)}


def _zone_centers(w, h):
    return {
        "ROJO": (ZONE_RADIUS + ZONE_MARGIN, h / 2),
        "AZUL": (w - ZONE_RADIUS - ZONE_MARGIN, h / 2),
        "AMARILLO": (w / 2, ZONE_RADIUS + ZONE_MARGIN),
    }


def _iou(a, b):
    x1, y1 = max(a["x1"], b["x1"]), max(a["y1"], b["y1"])
    x2, y2 = min(a["x2"], b["x2"]), min(a["y2"], b["y2"])
    inter = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    area_a = (a["x2"] - a["x1"]) * (a["y2"] - a["y1"])
    area_b = (b["x2"] - b["x1"]) * (b["y2"] - b["y1"])
    return inter / (area_a + area_b - inter + 1e-9)


def _nms(dets, iou_thres):
    dets = sorted(dets, key=lambda d: d["score"], reverse=True)
    kept = []
    for det in dets:
        if all(_iou(det, k) <= iou_thres for k in kept):
            kept.append(det)
    return kept


class YoloSegDetector:
    def __init__(self, model_path: str):
        if not os.path.isfile(model_path):
            raise FileNotFoundError(f"No se encontro el modelo: {model_path}")
        self.session = ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])
        self.input_name = self.session.get_inputs()[0].name
        self.output_names = [o.name for o in self.session.get_outputs()]
        self.class_names = ZONE_NAMES

    def detect(self, frame: np.ndarray):
        h, w = frame.shape[:2]
        resized = cv2.resize(frame, (INPUT_SIZE, INPUT_SIZE))
        rgb = cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0
        tensor = np.transpose(rgb, (2, 0, 1))[np.newaxis, ...]

        outputs = self.session.run(self.output_names, {self.input_name: tensor})
        pred, proto = outputs[0], outputs[1]

        row_len = pred.shape[-1]
        num_det = pred.shape[-2]
        mask_coeff_count = row_len - 85
        pred = pred[0]

        proto_c, proto_h, proto_w = proto.shape[-3], proto.shape[-2], proto.shape[-1]
        proto = proto[0]

        scale_x, scale_y = w / INPUT_SIZE, h / INPUT_SIZE
        proto_scale_x, proto_scale_y = w / proto_w, h / proto_h

        candidates = []
        for i in range(num_det):
            row = pred[i]
            obj_conf = row[4]
            if obj_conf < CONF_THRES:
                continue
            person_score = row[5 + PERSON_CLASS_ID]
            conf = person_score * obj_conf
            if conf <= CONF_THRES:
                continue

            x, y, bw, bh = row[0], row[1], row[2], row[3]
            x1 = (x - bw / 2) * scale_x
            y1 = (y - bh / 2) * scale_y
            x2 = (x + bw / 2) * scale_x
            y2 = (y + bh / 2) * scale_y
            coeffs = row[85:85 + mask_coeff_count]
            candidates.append({"x1": x1, "y1": y1, "x2": x2, "y2": y2, "score": float(conf), "coeffs": coeffs})

        kept = _nms(candidates, IOU_THRES)
        centers = _zone_centers(w, h)
        touched = {}
        detections = []

        proto_flat = proto.reshape(proto_c, proto_h * proto_w)
        for det in kept:
            mask_small = sigmoid(det["coeffs"] @ proto_flat).reshape(proto_h, proto_w)
            box = {
                "x1": max(0, round(det["x1"])), "y1": max(0, round(det["y1"])),
                "x2": min(w, round(det["x2"])), "y2": min(h, round(det["y2"])),
            }
            detections.append({"score": det["score"], "box": box, "mask_small": mask_small})

            for zname, (cx, cy) in centers.items():
                pcx, pcy = cx / proto_scale_x, cy / proto_scale_y
                pr = ZONE_RADIUS / ((proto_scale_x + proto_scale_y) / 2)
                px0, px1 = max(0, int(np.floor(pcx - pr))), min(proto_w - 1, int(np.ceil(pcx + pr)))
                py0, py1 = max(0, int(np.floor(pcy - pr))), min(proto_h - 1, int(np.ceil(pcy + pr)))
                count = 0
                for py in range(py0, py1 + 1):
                    for px in range(px0, px1 + 1):
                        dx, dy = px - pcx, py - pcy
                        if dx * dx + dy * dy <= pr * pr and mask_small[py, px] > 0.5:
                            count += 1
                    if count > ZONE_TOUCH_PIXELS:
                        break
                if count > ZONE_TOUCH_PIXELS:
                    touched[zname] = max(touched.get(zname, 0.0), det["score"])

        return detections, touched, centers, (proto_w, proto_h)

    def draw(self, frame: np.ndarray, detections, touched, centers):
        out = frame.copy()
        h, w = out.shape[:2]
        for det in detections:
            box = det["box"]
            mask_full = cv2.resize(det["mask_small"], (w, h), interpolation=cv2.INTER_LINEAR)
            overlay = out.copy()
            overlay[mask_full > 0.5] = (0, 255, 0)
            out = cv2.addWeighted(overlay, 0.35, out, 0.65, 0)
            cv2.rectangle(out, (box["x1"], box["y1"]), (box["x2"], box["y2"]), (0, 255, 0), 2)
            cv2.putText(out, f"persona {det['score'] * 100:.0f}%", (box["x1"], max(0, box["y1"] - 8)),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 255, 0), 1, cv2.LINE_AA)

        for zname, (cx, cy) in centers.items():
            color = ZONE_COLORS.get(zname, (255, 255, 255))
            active = zname in touched
            cv2.circle(out, (int(cx), int(cy)), ZONE_RADIUS, color, 3 if active else 1)
            cv2.putText(out, zname, (int(cx) - 24, int(cy) - ZONE_RADIUS - 8),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.5, color, 2, cv2.LINE_AA)
        return out

    def to_payload(self, touched):
        return [
            {"class_name": zname, "class_id": ZONE_NAMES.index(zname), "confidence": round(score * 100, 1)}
            for zname, score in touched.items()
        ]
