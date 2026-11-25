"""M3 - Ejes: YOLO-pose de llantas (5 keypoints) y conteo de ejes.

Logica tomada del notebook de keypoints (celda ``analizar_ejes``): se cuentan las ruedas del lado cercano,
se agrupan por separacion en x (ruedas dobles = un solo eje) y en vistas frontal/posterior no es determinable.
Keypoints por llanta: 0 centro, 1 contacto, 2 superior, 3 izquierda, 4 derecha.
"""
from __future__ import annotations

import logging
from pathlib import Path
from typing import Optional

import numpy as np

from .base import Contexto, ModeloUltralytics

log = logging.getLogger("inferencia.m3")

UMBRAL_CONTENCION = 0.7  # una deteccion contenida en otra (p. ej. el aro dentro de la llanta) se descarta
UMBRAL_CERCANA = 0.65    # altura visible minima (respecto a la mayor) para considerar la llanta del lado cercano
SEPARACION_EJE = 0.6     # distancia minima en x entre ejes, en diametros
CONF_LLANTA = 0.15


def altura_visible(kps: np.ndarray) -> float:
    return max(1.0, float(kps[1, 1] - kps[2, 1]))  # contacto.y - superior.y


def contenida(a, b) -> float:
    x1, y1 = max(a[0], b[0]), max(a[1], b[1])
    x2, y2 = min(a[2], b[2]), min(a[3], b[3])
    inter = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    area = max(1e-6, (a[2] - a[0]) * (a[3] - a[1]))
    return inter / area


def limpiar_llantas(llantas: list[dict]) -> list[dict]:
    orden = sorted(llantas, key=lambda l: -(l["box"][2] - l["box"][0]) * (l["box"][3] - l["box"][1]))
    res: list[dict] = []
    for l in orden:
        if all(contenida(l["box"], r["box"]) < UMBRAL_CONTENCION for r in res):
            res.append(l)
    return res


def llantas_del_vehiculo(llantas: list[dict], caja_vehiculo) -> list[dict]:
    """Llantas cuyo centro esta dentro del vehiculo y en su parte baja."""
    x1, y1, x2, y2 = caja_vehiculo
    w, h = x2 - x1, y2 - y1
    out = []
    for l in llantas:
        cx, cy = l["kps"][0, :2]
        if x1 - 0.05 * w <= cx <= x2 + 0.05 * w and y1 + 0.35 * h <= cy <= y2 + 0.10 * h:
            out.append(l)
    return out


def analizar_ejes(caja_vehiculo, ls: list[dict]) -> tuple[Optional[int], Optional[float], str]:
    """Devuelve (ejes, confianza, vista). ejes=None si no es determinable."""
    if not ls:
        return None, None, "sin_llantas"
    hmax = max(altura_visible(l["kps"]) for l in ls)
    cercanas = [l for l in ls if altura_visible(l["kps"]) >= UMBRAL_CERCANA * hmax]
    x1, y1, x2, y2 = caja_vehiculo
    proporcion = (x2 - x1) / max(1.0, y2 - y1)
    if len(cercanas) == 2 and proporcion < 1.6:
        a, b = cercanas
        angostas = all((l["kps"][4, 0] - l["kps"][3, 0]) / altura_visible(l["kps"]) < 0.65 for l in cercanas)
        misma_altura = abs(a["kps"][1, 1] - b["kps"][1, 1]) < 0.15 * hmax
        if angostas and misma_altura:
            return None, None, "frontal_posterior"
    xs = sorted(float(l["kps"][0, 0]) for l in cercanas)
    diam = float(np.median([altura_visible(l["kps"]) for l in cercanas]))
    ejes = 1 + sum(1 for p, q in zip(xs, xs[1:]) if q - p > SEPARACION_EJE * diam)
    ejes = max(ejes, 2)  # minimo asumido: todo vehiculo de cuatro o mas ruedas tiene al menos 2 ejes
    conf = float(np.mean([l["conf"] for l in cercanas]))
    return ejes, conf, "lateral"


def kps_de_caja(box: list[float], kps: np.ndarray, conf: float) -> np.ndarray:
    """Completa a 5 keypoints [centro, contacto, superior, izq, der] si el modelo devuelve 1 keypoint (centro)."""
    if kps.shape[0] >= 5:
        return kps[:5].astype(np.float32)
    x1, y1, x2, y2 = [float(v) for v in box]
    cx, cy = (float(kps[0, 0]), float(kps[0, 1])) if kps.shape[0] >= 1 else ((x1 + x2) / 2.0, (y1 + y2) / 2.0)
    return np.array([
        [cx, cy, conf],
        [cx, y2, conf],
        [cx, y1, conf],
        [x1, cy, conf],
        [x2, cy, conf],
    ], dtype=np.float32)


