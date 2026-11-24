import os
import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

RAIZ = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RAIZ))

from app.config import Config  # noqa: E402
from app.main import crear_app  # noqa: E402

CLAVE = "clave-de-prueba-123"
IMAGEN = Path(__file__).parent / "data" / "placa_sintetica.jpg"


@pytest.fixture(scope="session")
def cliente():
    cfg = Config.desde_entorno()
    cfg.api_key = CLAVE
    cfg.allow_no_auth = False
    cfg.max_image_mb = 10
    with TestClient(crear_app(cfg)) as c:
        yield c


@pytest.fixture(scope="session")
def bytes_imagen() -> bytes:
    if not IMAGEN.is_file():
        sys.path.insert(0, str(RAIZ / "scripts"))
        import cv2
        from generar_imagen_prueba import generar

        IMAGEN.parent.mkdir(parents=True, exist_ok=True)
        cv2.imwrite(str(IMAGEN), generar("2345ABC"), [cv2.IMWRITE_JPEG_QUALITY, 92])
    return IMAGEN.read_bytes()
