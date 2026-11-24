"""M4 - Pasajeros: YOLO-seg (clase person) y conteo de personas dentro de la mascara del vehiculo.

Privacidad: solo se cuentan personas; no se guardan rostros, recortes ni mascaras. Todo queda en memoria.
"""
from __future__ import annotations

import logging
from typing import Optional

import cv2
import numpy as np

from .base import Contexto, ModeloUltralytics

log = logging.getLogger("inferencia.m4")

FRACCION_DENTRO = 0.45  # fraccion minima de la persona que debe caer dentro del vehiculo


class ModeloPasajeros(ModeloUltralytics):
    clave = "m4"
    nombre = "pasajeros"

    def __init__(self, ruta_peso, dispositivo: str = "auto", conf: float = 0.22, fallback_zeroshot: bool = False) -> None:
        super().__init__(ruta_peso, dispositivo)
        self.conf = conf
        self.fallback_zeroshot = fallback_zeroshot

    def cargar(self) -> None:
        super().cargar()
        if self.disponible or not self.fallback_zeroshot:
            return
        try:
            from ultralytics import YOLO
            from .base import OK
            self.device = self._elegir_dispositivo()
            peso = "yolo11m-seg.pt" if self.device != "cpu" else "yolo11n-seg.pt"
            self.modelo = YOLO(peso)
            self.estado = OK
            self.detalle = f"fallback {peso} (person) en {self.device}"
        except Exception as e:
            self.detalle = f"fallback M4 no disponible: {e}"

    def inferir(self, imagen_bgr: np.ndarray, ctx: Contexto) -> Optional[dict]:
        alto, ancho = imagen_bgr.shape[:2]
        # Si M2 detecto el vehiculo, recortamos la region del vehiculo para detectar mejor personas tras el parabrisas
        ox, oy = 0, 0
        entrada = imagen_bgr
        if ctx.vehiculo_caja is not None:
            vx1, vy1, vx2, vy2 = ctx.vehiculo_caja
            mw, mh = (vx2 - vx1) * 0.05, (vy2 - vy1) * 0.05
            ox, oy = max(0, int(vx1 - mw)), max(0, int(vy1 - mh))
            ex, ey = min(ancho, int(vx2 + mw)), min(alto, int(vy2 + mh))
            if ex - ox >= 40 and ey - oy >= 40:
                entrada = imagen_bgr[oy:ey, ox:ex]

        r = self.modelo.predict(entrada, conf=self.conf, device=self.device, verbose=False)[0]
        nombres = r.names
        # mascara del vehiculo: poligono de M2 > caja de M2 > imagen completa
        zona = np.zeros((alto, ancho), np.uint8)
        if ctx.vehiculo_poligono is not None:
            cv2.fillPoly(zona, [np.round(ctx.vehiculo_poligono).astype(np.int32)], 1)
        elif ctx.vehiculo_caja is not None:
            x1, y1, x2, y2 = (int(v) for v in ctx.vehiculo_caja)
            zona[max(0, y1):y2, max(0, x1):x2] = 1
        else:
            zona[:] = 1
        cantidad = 0
        if r.boxes is not None:
            for i, caja in enumerate(r.boxes):
                if str(nombres[int(caja.cls)]).lower() not in ("person", "persona"):
                    continue
                persona = np.zeros((alto, ancho), np.uint8)
                poly_full = None
                if r.masks is not None and len(r.masks.xy) > i and len(r.masks.xy[i]) >= 3:
                    poly_full = np.asarray(r.masks.xy[i], dtype=np.float32)
                    poly_full[:, 0] += ox
                    poly_full[:, 1] += oy
                    cv2.fillPoly(persona, [np.round(poly_full).astype(np.int32)], 1)
                else:
                    x1, y1, x2, y2 = (int(v) for v in caja.xyxy[0].tolist())
                    persona[max(0, y1 + oy):min(alto, y2 + oy), max(0, x1 + ox):min(ancho, x2 + ox)] = 1
                area = int(persona.sum())
                if area and int((persona & zona).sum()) / area >= FRACCION_DENTRO:
                    cantidad += 1
                    if poly_full is not None:
                        ctx.pasajeros_poligonos.append(poly_full)
        return {"cantidad": cantidad}
