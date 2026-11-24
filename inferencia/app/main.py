"""Servicio de inferencia del peaje: FastAPI.

Arranque:  uvicorn app.main:app --host 0.0.0.0 --port 8000
"""
from __future__ import annotations

import base64
import hmac
import logging
import platform
import time
from contextlib import asynccontextmanager
from typing import Optional

import cv2
import numpy as np
from fastapi import Depends, FastAPI, File, Header, Request, UploadFile, WebSocket, WebSocketDisconnect
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from .config import VERSION, Config
from .modelos.base import ERROR, NO_DISPONIBLE, OK, Contexto
from .modelos.m1_placas import ModeloPlacas
from .modelos.m2_vehiculos import ModeloVehiculos
from .modelos.m3_ejes import ModeloEjes
from .modelos.m4_pasajeros import ModeloPasajeros

log = logging.getLogger("inferencia")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")

MAX_PIXELES = 50_000_000  # proteccion contra imagenes "bomba" de descompresion


class ErrorApi(Exception):
    def __init__(self, status: int, mensaje: str, errores: Optional[dict] = None) -> None:
        self.status, self.mensaje, self.errores = status, mensaje, errores or {}


def tipo_por_firma(datos: bytes) -> Optional[str]:
    """Detecta JPG/PNG/WebP por magic bytes (no se confia en el nombre ni en Content-Type)."""
    if datos[:3] == b"\xff\xd8\xff":
        return "jpeg"
    if datos[:8] == b"\x89PNG\r\n\x1a\n":
        return "png"
    if datos[:4] == b"RIFF" and datos[8:12] == b"WEBP":
        return "webp"
    return None


