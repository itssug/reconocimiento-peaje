# Servicio de Inferencia — Control de Peaje (FastAPI + ONNX + YOLO)

Servicio REST en Python (gestionado exclusivamente con **`venv`**) que expone un único endpoint unificado (`POST /inferir`) para los modelos del sistema de peaje:
- **M1 (`m1_placas.py`)**: Detección de placas (`yolo-v9-t-384-license-plates-end2end.onnx`) + lectura OCR (`cct_xs_v2_global.onnx`) + normalización y corrección de confusiones (`O↔0`, `I↔1`, `B↔8`, `S↔5`) para formato boliviano (`^\d{3,4}[A-Z]{3}$`).
- **M2 (`m2_vehiculos.py`)**: Segmentación de instancias (YOLO-seg) para clasificar en `motocicleta`, `automovil`, `minibus`, `camion`, `bus`.
- **M3 (`m3_ejes.py`)**: Keypoints de ruedas del lado cercano (YOLO-pose) para estimar el número de ejes.
- **M4 (`m4_pasajeros.py`)**: Segmentación de personas dentro de la máscara del vehículo para conteo de pasajeros (bonificación, sin reconocimiento facial ni recortes de rostros).

---

## 1. Creación y recreación del entorno con `venv` (+5 pts bonificación)

### Linux (Google Colab / RunPod)
```bash
cd inferencia
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
python scripts/descargar_modelos.py
python scripts/diagnostico_entorno.py
```

### Windows (PowerShell)
```powershell
cd inferencia
python -m venv venv
.\venv\Scripts\Activate.ps1
pip install -r requirements.txt
python scripts\descargar_modelos.py
python scripts\diagnostico_entorno.py
```

Para guardar la evidencia del entorno en la nube:
```bash
mkdir -p evidencias_entornos
python scripts/diagnostico_entorno.py > evidencias_entornos/diagnostico_nube.txt
python scripts/diagnostico_entorno.py --json > evidencias_entornos/diagnostico_nube.json
```

---

## 2. Arranque del servidor

```bash
export INFERENCE_API_KEY="su-clave-secreta"
uvicorn app.main:app --host 0.0.0.0 --port 8000
```

### Endpoints
- `GET /salud`: Estado del servicio y disponibilidad de `m1..m4`.
- `POST /inferir`: Recibe `multipart/form-data` con el campo `imagen` y cabecera `X-API-Key`. Devuelve:
  ```json
  {
    "success": true,
    "inference": {
      "placa": {
        "texto": "1234ABC",
        "confianza": 0.96,
        "confianza_deteccion": 0.91,
        "formato_bolivia": true,
        "bbox": [120, 210, 260, 255]
      },
      "vehiculo": { "categoria": "automovil", "confianza": 0.94 },
      "ejes": { "cantidad": 2, "confianza": 0.91 },
      "pasajeros": { "cantidad": 2 }
    },
    "modelos": { "m1": "ok", "m2": "no_disponible", "m3": "no_disponible", "m4": "no_disponible" },
    "latencia_ms": 142
  }
  ```

---

## 3. Despliegue en RunPod (+5 pts bonificación) o Google Colab

- **RunPod**: Cree un Pod con GPU (ej. RTX 3090 / A4000), exponga el puerto HTTP `8000` en la configuración del Pod, ejecute los comandos de la sección 1 y configure en el `.env` de la aplicación web PHP:
  ```ini
  INFERENCE_URL=https://<pod-id>-8000.proxy.runpod.net
  INFERENCE_API_KEY=<su-clave-secreta>
  ```
- **Google Colab**: Ejecute los comandos en la máquina virtual de Colab y exponga el puerto `8000` desde la propia VM de Colab.
