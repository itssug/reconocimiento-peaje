"""M1 - Placas: detector YOLOv9-t (ONNX) + OCR fast-plate-ocr (ONNX, via onnxruntime directo).

La logica del detector (letterbox, escala de cajas, limpieza, regex) viene de ``detector.py`` de la guia.
Las sesiones ONNX se crean UNA sola vez en ``cargar()``.
"""
from __future__ import annotations

import logging
import re
from pathlib import Path
from typing import Optional

import cv2
import numpy as np
import onnxruntime as ort
import yaml

from .base import NO_DISPONIBLE, OK, Contexto, ModeloBase

log = logging.getLogger("inferencia.m1")

ARCHIVO_DETECTOR = "yolo-v9-t-384-license-plates-end2end.onnx"
ARCHIVO_OCR = "cct_xs_v2_global.onnx"
ARCHIVO_CONFIG_OCR = "cct_xs_v2_global_plate_config.yaml"

LADO = 384
FORMATO_BOLIVIA = re.compile(r"^\d{3,4}[A-Z]{3}$")

# Confusiones visuales frecuentes del OCR (solo se aplican si completan el formato boliviano ^\d{3,4}[A-Z]{3}$)
LETRA_A_DIGITO = {"O": "0", "Q": "0", "D": "0", "I": "1", "L": "1", "Z": "2", "S": "5", "G": "6", "T": "7", "B": "8"}
DIGITO_A_LETRA = {"0": "O", "1": "I", "2": "Z", "5": "S", "6": "G", "7": "T", "8": "B"}


def limpiar(texto: str) -> str:
    """Mayusculas y solo alfanumericos."""
    return re.sub(r"[^A-Z0-9]", "", (texto or "").upper())


def normalizar_placa(texto: str) -> tuple[str, bool]:
    """Devuelve ``(texto_corregido, formato_bolivia)``.

    Si ya cumple ``^\\d{3,4}[A-Z]{3}$`` no cambia nada. Si no, para longitudes 6 o 7 aplica
    O<->0, I<->1, B<->8, S<->5 segun la posicion (primeros 3-4 = digitos, ultimos 3 = letras).
    Si ni asi cumple, devuelve el texto limpio y ``False``.
    """
    t = limpiar(texto)
    if FORMATO_BOLIVIA.match(t):
        return t, True
    if len(t) in (6, 7):
        n_dig = len(t) - 3
        corregido = "".join(
            LETRA_A_DIGITO.get(c, c) if i < n_dig else DIGITO_A_LETRA.get(c, c) for i, c in enumerate(t)
        )
        if FORMATO_BOLIVIA.match(corregido):
            return corregido, True
    return t, False


def elegir_proveedores(dispositivo: str = "auto") -> list[str]:
    disp = ort.get_available_providers()
    if dispositivo != "cpu" and "CUDAExecutionProvider" in disp:
        return ["CUDAExecutionProvider", "CPUExecutionProvider"]
    return ["CPUExecutionProvider"]


