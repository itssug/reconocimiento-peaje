"""Genera los notebooks oficiales en inferencia/notebooks/ en formato nbformat 4 valido."""
from __future__ import annotations

import json
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / "notebooks"
OUT.mkdir(parents=True, exist_ok=True)


def md(texto: str) -> dict:
    return {
        "cell_type": "markdown",
        "metadata": {},
        "source": [linea + "\n" for linea in texto.strip().splitlines()],
    }


def code(texto: str) -> dict:
    return {
        "cell_type": "code",
        "execution_count": None,
        "metadata": {},
        "outputs": [],
        "source": [linea + "\n" for linea in texto.strip().splitlines()],
    }


def guardar(nombre: str, celdas: list[dict]) -> Path:
    nb = {
        "nbformat": 4,
        "nbformat_minor": 5,
        "metadata": {
            "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
            "language_info": {"name": "python", "version": "3.11"},
            "accelerator": "GPU",
        },
        "cells": celdas,
    }
    ruta = OUT / nombre
    ruta.write_text(json.dumps(nb, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return ruta


# ==============================================================================
# NOTEBOOK 1: INFERENCIA UNIFICADA (M1 + M2 + M3 + M4) + SERVIDOR FASTAPI
# ==============================================================================
nb1 = [
    md("""
# Sistema Inteligente de Control de Peaje — Inferencia Unificada (M1 + M2 + M3 + M4)

Este notebook integra **todos los modelos de visión por computadora en un solo lugar** y levanta el endpoint HTTPS (`INFERENCE_URL`) que consume la aplicación web PHP y la aplicación móvil:

| Modelo | Tarea | Implementación |
|---|---|---|
| **M1** | Detección de placas + OCR | `yolo-v9-t-384-license-plates-end2end.onnx` + `cct_xs_v2_global.onnx` (normalización boliviana `^\\d{3,4}[A-Z]{3}$` + corrección `O↔0, I↔1, B↔8, S↔5`) |
| **M2** | Segmentación y tipo de vehículo (`motocicleta`, `automovil`, `minibus`, `camion`, `bus`) | Peso propio `m2_seg.pt` (YOLO11-seg) o fallback automático `YOLOE-11s-seg` |
| **M3** | Keypoints de ruedas → conteo de ejes (lado cercano) | Peso propio `m3_pose.pt` (YOLO11-pose 5 kps) o fallback automático `YOLOE-11s-seg` + keypoints geométricos |
| **M4** | *(Bono +5)* Conteo de pasajeros dentro del vehículo | `m4_personas.pt` o `yolo11n-seg.pt` (clase `person` intersectada con máscara M2; **sin rostros ni recortes**) |

> **Bonificación `venv` (+5 pts):** Todas las dependencias se instalan en un entorno virtual **`venv`** aislado (sin conda/mamba) y se guarda el diagnóstico del entorno en `evidencias_entornos/`.
"""),

    md("## 1. Verificación de GPU y preparación del proyecto (Clonar o usar Drive)"),
    code("""
!nvidia-smi -L || echo "Sin GPU detectada: en Colab activa Entorno de ejecucion > Cambiar tipo de entorno > GPU T4"

import os
from pathlib import Path

# === CONFIGURACION ===
# Opcion A: URL de tu repositorio Git remoto (recomendado en Colab y RunPod)
REPO_URL = ""  # Ej: "https://github.com/tu-usuario/reconocimiento-peaje.git"
CARPETA_TRABAJO = Path("/content/reconocimiento-peaje")

# Opcion B: Si tus pesos entrenados (m2_seg.pt, m3_pose.pt, m4_personas.pt) estan en Google Drive:
USAR_DRIVE = False
CARPETA_PESOS_DRIVE = Path("/content/drive/MyDrive/peaje_modelos")

# Clave secreta que tambien pegaras en INFERENCE_API_KEY de sistema-peaje/.env
INFERENCE_API_KEY = "peaje-clave-segura-cambiar-2026"
# 1 = usar YOLOE/YOLO11-seg automaticamente si aun no tienes m2_seg.pt / m3_pose.pt
USE_ZEROSHOT_FALLBACK = "1"

if REPO_URL and not CARPETA_TRABAJO.exists():
    !git clone --depth 1 "{REPO_URL}" "{CARPETA_TRABAJO}"

DIR_INF = CARPETA_TRABAJO / "inferencia" if (CARPETA_TRABAJO / "inferencia").exists() else Path("/content/inferencia")
DIR_INF.mkdir(parents=True, exist_ok=True)

# Opcion C (pruebas rapidas sin GitHub): si falta app/main.py, sube inferencia_colab.zip desde tu PC
if not (DIR_INF / "app" / "main.py").is_file():
    import zipfile
    try:
        from google.colab import files
        print("No hay REPO_URL configurado. Sube el archivo 'inferencia_colab.zip' desde tu PC:")
        sub = files.upload()
        for nombre, contenido in sub.items():
            if nombre.endswith(".zip"):
                zpath = Path("/content") / nombre
                zpath.write_bytes(contenido)
                with zipfile.ZipFile(zpath, "r") as zf:
                    zf.extractall(DIR_INF)
                print("Extraido en:", DIR_INF)
    except ImportError:
        print("Copia la carpeta inferencia/ en:", DIR_INF)

print("Directorio de inferencia:", DIR_INF, "| app/main.py listo:", (DIR_INF / "app" / "main.py").is_file())
"""),

    md("## 2. Creación del entorno `venv` e instalación reproducible (Bonificación +5 pts)"),
    code("""
import subprocess, sys
from pathlib import Path

VENV_DIR = DIR_INF / "venv"
VENV_PY = VENV_DIR / "bin" / "python"
VENV_PIP = VENV_DIR / "bin" / "pip"

if not VENV_PY.exists():
    py_ver = f"{sys.version_info.major}.{sys.version_info.minor}"
    subprocess.run(["bash", "-c", f"apt-get -qq update && apt-get -qq install -y python{py_ver}-venv python3-venv >/dev/null 2>&1"], check=False)
    r = subprocess.run([sys.executable, "-m", "venv", str(VENV_DIR)])
    if r.returncode != 0:
        # Fallback si ensurepip no esta disponible en el contenedor
        subprocess.run([sys.executable, "-m", "venv", "--without-pip", str(VENV_DIR)], check=True)
        subprocess.run(["bash", "-c", f"curl -sS https://bootstrap.pypa.io/get-pip.py | '{VENV_PY}'"], check=True)

subprocess.run([str(VENV_PIP), "install", "--upgrade", "pip"], check=True)
req = DIR_INF / "requirements-modelos.txt"
if req.is_file():
    subprocess.run([str(VENV_PIP), "install", "-r", str(req)], check=True)
else:
    subprocess.run([
        str(VENV_PIP), "install",
        "fastapi>=0.115.0", "uvicorn[standard]>=0.34.0", "python-multipart>=0.0.20",
        "onnxruntime>=1.21.0", "opencv-python-headless>=4.11.0", "numpy>=2.2.0",
        "PyYAML>=6.0.2", "requests>=2.32.0", "ultralytics>=8.3.0", "torch>=2.5.0", "torchvision>=0.20.0",
    ], check=True)

# Codificador CLIP para YOLOE (opcional para modo zero-shot)
subprocess.run([str(VENV_PIP), "install", "git+https://github.com/ultralytics/CLIP.git"], check=False)

# Guardar freeze exacto del entorno en la nube (evidencia bono +5 venv)
evid = DIR_INF / "evidencias_entornos"
evid.mkdir(parents=True, exist_ok=True)
frozen = subprocess.check_output([str(VENV_PIP), "freeze"], text=True)
(evid / "requirements_nube_frozen.txt").write_text(frozen, encoding="utf-8")
print("Entorno venv listo y dependencias instaladas:", VENV_PY)
"""),

    md("## 3. Descarga verificada de M1 (SHA-256), carga de pesos propios y Diagnóstico del entorno"),
    code("""
import shutil

if USAR_DRIVE:
    from google.colab import drive
    drive.mount("/content/drive")
    (DIR_INF / "modelos").mkdir(parents=True, exist_ok=True)
    for peso in ("m2_seg.pt", "m3_pose.pt", "m4_personas.pt"):
        src = CARPETA_PESOS_DRIVE / peso
        if src.is_file():
            shutil.copy2(src, DIR_INF / "modelos" / peso)
            print("Copiado desde Drive:", peso)

# Descargar M1 (YOLOv9-t placas + fast-plate-ocr) verificando SHA-256
if (DIR_INF / "scripts" / "descargar_modelos.py").is_file():
    !{VENV_PY} "{DIR_INF / 'scripts' / 'descargar_modelos.py'}"
else:
    print("AVISO: Clona primero el repositorio (REPO_URL) o sube la carpeta inferencia/.")

# Evidencia obligatoria para la bonificacion venv (+5 pts)
EVID_DIR = DIR_INF / "evidencias_entornos"
EVID_DIR.mkdir(parents=True, exist_ok=True)
if (DIR_INF / "scripts" / "diagnostico_entorno.py").is_file():
    !{VENV_PY} "{DIR_INF / 'scripts' / 'diagnostico_entorno.py'}" --salida "{EVID_DIR / 'diagnostico_nube.txt'}"
    !{VENV_PY} "{DIR_INF / 'scripts' / 'diagnostico_entorno.py'}" --json --salida "{EVID_DIR / 'diagnostico_nube.json'}"
"""),

    md("""
## 4. Prueba visual unificada: M1 + M2 + M3 + M4 sobre una imagen

Ejecuta los **4 modelos juntos** dentro del `venv` sobre una foto (propia o de ejemplo), muestra el JSON exacto del contrato y genera una visualización con:
- Caja verde + texto de la placa (M1)
- Polígono/caja del vehículo + categoría (M2)
- Puntos de ruedas y conteo de ejes (M3)
- Conteo de pasajeros en cabecera (M4 — **nunca** guarda recortes de rostros)
"""),
    code("""
import json, urllib.request
from pathlib import Path
from IPython.display import Image, display

IMG_PRUEBA = DIR_INF / "prueba_vehiculo.jpg"
# Si estas en Colab y quieres subir una foto tuya, descomenta estas 3 lineas:
# from google.colab import files
# sub = files.upload()
# IMG_PRUEBA.write_bytes(next(iter(sub.values())))

if not IMG_PRUEBA.exists():
    urllib.request.urlretrieve("https://ultralytics.com/images/bus.jpg", IMG_PRUEBA)

OUT_IMG = DIR_INF / "resultado_unificado.jpg"
!USE_ZEROSHOT_FALLBACK={USE_ZEROSHOT_FALLBACK} {VENV_PY} "{DIR_INF / 'scripts' / 'demo_unificada.py'}" "{IMG_PRUEBA}" "{OUT_IMG}"
if OUT_IMG.exists():
    display(Image(filename=str(OUT_IMG)))
"""),

    md("""
## 5. Levantar el Endpoint Único de Inferencia (FastAPI en el puerto 8000)

Esta celda arranca `uvicorn app.main:app` dentro del `venv` y lo expone con una URL HTTPS pública para pegarla en **`INFERENCE_URL`** dentro de `sistema-peaje/.env`:
- En **Google Colab**: descarga `cloudflared` en la VM de Colab (sin cuenta ni tarjeta) e imprime `https://xxxx.trycloudflare.com`.
- En **RunPod (+5 pts)**: usa la URL nativa del Pod `https://<RUNPOD_POD_ID>-8000.proxy.runpod.net`.
"""),
    code("""
import os, re, subprocess, time, requests

os.environ["INFERENCE_API_KEY"] = INFERENCE_API_KEY
os.environ["USE_ZEROSHOT_FALLBACK"] = USE_ZEROSHOT_FALLBACK

# Detener instancias previas si existieran
!pkill -f "uvicorn app.main:app" || true
!pkill -f "cloudflared tunnel" || true

log_api = open(DIR_INF / "api.log", "w")
proc_api = subprocess.Popen(
    [str(VENV_PY), "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"],
    cwd=str(DIR_INF), stdout=log_api, stderr=subprocess.STDOUT, env=os.environ.copy()
)

# Esperar a que /salud responda (la primera vez puede tardar 30-60s cargando pesos)
ok = False
for _ in range(90):
    try:
        r = requests.get("http://127.0.0.1:8000/salud", timeout=2)
        if r.ok:
            print("API lista:", r.json())
            ok = True
            break
    except Exception:
        pass
    time.sleep(2)

if not ok:
    print("ERROR: La API no respondio. Ultimas lineas de api.log:")
    print((DIR_INF / "api.log").read_text()[-2000:])
else:
    if os.environ.get("RUNPOD_POD_ID"):
        PUBLIC_URL = f"https://{os.environ['RUNPOD_POD_ID']}-8000.proxy.runpod.net"
        print("\\n=== COPIA ESTO EN sistema-peaje/.env (RunPod) ===")
        print(f"INFERENCE_URL={PUBLIC_URL}")
        print(f"INFERENCE_API_KEY={INFERENCE_API_KEY}")
    else:
        # Exponer desde la VM de Colab mediante cloudflared
        !wget -q -O /usr/local/bin/cloudflared https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64
        !chmod +x /usr/local/bin/cloudflared
        log_cf = open(DIR_INF / "tunnel.log", "w")
        subprocess.Popen(
            ["/usr/local/bin/cloudflared", "tunnel", "--url", "http://127.0.0.1:8000", "--no-autoupdate"],
            stdout=log_cf, stderr=subprocess.STDOUT
        )
        PUBLIC_URL = ""
        for _ in range(30):
            txt = (DIR_INF / "tunnel.log").read_text(errors="ignore")
            m = re.search(r"https://[a-z0-9-]+\\.trycloudflare\\.com", txt)
            if m:
                PUBLIC_URL = m.group(0)
                break
            time.sleep(1)
        print("\\n=== COPIA ESTO EN sistema-peaje/.env (Colab) ===")
        print(f"INFERENCE_URL={PUBLIC_URL}")
        print(f"INFERENCE_API_KEY={INFERENCE_API_KEY}")
"""),

    md("## 6. Prueba en vivo contra el endpoint público (`GET /salud` y `POST /inferir`)"),
    code("""
import json, requests

assert PUBLIC_URL, "Ejecuta primero la celda anterior para obtener PUBLIC_URL."
print("GET /salud ->", requests.get(f"{PUBLIC_URL}/salud", timeout=15).json())

with open(IMG_PRUEBA, "rb") as f:
    r = requests.post(
        f"{PUBLIC_URL}/inferir",
        headers={"X-API-Key": INFERENCE_API_KEY},
        files={"imagen": ("vehiculo.jpg", f, "image/jpeg")},
        timeout=60,
    )
print("HTTP", r.status_code)
print(json.dumps(r.json(), indent=2, ensure_ascii=False))
"""),

    md("## 7. Detener el servidor (cambiar `DETENER = True` solo al terminar)"),
    code("""
DETENER = False
if DETENER:
    !pkill -f "uvicorn app.main:app" || true
    !pkill -f "cloudflared tunnel" || true
    print("Servidor detenido.")
else:
    print("El servidor sigue activo en:", PUBLIC_URL)
"""),
]

# ==============================================================================
# NOTEBOOK 2: ENTRENAMIENTO M2 (YOLO11-SEG) Y M3 (YOLO11-POSE) SIN ROBOFLOW
# ==============================================================================
nb2 = [
    md("""
# Entrenamiento de M2 (Vehículos) y M3 (Keypoints de Ruedas) — Sin Roboflow

Este notebook permite crear tu dataset propio en **Google Drive** (sin Roboflow), pre-etiquetarlo automáticamente con **YOLOE**, entrenarlo en GPU T4 de Google Colab usando un **`venv`** limpio y exportar los pesos finales:
- `m2_seg.pt`: Segmentación de instancias (`0: motocicleta`, `1: automovil`, `2: minibus`, `3: camion`, `4: bus`).
- `m3_pose.pt`: Detección de llantas con **5 keypoints** (`0: centro`, `1: contacto`, `2: superior`, `3: izquierda`, `4: derecha`).

### Estructura esperada en tu Google Drive
Sube tus fotos propias a `MyDrive/peaje_datasets/raw_vehiculos/` (para M2) y `MyDrive/peaje_datasets/raw_laterales/` (fotos laterales para M3).
"""),

    md("## 1. Montar Google Drive y crear entorno `venv`"),
    code("""
!nvidia-smi -L
from google.colab import drive
drive.mount("/content/drive")

from pathlib import Path
BASE_DRIVE = Path("/content/drive/MyDrive/peaje_datasets")
PESOS_DRIVE = Path("/content/drive/MyDrive/peaje_modelos")
for d in (BASE_DRIVE / "raw_vehiculos", BASE_DRIVE / "raw_laterales", PESOS_DRIVE):
    d.mkdir(parents=True, exist_ok=True)

VENV_DIR = Path("/content/venv_train")
if not (VENV_DIR / "bin" / "python").exists():
    import subprocess, sys
    py_ver = f"{sys.version_info.major}.{sys.version_info.minor}"
    subprocess.run(["bash", "-c", f"apt-get -qq update && apt-get -qq install -y python{py_ver}-venv python3-venv >/dev/null 2>&1"], check=False)
    if subprocess.run([sys.executable, "-m", "venv", str(VENV_DIR)]).returncode != 0:
        subprocess.run([sys.executable, "-m", "venv", "--without-pip", str(VENV_DIR)], check=True)
        subprocess.run(["bash", "-c", f"curl -sS https://bootstrap.pypa.io/get-pip.py | '{VENV_DIR}/bin/python'"], check=True)
    !{VENV_DIR}/bin/pip install -q --upgrade pip
    !{VENV_DIR}/bin/pip install -q "ultralytics>=8.3.0" "opencv-python-headless>=4.11.0" "PyYAML>=6.0.2"
    !{VENV_DIR}/bin/pip install -q "git+https://github.com/ultralytics/CLIP.git" || true

VENV_PY = VENV_DIR / "bin" / "python"
print("Listo. Sube tus fotos a:", BASE_DRIVE)
"""),

    md("## 2. Pre-etiquetado automático (Auto-labeling) de tus fotos para M2 y M3"),
    code("""
script_auto = Path("/content/auto_etiquetar.py")
script_auto.write_text('''
import random, shutil
from pathlib import Path
import cv2
import numpy as np
import yaml
from ultralytics import YOLOE

BASE = Path("/content/drive/MyDrive/peaje_datasets")
CLASES_M2 = ["motocicleta", "automovil", "minibus", "camion", "bus"]
PROMPTS_M2 = ["motorcycle", "car", "minibus", "truck", "bus"]
MAP_M2 = {"motorcycle": 0, "car": 1, "minibus": 2, "truck": 3, "bus": 4}

def preparar_dirs(raiz: Path):
    for sp in ("train", "val"):
        (raiz / "images" / sp).mkdir(parents=True, exist_ok=True)
        (raiz / "labels" / sp).mkdir(parents=True, exist_ok=True)

def auto_m2():
    imgs = sorted([p for p in (BASE / "raw_vehiculos").glob("*") if p.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp")])
    if not imgs:
        print("M2: No hay imagenes en raw_vehiculos/.")
        return
    ds = BASE / "dataset_m2_seg"
    preparar_dirs(ds)
    m = YOLOE("yoloe-11s-seg.pt")
    m.set_classes(PROMPTS_M2, m.get_text_pe(PROMPTS_M2))
    random.seed(42); random.shuffle(imgs)
    corte = max(1, int(len(imgs) * 0.8))
    for idx, ruta in enumerate(imgs):
        sp = "train" if idx < corte else "val"
        im = cv2.imread(str(ruta))
        if im is None: continue
        h, w = im.shape[:2]
        r = m.predict(im, conf=0.30, verbose=False)[0]
        lineas = []
        if r.boxes is not None and r.masks is not None:
            for i, b in enumerate(r.boxes):
                cls_name = str(r.names[int(b.cls)]).lower()
                if cls_name not in MAP_M2 or i >= len(r.masks.xyn) or len(r.masks.xyn[i]) < 3:
                    continue
                coords = " ".join(f"{x:.6f} {y:.6f}" for x, y in r.masks.xyn[i])
                lineas.append(f"{MAP_M2[cls_name]} {coords}")
        shutil.copy2(ruta, ds / "images" / sp / ruta.name)
        (ds / "labels" / sp / f"{ruta.stem}.txt").write_text("\\n".join(lineas), encoding="utf-8")
    yaml.safe_dump({"path": str(ds), "train": "images/train", "val": "images/val",
                    "names": {i: c for i, c in enumerate(CLASES_M2)}}, open(ds / "data.yaml", "w"))
    print(f"M2 pre-etiquetado listo en {ds} ({len(imgs)} imagenes). Revisa/corrige en CVAT o Label Studio antes de entrenar.")

def auto_m3():
    imgs = sorted([p for p in (BASE / "raw_laterales").glob("*") if p.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp")])
    if not imgs:
        print("M3: No hay imagenes en raw_laterales/.")
        return
    ds = BASE / "dataset_m3_pose"
    preparar_dirs(ds)
    m = YOLOE("yoloe-11s-seg.pt")
    m.set_classes(["wheel", "tire"], m.get_text_pe(["wheel", "tire"]))
    random.seed(42); random.shuffle(imgs)
    corte = max(1, int(len(imgs) * 0.8))
    for idx, ruta in enumerate(imgs):
        sp = "train" if idx < corte else "val"
        im = cv2.imread(str(ruta))
        if im is None: continue
        h, w = im.shape[:2]
        r = m.predict(im, conf=0.15, imgsz=1024, verbose=False)[0]
        lineas = []
        if r.boxes is not None and r.masks is not None:
            for i, b in enumerate(r.boxes):
                if i >= len(r.masks.xy) or len(r.masks.xy[i]) < 3:
                    continue
                x1, y1, x2, y2 = b.xyxy[0].tolist()
                xc, yc, bw, bh = ((x1+x2)/2)/w, ((y1+y2)/2)/h, (x2-x1)/w, (y2-y1)/h
                pts = np.asarray(r.masks.xy[i], dtype=np.float32)
                kps = [
                    (pts[:,0].mean()/w, pts[:,1].mean()/h, 2),
                    (pts[np.argmax(pts[:,1]),0]/w, pts[np.argmax(pts[:,1]),1]/h, 2),
                    (pts[np.argmin(pts[:,1]),0]/w, pts[np.argmin(pts[:,1]),1]/h, 2),
                    (pts[np.argmin(pts[:,0]),0]/w, pts[np.argmin(pts[:,0]),1]/h, 2),
                    (pts[np.argmax(pts[:,0]),0]/w, pts[np.argmax(pts[:,0]),1]/h, 2),
                ]
                kp_str = " ".join(f"{kx:.6f} {ky:.6f} {v}" for kx, ky, v in kps)
                lineas.append(f"0 {xc:.6f} {yc:.6f} {bw:.6f} {bh:.6f} {kp_str}")
        shutil.copy2(ruta, ds / "images" / sp / ruta.name)
        (ds / "labels" / sp / f"{ruta.stem}.txt").write_text("\\n".join(lineas), encoding="utf-8")
    yaml.safe_dump({"path": str(ds), "train": "images/train", "val": "images/val",
                    "kpt_shape": [5, 3], "flip_idx": [0, 1, 2, 4, 3], "names": {0: "llanta"}},
                   open(ds / "data.yaml", "w"))
    print(f"M3 pre-etiquetado listo en {ds} ({len(imgs)} imagenes).")

if __name__ == "__main__":
    auto_m2()
    auto_m3()
''', encoding="utf-8")

!{VENV_PY} "{script_auto}"
"""),

    md("## 3. Entrenamiento de M2 (`yolo11s-seg.pt` → `m2_seg.pt`) y M3 (`yolo11s-pose.pt` → `m3_pose.pt`)"),
    code("""
import shutil
from pathlib import Path

DS_M2 = BASE_DRIVE / "dataset_m2_seg" / "data.yaml"
DS_M3 = BASE_DRIVE / "dataset_m3_pose" / "data.yaml"

if DS_M2.is_file():
    !{VENV_PY} -m ultralytics segment train model=yolo11s-seg.pt data="{DS_M2}" epochs=50 imgsz=640 batch=16 project=/content/runs name=m2_vehiculos
    best_m2 = Path("/content/runs/m2_vehiculos/weights/best.pt")
    if best_m2.is_file():
        shutil.copy2(best_m2, PESOS_DRIVE / "m2_seg.pt")
        print("Peso M2 guardado en Drive:", PESOS_DRIVE / "m2_seg.pt")

if DS_M3.is_file():
    !{VENV_PY} -m ultralytics pose train model=yolo11s-pose.pt data="{DS_M3}" epochs=60 imgsz=640 batch=16 project=/content/runs name=m3_ruedas
    best_m3 = Path("/content/runs/m3_ruedas/weights/best.pt")
    if best_m3.is_file():
        shutil.copy2(best_m3, PESOS_DRIVE / "m3_pose.pt")
        print("Peso M3 guardado en Drive:", PESOS_DRIVE / "m3_pose.pt")
"""),
]

p1 = guardar("01_peaje_inferencia_unificada.ipynb", nb1)
p2 = guardar("02_entrenamiento_m2_m3_sin_roboflow.ipynb", nb2)
print("Notebooks generados:", p1, "y", p2)
