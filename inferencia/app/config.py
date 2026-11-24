"""Configuracion del servicio, leida de variables de entorno (portable: sin rutas de Windows)."""
from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

VERSION = "1.0.0"
RAIZ = Path(__file__).resolve().parent.parent


def _cargar_dotenv() -> None:
    """Carga ``.env`` (clave=valor) si existe, sin pisar variables ya definidas."""
    ruta = RAIZ / ".env"
    if not ruta.is_file():
        return
    for linea in ruta.read_text(encoding="utf-8").splitlines():
        linea = linea.strip()
        if not linea or linea.startswith("#") or "=" not in linea:
            continue
        k, v = linea.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip().strip("'\""))


def _ruta(valor: str) -> Path:
    p = Path(valor)
    return p if p.is_absolute() else RAIZ / p


@dataclass
class Config:
    api_key: str = ""
    allow_no_auth: bool = False
    max_image_mb: float = 10.0
    dir_modelos: Path = field(default_factory=lambda: RAIZ / "modelos")
    modelo_m2: Path = field(default_factory=lambda: RAIZ / "modelos" / "m2_seg.pt")
    modelo_m3: Path = field(default_factory=lambda: RAIZ / "modelos" / "m3_pose.pt")
    modelo_m4: Path = field(default_factory=lambda: RAIZ / "modelos" / "m4_personas.pt")
    dispositivo: str = "auto"  # auto | cpu | cuda
    umbral_placa: float = 0.4
    mapeo_m2_json: str = ""
    use_zeroshot_fallback: bool = False

    @property
    def max_image_bytes(self) -> int:
        return int(self.max_image_mb * 1024 * 1024)

    @classmethod
    def desde_entorno(cls) -> "Config":
        _cargar_dotenv()
        e = os.environ
        dir_modelos = _ruta(e.get("DIR_MODELOS", "modelos"))
        return cls(
            api_key=e.get("INFERENCE_API_KEY", ""),
            allow_no_auth=e.get("ALLOW_NO_AUTH", "") == "1",
            max_image_mb=float(e.get("MAX_IMAGE_MB", "10") or 10),
            dir_modelos=dir_modelos,
            modelo_m2=_ruta(e.get("MODELO_M2") or str(dir_modelos / "m2_seg.pt")),
            modelo_m3=_ruta(e.get("MODELO_M3") or str(dir_modelos / "m3_pose.pt")),
            modelo_m4=_ruta(e.get("MODELO_M4") or str(dir_modelos / "m4_personas.pt")),
            dispositivo=(e.get("INFERENCE_DEVICE", "auto") or "auto").lower(),
            umbral_placa=float(e.get("UMBRAL_PLACA", "0.4") or 0.4),
            mapeo_m2_json=e.get("MAPEO_M2", ""),
            use_zeroshot_fallback=e.get("USE_ZEROSHOT_FALLBACK", "") == "1",
        )