def _simplificar_poly(poly: np.ndarray, w: int, h: int, max_pts: int = 40) -> list[list[float]]:
    n = len(poly)
    step = max(1, n // max_pts)
    return [[round(float(x) / w, 4), round(float(y) / h, 4)] for x, y in poly[::step]]


def _renderizar_evidencia(img_bgr: np.ndarray, ctx: Contexto, res: dict, lat_ms: int) -> tuple[str, dict]:
    """Dibuja M1 (placa), M2 (mascara vehiculo), M3 (keypoints llantas/ejes) y M4 (mascara pasajeros).
    Privacidad: sobre pasajeros solo se dibuja mascara silueta y conteo, nunca recortes de rostro.
    """
    h, w = img_bgr.shape[:2]
    vis = img_bgr.copy()

    # M2: mascara + caja del vehiculo
    if ctx.vehiculo_poligono is not None and len(ctx.vehiculo_poligono) >= 3:
        ov = vis.copy()
        pts = np.round(ctx.vehiculo_poligono).astype(np.int32)
        cv2.fillPoly(ov, [pts], (255, 190, 0))
        vis = cv2.addWeighted(ov, 0.28, vis, 0.72, 0)
        cv2.polylines(vis, [pts], True, (255, 190, 0), 2, cv2.LINE_AA)
    if ctx.vehiculo_caja is not None:
        x1, y1, x2, y2 = [int(v) for v in ctx.vehiculo_caja]
        cv2.rectangle(vis, (x1, y1), (x2, y2), (255, 160, 0), 2)

    # M4: mascaras de pasajeros (solo silueta opaca para privacidad, nunca rostros)
    for ppoly in ctx.pasajeros_poligonos:
        ov = vis.copy()
        pts = np.round(ppoly).astype(np.int32)
        cv2.fillPoly(ov, [pts], (210, 60, 210))
        vis = cv2.addWeighted(ov, 0.55, vis, 0.45, 0)
        cv2.polylines(vis, [pts], True, (255, 120, 255), 2, cv2.LINE_AA)

    # M3: keypoints de ruedas (0 centro, 1 contacto, 2 superior, 3 izq, 4 der)
    colores_kp = [(0, 255, 255), (0, 140, 255), (0, 255, 140), (255, 220, 0), (255, 220, 0)]
    for kps in ctx.llantas_kps:
        if len(kps) >= 5:
            cx, cy = int(kps[0][0]), int(kps[0][1])
            cv2.line(vis, (int(kps[2][0]), int(kps[2][1])), (int(kps[1][0]), int(kps[1][1])), (0, 210, 255), 2)
            cv2.line(vis, (int(kps[3][0]), int(kps[3][1])), (int(kps[4][0]), int(kps[4][1])), (0, 210, 255), 2)
            for idx_k, pt in enumerate(kps[:5]):
                cv2.circle(vis, (int(pt[0]), int(pt[1])), 5 if idx_k == 0 else 4, colores_kp[idx_k], -1, cv2.LINE_AA)
            cv2.circle(vis, (cx, cy), 8, (0, 165, 255), 2, cv2.LINE_AA)

    # M1: caja y texto de placa
    r1 = res.get("m1")
    if r1 and r1.get("bbox"):
        x1, y1, x2, y2 = [int(v) for v in r1["bbox"]]
        cv2.rectangle(vis, (x1, y1), (x2, y2), (0, 230, 0), 3)
        cv2.putText(vis, r1["texto"], (x1, max(28, y1 - 8)), cv2.FONT_HERSHEY_SIMPLEX, 0.85, (0, 230, 0), 2, cv2.LINE_AA)

    # Cabecera resumen
    r2, r3, r4 = res.get("m2"), res.get("m3"), res.get("m4")
    banner = (
        f"Placa: {r1['texto'] if r1 else 'N/A'} | "
        f"Cat: {r2['categoria'] if r2 else 'N/A'} | "
        f"Ejes: {r3['cantidad'] if r3 else 'N/A'} | "
        f"Pasaj: {r4['cantidad'] if r4 else 'N/A'} | {lat_ms}ms"
    )
    cv2.rectangle(vis, (0, 0), (w, 38), (18, 18, 18), -1)
    cv2.putText(vis, banner, (10, 26), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 2, cv2.LINE_AA)

    # Redimensionar si es muy grande para ahorrar ancho de banda
    if w > 960:
        nh = int(h * (960 / w))
        vis = cv2.resize(vis, (960, nh), interpolation=cv2.INTER_AREA)
    ok_enc, buf = cv2.imencode(".jpg", vis, [cv2.IMWRITE_JPEG_QUALITY, 80])
    b64 = base64.b64encode(buf.tobytes()).decode("ascii") if ok_enc else ""

    overlay = {
        "placa_bbox": [round(r1["bbox"][0] / w, 4), round(r1["bbox"][1] / h, 4),
                       round(r1["bbox"][2] / w, 4), round(r1["bbox"][3] / h, 4)] if (r1 and r1.get("bbox")) else None,
        "placa_texto": r1["texto"] if r1 else None,
        "vehiculo_poligono": _simplificar_poly(ctx.vehiculo_poligono, w, h) if ctx.vehiculo_poligono is not None else [],
        "vehiculo_caja": [round(ctx.vehiculo_caja[0] / w, 4), round(ctx.vehiculo_caja[1] / h, 4),
                          round(ctx.vehiculo_caja[2] / w, 4), round(ctx.vehiculo_caja[3] / h, 4)] if ctx.vehiculo_caja else None,
        "ruedas_kps": [
            [[round(pt[0] / w, 4), round(pt[1] / h, 4)] for pt in kps[:5]]
            for kps in ctx.llantas_kps if len(kps) >= 5
        ],
        "pasajeros_poligonos": [_simplificar_poly(p, w, h, 24) for p in ctx.pasajeros_poligonos],
    }
    return b64, overlay


def respuesta_error(status: int, mensaje: str, errores: Optional[dict] = None) -> JSONResponse:
    return JSONResponse(status_code=status, content={"success": False, "message": mensaje, "errors": errores or {}})


def crear_app(config: Optional[Config] = None) -> FastAPI:
    @asynccontextmanager
    async def ciclo(app: FastAPI):
        cfg = config or Config.desde_entorno()
        if not cfg.api_key and not cfg.allow_no_auth:
            raise RuntimeError(
                "INFERENCE_API_KEY esta vacia. Define una clave o usa ALLOW_NO_AUTH=1 solo para pruebas."
            )
        if not cfg.api_key:
            log.warning("ALLOW_NO_AUTH=1: el servicio corre SIN autenticacion (solo pruebas).")
        app.state.cfg = cfg
        app.state.inicio = time.monotonic()
        modelos = {
            "m1": ModeloPlacas(cfg.dir_modelos, cfg.dispositivo, cfg.umbral_placa),
            "m2": ModeloVehiculos(cfg.modelo_m2, cfg.dispositivo, cfg.mapeo_m2_json, fallback_zeroshot=cfg.use_zeroshot_fallback),
            "m3": ModeloEjes(cfg.modelo_m3, cfg.dispositivo, fallback_zeroshot=cfg.use_zeroshot_fallback),
            "m4": ModeloPasajeros(cfg.modelo_m4, cfg.dispositivo, fallback_zeroshot=cfg.use_zeroshot_fallback),
        }
        for m in modelos.values():
            m.cargar()
            log.info("%s (%s): %s %s", m.clave, m.nombre, m.estado, m.detalle)
        app.state.modelos = modelos
        yield

    app = FastAPI(title="Servicio de inferencia - Peaje", version=VERSION, lifespan=ciclo)

    # ---------------------------------------------------------------- errores
    @app.exception_handler(ErrorApi)
    async def _h_error(_: Request, e: ErrorApi):
        return respuesta_error(e.status, e.mensaje, e.errores)

    @app.exception_handler(StarletteHTTPException)
    async def _h_http(_: Request, e: StarletteHTTPException):
        return respuesta_error(e.status_code, str(e.detail))

    @app.exception_handler(RequestValidationError)
    async def _h_validacion(_: Request, e: RequestValidationError):
        errores = {".".join(str(p) for p in err["loc"] if p != "body"): [err["msg"]] for err in e.errors()}
        return respuesta_error(422, "Datos invalidos: se requiere el campo multipart 'imagen'.", errores)

    @app.exception_handler(Exception)
    async def _h_500(_: Request, e: Exception):
        log.exception("Error no controlado")
        return respuesta_error(500, "Error interno del servicio de inferencia.")

    # ------------------------------------------------------------------- auth
    def autenticar(request: Request, x_api_key: Optional[str] = Header(default=None)) -> None:
        clave = request.app.state.cfg.api_key
        if not clave:  # ALLOW_NO_AUTH=1 (el arranque ya lo valido)
            return
        recibida = (x_api_key or "").encode("utf-8")
        if not hmac.compare_digest(recibida, clave.encode("utf-8")):
            raise ErrorApi(401, "API key invalida o ausente.")

    # ------------------------------------------------------------------ rutas
    @app.get("/salud")
    def salud(request: Request):
        modelos = request.app.state.modelos
        return {
            "success": True,
            "estado": "ok",
            "modelos": {k: (OK if m.disponible else NO_DISPONIBLE) for k, m in modelos.items()},
            "version": VERSION,
            "python": platform.python_version(),
            "uptime_s": int(time.monotonic() - request.app.state.inicio),
        }

    @app.post("/inferir", dependencies=[Depends(autenticar)])
    def inferir(request: Request, imagen: UploadFile = File(...)):
        t0 = time.perf_counter()
        cfg: Config = request.app.state.cfg
        modelos = request.app.state.modelos

        # tamano: cabecera + lectura acotada (no se lee mas de max+1 bytes)
        declarado = request.headers.get("content-length")
        limite = cfg.max_image_bytes
        if declarado and declarado.isdigit() and int(declarado) > limite + 64 * 1024:
            raise ErrorApi(413, f"La imagen supera el maximo de {cfg.max_image_mb:g} MB.")
        datos = imagen.file.read(limite + 1)
        if len(datos) > limite:
            raise ErrorApi(413, f"La imagen supera el maximo de {cfg.max_image_mb:g} MB.")
        if not datos:
            raise ErrorApi(422, "El archivo esta vacio.", {"imagen": ["archivo vacio"]})
        if tipo_por_firma(datos) is None:
            raise ErrorApi(415, "Formato no soportado: solo JPG, PNG o WebP.")
        img = cv2.imdecode(np.frombuffer(datos, np.uint8), cv2.IMREAD_COLOR)
        if img is None:
            raise ErrorApi(422, "No se pudo decodificar la imagen.", {"imagen": ["imagen corrupta"]})
        if img.shape[0] * img.shape[1] > MAX_PIXELES:
            raise ErrorApi(422, "La imagen tiene demasiados pixeles.", {"imagen": ["resolucion excesiva"]})

        ctx = Contexto()
        resultados: dict[str, Optional[dict]] = {}
        estados: dict[str, str] = {}
        # M1 es el modelo base; si falla -> 500. M2-M4 nunca rompen el endpoint.
        for clave in ("m1", "m2", "m3", "m4"):
            m = modelos[clave]
            resultados[clave], estados[clave] = None, (OK if m.disponible else NO_DISPONIBLE)
            if not m.disponible:
                continue
            try:
                resultados[clave] = m.inferir(img, ctx)
            except Exception:  # noqa: BLE001
                log.exception("Fallo %s en tiempo de inferencia", clave)
                estados[clave] = ERROR
                if clave == "m1":
                    raise ErrorApi(500, "Fallo el modelo de placas (M1).")

        lat_ms = int((time.perf_counter() - t0) * 1000)
        img_anotada_b64, overlay = _renderizar_evidencia(img, ctx, resultados, lat_ms)

        return {
            "success": True,
            "inference": {
                "placa": resultados["m1"],
                "vehiculo": resultados["m2"],
                "ejes": resultados["m3"],
                "pasajeros": resultados["m4"],
            },
            "overlay": overlay,
            "imagen_anotada_base64": img_anotada_b64,
            "modelos": estados,
            "latencia_ms": lat_ms,
        }

    # ------------------------------------------------------- tracking en vivo
    @app.websocket("/ws/track")
    async def ws_track(ws: WebSocket):
        """Stream binario JPEG -> JSON ligero de tracking (ByteTrack + M1 + M3/M4) en tiempo real."""
        cfg: Config = ws.app.state.cfg
        clave = cfg.api_key
        if clave:
            recibida = (ws.query_params.get("api_key") or ws.headers.get("x-api-key") or "").encode("utf-8")
            if not hmac.compare_digest(recibida, clave.encode("utf-8")):
                await ws.close(code=1008, reason="API key invalida")
                return
        await ws.accept()
        modelos = ws.app.state.modelos
        m1, m2 = modelos["m1"], modelos["m2"]
        try:
            while True:
                datos = await ws.receive_bytes()
                t0 = time.perf_counter()
                if not datos or len(datos) > cfg.max_image_bytes:
                    await ws.send_json({"ok": False, "error": "frame invalido"})
                    continue
                img = cv2.imdecode(np.frombuffer(datos, np.uint8), cv2.IMREAD_COLOR)
                if img is None:
                    await ws.send_json({"ok": False, "error": "imagen corrupta"})
                    continue
                h, w = img.shape[:2]
                ctx = Contexto()

                # Loop ultrarrapido: SOLO M1 (1 pasada, umbral 0.25 para movimiento) + M2 ByteTrack (320px).
                # M3 (ejes) y M4 (pasajeros) corren en maxima precision al registrar el paso en POST /inferir.
                r1 = None
                if m1.disponible:
                    try:
                        r1 = m1.inferir(img, ctx, max_candidatas=1, rapido=True, umbral=0.25)
                    except Exception:
                        r1 = None

                tracked = []
                if m2.disponible:
                    try:
                        tracked = m2.rastrear(img, imgsz=320)
                    except Exception:
                        tracked = []

                pcx = (r1["bbox"][0] + r1["bbox"][2]) / 2.0 if (r1 and r1.get("bbox")) else None
                pcy = (r1["bbox"][1] + r1["bbox"][3]) / 2.0 if (r1 and r1.get("bbox")) else None

                vehiculos_json = []
                for v in tracked:
                    x1, y1, x2, y2 = v["box"]
                    tiene_placa = (
                        pcx is not None and pcy is not None
                        and x1 - 15 <= pcx <= x2 + 15 and y1 - 15 <= pcy <= y2 + 15
                    )
                    vehiculos_json.append({
                        "track_id": v["track_id"],
                        "categoria": v["categoria"],
                        "confianza": v["confianza"],
                        "bbox": [round(x1 / w, 4), round(y1 / h, 4), round(x2 / w, 4), round(y2 / h, 4)],
                        "poligono": _simplificar_poly(v["poligono"], w, h, 24) if v["poligono"] is not None else [],
                        "placa": {
                            "texto": r1["texto"],
                            "confianza": r1["confianza"],
                            "formato_bolivia": r1.get("formato_bolivia", False),
                            "bbox": [round(r1["bbox"][0] / w, 4), round(r1["bbox"][1] / h, 4),
                                     round(r1["bbox"][2] / w, 4), round(r1["bbox"][3] / h, 4)],
                        } if tiene_placa else None,
                    })

                await ws.send_json({
                    "ok": True,
                    "latencia_ms": int((time.perf_counter() - t0) * 1000),
                    "placa_suelta": {
                        "texto": r1["texto"],
                        "confianza": r1["confianza"],
                        "formato_bolivia": r1.get("formato_bolivia", False),
                        "bbox": [round(r1["bbox"][0] / w, 4), round(r1["bbox"][1] / h, 4),
                                 round(r1["bbox"][2] / w, 4), round(r1["bbox"][3] / h, 4)],
                    } if (r1 and r1.get("bbox")) else None,
                    "vehiculos": vehiculos_json,
                    "ruedas_kps": [],
                    "pasajeros_poligonos": [],
                    "ejes": None,
                    "pasajeros": None,
                })
        except WebSocketDisconnect:
            pass

    return app


app = crear_app()
