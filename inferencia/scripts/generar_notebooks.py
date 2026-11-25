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

DIR_MODELOS = DIR_INF / "modelos"
DIR_MODELOS.mkdir(parents=True, exist_ok=True)

# Si USAR_DRIVE=True y existen en Drive, los copia; si ya estan en el repo o los subiste a /content, tambien los detecta
if USAR_DRIVE:
    try:
        from google.colab import drive
        drive.mount("/content/drive")
        for peso in ("m2_seg.pt", "m3_pose.pt", "m4_personas.pt"):
            src = CARPETA_PESOS_DRIVE / peso
            if src.is_file():
                shutil.copy2(src, DIR_MODELOS / peso)
                print("Copiado desde Drive:", peso)
    except Exception as e:
        print("Drive omitido:", e)

# Si el usuario subio m2_seg.pt o m3_pose.pt a la raiz /content/, moverlos a DIR_INF/modelos/
for peso in ("m2_seg.pt", "m3_pose.pt", "m4_personas.pt"):
    en_raiz = Path("/content") / peso
    if en_raiz.is_file() and not (DIR_MODELOS / peso).is_file():
        shutil.copy2(en_raiz, DIR_MODELOS / peso)
        print("Copiado desde /content:", peso)

# Descargar M1 (YOLOv9-t placas + fast-plate-ocr) verificando SHA-256
if (DIR_INF / "scripts" / "descargar_modelos.py").is_file():
    !{VENV_PY} "{DIR_INF / 'scripts' / 'descargar_modelos.py'}"
else:
    print("AVISO: Clona primero el repositorio (REPO_URL) o sube la carpeta inferencia/.")

