import numpy as np
import pytest
from fastapi.testclient import TestClient

from app.config import Config
from app.main import crear_app
from app.modelos.m1_placas import normalizar_placa
from app.modelos.m3_ejes import analizar_ejes, kps_de_caja

from conftest import CLAVE

H = {"X-API-Key": CLAVE}


def test_salud_sin_auth(cliente):
    r = cliente.get("/salud")
    assert r.status_code == 200
    j = r.json()
    assert j["success"] is True and j["estado"] == "ok"
    assert set(j["modelos"]) == {"m1", "m2", "m3", "m4"}
    assert j["modelos"]["m1"] == "ok"
    assert isinstance(j["version"], str) and isinstance(j["python"], str) and isinstance(j["uptime_s"], int)


def test_inferir_sin_api_key_401(cliente, bytes_imagen):
    r = cliente.post("/inferir", files={"imagen": ("a.jpg", bytes_imagen, "image/jpeg")})
    assert r.status_code == 401
    assert r.json() == {"success": False, "message": "API key invalida o ausente.", "errors": {}}


def test_inferir_api_key_incorrecta_401(cliente, bytes_imagen):
    r = cliente.post("/inferir", headers={"X-API-Key": "otra"}, files={"imagen": ("a.jpg", bytes_imagen, "image/jpeg")})
    assert r.status_code == 401


def test_inferir_ok_esquema_exacto(cliente, bytes_imagen):
    r = cliente.post("/inferir", headers=H, files={"imagen": ("a.jpg", bytes_imagen, "image/jpeg")})
    assert r.status_code == 200, r.text
    j = r.json()
    assert list(j) == ["success", "inference", "modelos", "latencia_ms"]
    assert j["success"] is True
    assert list(j["inference"]) == ["placa", "vehiculo", "ejes", "pasajeros"]
    assert list(j["modelos"]) == ["m1", "m2", "m3", "m4"]
    assert isinstance(j["latencia_ms"], int)
    p = j["inference"]["placa"]
    assert p is not None, "la imagen sintetica deberia tener placa detectada"
    assert list(p) == ["texto", "confianza", "confianza_deteccion", "formato_bolivia", "bbox"]
    assert isinstance(p["texto"], str) and isinstance(p["formato_bolivia"], bool)
    assert 0 <= p["confianza"] <= 1 and 0 <= p["confianza_deteccion"] <= 1
    assert len(p["bbox"]) == 4 and all(isinstance(v, int) for v in p["bbox"])
    assert p["texto"] == "2345ABC" and p["formato_bolivia"] is True
    assert j["modelos"]["m1"] == "ok"
    # sin pesos M2-M4 el endpoint no falla: devuelve null
    for k, m in (("vehiculo", "m2"), ("ejes", "m3"), ("pasajeros", "m4")):
        if j["modelos"][m] == "no_disponible":
            assert j["inference"][k] is None


def test_inferir_sin_placa_devuelve_null(cliente):
    import cv2

    img = np.full((300, 400, 3), 120, np.uint8)
    ok, buf = cv2.imencode(".png", img)
    r = cliente.post("/inferir", headers=H, files={"imagen": ("v.png", buf.tobytes(), "image/png")})
    assert r.status_code == 200
    assert r.json()["success"] is True and r.json()["inference"]["placa"] is None


def test_archivo_no_imagen_415(cliente):
    r = cliente.post("/inferir", headers=H, files={"imagen": ("a.jpg", b"esto no es una imagen", "image/jpeg")})
    assert r.status_code == 415
    assert r.json()["success"] is False and r.json()["errors"] == {}


def test_imagen_enorme_413(cliente):
    grande = b"\xff\xd8\xff\xe0" + b"\0" * (11 * 1024 * 1024)
    r = cliente.post("/inferir", headers=H, files={"imagen": ("g.jpg", grande, "image/jpeg")})
    assert r.status_code == 413
    assert r.json()["success"] is False


def test_sin_campo_imagen_422(cliente):
    r = cliente.post("/inferir", headers=H, data={"x": "1"})
    assert r.status_code == 422 and r.json()["success"] is False


def test_jpeg_corrupto_422(cliente):
    r = cliente.post("/inferir", headers=H, files={"imagen": ("c.jpg", b"\xff\xd8\xff\xe0" + b"basura" * 50, "image/jpeg")})
    assert r.status_code == 422


def test_arranque_rechazado_sin_clave():
    with pytest.raises(RuntimeError, match="INFERENCE_API_KEY"):
        with TestClient(crear_app(Config(api_key="", allow_no_auth=False))):
            pass


def test_allow_no_auth(bytes_imagen):
    with TestClient(crear_app(Config(api_key="", allow_no_auth=True))) as c:
        r = c.post("/inferir", files={"imagen": ("a.jpg", bytes_imagen, "image/jpeg")})
        assert r.status_code == 200


@pytest.mark.parametrize("crudo,esperado,valido", [
    ("1234ABC", "1234ABC", True),
    ("123-abc", "123ABC", True),
    ("I234A8C", "1234ABC", True),      # I->1, 8->B
    ("1234AB0", "1234ABO", True),      # 0->O
    ("O23ABC", "023ABC", True),        # O->0 en la zona numerica
    ("5S3ABC", "553ABC", True),        # S->5
    ("1234ABCD", "1234ABCD", False),   # 8 caracteres: no se inventa nada
    ("12AB", "12AB", False),
    ("", "", False),
    ("LL34ABC", "LL34ABC", False),     # L no es una confusion admitida
])
def test_normalizar_placa(crudo, esperado, valido):
    assert normalizar_placa(crudo) == (esperado, valido)


def _llanta(cx, cy, d, conf=0.9):
    kps = np.array([[cx, cy, 1], [cx, cy + d / 2, 1], [cx, cy - d / 2, 1], [cx - d / 2, cy, 1], [cx + d / 2, cy, 1]], float)
    return {"box": [cx - d / 2, cy - d / 2, cx + d / 2, cy + d / 2], "conf": conf, "kps": kps}


def test_ejes_camion_ruedas_dobles():
    caja = (0, 0, 1000, 400)
    # eje delantero (1 rueda) + eje trasero con ruedas dobles muy juntas + tercer eje
    llantas = [_llanta(150, 330, 100), _llanta(600, 330, 100), _llanta(660, 330, 100), _llanta(850, 330, 100)]
    ejes, conf, vista = analizar_ejes(caja, llantas)
    assert (ejes, vista) == (3, "lateral") and conf == pytest.approx(0.9)


def test_ejes_frontal_no_determinable():
    caja = (0, 0, 400, 400)
    a, b = _llanta(100, 330, 100), _llanta(300, 330, 100)
    for l in (a, b):  # ruedas angostas (de canto)
        l["kps"][3, 0], l["kps"][4, 0] = l["kps"][0, 0] - 15, l["kps"][0, 0] + 15
    assert analizar_ejes(caja, [a, b])[0] is None


def test_ejes_con_1_keypoint_centro():
    caja = (0, 0, 1000, 400)
    llantas = []
    for cx in (150, 600, 660, 850):
        box = [cx - 50, 280, cx + 50, 380]
        k1 = np.array([[cx, 330, 0.92]], dtype=np.float32)
        llantas.append({"box": box, "conf": 0.92, "kps": kps_de_caja(box, k1, 0.92)})
    ejes, conf, vista = analizar_ejes(caja, llantas)
    assert (ejes, vista) == (3, "lateral") and conf == pytest.approx(0.92)
