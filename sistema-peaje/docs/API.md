# Documentación de la API REST — Sistema Inteligente de Control de Peaje

Todas las respuestas siguen el formato JSON estandarizado:

```json
{
  "success": true,
  "message": "OK",
  "data": {},
  "errors": []
}
```

## Autenticación

Todas las rutas, **excepto** `POST /api/v1/auth/google`, requieren autenticación mediante:
- Cabecera `Authorization: Bearer <token_de_sesion>` (para aplicaciones móviles y clientes REST), o
- Cookie `HttpOnly` `peaje_session` + cabecera `X-CSRF-Token` en peticiones `POST`, `PUT` y `DELETE` (para el panel web).

---

## 1. `POST /api/v1/auth/google`

Valida el ID Token de Google OAuth 2.0 (firma RS256 contra JWKS oficial de Google, `aud`, `iss`, `exp`, `email_verified`), verifica que el correo pertenezca a la `lista_blanca` activa y emite un token de sesión propio (almacenando únicamente su hash SHA-256).

### Solicitud
```http
POST /api/v1/auth/google HTTP/1.1
Content-Type: application/json

{
  "credential": "eyJhbGciOiJSUzI1NiIsImtpZCI6..."
}
```

### Respuesta exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Inicio de sesion correcto.",
  "data": {
    "token": "f93a1e04b7c2...64_hex_chars",
    "token_type": "Bearer",
    "expires_at": "2026-11-24T20:00:00Z",
    "csrf_token": "8d12c4...",
    "user": {
      "id": 1,
      "email": "doc.alvaro.pinto.ma@unifranz.edu.bo",
      "nombre": "Alvaro Pinto",
      "foto_url": "https://lh3.googleusercontent.com/...",
      "rol": "ADMIN"
    }
  },
  "errors": []
}
```

### Errores posibles
- `401 Unauthorized`: Token de Google inválido, expirado o con firma/audiencia incorrecta.
- `403 Forbidden`: Correo de Google no autorizado en la lista blanca.
- `429 Too Many Requests`: Límite de intentos fallidos superado desde la IP.

---

## 2. `POST /api/v1/pasos/frame`

Recibe una imagen o frame desde la caseta (aplicación móvil o panel web), la almacena con nombre aleatorio seguro fuera del directorio público, invoca al servicio externo de inferencia (`INFERENCE_URL` para M1+M2+M3+M4), calcula la tarifa según categoría y ejes, descuenta saldo dentro de una transacción SQLite y genera alertas automáticas si corresponde.

### Solicitud (`multipart/form-data` o `application/json`)
- `caseta_id` (int, obligatorio): ID de la caseta activa.
- `imagen` (archivo JPG/PNG/WebP, máx. 5 MB) o `imagen_base64` (string base64).

```http
POST /api/v1/pasos/frame HTTP/1.1
Authorization: Bearer <token>
Content-Type: multipart/form-data; boundary=---boundary

---boundary
Content-Disposition: form-data; name="caseta_id"

1
---boundary
Content-Disposition: form-data; name="imagen"; filename="frame.jpg"
Content-Type: image/jpeg

<bytes_jpeg>
---boundary--
```

### Respuesta exitosa (`201 Created`)
```json
{
  "success": true,
  "message": "Paso registrado.",
  "data": {
    "paso": {
      "id": 37,
      "placa_leida": "1001DEM",
      "placa_normalizada": "1001DEM",
      "confianza_ocr": 0.96,
      "categoria_detectada": "automovil",
      "ejes_detectados": 2,
      "pasajeros": 2,
      "monto": 5.0,
      "caseta_id": 1,
      "caseta_nombre": "DEMO Caseta Norte",
      "usuario_id": 1,
      "operador_nombre": "Operador",
      "imagen_url": "/api/v1/evidencias/20261124_124139_8b463741dc4ee3f1c600f8dec5c741de.png",
      "estado": "REGISTRADO",
      "origen": "REAL",
      "created_at": "2026-11-24T12:41:39Z"
    },
    "vehiculo": {
      "id": 1,
      "placa": "1001DEM",
      "propietario": "DEMO - Propietario ficticio 01",
      "categoria": "automovil",
      "ejes": 2,
      "saldo": 95.0,
      "activo": true
    },
    "alertas_generadas": [],
    "inferencia": {
      "modo": "REAL",
      "latencia_ms": 245,
      "ok": true
    }
  },
  "errors": []
}
```

---

## 3. `POST /api/v1/placas/consultar`

Consulta una placa por texto, la normaliza (mayúsculas, sin guiones ni espacios), corrige confusiones típicas de OCR (`O↔0`, `I↔1`, `B↔8`, `S↔5`) y devuelve uno de los 4 estados:
- `REGISTRADA_CON_PASO`
- `REGISTRADA_SIN_PASO`
- `IDENTIFICADA_NO_REGISTRADA`
- `NO_EXISTE`

### Solicitud
```json
{
  "placa": "1001-dem"
}
```

### Respuesta exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "placa_consultada": "1001-dem",
    "placa_normalizada": "1001DEM",
    "formato_boliviano": true,
    "autocorregida_desde": null,
    "estado_placa": "REGISTRADA_CON_PASO",
    "estado_descripcion": "Registrada y con paso",
    "vehiculo": {
      "id": 1,
      "placa": "1001DEM",
      "propietario": "DEMO - Propietario ficticio 01",
      "categoria": "motocicleta",
      "ejes": 2,
      "saldo": 100.0,
      "activo": true
    },
    "ultimo_paso": {
      "id": 37,
      "monto": 2.0,
      "caseta_nombre": "DEMO Caseta Norte",
      "created_at": "2026-11-24T12:41:39Z"
    },
    "total_pasos": 3,
    "sugerencias": []
  },
  "errors": []
}
```

