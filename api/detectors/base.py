"""
base.py
-----------------------------------------------------------------------
Utilidades compartidas por todos los detectores de la API Flask:
  - Decodificar/codificar imagenes en base64 (con o sin prefijo Data URL).
  - Helpers de dibujo con OpenCV (cajas, etiquetas, esqueletos) que
    reproducen el mismo estilo visual que ya usan los detectores JS del
    lado del navegador (modules/assets/*/*.js), para que la imagen que
    regresa la API se vea igual sea cual sea el lado que dibuje.

Ninguna funcion de este archivo depende de un modelo ONNX especifico -
son utilidades genericas de imagen.
-----------------------------------------------------------------------
"""
import base64
import re

import cv2
import numpy as np

DATA_URL_RE = re.compile(r"^data:image/\w+;base64,")


def decode_base64_image(b64_str: str) -> np.ndarray:
    """
    Decodifica un string base64 (con o sin prefijo "data:image/...;base64,")
    a un array BGR de OpenCV (igual que cv2.imread).
    """
    if not b64_str:
        raise ValueError("Imagen base64 vacia")
    clean = DATA_URL_RE.sub("", b64_str.strip())
    try:
        raw = base64.b64decode(clean, validate=False)
    except Exception as exc:  # noqa: BLE001
        raise ValueError(f"No se pudo decodificar el base64: {exc}") from exc

    arr = np.frombuffer(raw, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("El base64 no representa una imagen valida (jpg/png)")
    return img


def encode_image_base64(img_bgr: np.ndarray, quality: int = 85) -> str:
    """
    Codifica un array BGR de OpenCV a JPEG y lo devuelve como base64 SIN
    prefijo "data:image/..." (el cliente PHP/JS le agrega el prefijo que
    necesite, ej. para pintarlo directo en un <img src="data:...">).
    """
    ok, buf = cv2.imencode(".jpg", img_bgr, [cv2.IMWRITE_JPEG_QUALITY, quality])
    if not ok:
        raise ValueError("No se pudo codificar la imagen de salida")
    return base64.b64encode(buf.tobytes()).decode("ascii")


def sigmoid(x):
    return 1.0 / (1.0 + np.exp(-np.clip(x, -500, 500)))


def color_for_class(class_id: int):
    """Mismo criterio que getColorForClass() en utils.js (hue determinístico)."""
    hue = (class_id * 47) % 360
    # HSV (OpenCV: H en 0-179) -> BGR
    hsv = np.uint8([[[int(hue / 2), 200, 220]]])
    bgr = cv2.cvtColor(hsv, cv2.COLOR_HSV2BGR)[0][0]
    return int(bgr[0]), int(bgr[1]), int(bgr[2])


def draw_label(img, x, y, text, color, text_color=(0, 0, 0)):
    """Caja de fondo + texto, mismo estilo que drawDetections() en utils.js."""
    font = cv2.FONT_HERSHEY_SIMPLEX
    (tw, th), _ = cv2.getTextSize(text, font, 0.55, 1)
    label_y = y - th - 10 if y - th - 10 >= 0 else y
    cv2.rectangle(img, (x, label_y), (x + tw + 10, label_y + th + 10), color, -1)
    cv2.putText(img, text, (x + 5, label_y + th + 2), font, 0.55, text_color, 1, cv2.LINE_AA)


def draw_box(img, left, top, width, height, class_id, label, score):
    color = color_for_class(class_id)
    x1, y1 = int(left), int(top)
    x2, y2 = int(left + width), int(top + height)
    cv2.rectangle(img, (x1, y1), (x2, y2), color, 2)
    draw_label(img, x1, y1, f"{label} {score * 100:.1f}%", color)
    return img


def nms(boxes, iou_thres):
    """
    Non-Maximum Suppression por clase, mismo criterio que
    nonMaxSuppression() en modules/assets/yolo/utils.js.
    boxes: lista de dicts {left, top, width, height, score, class_id}
    """
    by_class = {}
    for b in boxes:
        by_class.setdefault(b["class_id"], []).append(b)

    kept_all = []
    for _, items in by_class.items():
        items = sorted(items, key=lambda b: b["score"], reverse=True)
        active = [True] * len(items)
        for i, bi in enumerate(items):
            if not active[i]:
                continue
            kept_all.append(bi)
            for j in range(i + 1, len(items)):
                if not active[j]:
                    continue
                if _iou(bi, items[j]) > iou_thres:
                    active[j] = False
    return kept_all


def _iou(a, b):
    ax1, ay1, ax2, ay2 = a["left"], a["top"], a["left"] + a["width"], a["top"] + a["height"]
    bx1, by1, bx2, by2 = b["left"], b["top"], b["left"] + b["width"], b["top"] + b["height"]
    ix1, iy1 = max(ax1, bx1), max(ay1, by1)
    ix2, iy2 = min(ax2, bx2), min(ay2, by2)
    iw, ih = max(0.0, ix2 - ix1), max(0.0, iy2 - iy1)
    inter = iw * ih
    area_a = a["width"] * a["height"]
    area_b = b["width"] * b["height"]
    union = area_a + area_b - inter
    return inter / union if union > 0 else 0.0
