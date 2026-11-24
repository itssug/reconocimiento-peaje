"""Prueba unificada M1 (placas+OCR) + M2 (vehiculo) + M3 (ejes) + M4 (pasajeros) sobre una imagen.

Uso:
  python scripts/demo_unificada.py ruta/imagen.jpg ruta/salida.jpg
"""
from __future__ import annotations

import json
import os
import sys
import time
from pathlib import Path

import cv2
import numpy as np

RAIZ = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RAIZ))

from app.config import Config
from app.modelos.base import Contexto
from app.modelos.m1_placas import ModeloPlacas
from app.modelos.m2_vehiculos import ModeloVehiculos
from app.modelos.m3_ejes import ModeloEjes
from app.modelos.m4_pasajeros import ModeloPasajeros


def main() -> int:
    if len(sys.argv) < 3:
        print("Uso: python scripts/demo_unificada.py <imagen_entrada> <imagen_salida>")
        return 2

    img_path = Path(sys.argv[1])
    out_path = Path(sys.argv[2])
    cfg = Config.desde_entorno()
    zs = os.environ.get("USE_ZEROSHOT_FALLBACK", "1") == "1"

    m1 = ModeloPlacas(cfg.dir_modelos, cfg.dispositivo, cfg.umbral_placa)
    m2 = ModeloVehiculos(cfg.modelo_m2, cfg.dispositivo, cfg.mapeo_m2_json, fallback_zeroshot=zs)
    m3 = ModeloEjes(cfg.modelo_m3, cfg.dispositivo, fallback_zeroshot=zs)
    m4 = ModeloPasajeros(cfg.modelo_m4, cfg.dispositivo, fallback_zeroshot=zs)

    for m in (m1, m2, m3, m4):
        m.cargar()
        print(f"[{m.clave}] {m.estado} -> {m.detalle}")

    img = cv2.imread(str(img_path))
    if img is None:
        print(f"ERROR: No se pudo leer la imagen {img_path}")
        return 1

    t0 = time.perf_counter()
    ctx = Contexto()
    r1 = m1.inferir(img, ctx) if m1.disponible else None
    r2 = m2.inferir(img, ctx) if m2.disponible else None
    r3 = m3.inferir(img, ctx) if m3.disponible else None
    r4 = m4.inferir(img, ctx) if m4.disponible else None
    lat = int((time.perf_counter() - t0) * 1000)

    res = {
        "success": True,
        "inference": {"placa": r1, "vehiculo": r2, "ejes": r3, "pasajeros": r4},
        "modelos": {m.clave: m.estado for m in (m1, m2, m3, m4)},
        "latencia_ms": lat,
    }
    print("\n=== JSON DEL CONTRATO ===")
    print(json.dumps(res, indent=2, ensure_ascii=False))

    vis = img.copy()
    if ctx.vehiculo_poligono is not None:
        overlay = vis.copy()
        cv2.fillPoly(overlay, [np.round(ctx.vehiculo_poligono).astype(np.int32)], (255, 180, 0))
        vis = cv2.addWeighted(overlay, 0.25, vis, 0.75, 0)
    if ctx.vehiculo_caja is not None:
        x1, y1, x2, y2 = [int(v) for v in ctx.vehiculo_caja]
        cv2.rectangle(vis, (x1, y1), (x2, y2), (255, 160, 0), 3)
    if r1 and r1.get("bbox"):
        x1, y1, x2, y2 = r1["bbox"]
        cv2.rectangle(vis, (x1, y1), (x2, y2), (0, 220, 0), 3)
        cv2.putText(vis, r1["texto"], (x1, max(28, y1 - 8)), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (0, 220, 0), 2)

    etiqueta = (
        f"Placa: {r1['texto'] if r1 else 'N/A'} | "
        f"Cat: {r2['categoria'] if r2 else 'N/A'} | "
        f"Ejes: {r3['cantidad'] if r3 else 'N/A'} | "
        f"Pasajeros: {r4['cantidad'] if r4 else 'N/A'} | {lat} ms"
    )
    cv2.rectangle(vis, (0, 0), (vis.shape[1], 42), (20, 20, 20), -1)
    cv2.putText(vis, etiqueta, (10, 28), cv2.FONT_HERSHEY_SIMPLEX, 0.65, (255, 255, 255), 2)
    cv2.imwrite(str(out_path), vis)
    print(f"Imagen anotada guardada en: {out_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
