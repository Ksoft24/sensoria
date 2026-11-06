# SENSOR-IA API (Flask)

API de inferencia de vision artificial, separada del panel PHP. Recibe
un frame de camara en base64 y devuelve la imagen ya anotada (cajas,
mascaras, esqueletos) + la clase detectada + confianza, en el mismo
formato que despues consume el motor de reglas (`RulesEngine`) del
panel web, para el modulo **ApiCam**.

Por que una API aparte: permite correr la inferencia en un servidor
con mas CPU/GPU que el navegador del cliente, centralizar los modelos
en un solo lugar, o correr los modelos donde el hosting PHP compartido
(cPanel) no permite instalar `onnxruntime`/`opencv`.

## 1. Instalar

```bash
cd API
python3 -m venv venv
source venv/bin/activate        # Windows: venv\Scripts\activate
pip install -r requirements.txt
```

**Version de Python:** usa Python 3.11 (o 3.10/3.12). `onnxruntime==1.18.1`
todavia no tiene binarios (wheels) para versiones muy nuevas de Python
(ej. 3.13/3.14) - si tu gestor de Python usa la ultima version por
defecto, la instalacion de `onnxruntime` falla con "No matching
distribution found". El archivo `.python-version` (`3.11`) en esta
carpeta ya le indica esto a Render automaticamente - es EL metodo
actual de Render para fijar version de Python (NO uses `runtime.txt`,
esa es la convencion vieja de Heroku y Render ya no la respeta). Si aun
asi Render sigue usando otra version, fijalo tambien a mano en
**Settings > Environment**, variable `PYTHON_VERSION = 3.11.9`
(version completa con el patch, ej. `3.11.9`, no solo `3.11`) - segun
Render, esta variable de entorno tiene prioridad y es el metodo mas
confiable. En tu maquina local usa `pyenv`/`venv` con Python 3.11 si te
pasa lo mismo.

## 2. Colocar los modelos ONNX

Copia cada modelo (los mismos `.onnx` que ya usan los modulos del panel
web en `modules/assets/<modulo>/model.onnx`) dentro de:

```
API/models/yolo/model.onnx            (+ classes.json, ya incluido)
API/models/yolo_seg/model.onnx
API/models/movenet/model.onnx
API/models/handlandmarks/model.onnx
API/models/pinch/model.onnx
```

`qr` y `aruco` NO necesitan modelo (usan OpenCV puro: `cv2.QRCodeDetector`
y `cv2.aruco`, diccionario `ARUCO_MIP_36h12` con fallback a `DICT_4X4_50`
si tu version de OpenCV no lo trae).

Si pides un `type` cuyo modelo todavia no colocaste, la API responde
`503` con un mensaje claro en vez de caerse.

## 3. Ejecutar

Desarrollo:
```bash
python app.py
# escucha en http://0.0.0.0:5000
```

Produccion (recomendado):
```bash
gunicorn -w 2 -b 0.0.0.0:5000 app:app
```

### Autenticacion opcional
Si defines la variable de entorno `SENSORIA_API_KEY`, toda peticion a
`/infer` debe incluir el header `X-API-Key` con ese mismo valor. El
modulo `apicam.php` del panel ya manda esa cabecera si se configura la
API Key en **Admin > Configuracion API de Vision** (o el campo
correspondiente en tu configuracion de hardware/API).

```bash
export SENSORIA_API_KEY="clave-secreta-larga"
```

## 4. Contrato de la API

### `GET /health`
Devuelve que modelos ya se cargaron y que tipos soporta la API.

### `POST /infer`
```json
{
  "type": "yolo",
  "image": "<base64 jpg/png, con o sin prefijo data:image/...;base64,>"
}
```

Tipos validos: `yolo`, `yolo_seg`, `movenet`, `handlandmarks`, `pinch`,
`qr`, `aruco`.

Respuesta:
```json
{
  "ok": true,
  "type": "yolo",
  "image": "<base64 JPEG ya con cajas/lineas dibujadas>",
  "detections": [
    {"class_name": "persona", "class_id": 0, "confidence": 93.4}
  ],
  "best": {"class_name": "persona", "class_id": 0, "confidence": 93.4},
  "inference_ms": 42.7
}
```

- `pinch` siempre devuelve una sola deteccion con `class_name: "Apertura
  de Pinza"` y `confidence` = porcentaje continuo 0-100 (se usa con
  reglas de tipo "continuo": `scale_out_min`/`scale_out_max`).
- `qr` devuelve un item por cada codigo leido, con `class_name` = texto
  decodificado.
- `aruco` devuelve un item por cada marcador, con `class_name = "ID <n>"`.
- `yolo_seg` solo devuelve las zonas (`ROJO`/`AZUL`/`AMARILLO`) que la
  persona detectada esta tocando en ese frame.