class ModeloPlacas(ModeloBase):
    clave = "m1"
    nombre = "placas"

    def __init__(self, dir_modelos: Path, dispositivo: str = "auto", umbral: float = 0.4) -> None:
        super().__init__()
        self.dir = Path(dir_modelos)
        self.dispositivo = dispositivo
        self.umbral = umbral
        self.det: Optional[ort.InferenceSession] = None
        self.ocr: Optional[ort.InferenceSession] = None
        self.cfg: dict = {}
        self.proveedores: list[str] = []

    # ------------------------------------------------------------------ carga
    def cargar(self) -> None:
        faltan = [n for n in (ARCHIVO_DETECTOR, ARCHIVO_OCR, ARCHIVO_CONFIG_OCR) if not (self.dir / n).is_file()]
        if faltan:
            self.detalle = f"faltan archivos en {self.dir}: {', '.join(faltan)} (python scripts/descargar_modelos.py)"
            log.warning("M1: %s", self.detalle)
            return
        try:
            self.proveedores = elegir_proveedores(self.dispositivo)
            opciones = ort.SessionOptions()
            opciones.log_severity_level = 3
            self.det = ort.InferenceSession(str(self.dir / ARCHIVO_DETECTOR), opciones, providers=self.proveedores)
            self.ocr = ort.InferenceSession(str(self.dir / ARCHIVO_OCR), opciones, providers=self.proveedores)
            self.cfg = yaml.safe_load((self.dir / ARCHIVO_CONFIG_OCR).read_text(encoding="utf-8"))
            self._in_det = self.det.get_inputs()[0].name
            self._in_ocr = self.ocr.get_inputs()[0].name
            self._ocr_uint8 = "uint8" in self.ocr.get_inputs()[0].type
            self.estado = OK
            self.detalle = f"proveedores: {self.det.get_providers()}"
        except Exception as e:  # noqa: BLE001
            self.det = self.ocr = None
            self.estado = NO_DISPONIBLE
            self.detalle = f"error al cargar M1: {e}"
            log.exception("M1 no cargo")

    # --------------------------------------------------------------- detector
    def detectar(self, imagen: np.ndarray, umbral: Optional[float] = None) -> list[tuple[int, int, int, int, float]]:
        """Lista de (x1, y1, x2, y2, confianza) en coordenadas de la imagen original."""
        umbral = self.umbral if umbral is None else umbral
        alto, ancho = imagen.shape[:2]
        escala = min(LADO / alto, LADO / ancho)
        nuevo_ancho, nuevo_alto = round(ancho * escala), round(alto * escala)
        margen_x, margen_y = (LADO - nuevo_ancho) / 2, (LADO - nuevo_alto) / 2
        lienzo = cv2.resize(imagen, (nuevo_ancho, nuevo_alto), interpolation=cv2.INTER_LINEAR)
        lienzo = cv2.copyMakeBorder(
            lienzo,
            round(margen_y - 0.1), round(margen_y + 0.1), round(margen_x - 0.1), round(margen_x + 0.1),
            cv2.BORDER_CONSTANT, value=(114, 114, 114),
        )
        entrada = np.ascontiguousarray((lienzo[:, :, ::-1].transpose(2, 0, 1) / 255.0).astype(np.float32)[None])
        salida = self.det.run(None, {self._in_det: entrada})[0]
        cajas = []
        for _, x1, y1, x2, y2, _, confianza in salida:
            if confianza < umbral:
                continue
            x1, x2 = [(v - margen_x) / escala for v in (x1, x2)]
            y1, y2 = [(v - margen_y) / escala for v in (y1, y2)]
            x1, y1, x2, y2 = int(max(0, x1)), int(max(0, y1)), int(min(ancho, x2)), int(min(alto, y2))
            if x2 - x1 < 2 or y2 - y1 < 2:
                continue
            cajas.append((x1, y1, x2, y2, float(confianza)))
        cajas.sort(key=lambda c: -c[4])
        return cajas

    # -------------------------------------------------------------------- ocr
    def _preparar_recorte(self, recorte_bgr: np.ndarray) -> np.ndarray:
        h, w = int(self.cfg.get("img_height", 64)), int(self.cfg.get("img_width", 128))
        if self.cfg.get("image_color_mode", "rgb") == "grayscale":
            img = cv2.cvtColor(recorte_bgr, cv2.COLOR_BGR2GRAY)[..., None]
        else:
            img = cv2.cvtColor(recorte_bgr, cv2.COLOR_BGR2RGB)
        interp = {"nearest": cv2.INTER_NEAREST, "linear": cv2.INTER_LINEAR, "cubic": cv2.INTER_CUBIC,
                  "area": cv2.INTER_AREA}.get(str(self.cfg.get("interpolation", "linear")), cv2.INTER_LINEAR)
        img = cv2.resize(img, (w, h), interpolation=interp)
        if img.ndim == 2:
            img = img[..., None]
        x = img[None]
        return x.astype(np.uint8) if self._ocr_uint8 else (x.astype(np.float32) / 255.0)

    def leer(self, recorte_bgr: np.ndarray) -> tuple[str, float]:
        """OCR de un recorte: devuelve (texto sin relleno, media de char_probs de los caracteres reales)."""
        slots = int(self.cfg["max_plate_slots"])
        alfabeto = self.cfg["alphabet"]
        pad = self.cfg.get("pad_char", "_")
        salidas = self.ocr.run(None, {self._in_ocr: self._preparar_recorte(recorte_bgr)})
        probs = np.asarray(salidas[0], dtype=np.float32).reshape(slots, len(alfabeto))
        idx = probs.argmax(axis=-1)
        chars = [alfabeto[i] for i in idx]
        maximos = probs.max(axis=-1)
        # media de char_probs como fast-plate-ocr (sobre todas las posiciones de la placa leida)
        reales = [p for c, p in zip(chars, maximos) if c != pad]
        texto = "".join(c for c in chars if c != pad)
        conf = float(np.mean(reales)) if reales else 0.0
        return texto, conf

    @staticmethod
    def _recorte_con_margen(img: np.ndarray, x1: int, y1: int, x2: int, y2: int, margen: float = 0.08) -> np.ndarray:
        h, w = img.shape[:2]
        dx = int((x2 - x1) * margen)
        dy = int((y2 - y1) * margen)
        return img[max(0, y1 - dy):min(h, y2 + dy), max(0, x1 - dx):min(w, x2 + dx)]

    @staticmethod
    def _mejorar_contraste(recorte_bgr: np.ndarray) -> np.ndarray:
        """CLAHE sobre luminancia + nitidez suave para placas con sombra o reflejo."""
        lab = cv2.cvtColor(recorte_bgr, cv2.COLOR_BGR2LAB)
        l_ch, a_ch, b_ch = cv2.split(lab)
        clahe = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(4, 4))
        mejorado = cv2.cvtColor(cv2.merge((clahe.apply(l_ch), a_ch, b_ch)), cv2.COLOR_LAB2BGR)
        suave = cv2.GaussianBlur(mejorado, (0, 0), 1.0)
        return cv2.addWeighted(mejorado, 1.35, suave, -0.35, 0)

    # --------------------------------------------------------------- pipeline
    def inferir(
        self,
        imagen_bgr: np.ndarray,
        ctx: Contexto,
        max_candidatas: int = 3,
        rapido: bool = False,
        umbral: Optional[float] = None,
    ) -> Optional[dict]:
        cajas = self.detectar(imagen_bgr, umbral=umbral)
        if not cajas:
            return None
        candidatas = []
        for x1, y1, x2, y2, conf_det in cajas[:max_candidatas]:
            recorte = self._recorte_con_margen(imagen_bgr, x1, y1, x2, y2, margen=0.08)
            if recorte.size == 0:
                continue
            # En modo rapido (stream /ws/track): 1 sola pasada sin CLAHE; en /inferir: doble pasada con CLAHE
            variantes = (recorte,) if rapido else (recorte, self._mejorar_contraste(recorte))
            lecturas = []
            for variante in variantes:
                texto_crudo, conf_ocr = self.leer(variante)
                texto, valido = normalizar_placa(texto_crudo)
                if texto:
                    lecturas.append((valido, conf_ocr, texto))
            if not lecturas:
                continue
            lecturas.sort(key=lambda item: (item[0], item[1]), reverse=True)
            valido, conf_ocr, texto = lecturas[0]
            candidatas.append({
                "texto": texto,
                "confianza": round(conf_ocr, 4),
                "confianza_deteccion": round(conf_det, 4),
                "formato_bolivia": bool(valido),
                "bbox": [x1, y1, x2, y2],
            })
        if not candidatas:
            return None
        # prefiere las que cumplen el formato boliviano y luego la mejor confianza combinada
        candidatas.sort(key=lambda c: (c["formato_bolivia"], c["confianza"] * c["confianza_deteccion"]), reverse=True)
        mejor = candidatas[0]
        ctx.placa_bbox = tuple(mejor["bbox"])  # M2 usara esta caja para asociar el vehiculo correcto
        return mejor
