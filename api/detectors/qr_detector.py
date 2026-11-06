"""
qr_detector.py
-----------------------------------------------------------------------
Equivalente Python de modules/qr.php (que usa jsQR en el navegador):
no requiere ONNX, decodifica codigos QR con el detector nativo de
OpenCV. class_name = texto decodificado del QR (igual que el modulo
JS, que usa el texto crudo como "clase" para las reglas).
-----------------------------------------------------------------------
"""
import cv2
import numpy as np


class QrDetector:
    def __init__(self):
        self.detector = cv2.QRCodeDetector()
        self.class_names = []  # dinamico: el texto del QR ES la clase

    def detect(self, frame: np.ndarray):
        """
        Devuelve una lista de {text, points} - OpenCV solo decodifica un QR
        a la vez de forma confiable con detectAndDecode(); se intenta
        tambien detectAndDecodeMulti() para leer varios códigos en el
        mismo frame si la version de OpenCV lo soporta.
        """
        results = []
        try:
            ok, decoded_info, points, _ = self.detector.detectAndDecodeMulti(frame)
            if ok and points is not None:
                for text, pts in zip(decoded_info, points):
                    if text:
                        results.append({"text": text, "points": pts})
        except Exception:  # noqa: BLE001 - fallback si la version de cv2 no trae multi
            text, points, _ = self.detector.detectAndDecode(frame)
            if text and points is not None:
                results.append({"text": text, "points": points[0]})
        return results

    def draw(self, frame: np.ndarray, results):
        out = frame.copy()
        for r in results:
            pts = r["points"].astype(int)
            cv2.polylines(out, [pts], True, (0, 255, 0), 3)
            x, y = pts[0]
            cv2.rectangle(out, (x, max(0, y - 26)), (x + max(120, 10 * len(r["text"])), y), (0, 255, 0), -1)
            cv2.putText(out, r["text"][:40], (x + 4, y - 6), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 0, 0), 1, cv2.LINE_AA)
        return out

    def to_payload(self, results):
        return [{"class_name": r["text"], "class_id": 0, "confidence": 100.0} for r in results]