---

## 4. `POST /api/v1/placas/consultar-imagen`

Recibe una imagen (`multipart/form-data` campo `imagen` o JSON `imagen_base64`), invoca al modelo M1 + OCR y consulta el estado de la placa identificada en la base de datos.

### Respuesta exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "placa_detectada": true,
    "inferencia": {
      "ok": true,
      "modo": "REAL",
      "placa": {
        "texto": "1001DEM",
        "confianza": 0.96
      },
      "latencia_ms": 180
    },
    "consulta": {
      "placa_normalizada": "1001DEM",
      "estado_placa": "REGISTRADA_CON_PASO"
    }
  },
  "errors": []
}
```

---

## 5. `GET /api/v1/placas/{placa}/historial`

Devuelve el estado del vehículo y la lista cronológica de pasos registrados para esa placa.

### Respuesta exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "placa_normalizada": "1001DEM",
    "estado_placa": "REGISTRADA_CON_PASO",
    "vehiculo": { "id": 1, "placa": "1001DEM", "categoria": "motocicleta", "ejes": 2 },
    "total_pasos": 2,
    "pasos": [
      {
        "id": 37,
        "placa_leida": "1001DEM",
        "placa_normalizada": "1001DEM",
        "confianza_ocr": 0.92,
        "categoria_detectada": "motocicleta",
        "ejes_detectados": 2,
        "pasajeros": 1,
        "monto": 2.0,
        "caseta_id": 1,
        "caseta_nombre": "DEMO Caseta Norte",
        "estado": "REGISTRADO",
        "created_at": "2026-11-24T12:41:39Z"
      }
    ]
  },
  "errors": []
}
```

---

## 6. `GET /api/v1/casetas/{id}/resumen`

Devuelve el conteo de pasos y la recaudación del día (en la zona horaria `America/La_Paz`) y acumulada de la caseta indicada.

### Respuesta exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "caseta": { "id": 1, "nombre": "DEMO Caseta Norte", "activa": true },
    "fecha": "2026-11-24",
    "pasos_hoy": 18,
    "recaudacion_hoy": 245.0,
    "observados_hoy": 3,
    "pasos_total": 18,
    "recaudacion_total": 245.0,
    "por_categoria_hoy": [
      { "categoria": "automovil", "cantidad": 8, "recaudacion": 40.0 }
    ]
  },
  "errors": []
}
```

---

## 7. `POST /api/v1/agente/chat`

Recibe un mensaje en lenguaje natural (`mensaje`) y/o una imagen (`imagen` o `imagen_base64`). Consulta datos reales de SQLite y responde sin inventar información.

### Solicitud
```json
{
  "mensaje": "Consultar placa 1001DEM"
}
```

### Respuesta exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "modo_agente": "DEMOSTRACION",
    "tipo_consulta": "CONSULTA_PLACA",
    "estado_placa": "REGISTRADA_CON_PASO",
    "respuesta": "Estado: REGISTRADA Y CON PASO.\n• Placa: 1001DEM | Propietario: DEMO - Propietario ficticio 01...",
    "datos": {
      "placa_normalizada": "1001DEM",
      "estado_placa": "REGISTRADA_CON_PASO"
    }
  },
  "errors": []
}
```

---

## 8. Rutas complementarias del panel administrativo

| Método | Ruta | Rol | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/auth/me` | Todos | Usuario actual y token CSRF |
| `POST` | `/api/v1/auth/logout` | Todos | Revoca el token de sesión actual |
| `GET` | `/api/v1/dashboard/poll` | Todos | Datos incrementales cada 2 s (`since_paso_id`, `since_alerta_id`) |
| `GET` | `/api/v1/pasos` | Todos | Historial paginado con filtros (`placa`, `categoria`, `caseta_id`, `estado`, `desde`, `hasta`) |
| `GET` | `/api/v1/evidencias/{archivo}` | Todos | Sirve imagen de evidencia autenticada desde `storage/evidencias` |
| `GET` | `/api/v1/alertas` | Todos | Lista alertas (`atendida`, `tipo`) |
| `PUT` | `/api/v1/alertas/{id}/atender` | Todos | Marca una alerta como atendida |
| `GET` | `/api/v1/vehiculos` | Todos | Lista y filtra vehículos (`q`, `categoria`, `activo`) |
| `POST` | `/api/v1/vehiculos` | `ADMIN` | Registra un vehículo (valida y normaliza placa boliviana) |
| `PUT` | `/api/v1/vehiculos/{id}` | `ADMIN` | Actualiza vehículo |
| `DELETE` | `/api/v1/vehiculos/{id}` | `ADMIN` | Desactiva vehículo (borrado lógico) |
| `GET/POST/PUT/DELETE` | `/api/v1/casetas[/{id}]` | Lectura todos / Escritura `ADMIN` | Gestión de casetas |
| `GET/POST/PUT/DELETE` | `/api/v1/tarifas[/{id}]` | Lectura todos / Escritura `ADMIN` | Gestión de tarifas por categoría y ejes |
| `GET/POST/PUT` | `/api/v1/usuarios[/{id}]` | `ADMIN` | Gestión de lista blanca de cuentas Google autorizadas |
| `GET` | `/api/v1/estado/servicios` | Todos | Estado de PHP, SQLite y latencia del endpoint de inferencia |