print("\\n=== ESTADO DE PESOS EN", DIR_MODELOS, "===")
for p in ("yolo-v9-t-384-license-plates-end2end.onnx", "cct_xs_v2_global.onnx", "m2_seg.pt", "m3_pose.pt"):
    ruta = DIR_MODELOS / p
    print(f" - {p}: {'LISTO (' + str(round(ruta.stat().st_size / 1e6, 1)) + ' MB)' if ruta.is_file() else 'NO ENCONTRADO (usara fallback)'}")

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
# NOTEBOOK 2: ENTRENAMIENTO M2 (YOLO11-SEG) Y M3 (YOLO11-POSE) CON ROBOFLOW + APOYO
# ==============================================================================
nb2 = [
    md("""
# Entrenamiento de M2 (Segmentación de Vehículos) y M3 (Keypoints de Ruedas) — Roboflow + Refuerzo

Este notebook descarga tus datasets oficiales desde **Roboflow**, los normaliza al esquema exacto del sistema de peaje, opcionalmente los **refuerza con imágenes de apoyo** (auto-etiquetadas con YOLOE para aumentar variedad), entrena con **YOLO11** dentro de un **`venv`** limpio en GPU T4 y guarda tanto los pesos (`m2_seg.pt`, `m3_pose.pt`) como las **métricas oficiales (mAP50, mAP50-95, Precision, Recall)** para tu informe técnico:

| Modelo | Proyecto Roboflow | Clases / Estructura | Peso Final |
|---|---|---|---|
| **M2 (`yolo11s-seg.pt`)** | `luzmilas-workspace/m2-qfggl` | `0: motocicleta`, `1: automovil`, `2: minibus`, `3: camion`, `4: bus` | `m2_seg.pt` |
| **M3 (`yolo11s-pose.pt`)** | `luzmilas-workspace/m3-r2mey` | `0: rueda` con **1 keypoint** `[cx, cy, v]` en el centro de la rueda | `m3_pose.pt` |
"""),

    md("## 1. Montar Google Drive, configurar Roboflow y crear entorno `venv` (+5 pts bono)"),
    code("""
!nvidia-smi -L || echo "Activa GPU T4 en: Entorno de ejecucion > Cambiar tipo de entorno"

import os, subprocess, sys
from pathlib import Path
from getpass import getpass

# === CONFIGURACION DE ROBOFLOW ===
# Si dejas ROBOFLOW_API_KEY vacio, te lo pedira de forma segura al ejecutar la celda
ROBOFLOW_API_KEY = os.environ.get("ROBOFLOW_API_KEY", "")  # Pega tu key o ingresala cuando lo pida
if not ROBOFLOW_API_KEY:
    ROBOFLOW_API_KEY = getpass("Ingresa tu ROBOFLOW_API_KEY: ").strip()

RF_WORKSPACE = "luzmilas-workspace"
RF_PROYECTO_M2 = "m2-qfggl"
RF_VERSION_M2 = 1   # Cambia si generas una version 2, 3, etc. en Roboflow
RF_PROYECTO_M3 = "m3-r2mey"
RF_VERSION_M3 = 1

# Epocas de entrenamiento (80-100 recomendado en GPU T4)
EPOCHS_M2 = 80
EPOCHS_M3 = 100
AGREGAR_APOYO_EXTRA = True  # True = suma imagenes de refuerzo auto-etiquetadas con YOLOE

# Montar Google Drive para guardar los pesos finales m2_seg.pt y m3_pose.pt
USAR_DRIVE = True
PESOS_DRIVE = Path("/content/drive/MyDrive/peaje_modelos")
if USAR_DRIVE:
    try:
        from google.colab import drive
        drive.mount("/content/drive")
        PESOS_DRIVE.mkdir(parents=True, exist_ok=True)
    except Exception as e:
        print("Drive no montado (se guardara en /content/pesos_entrenados):", e)
        PESOS_DRIVE = Path("/content/pesos_entrenados")
        PESOS_DRIVE.mkdir(parents=True, exist_ok=True)

VENV_DIR = Path("/content/venv_train")
VENV_PY = VENV_DIR / "bin" / "python"
VENV_PIP = VENV_DIR / "bin" / "pip"

if not VENV_PY.exists():
    py_ver = f"{sys.version_info.major}.{sys.version_info.minor}"
    subprocess.run(["bash", "-c", f"apt-get -qq update && apt-get -qq install -y python{py_ver}-venv python3-venv >/dev/null 2>&1"], check=False)
    if subprocess.run([sys.executable, "-m", "venv", str(VENV_DIR)]).returncode != 0:
        subprocess.run([sys.executable, "-m", "venv", "--without-pip", str(VENV_DIR)], check=True)
        subprocess.run(["bash", "-c", f"curl -sS https://bootstrap.pypa.io/get-pip.py | '{VENV_PY}'"], check=True)
    subprocess.run([str(VENV_PIP), "install", "-q", "--upgrade", "pip"], check=True)
    subprocess.run([
        str(VENV_PIP), "install", "-q",
        "ultralytics>=8.3.0", "roboflow>=1.1.49", "opencv-python-headless>=4.11.0", "PyYAML>=6.0.2"
    ], check=True)
    subprocess.run([str(VENV_PIP), "install", "-q", "git+https://github.com/ultralytics/CLIP.git"], check=False)

print("Entorno venv listo:", VENV_PY)
print("Carpeta destino de pesos:", PESOS_DRIVE)
"""),

    md("""
## 2. Descarga desde Roboflow + Normalización de Clases + Imágenes de Apoyo

Esta celda:
1. Descarga `m2-qfggl` (segmentación) y `m3-r2mey` (keypoints 1 punto `rueda`) desde Roboflow.
2. Si tu proyecto aún no tiene una versión generada en Roboflow, te avisa cómo darle a **Generate → Create Version**.
3. Re-mapea automáticamente los IDs de clase de `m2-qfggl` al orden canónico (`0: motocicleta, 1: automovil, 2: minibus, 3: camion, 4: bus`) sin importar el orden en que Roboflow los exportó.
4. Asegura que `m3-r2mey` tenga `kpt_shape: [1, 3]` y clase `0: rueda`.
5. Si `AGREGAR_APOYO_EXTRA = True`, añade imágenes adicionales de apoyo (y cualquier foto extra que pongas en `/content/drive/MyDrive/peaje_datasets/raw_vehiculos` o `raw_laterales`) auto-etiquetadas con **YOLOE** para reforzar las clases con menos ejemplos.
"""),
    code(r"""
script_prep = Path("/content/preparar_datasets.py")
script_prep.write_text(r'''
import os, re, shutil, urllib.request
from pathlib import Path
import cv2
import numpy as np
import yaml
from roboflow import Roboflow

API_KEY = os.environ["RF_KEY"]
WS = os.environ["RF_WS"]
P_M2, V_M2 = os.environ["RF_P_M2"], int(os.environ["RF_V_M2"])
P_M3, V_M3 = os.environ["RF_P_M3"], int(os.environ["RF_V_M3"])
APOYO = os.environ.get("AGREGAR_APOYO", "1") == "1"

WORK = Path("/content/datasets")
WORK.mkdir(parents=True, exist_ok=True)

CLASES_M2 = ["motocicleta", "automovil", "minibus", "camion", "bus"]
SINONIMOS_M2 = {
    "motocicleta": 0, "moto": 0, "motorcycle": 0, "motorbike": 0,
    "automovil": 1, "auto": 1, "car": 1, "sedan": 1, "suv": 1, "camioneta": 1, "pickup": 1,
    "minibus": 2, "minubus": 2, "mini bus": 2, "van": 2, "minivan": 2, "micro": 2, "combi": 2, "trufi": 2,
    "camion": 3, "truck": 3, "cargo truck": 3,
    "bus": 4, "autobus": 4, "passenger bus": 4, "flota": 4,
}

def descargar_rf(rf, ws_name, proj_name, ver_num, fmt, dest: Path) -> Path:
    proj = rf.workspace(ws_name).project(proj_name)
    versions = proj.versions()
    ver = None
    if versions:
        for v in versions:
            if int(getattr(v, "version", 0)) == ver_num:
                ver = v
                break
        if ver is None:
            ver = versions[0]
            print(f"Usando version disponible v{ver.version} de {proj_name}")
    else:
        print(f"AVISO: '{proj_name}' aun no tiene ninguna version generada en Roboflow. Generando v1 automaticamente...")
        try:
            gen_v = proj.generate_version(settings={
                "augmentation": {"flip": {"horizontal": True, "vertical": False}},
                "preprocessing": {"auto-orient": True, "resize": {"width": 640, "height": 640, "format": "Stretch to"}},
            })
            ver = proj.version(int(gen_v))
        except Exception as e:
            raise RuntimeError(
                f"El proyecto '{proj_name}' no tiene una Version generada en Roboflow. "
                f"Entra a https://app.roboflow.com/{ws_name}/{proj_name}/generate y haz clic en 'Create' -> 'Generate' (Version 1), luego vuelve a ejecutar esta celda. Detalle: {e}"
            )
    for formato in (fmt, "yolov8", "yolov11"):
        try:
            ds = ver.download(formato, location=str(dest), overwrite=True)
            return Path(ds.location)
        except Exception as e_dl:
            ult_err = e_dl
    raise RuntimeError(f"No se pudo descargar {proj_name}: {ult_err}")

def normalizar_m2(ruta_ds: Path):
    yml = ruta_ds / "data.yaml"
    cfg = yaml.safe_load(yml.read_text(encoding="utf-8"))
    raw_names = cfg.get("names", [])
    if isinstance(raw_names, dict):
        id_a_nombre = {int(k): str(v).lower().strip() for k, v in raw_names.items()}
    else:
        id_a_nombre = {i: str(v).lower().strip() for i, v in enumerate(raw_names)}

    mapa_id = {}
    for old_id, nombre in id_a_nombre.items():
        if nombre in SINONIMOS_M2:
            mapa_id[old_id] = SINONIMOS_M2[nombre]
        else:
            for k, target_id in SINONIMOS_M2.items():
                if k in nombre:
                    mapa_id[old_id] = target_id
                    break
    print("M2 clases en Roboflow:", id_a_nombre, "-> mapeo a [0..4]:", mapa_id)

    for lbl in ruta_ds.rglob("labels/*.txt"):
        nuevas = []
        for linea in lbl.read_text(encoding="utf-8").splitlines():
            partes = linea.strip().split()
            if len(partes) < 7:
                continue
            old_c = int(float(partes[0]))
            if old_c not in mapa_id:
                continue
            nuevas.append(f"{mapa_id[old_c]} " + " ".join(partes[1:]))
        lbl.write_text("\n".join(nuevas), encoding="utf-8")

    train_dir = "train/images" if (ruta_ds / "train" / "images").exists() else "images/train"
    val_dir = "valid/images" if (ruta_ds / "valid" / "images").exists() else ("val/images" if (ruta_ds / "val" / "images").exists() else train_dir)
    nuevo_cfg = {
        "path": str(ruta_ds.resolve()),
        "train": train_dir,
        "val": val_dir,
        "names": {i: c for i, c in enumerate(CLASES_M2)},
    }
    if (ruta_ds / "test" / "images").exists():
        nuevo_cfg["test"] = "test/images"
    yml.write_text(yaml.safe_dump(nuevo_cfg, sort_keys=False, allow_unicode=True), encoding="utf-8")
    return nuevo_cfg

def normalizar_m3(ruta_ds: Path):
    yml = ruta_ds / "data.yaml"
    for lbl in ruta_ds.rglob("labels/*.txt"):
        nuevas = []
        for linea in lbl.read_text(encoding="utf-8").splitlines():
            p = linea.strip().split()
            if len(p) < 7:
                continue
            xc, yc, bw, bh = float(p[1]), float(p[2]), float(p[3]), float(p[4])
            kx, ky = float(p[5]), float(p[6])
            v = int(float(p[7])) if len(p) >= 8 else 2
            nuevas.append(f"0 {xc:.6f} {yc:.6f} {bw:.6f} {bh:.6f} {kx:.6f} {ky:.6f} {v}")
        lbl.write_text("\n".join(nuevas), encoding="utf-8")

    train_dir = "train/images" if (ruta_ds / "train" / "images").exists() else "images/train"
    val_dir = "valid/images" if (ruta_ds / "valid" / "images").exists() else ("val/images" if (ruta_ds / "val" / "images").exists() else train_dir)
    nuevo_cfg = {
        "path": str(ruta_ds.resolve()),
        "train": train_dir,
        "val": val_dir,
        "kpt_shape": [1, 3],
        "flip_idx": [0],
        "names": {0: "rueda"},
    }
    if (ruta_ds / "test" / "images").exists():
        nuevo_cfg["test"] = "test/images"
    yml.write_text(yaml.safe_dump(nuevo_cfg, sort_keys=False, allow_unicode=True), encoding="utf-8")
    return nuevo_cfg

def agregar_apoyo_yoloe(ds_m2: Path, cfg_m2: dict, ds_m3: Path, cfg_m3: dict):
    from ultralytics import YOLOE
    apoyo_dir = WORK / "apoyo_raw"
    apoyo_dir.mkdir(parents=True, exist_ok=True)
    urls_apoyo = [
        ("bus_ultralytics.jpg", "https://ultralytics.com/images/bus.jpg"),
    ]
    for nombre, u in urls_apoyo:
        dest = apoyo_dir / nombre
        if not dest.exists():
            try:
                urllib.request.urlretrieve(u, dest)
            except Exception:
                pass

    # Si el usuario subio fotos extra a Drive, tambien las sumamos como apoyo
    for extra_dir in [Path("/content/drive/MyDrive/peaje_datasets/raw_vehiculos"), Path("/content/drive/MyDrive/peaje_datasets/raw_laterales")]:
        if extra_dir.exists():
            for p in extra_dir.glob("*"):
                if p.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp"):
                    shutil.copy2(p, apoyo_dir / f"drive_{p.name}")

    imgs = sorted([p for p in apoyo_dir.glob("*") if p.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp")])
    if not imgs:
        return

    # Auto-label M2 en imagenes de apoyo
    tr_img_m2 = ds_m2 / cfg_m2["train"]
    tr_lbl_m2 = tr_img_m2.parent / "labels"
    tr_img_m2.mkdir(parents=True, exist_ok=True); tr_lbl_m2.mkdir(parents=True, exist_ok=True)

    prompts_m2 = ["motorcycle", "car", "minibus", "truck", "bus"]
    map_p = {"motorcycle": 0, "car": 1, "minibus": 2, "truck": 3, "bus": 4}
    m_v = YOLOE("yoloe-11s-seg.pt")
    m_v.set_classes(prompts_m2, m_v.get_text_pe(prompts_m2))

    # Auto-label M3 (1 keypoint centro de rueda) en imagenes de apoyo
    tr_img_m3 = ds_m3 / cfg_m3["train"]
    tr_lbl_m3 = tr_img_m3.parent / "labels"
    tr_img_m3.mkdir(parents=True, exist_ok=True); tr_lbl_m3.mkdir(parents=True, exist_ok=True)

    m_w = YOLOE("yoloe-11s-seg.pt")
    m_w.set_classes(["wheel", "tire"], m_w.get_text_pe(["wheel", "tire"]))

    agregadas_m2, agregadas_m3 = 0, 0
    for ruta in imgs:
        im = cv2.imread(str(ruta))
        if im is None:
            continue
        h, w = im.shape[:2]
        rv = m_v.predict(im, conf=0.30, verbose=False)[0]
        if rv.boxes is not None and rv.masks is not None and len(rv.boxes) > 0:
            lineas = []
            for i, b in enumerate(rv.boxes):
                cname = str(rv.names[int(b.cls)]).lower()
                if cname in map_p and i < len(rv.masks.xyn) and len(rv.masks.xyn[i]) >= 3:
                    coords = " ".join(f"{x:.6f} {y:.6f}" for x, y in rv.masks.xyn[i])
                    lineas.append(f"{map_p[cname]} {coords}")
            if lineas:
                shutil.copy2(ruta, tr_img_m2 / f"apoyo_{ruta.name}")
                (tr_lbl_m2 / f"apoyo_{ruta.stem}.txt").write_text("\n".join(lineas), encoding="utf-8")
                agregadas_m2 += 1

        rw = m_w.predict(im, conf=0.20, imgsz=1024, verbose=False)[0]
        if rw.boxes is not None and len(rw.boxes) > 0:
            lineas_w = []
            for i, b in enumerate(rw.boxes):
                x1, y1, x2, y2 = b.xyxy[0].tolist()
                xc, yc, bw, bh = ((x1 + x2) / 2) / w, ((y1 + y2) / 2) / h, (x2 - x1) / w, (y2 - y1) / h
                if rw.masks is not None and i < len(rw.masks.xy) and len(rw.masks.xy[i]) >= 3:
                    pts = np.asarray(rw.masks.xy[i], dtype=np.float32)
                    kx, ky = float(pts[:, 0].mean()) / w, float(pts[:, 1].mean()) / h
                else:
                    kx, ky = xc, yc
                lineas_w.append(f"0 {xc:.6f} {yc:.6f} {bw:.6f} {bh:.6f} {kx:.6f} {ky:.6f} 2")
            if lineas_w:
                shutil.copy2(ruta, tr_img_m3 / f"apoyo_{ruta.name}")
                (tr_lbl_m3 / f"apoyo_{ruta.stem}.txt").write_text("\n".join(lineas_w), encoding="utf-8")
                agregadas_m3 += 1

    print(f"Imagenes de apoyo sumadas -> M2: +{agregadas_m2}, M3: +{agregadas_m3}")

if __name__ == "__main__":
    rf = Roboflow(api_key=API_KEY)
    ds_m2 = descargar_rf(rf, WS, P_M2, V_M2, "yolov11", WORK / "m2_roboflow")
    cfg_m2 = normalizar_m2(ds_m2)

    ds_m3 = descargar_rf(rf, WS, P_M3, V_M3, "yolov11", WORK / "m3_roboflow")
    cfg_m3 = normalizar_m3(ds_m3)

    if APOYO:
        try:
            agregar_apoyo_yoloe(ds_m2, cfg_m2, ds_m3, cfg_m3)
        except Exception as e_ap:
            print("AVISO: Se omitio el auto-etiquetado extra YOLOE (se entrenara 100% con tus datasets de Roboflow):", e_ap)

    n_m2 = len(list(ds_m2.rglob("images/*.jpg"))) + len(list(ds_m2.rglob("images/*.png")))
    n_m3 = len(list(ds_m3.rglob("images/*.jpg"))) + len(list(ds_m3.rglob("images/*.png")))
    print(f"Datasets listos -> M2: {n_m2} imgs ({ds_m2}/data.yaml) | M3: {n_m3} imgs ({ds_m3}/data.yaml)")
''', encoding="utf-8")

os.environ.update({
    "RF_KEY": ROBOFLOW_API_KEY,
    "RF_WS": RF_WORKSPACE,
    "RF_P_M2": RF_PROYECTO_M2,
    "RF_V_M2": str(RF_VERSION_M2),
    "RF_P_M3": RF_PROYECTO_M3,
    "RF_V_M3": str(RF_VERSION_M3),
    "AGREGAR_APOYO": "1" if AGREGAR_APOYO_EXTRA else "0",
})
!{VENV_PY} "{script_prep}"
"""),

    md("## 3. Entrenamiento de M2 (`yolo11s-seg.pt` → `m2_seg.pt`) y M3 (`yolo11s-pose.pt` → `m3_pose.pt`) + Métricas para el Informe"),
    code(r"""
import json, os, shutil
from pathlib import Path

os.environ["MPLBACKEND"] = "Agg"  # Evita error de matplotlib_inline dentro del venv aislado

DS_M2 = Path("/content/datasets/m2_roboflow/data.yaml")
DS_M3 = Path("/content/datasets/m3_roboflow/data.yaml")
YOLO_CLI = VENV_DIR / "bin" / "yolo"

if DS_M2.is_file():
    print("=== Entrenando M2 (Segmentacion de vehiculos) ===")
    !MPLBACKEND=Agg {YOLO_CLI} segment train model=yolo11s-seg.pt data="{DS_M2}" epochs={EPOCHS_M2} imgsz=640 batch=16 patience=20 fliplr=0.5 hsv_v=0.4 mosaic=1.0 project=/content/runs name=m2_vehiculos exist_ok=True
    best_m2 = Path("/content/runs/m2_vehiculos/weights/best.pt")
    if best_m2.is_file():
        shutil.copy2(best_m2, PESOS_DRIVE / "m2_seg.pt")
        print("Peso M2 guardado en:", PESOS_DRIVE / "m2_seg.pt")

if DS_M3.is_file():
    print("=== Entrenando M3 (Keypoints de ruedas - 1 punto centro) ===")
    !MPLBACKEND=Agg {YOLO_CLI} pose train model=yolo11s-pose.pt data="{DS_M3}" epochs={EPOCHS_M3} imgsz=640 batch=16 patience=25 fliplr=0.5 hsv_v=0.4 mosaic=1.0 project=/content/runs name=m3_ruedas exist_ok=True
    best_m3 = Path("/content/runs/m3_ruedas/weights/best.pt")
    if best_m3.is_file():
        shutil.copy2(best_m3, PESOS_DRIVE / "m3_pose.pt")
        print("Peso M3 guardado en:", PESOS_DRIVE / "m3_pose.pt")

# Extraer resumen de metricas (mAP50 y mAP50-95) para el Informe Tecnico PDF
for nombre_run in ("m2_vehiculos", "m3_ruedas"):
    csv_path = Path(f"/content/runs/{nombre_run}/results.csv")
    if csv_path.is_file():
        lineas = [l.strip() for l in csv_path.read_text(encoding="utf-8").splitlines() if l.strip()]
        if len(lineas) >= 2:
            headers = [h.strip() for h in lineas[0].split(",")]
            ult = [v.strip() for v in lineas[-1].split(",")]
            resumen = dict(zip(headers, ult))
            print(f"\n--- METRICAS FINALES {nombre_run} (copiar al Informe Tecnico) ---")
            for k, v in resumen.items():
                if any(m in k for m in ("mAP50", "precision", "recall", "epoch")):
                    print(f"  {k}: {v}")
"""),

    md("## 4. Descargar `m2_seg.pt` y `m3_pose.pt` a tu computadora (opcional)"),
    code("""
from google.colab import files

for p in ("m2_seg.pt", "m3_pose.pt"):
    archivo = PESOS_DRIVE / p
    if archivo.is_file():
        print("Descargando:", archivo)
        files.download(str(archivo))
"""),
]

p1 = guardar("01_peaje_inferencia_unificada.ipynb", nb1)
p2 = guardar("02_entrenamiento_m2_m3_roboflow.ipynb", nb2)
p2_compat = guardar("02_entrenamiento_m2_m3_sin_roboflow.ipynb", nb2)
print("Notebooks generados:", p1, "y", p2)
