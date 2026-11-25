"""M2 - Vehiculos: YOLO-seg que clasifica motocicleta | automovil | minibus | camion | bus.

Disponible solo si existe el peso (``MODELO_M2``, por defecto ``modelos/m2_seg.pt``) y ``ultralytics`` esta instalado.
Deja en el ``Contexto`` la caja y el poligono del vehiculo para M3 y M4.
"""
from __future__ import annotations

import json
import logging
from pathlib import Path
from typing import Optional

import numpy as np

from .base import Contexto, ModeloUltralytics

log = logging.getLogger("inferencia.m2")

CATEGORIAS = ("motocicleta", "automovil", "minibus", "camion", "bus")

# Nombre de clase del modelo (minusculas) -> categoria del contrato. Configurable con MAPEO_M2 (JSON).
MAPEO_POR_DEFECTO = {
    "motocicleta": "motocicleta", "motorcycle": "motocicleta", "moto": "motocicleta", "motorbike": "motocicleta",
    "automovil": "automovil", "auto": "automovil", "car": "automovil", "sedan": "automovil", "sedan car": "automovil",
    "suv": "automovil", "suv pickup": "automovil", "camioneta": "automovil", "pickup": "automovil",
    "minibus": "minibus", "minubus": "minibus", "minibus van": "minibus", "van": "minibus", "minivan": "minibus", "micro": "minibus",
    "camion": "camion", "truck": "camion", "cargo truck": "camion",
    "bus": "bus", "autobus": "bus", "passenger bus": "bus",
}


class ModeloVehiculos(ModeloUltralytics):
    clave = "m2"
    nombre = "vehiculos"
    PROMPTS_ZS = ["motorcycle", "sedan car", "suv pickup", "minibus van", "cargo truck", "passenger bus"]

    def __init__(self, ruta_peso: Path, dispositivo: str = "auto", mapeo_json: str = "", conf: float = 0.25, fallback_zeroshot: bool = False) -> None:
        super().__init__(ruta_peso, dispositivo)
        self.conf = conf
        self.fallback_zeroshot = fallback_zeroshot
        self._tracker_rapido = None
        self._es_coco_rapido = False
        self.mapeo = dict(MAPEO_POR_DEFECTO)
        if mapeo_json:
            try:
                self.mapeo.update({str(k).lower(): v for k, v in json.loads(mapeo_json).items()})
            except (ValueError, AttributeError):
                log.warning("MAPEO_M2 no es un JSON valido; se usa el mapeo por defecto")

    def cargar(self) -> None:
        super().cargar()
        if self.disponible or not self.fallback_zeroshot:
            return
        try:
            from ultralytics import YOLOE, YOLO
            self.device = self._elegir_dispositivo()
            peso_zs = "yoloe-11m-seg.pt" if self.device != "cpu" else "yoloe-11s-seg.pt"
            try:
                m = YOLOE(peso_zs)
                m.set_classes(self.PROMPTS_ZS, m.get_text_pe(self.PROMPTS_ZS))
                self.modelo = m
                self.detalle = f"zero-shot {peso_zs} en {self.device}"
            except Exception:
                self.modelo = YOLO("yolo11n-seg.pt")
                self.detalle = f"fallback yolo11n-seg en {self.device}"
            from .base import OK
            self.estado = OK
        except Exception as e:
            self.detalle = f"fallback M2 no disponible: {e}"

    def categoria(self, nombre_clase: str) -> Optional[str]:
        c = self.mapeo.get(nombre_clase.lower())
        return c if c in CATEGORIAS else None

    def inferir(self, imagen_bgr: np.ndarray, ctx: Contexto) -> Optional[dict]:
        r = self.modelo.predict(imagen_bgr, conf=self.conf, device=self.device, verbose=False)[0]
        if r.boxes is None or len(r.boxes) == 0:
            return None
        nombres = r.names
        pcx = (ctx.placa_bbox[0] + ctx.placa_bbox[2]) / 2.0 if ctx.placa_bbox else None
        pcy = (ctx.placa_bbox[1] + ctx.placa_bbox[3]) / 2.0 if ctx.placa_bbox else None

        mejor, puntaje = None, -1.0
        for i, caja in enumerate(r.boxes):
            cat = self.categoria(str(nombres[int(caja.cls)]))
            if cat is None:
                continue
            x1, y1, x2, y2 = caja.xyxy[0].tolist()
            conf = float(caja.conf)
            area = max(1.0, (x2 - x1) * (y2 - y1))
            # Si la placa detectada por M1 cae dentro de este vehiculo, priorizarlo fuertemente
            contiene_placa = (
                pcx is not None and pcy is not None
                and x1 - 15 <= pcx <= x2 + 15 and y1 - 15 <= pcy <= y2 + 15
            )
            p = (10.0 if contiene_placa else 1.0) * conf * area
            if p > puntaje:
                puntaje, mejor = p, (i, cat, conf, (x1, y1, x2, y2))
        if mejor is None:
            return None
        i, cat, conf, caja = mejor
        ctx.vehiculo_caja = caja
        if r.masks is not None and len(r.masks.xy) > i and len(r.masks.xy[i]) >= 3:
            ctx.vehiculo_poligono = np.asarray(r.masks.xy[i], dtype=np.float32)
        return {"categoria": cat, "confianza": round(conf, 4)}

    def rastrear(self, imagen_bgr: np.ndarray, imgsz: int = 320) -> list[dict]:
        """Tracking multi-vehiculo en tiempo real (usa m2_seg.pt o YOLOE con clase minibus + ByteTrack + FP16)."""
        half = self.device != "cpu"
        kwargs = {
            "conf": self.conf,
            "device": self.device,
            "imgsz": imgsz,
            "half": half,
            "verbose": False,
        }
        try:
            r = self.modelo.track(imagen_bgr, persist=True, tracker="bytetrack.yaml", **kwargs)[0]
        except Exception:
            r = self.modelo.predict(imagen_bgr, **kwargs)[0]
        if r.boxes is None or len(r.boxes) == 0:
            return []
        nombres = r.names
        ids = r.boxes.id.int().cpu().tolist() if r.boxes.id is not None else list(range(1, len(r.boxes) + 1))
        out = []
        for i, caja in enumerate(r.boxes):
            cat = self.categoria(str(nombres[int(caja.cls)]))
            if cat is None:
                continue
            x1, y1, x2, y2 = caja.xyxy[0].tolist()
            poly = (
                np.asarray(r.masks.xy[i], dtype=np.float32)
                if (r.masks is not None and len(r.masks.xy) > i and len(r.masks.xy[i]) >= 3)
                else None
            )
            out.append({
                "track_id": int(ids[i]),
                "categoria": cat,
                "confianza": round(float(caja.conf), 4),
                "box": (x1, y1, x2, y2),
                "poligono": poly,
            })
        return out
