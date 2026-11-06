"""
yolo_detector.py
-----------------------------------------------------------------------
Port a Python/onnxruntime de modules/assets/yolo/yolo-detector.js:
  - Padding cuadrado SIN centrar (frame pegado en la esquina superior
    izquierda) + resize a 320x320 + normalizacion 1/255 + NCHW.
  - Salida sin objectness (4 + N clases): se toma directo el score mas
    alto entre las clases.
  - NMS por clase (ver detectors/base.py).
-----------------------------------------------------------------------
"""
import json
import os

import cv2
import numpy as np
import onnxruntime as ort

from .base import draw_box, nms

INPUT_SIZE = 320
SCORE_THRESHOLD = 0.35
IOU_THRESHOLD = 0.40


class YoloDetector:
    def __init__(self, model_path: str, classes_path: str):
        if not os.path.isfile(model_path):
            raise FileNotFoundError(f"No se encontro el modelo: {model_path}")
        self.session = ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])
        self.input_name = self.session.get_inputs()[0].name
        self.output_name = self.session.get_outputs()[0].name

        with open(classes_path, encoding="utf-8") as fh:
            self.class_names = json.load(fh)

    def _preprocess(self, frame: np.ndarray):
        h, w = frame.shape[:2]
        length = max(h, w)
        square = np.zeros((length, length, 3), dtype=np.uint8)
        square[0:h, 0:w] = frame

        resized = cv2.resize(square, (INPUT_SIZE, INPUT_SIZE))
        # BGR -> RGB, HWC -> CHW, normalizado 0-1
        rgb = cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0
        chw = np.transpose(rgb, (2, 0, 1))[np.newaxis, ...]

        scale = length / INPUT_SIZE
        return chw, scale, w, h

    def detect(self, frame: np.ndarray):
        tensor, scale, orig_w, orig_h = self._preprocess(frame)
        raw = self.session.run([self.output_name], {self.input_name: tensor})[0]

        dims = raw.shape  # (1, A, B)
        a, b = dims[1], dims[2]
        if a == 84 and b != 84:
            data = raw[0]  # (84, N) -> transpuesto
            num_features, num_detections = a, b
            transposed = True
        elif b == 84 and a != 84:
            data = raw[0]  # (N, 84)
            num_features, num_detections = b, a
            transposed = False
        else:
            transposed = b > a
            data = raw[0]
            num_features, num_detections = (a, b) if transposed else (b, a)

        if transposed:
            get = lambda det_idx, feat_idx: data[feat_idx, det_idx]  # noqa: E731
        else:
            get = lambda det_idx, feat_idx: data[det_idx, feat_idx]  # noqa: E731

        num_classes = num_features - 4
        candidates = []
        for i in range(num_detections):
            scores = np.array([get(i, 4 + c) for c in range(num_classes)])
            best_class = int(np.argmax(scores))
            best_score = float(scores[best_class])
            if best_score < SCORE_THRESHOLD:
                continue

            cx, cy, bw, bh = get(i, 0), get(i, 1), get(i, 2), get(i, 3)
            left320 = cx - bw / 2
            top320 = cy - bh / 2

            left = left320 * scale
            top = top320 * scale
            width = bw * scale
            height = bh * scale

            right = min(left + width, orig_w)
            bottom = min(top + height, orig_h)
            left = max(left, 0)
            top = max(top, 0)
            width = max(0, right - left)
            height = max(0, bottom - top)
            if width <= 0 or height <= 0:
                continue

            candidates.append({
                "left": left, "top": top, "width": width, "height": height,
                "score": best_score, "class_id": best_class,
            })

        kept = nms(candidates, IOU_THRESHOLD)
        return kept

    def draw(self, frame: np.ndarray, detections):
        out = frame.copy()
        for det in detections:
            label = self.class_names[det["class_id"]] if det["class_id"] < len(self.class_names) else f"class_{det['class_id']}"
            draw_box(out, det["left"], det["top"], det["width"], det["height"], det["class_id"], label, det["score"])
        return out

    def to_payload(self, detections):
        results = []
        for det in detections:
            label = self.class_names[det["class_id"]] if det["class_id"] < len(self.class_names) else f"class_{det['class_id']}"
            results.append({
                "class_name": label,
                "class_id": det["class_id"],
                "confidence": round(det["score"] * 100, 1),
            })
        return results
