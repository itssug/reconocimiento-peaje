"""Clase base comun de los modelos M1-M4 y el contexto que comparten dentro de una peticion."""
from __future__ import annotations

import logging
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Optional

import numpy as np

log = logging.getLogger("inferencia")

OK = "ok"
NO_DISPONIBLE = "no_disponible"
ERROR = "error"


@dataclass
class Contexto:
    """Resultados intermedios que M1/M2/M3/M4 comparten dentro de una peticion (solo en memoria)."""

    placa_bbox: Optional[tuple[int, int, int, int]] = None
    vehiculo_caja: Optional[tuple[float, float, float, float]] = None
    vehiculo_poligono: Optional[np.ndarray] = None  # (N, 2) en coordenadas de la imagen
    llantas_kps: list[list[list[float]]] = field(default_factory=list)  # [[[x,y,conf],...], ...]
    pasajeros_poligonos: list[np.ndarray] = field(default_factory=list)  # mascaras de personas dentro del vehiculo
    extra: dict[str, Any] = field(default_factory=dict)


class ModeloBase(ABC):
    """Interfaz comun. ``estado`` es ``ok`` si hay pesos cargados y ``no_disponible`` si no."""

    clave: str = ""  # m1..m4
    nombre: str = ""

    def __init__(self) -> None:
        self.estado: str = NO_DISPONIBLE
        self.detalle: str = ""

    @property
    def disponible(self) -> bool:
        return self.estado == OK

    @abstractmethod
    def cargar(self) -> None:
        """Carga los pesos UNA vez al arrancar. No debe lanzar: deja ``estado`` en no_disponible."""

    @abstractmethod
    def inferir(self, imagen_bgr: np.ndarray, ctx: Contexto) -> Optional[dict]:
        """Devuelve el dict del modelo para el JSON o ``None`` si no hay resultado."""


class ModeloUltralytics(ModeloBase):
    """Base de M2/M3/M4: carga opcional de un peso YOLO si existe y ultralytics esta instalado."""

    def __init__(self, ruta_peso: Path, dispositivo: str = "auto") -> None:
        super().__init__()
        self.ruta_peso = Path(ruta_peso)
        self.dispositivo_pedido = dispositivo
        self.modelo = None
        self.device: Any = "cpu"

    def cargar(self) -> None:
        if not self.ruta_peso.is_file():
            self.detalle = f"falta el peso {self.ruta_peso.name}"
            return
        try:
            from ultralytics import YOLO  # import perezoso: ultralytics es opcional
        except ImportError:
            self.detalle = "ultralytics no instalado (pip install -r requirements-modelos.txt)"
            return
        try:
            self.device = self._elegir_dispositivo()
            self.modelo = YOLO(str(self.ruta_peso))
            self.estado = OK
            self.detalle = f"cargado en {self.device}"
        except Exception as e:  # noqa: BLE001
            self.modelo = None
            self.estado = NO_DISPONIBLE
            self.detalle = f"error al cargar: {e}"
            log.exception("No se pudo cargar %s", self.ruta_peso)

    def _elegir_dispositivo(self) -> Any:
        if self.dispositivo_pedido == "cpu":
            return "cpu"
        try:
            import torch

            if torch.cuda.is_available():
                return 0
        except ImportError:
            pass
        return "cpu"
