import os
import time

from flask import Flask, jsonify, request
from flask_cors import CORS

from detectors.base import decode_base64_image, encode_image_base64

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(BASE_DIR, "models")
API_KEY = os.environ.get("SENSORIA_API_KEY", "").strip()

app = Flask(__name__)
CORS(app)  # el modulo ApiCam.php llama desde el dominio del panel PHP (otro origen)

# Cache de detectores: se cargan de forma perezosa (al primer uso) para
# no pagar el costo de cargar los 5 modelos ONNX si solo se usa uno.
_detectors_cache = {}


def _get_detector(kind: str):
    if kind in _detectors_cache:
        return _detectors_cache[kind]

    if kind == "yolo":
        from detectors.yolo_detector import YoloDetector
        det = YoloDetector(
            os.path.join(MODELS_DIR, "yolo", "model.onnx"),
            os.path.join(MODELS_DIR, "yolo", "classes.json"),
        )
    elif kind == "yolo_seg":
        from detectors.yolo_seg_detector import YoloSegDetector
        det = YoloSegDetector(os.path.join(MODELS_DIR, "yolo_seg", "model.onnx"))
    elif kind == "movenet":
        from detectors.movenet_detector import MoveNetDetector
        det = MoveNetDetector(os.path.join(MODELS_DIR, "movenet", "model.onnx"))
    elif kind == "handlandmarks":
        from detectors.handlandmarks_detector import HandLandmarksDetector
        det = HandLandmarksDetector(os.path.join(MODELS_DIR, "handlandmarks", "model.onnx"))
    elif kind == "pinch":
        from detectors.pinch_detector import PinchDetector
        det = PinchDetector(os.path.join(MODELS_DIR, "pinch", "model.onnx"))
    elif kind == "qr":
        from detectors.qr_detector import QrDetector
        det = QrDetector()
    elif kind == "aruco":
        from detectors.aruco_detector import ArucoDetector
        det = ArucoDetector()
    elif kind == "colorimeter":
        from detectors.colorimeter_detector import ColorimeterDetector
        det = ColorimeterDetector()
    elif kind == "geometric_scan":
        from detectors.geometric_scan_detector import GeometricScanDetector
        det = GeometricScanDetector()
    else:
        raise ValueError(f"Tipo de analisis no soportado: {kind}")

    _detectors_cache[kind] = det
    return det


def _check_api_key():
    if not API_KEY:
        return True
    sent = request.headers.get("X-API-Key", "")
    return sent == API_KEY


@app.get("/health")
def health():
    return jsonify({
        "ok": True,
        "service": "sensoria-api",
        "loaded_models": list(_detectors_cache.keys()),
        "supported_types": ["yolo", "yolo_seg", "movenet", "handlandmarks", "pinch", "qr", "aruco", "colorimeter", "geometric_scan"],
        "mqtt_proxy_endpoints": ["/mqtt/state", "/mqtt/publish"],
        "auth_required": bool(API_KEY),
    })


def _mqtt_cfg_from_payload(payload):
    host = str(payload.get("host", "")).strip()
    if not host:
        raise ValueError("Falta 'host' del broker MQTT")
    return {
        "host": host,
        "port": payload.get("port"),
        "username": payload.get("username") or None,
        "password": payload.get("password") or None,
        "use_tls": bool(payload.get("use_tls", True)),
    }


@app.post("/mqtt/state")
def mqtt_state():
    """
    Usado por el modulo ApiCam cuando "MQTT via API" esta activo: en vez
    de conectar el broker desde el navegador (falla en Android viejo),
    le pide a esta API que se conecte y consulte el estado actual de
    Arduino (Entradas Digitales/Analogicas) - ver mqtt_proxy.query_state.
    """
    if not _check_api_key():
        return jsonify({"ok": False, "error": "API key invalida o faltante"}), 401

    payload = request.get_json(silent=True) or {}
    input_topic = str(payload.get("input_topic", "")).strip()
    if not input_topic:
        return jsonify({"ok": False, "error": "Falta 'input_topic'"}), 400

    try:
        cfg = _mqtt_cfg_from_payload(payload)
    except ValueError as exc:
        return jsonify({"ok": False, "error": str(exc)}), 400

    from mqtt_proxy import query_state
    timeout_s = float(payload.get("timeout_s", 4) or 4)
    try:
        result = query_state(cfg, input_topic, payload.get("query_topic") or None, timeout_s)
    except Exception as exc:  # noqa: BLE001
        return jsonify({"ok": False, "error": f"Error de conexion MQTT: {exc}"}), 502

    return jsonify({"ok": True, **result})


