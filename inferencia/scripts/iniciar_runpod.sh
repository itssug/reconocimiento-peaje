#!/usr/bin/env bash
# Script de arranque para RunPod (Pod con GPU y puerto HTTP 8000 expuesto).
# Crea/usa venv, guarda la evidencia de diagnostico (+5 pts venv) y levanta FastAPI.
#
# Uso en la terminal de RunPod:
#   export INFERENCE_API_KEY="tu-clave-secreta-larga"
#   export USE_ZEROSHOT_FALLBACK=1   # 0 cuando ya subas m2_seg.pt y m3_pose.pt
#   bash scripts/iniciar_runpod.sh

set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -d "venv" ]; then
  echo "[1/4] Creando entorno virtual venv..."
  python3 -m venv venv
fi
source venv/bin/activate

echo "[2/4] Instalando dependencias en venv..."
pip install -q --upgrade pip
pip install -q -r requirements-modelos.txt
pip install -q "git+https://github.com/ultralytics/CLIP.git" || true

echo "[3/4] Descargando modelos M1 (placas + OCR) y guardando diagnostico..."
python scripts/descargar_modelos.py
mkdir -p evidencias_entornos
python scripts/diagnostico_entorno.py --salida evidencias_entornos/diagnostico_runpod.txt
python scripts/diagnostico_entorno.py --json --salida evidencias_entornos/diagnostico_runpod.json

if [ -z "${INFERENCE_API_KEY:-}" ]; then
  echo "ADVERTENCIA: INFERENCE_API_KEY no esta definida. Exportela antes de produccion."
  export ALLOW_NO_AUTH=1
fi

echo "[4/4] Iniciando servidor de inferencia en 0.0.0.0:8000..."
echo "URL en RunPod: https://${RUNPOD_POD_ID:-<pod-id>}-8000.proxy.runpod.net"
exec uvicorn app.main:app --host 0.0.0.0 --port 8000
