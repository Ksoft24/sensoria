"""
aruco_detector.py
-----------------------------------------------------------------------
Equivalente Python de modules/aruco.php (que usa js-aruco2 en el
navegador, diccionario ARUCO_MIP_36h12). class_name = "ID <n>" del
marcador detectado, igual criterio que el modulo JS (el ID del
marcador es la "clase" para las reglas).

Requiere OpenCV con el modulo contrib "aruco" (opencv-contrib-python).
Si la version instalada no trae el diccionario ARUCO_MIP_36h12 (mismo
que usa el modulo web), se cae automaticamente a DICT_4X4_50 - en ese
caso hay que usar marcadores generados con ese diccionario.
-----------------------------------------------------------------------
"""
import cv2
import numpy as np


def _resolve_dictionary():
    try:
        return cv2.aruco.getPredefinedDictionary(cv2.aruco.DICT_ARUCO_MIP_36h12), "ARUCO_MIP_36h12"
    except Exception:  # noqa: BLE001
        return cv2.aruco.getPredefinedDictionary(cv2.aruco.DICT_4X4_50), "DICT_4X4_50 (fallback)"


class ArucoDetector:
    def __init__(self):
        self.dictionary, self.dict_name = _resolve_dictionary()
        try:
            self.params = cv2.aruco.DetectorParameters()
            self._detector = cv2.aruco.ArucoDetector(self.dictionary, self.params)
            self._new_api = True
        except AttributeError:
            # OpenCV < 4.7 no tiene la clase ArucoDetector
            self.params = cv2.aruco.DetectorParameters_create()
            self._detector = None
            self._new_api = False
        self.class_names = []  # dinamico: "ID <n>"

    def detect(self, frame: np.ndarray):
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        if self._new_api:
            corners, ids, _ = self._detector.detectMarkers(gray)
        else:
            corners, ids, _ = cv2.aruco.detectMarkers(gray, self.dictionary, parameters=self.params)

        results = []
        if ids is not None:
            for c, i in zip(corners, ids.flatten()):
                results.append({"id": int(i), "corners": c[0]})
        return results

    def draw(self, frame: np.ndarray, results):
        out = frame.copy()
        for r in results:
            pts = r["corners"].astype(int)
            cv2.polylines(out, [pts], True, (255, 120, 0), 3)
            cx, cy = pts.mean(axis=0).astype(int)
            cv2.putText(out, f"ID {r['id']}", (int(pts[0][0]), max(0, int(pts[0][1]) - 8)),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 120, 0), 2, cv2.LINE_AA)
            cv2.circle(out, (cx, cy), 3, (255, 120, 0), -1)
        return out

    def to_payload(self, results):
        return [{"class_name": f"ID {r['id']}", "class_id": r["id"], "confidence": 100.0} for r in results]
