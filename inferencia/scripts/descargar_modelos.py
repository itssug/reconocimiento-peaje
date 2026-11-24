"""Descarga los modelos de M1 (placas) en ``modelos/`` y verifica su SHA-256.

Solo usa la biblioteca estandar; funciona igual en Windows, Linux y Colab.
Uso:  python scripts/descargar_modelos.py [--forzar]
"""
from __future__ import annotations

import argparse
import hashlib
import sys
import urllib.request
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
DESTINO = RAIZ / "modelos"

MODELOS = [
    (
        "yolo-v9-t-384-license-plates-end2end.onnx",
        "https://github.com/ankandrew/open-image-models/releases/download/assets/yolo-v9-t-384-license-plates-end2end.onnx",
        "888397b96d761c89db40bc9c305838e8652660f5e282c2cadebbe8d2951a77a8",
    ),
    (
        "cct_xs_v2_global.onnx",
        "https://github.com/ankandrew/cnn-ocr-lp/releases/download/arg-plates/cct_xs_v2_global.onnx",
        "8031afb5fdc6b4d80462c9d542f1284ebd2cfddf5dbacd62609848d7e2855f44",
    ),
    (
        "cct_xs_v2_global_plate_config.yaml",
        "https://github.com/ankandrew/cnn-ocr-lp/releases/download/arg-plates/cct_xs_v2_global_plate_config.yaml",
        "0335c74a305173bb6f393efed0fde03cadeaa0b649ed8e19f431016d8232d0a6",
    ),
]


def sha256(ruta: Path) -> str:
    h = hashlib.sha256()
    with ruta.open("rb") as f:
        for bloque in iter(lambda: f.read(1 << 20), b""):
            h.update(bloque)
    return h.hexdigest()


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--forzar", action="store_true", help="volver a descargar aunque el hash coincida")
    args = ap.parse_args()
    DESTINO.mkdir(parents=True, exist_ok=True)
    errores = 0
    for nombre, url, esperado in MODELOS:
        ruta = DESTINO / nombre
        if ruta.exists() and not args.forzar and sha256(ruta) == esperado:
            print(f"[ok]    {nombre} (ya descargado, SHA-256 correcto)")
            continue
        print(f"[baja]  {nombre} <- {url}")
        tmp = ruta.with_suffix(ruta.suffix + ".part")
        try:
            with urllib.request.urlopen(url, timeout=120) as r, tmp.open("wb") as f:
                while True:
                    bloque = r.read(1 << 20)
                    if not bloque:
                        break
                    f.write(bloque)
        except Exception as e:  # noqa: BLE001
            print(f"[error] no se pudo descargar {nombre}: {e}")
            tmp.unlink(missing_ok=True)
            errores += 1
            continue
        obtenido = sha256(tmp)
        if obtenido != esperado:
            print(f"[error] SHA-256 distinto para {nombre}\n        esperado {esperado}\n        obtenido {obtenido}")
            tmp.unlink(missing_ok=True)
            errores += 1
            continue
        tmp.replace(ruta)
        print(f"[ok]    {nombre} SHA-256 verificado")
    return 1 if errores else 0


if __name__ == "__main__":
    sys.exit(main())