def kps_de_mascara(poly: np.ndarray, conf: float) -> Optional[np.ndarray]:
    """Calcula los 5 keypoints geometricos de una rueda (centro, contacto, superior, izq, der) desde su poligono."""
    if poly is None or len(poly) < 3:
        return None
    pts = np.asarray(poly, dtype=np.float32)
    cx, cy = float(pts[:, 0].mean()), float(pts[:, 1].mean())
    bot = pts[int(np.argmax(pts[:, 1]))]
    top = pts[int(np.argmin(pts[:, 1]))]
    izq = pts[int(np.argmin(pts[:, 0]))]
    der = pts[int(np.argmax(pts[:, 0]))]
    return np.array([
        [cx, cy, conf],
        [float(bot[0]), float(bot[1]), conf],
        [float(top[0]), float(top[1]), conf],
        [float(izq[0]), float(izq[1]), conf],
        [float(der[0]), float(der[1]), conf],
    ], dtype=np.float32)


class ModeloEjes(ModeloUltralytics):
    clave = "m3"
    nombre = "ejes"
    PROMPTS_LLANTA = ["wheel", "tire"]

    def __init__(self, ruta_peso: Path, dispositivo: str = "auto", conf: float = CONF_LLANTA, fallback_zeroshot: bool = False) -> None:
        super().__init__(ruta_peso, dispositivo)
        self.conf = conf
        self.fallback_zeroshot = fallback_zeroshot
        self._modo_zeroshot = False

    def cargar(self) -> None:
        super().cargar()
        if self.disponible or not self.fallback_zeroshot:
            return
        try:
            from ultralytics import YOLOE
            from .base import OK
            self.device = self._elegir_dispositivo()
            peso_zs = "yoloe-11m-seg.pt" if self.device != "cpu" else "yoloe-11s-seg.pt"
            m = YOLOE(peso_zs)
            m.set_classes(self.PROMPTS_LLANTA, m.get_text_pe(self.PROMPTS_LLANTA))
            self.modelo = m
            self._modo_zeroshot = True
            self.estado = OK
            self.detalle = f"zero-shot {peso_zs} llantas en {self.device}"
        except Exception as e:
            self.detalle = f"fallback M3 no disponible: {e}"

    def inferir(self, imagen_bgr: np.ndarray, ctx: Contexto) -> Optional[dict]:
        alto, ancho = imagen_bgr.shape[:2]
        # Si M2 detecto el vehiculo, recortamos su region con margen para multiplicar la resolucion sobre las ruedas
        ox, oy = 0, 0
        entrada = imagen_bgr
        if ctx.vehiculo_caja is not None:
            vx1, vy1, vx2, vy2 = ctx.vehiculo_caja
            mw, mh = (vx2 - vx1) * 0.10, (vy2 - vy1) * 0.12
            ox = max(0, int(vx1 - mw))
            oy = max(0, int(vy1 - mh * 0.3))
            ex = min(ancho, int(vx2 + mw))
            ey = min(alto, int(vy2 + mh))
            if ex - ox >= 40 and ey - oy >= 40:
                entrada = imagen_bgr[oy:ey, ox:ex]

        imgsz = 640 if (ox > 0 or oy > 0 or not self._modo_zeroshot) else 1024
        r = self.modelo.predict(entrada, conf=self.conf, device=self.device, imgsz=imgsz, verbose=False)[0]
        if r.boxes is None or len(r.boxes) == 0:
            return None
        llantas = []
        if self._modo_zeroshot:
            if r.masks is None:
                return None
            for i in range(len(r.boxes)):
                conf = float(r.boxes.conf[i])
                k = kps_de_mascara(r.masks.xy[i], conf) if i < len(r.masks.xy) else None
                if k is not None:
                    k[:, 0] += ox
                    k[:, 1] += oy
                    b = r.boxes.xyxy[i].tolist()
                    llantas.append({"box": [b[0] + ox, b[1] + oy, b[2] + ox, b[3] + oy], "conf": conf, "kps": k})
        else:
            if r.keypoints is None:
                return None
            kp = r.keypoints.data.cpu().numpy()  # (n, 1 o 5, 2 o 3)
            if kp.shape[1] < 1:
                return None
            for i in range(len(r.boxes)):
                conf = float(r.boxes.conf[i])
                b = r.boxes.xyxy[i].tolist()
                box_abs = [b[0] + ox, b[1] + oy, b[2] + ox, b[3] + oy]
                k_raw = kp[i].copy()
                k_raw[:, 0] += ox
                k_raw[:, 1] += oy
                if k_raw.shape[1] == 2:
                    k_raw = np.column_stack([k_raw, np.full(k_raw.shape[0], conf, dtype=np.float32)])
                k = kps_de_caja(box_abs, k_raw, conf)
                llantas.append({"box": box_abs, "conf": conf, "kps": k})
        if not llantas:
            return None
        llantas = limpiar_llantas(llantas)
        caja = ctx.vehiculo_caja or (0.0, 0.0, float(ancho), float(alto))
        propias = llantas_del_vehiculo(llantas, caja) if ctx.vehiculo_caja else llantas
        ctx.llantas_kps = [l["kps"].tolist() for l in propias]
        ejes, conf, _vista = analizar_ejes(caja, propias)
        if ejes is None:
            return None
        return {"cantidad": int(ejes), "confianza": round(conf, 4)}