## 4.5. Desplegar en Render (plan Free)

En el servicio Web Service que crees en Render, apuntando a tu repo
(ej. `Ksoft24/APICAM`):

| Campo | Valor |
|---|---|
| Root Directory | `API` (si el repo contiene toda la carpeta `API/` en la raiz del repo; ajusta segun tu estructura) |
| Runtime | Python 3 |
| Build Command | `pip install -r requirements.txt` |
| Start Command | `gunicorn --bind 0.0.0.0:$PORT app:app` |

`$PORT` es literal - Render lo reemplaza solo por el puerto que te
asigno, NO hardcodees `5000` ahi (eso es solo lo que usa el servidor de
desarrollo de Flask cuando corres `python app.py` en tu maquina local).

El archivo `.python-version` (incluido en esta carpeta, contiene solo
`3.11`) le indica a Render que use Python 3.11 en vez de la ultima
version disponible, evitando el error `No matching distribution found
for onnxruntime==1.18.1` (onnxruntime tarda meses en publicar wheels
para cada version nueva de Python). Si el build sigue mostrando
`Using Python version 3.14.3 (default)` en los logs, fija la version
tambien en **Settings > Environment**, variable
`PYTHON_VERSION = 3.11.9` (version completa, con el numero de patch) -
esa variable tiene prioridad sobre el archivo.

Variables de entorno opcionales (Settings > Environment):
```
SENSORIA_API_KEY = tu-clave-secreta   (si quieres proteger /infer)
```

**Plan Free duerme el servicio** tras ~15 min sin trafico: la primera
peticion despues de estar dormido tarda 30-60s en responder mientras
Render "despierta" el contenedor - es normal, no es un error.

## 5. Como la consume el panel PHP

El modulo `modules/apicam.php` (menu **ApiCam** del panel) captura un
frame del `<video>` cada cierto intervalo, lo manda en base64 a
`POST {API_URL}/infer`, pinta la imagen `image` que regresa en el
`<canvas>`, y por cada item de `detections` llama a
`rulesEngine.evaluate(class_name, confidence, class_id)` -- el MISMO
`RulesEngine` (assets/js/rules-engine.js) y el mismo `HardwareBridge`
(Serial/MQTT) que usan los otros 7 modulos, para que las reglas
configuradas en **Reglas** se comporten exactamente igual sin importar
si la inferencia corrio en el navegador o en esta API.

## 6. Proxy MQTT (`/mqtt/state`, `/mqtt/publish`) - para Android/navegadores viejos

Algunos navegadores antiguos (ej. Android 4.4) no soportan bien MQTT
sobre WebSocket. Para esos casos, el modulo ApiCam puede activar
"Usar MQTT via API" (en la pestaña **Conexion APICAM** del panel): en
vez de conectar el broker desde el navegador, le pide a ESTA API que se
conecte ella misma - la API es compartida por varios usuarios, asi que
**recibe los parametros de conexion del broker (host/usuario/clave/
topicos) en CADA peticion**, no guarda ninguna sesion MQTT propia entre
peticiones.

### `POST /mqtt/state`
El panel PHP la llama antes de evaluar las Reglas de cada foto, para
refrescar el estado de Entradas Digitales/Analogicas:
```json
{
  "host": "xxxx.hivemq.cloud", "port": 8883, "use_tls": true,
  "username": "...", "password": "...",
  "input_topic": "device/<uid>/input",
  "query_topic": "device/<uid>/query",
  "timeout_s": 4
}
```
La API se suscribe a `input_topic`, publica un "ping" en `query_topic`
(para que tu firmware de Arduino sepa que debe reportar sus sensores en
ese momento) y espera hasta `timeout_s` segundos la primera respuesta:
```json
{ "ok": true, "received": true, "digital": {"I12": 1}, "analog": {"AI0": 512}, "raw": "..." }
```
`"received": false` no es un error - solo significa que Arduino no
contesto a tiempo (el panel sigue con el ultimo estado conocido).

### `POST /mqtt/publish`
El panel PHP la llama cuando una Regla se cumple, para publicar el
comando de salida (Q2-Q11) sin pasar por el navegador:
```json
{
  "host": "xxxx.hivemq.cloud", "port": 8883, "use_tls": true,
  "username": "...", "password": "...",
  "topic": "device/<uid>/command",
  "payload": {"command": "Q2", "state": "1"}
}
```

Requiere `paho-mqtt==1.6.1` (ya en `requirements.txt`) y conexion
saliente al broker desde tu servidor Flask (en Render, esto funciona
sin configuracion adicional).
