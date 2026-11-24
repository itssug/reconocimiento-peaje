"""Genera una imagen sintetica (parte trasera de un vehiculo con placa) con OpenCV.

No es una foto real: sirve para probar el flujo completo. Uso: python scripts/generar_imagen_prueba.py [salida.jpg] [TEXTO]
"""
from __future__ import annotations

import sys
from pathlib import Path

import cv2
import numpy as np


def generar(texto: str = "2345ABC", ancho: int = 960, alto: int = 720) -> np.ndarray:
    rng = np.random.default_rng(7)
    img = np.full((alto, ancho, 3), (150, 150, 150), np.uint8)
    img[int(alto * 0.62):] = (95, 95, 100)  # asfalto
    # carroceria
    cv2.rectangle(img, (130, 150), (830, 560), (40, 40, 150), -1)
    cv2.rectangle(img, (220, 190), (740, 340), (60, 55, 50), -1)  # luneta
    cv2.rectangle(img, (130, 540), (830, 590), (30, 30, 30), -1)  # paragolpes
    for x in (160, 760):
        cv2.rectangle(img, (x, 370), (x + 50, 440), (30, 30, 220), -1)  # luces
    cv2.circle(img, (210, 600), 55, (20, 20, 20), -1)
    cv2.circle(img, (750, 600), 55, (20, 20, 20), -1)
    # placa
    x0, y0, w, h = 330, 430, 300, 100
    cv2.rectangle(img, (x0 - 4, y0 - 4), (x0 + w + 4, y0 + h + 4), (20, 20, 20), -1)
    cv2.rectangle(img, (x0, y0), (x0 + w, y0 + h), (245, 245, 245), -1)
    fuente, escala, grosor = cv2.FONT_HERSHEY_DUPLEX, 2.3, 5
    (tw, th), _ = cv2.getTextSize(texto, fuente, escala, grosor)
    cv2.putText(img, texto, (x0 + (w - tw) // 2, y0 + (h + th) // 2), fuente, escala, (15, 15, 15), grosor, cv2.LINE_AA)
    ruido = rng.normal(0, 4, img.shape)
    return np.clip(img.astype(np.float32) + ruido, 0, 255).astype(np.uint8)


if __name__ == "__main__":
    salida = Path(sys.argv[1] if len(sys.argv) > 1 else "tests/data/placa_sintetica.jpg")
    texto = sys.argv[2] if len(sys.argv) > 2 else "2345ABC"
    salida.parent.mkdir(parents=True, exist_ok=True)
    cv2.imwrite(str(salida), generar(texto), [cv2.IMWRITE_JPEG_QUALITY, 92])
    print("escrito", salida)