@app.post("/mqtt/publish")
def mqtt_publish_route():
    """
    Usado por el modulo ApiCam ("MQTT via API") cuando una Regla se
    cumple: en vez de publicar desde el navegador, le pide a esta API
    que publique el comando en el broker - Arduino lo recibe igual que
    si hubiera venido directo del navegador.
    """
    if not _check_api_key():
        return jsonify({"ok": False, "error": "API key invalida o faltante"}), 401

    payload = request.get_json(silent=True) or {}
    topic = str(payload.get("topic", "")).strip()
    if not topic:
        return jsonify({"ok": False, "error": "Falta 'topic'"}), 400

    try:
        cfg = _mqtt_cfg_from_payload(payload)
    except ValueError as exc:
        return jsonify({"ok": False, "error": str(exc)}), 400

    from mqtt_proxy import publish as mqtt_publish
    timeout_s = float(payload.get("timeout_s", 4) or 4)
    try:
        result = mqtt_publish(cfg, topic, payload.get("payload") or {}, timeout_s)
    except Exception as exc:  # noqa: BLE001
        return jsonify({"ok": False, "error": f"Error de conexion MQTT: {exc}"}), 502

    if not result["ok"]:
        return jsonify({"ok": False, "error": result.get("error") or "No se pudo publicar"}), 502
    return jsonify({"ok": True})


@app.post("/infer")
def infer():
    if not _check_api_key():
        return jsonify({"ok": False, "error": "API key invalida o faltante"}), 401

    payload = request.get_json(silent=True) or {}
    kind = str(payload.get("type", "")).strip()
    image_b64 = payload.get("image", "")

    if kind not in {"yolo", "yolo_seg", "movenet", "handlandmarks", "pinch", "qr", "aruco", "colorimeter", "geometric_scan"}:
        return jsonify({"ok": False, "error": f"type invalido o faltante: '{kind}'"}), 400
    if not image_b64:
        return jsonify({"ok": False, "error": "Falta el campo 'image' (base64)"}), 400

    try:
        frame = decode_base64_image(image_b64)
    except ValueError as exc:
        return jsonify({"ok": False, "error": str(exc)}), 400

    t0 = time.time()
    try:
        detections_payload, annotated = _run_inference(kind, frame)
    except FileNotFoundError as exc:
        # Modelo .onnx no colocado todavia en API/models/<tipo>/model.onnx
        return jsonify({"ok": False, "error": str(exc)}), 503
    except Exception as exc:  # noqa: BLE001
        return jsonify({"ok": False, "error": f"Error de inferencia: {exc}"}), 500
    inference_ms = (time.time() - t0) * 1000

    best = None
    if detections_payload:
        best = max(detections_payload, key=lambda d: d["confidence"])

    return jsonify({
        "ok": True,
        "type": kind,
        "image": encode_image_base64(annotated),
        "detections": detections_payload,
        "best": best,
        "inference_ms": round(inference_ms, 1),
    })


def _run_inference(kind: str, frame):
    """Corre el detector correspondiente y devuelve (payload_para_reglas, imagen_anotada)."""
    det = _get_detector(kind)

    if kind == "yolo":
        dets = det.detect(frame)
        annotated = det.draw(frame, dets)
        return det.to_payload(dets), annotated

    if kind == "yolo_seg":
        dets, touched, centers, _ = det.detect(frame)
        annotated = det.draw(frame, dets, touched, centers)
        return det.to_payload(touched), annotated

    if kind == "movenet":
        result = det.detect(frame)
        annotated = det.draw(frame, result)
        return det.to_payload(result), annotated

    if kind == "handlandmarks":
        result = det.detect(frame)
        annotated = det.draw(frame, result)
        return det.to_payload(result), annotated

    if kind == "pinch":
        result = det.detect(frame)
        annotated = det.draw(frame, result)
        return det.to_payload(result), annotated

    if kind == "qr":
        results = det.detect(frame)
        annotated = det.draw(frame, results)
        return det.to_payload(results), annotated

    if kind == "aruco":
        results = det.detect(frame)
        annotated = det.draw(frame, results)
        return det.to_payload(results), annotated

    if kind == "colorimeter":
        result = det.detect(frame)
        annotated = det.draw(frame, result)
        return det.to_payload(result), annotated

    if kind == "geometric_scan":
        results = det.detect(frame)
        annotated = det.draw(frame, results)
        return det.to_payload(results), annotated

    raise ValueError(f"Tipo de analisis no soportado: {kind}")


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    debug = os.environ.get("FLASK_DEBUG", "0") == "1"
    app.run(host="0.0.0.0", port=port, debug=debug)
