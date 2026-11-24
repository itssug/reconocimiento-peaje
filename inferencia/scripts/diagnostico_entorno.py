"""Diagnostico del entorno: Python, SO, onnxruntime, opencv, numpy, torch/CUDA/ultralytics y modelos.

Uso:  python scripts/diagnostico_entorno.py [--json] [--salida archivo]
"""
from __future__ import annotations

import argparse
import hashlib
import json
import platform
import subprocess
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
NO = "no instalado"


def _version(modulo: str):
    try:
        m = __import__(modulo)
        return getattr(m, "__version__", "desconocida")
    except Exception:  # noqa: BLE001
        return NO


def recolectar() -> dict:
    d: dict = {
        "python": sys.version.split()[0],
        "ejecutable": sys.executable,
        "en_venv": sys.prefix != getattr(sys, "base_prefix", sys.prefix),
        "so": platform.platform(),
        "arquitectura": platform.machine(),
    }
    try:
        import onnxruntime as ort

        d["onnxruntime"] = {"version": ort.__version__, "providers": ort.get_available_providers(), "dispositivo": ort.get_device()}
    except Exception:  # noqa: BLE001
        d["onnxruntime"] = NO
    d["opencv"] = _version("cv2")
    d["numpy"] = _version("numpy")
    d["fastapi"] = _version("fastapi")
    d["ultralytics"] = _version("ultralytics")
    try:
        import torch

        d["torch"] = {
            "version": torch.__version__,
            "cuda_disponible": bool(torch.cuda.is_available()),
            "cuda_version": torch.version.cuda,
            "gpu": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
        }
    except Exception:  # noqa: BLE001
        d["torch"] = NO
    try:
        out = subprocess.run(["nvidia-smi", "--query-gpu=name,driver_version,memory.total", "--format=csv,noheader"],
                             capture_output=True, text=True, timeout=10)
        d["nvidia_smi"] = out.stdout.strip() if out.returncode == 0 and out.stdout.strip() else "sin GPU NVIDIA"
    except Exception:  # noqa: BLE001
        d["nvidia_smi"] = "sin GPU NVIDIA"

    modelos = {}
    dir_modelos = RAIZ / "modelos"
    esperados = {
        "m1_detector": dir_modelos / "yolo-v9-t-384-license-plates-end2end.onnx",
        "m1_ocr": dir_modelos / "cct_xs_v2_global.onnx",
        "m1_ocr_config": dir_modelos / "cct_xs_v2_global_plate_config.yaml",
        "m2": dir_modelos / "m2_seg.pt",
        "m3": dir_modelos / "m3_pose.pt",
        "m4": dir_modelos / "m4_personas.pt",
    }
    for k, ruta in esperados.items():
        if ruta.is_file():
            h = hashlib.sha256(ruta.read_bytes()).hexdigest()[:16]
            modelos[k] = {"estado": "presente", "archivo": ruta.name, "bytes": ruta.stat().st_size, "sha256_16": h}
        else:
            modelos[k] = {"estado": "no_disponible", "archivo": ruta.name}
    d["modelos"] = modelos
    return d


def como_texto(d: dict) -> str:
    L = [
        "=== Diagnostico del entorno ===",
        f"Python        : {d['python']} ({'venv' if d['en_venv'] else 'sin venv'})",
        f"Ejecutable    : {d['ejecutable']}",
        f"Sistema       : {d['so']} [{d['arquitectura']}]",
    ]
    o = d["onnxruntime"]
    L.append(f"onnxruntime   : {o if isinstance(o, str) else o['version'] + ' providers=' + ', '.join(o['providers'])}")
    L += [f"opencv        : {d['opencv']}", f"numpy         : {d['numpy']}", f"fastapi       : {d['fastapi']}",
          f"ultralytics   : {d['ultralytics']}"]
    t = d["torch"]
    if isinstance(t, str):
        L.append(f"torch         : {t}")
    else:
        L.append(f"torch         : {t['version']} | CUDA disponible: {t['cuda_disponible']} (cuda {t['cuda_version']}) | GPU: {t['gpu']}")
    L.append(f"nvidia-smi    : {d['nvidia_smi']}")
    L.append("Modelos:")
    for k, m in d["modelos"].items():
        extra = f" ({m['bytes']} bytes, sha256={m['sha256_16']}...)" if m["estado"] == "presente" else ""
        L.append(f"  {k:14s}: {m['estado']} - {m['archivo']}{extra}")
    return "\n".join(L)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--json", action="store_true", help="salida en JSON")
    ap.add_argument("--salida", help="ademas guarda la salida en este archivo")
    a = ap.parse_args()
    d = recolectar()
    texto = json.dumps(d, indent=2, ensure_ascii=False) if a.json else como_texto(d)
    print(texto)
    if a.salida:
        Path(a.salida).parent.mkdir(parents=True, exist_ok=True)
        Path(a.salida).write_text(texto + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
