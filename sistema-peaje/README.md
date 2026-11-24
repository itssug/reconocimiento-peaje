# Sistema Inteligente de Control de Peaje (PHP 8.3 + SQLite)

Aplicación web y API REST desarrolladas en **PHP 8.3 puro** (sin frameworks ni dependencia de Composer en producción) con **SQLite (PDO)**, **Bootstrap 5**, **Google OAuth 2.0** (validación criptográfica RS256 en servidor + lista blanca) e integración por HTTP (`INFERENCE_URL`) con el servicio externo de visión por computadora (M1 placas/OCR, M2 clasificación por segmentación, M3 conteo de ejes por keypoints, M4 conteo de pasajeros).

---

## 1. Estructura del proyecto

```text
sistema-peaje/
├── public/                 # Único directorio público (public_html en HostGator)
│   ├── index.php           # Front-controller de /api/v1/*
│   ├── login.php           # Inicio de sesión exclusivo con Google Sign-In
│   ├── dashboard.php       # Panel en tiempo real (polling cada 2 s sin duplicados)
│   ├── vehiculos.php       # CRUD de vehículos con validación de placas bolivianas
│   ├── pasos.php           # Registro de frames e historial de pasos con filtros
│   ├── alertas.php         # Gestión y atención de alertas
│   ├── chat.php            # Agente conversacional (texto + imagen M1)
│   ├── estado.php          # Estado de PHP, SQLite y endpoint de inferencia
│   ├── administracion.php  # Casetas, tarifas y lista blanca de usuarios (ADMIN)
│   ├── diagnostico.php     # Diagnóstico del entorno sin exponer secretos
│   ├── assets/             # CSS y JS modulares
│   └── .htaccess           # Protección y rewrite de /api/
├── app/
│   ├── bootstrap.php       # Autoload PSR-4 sin Composer + manejo de errores
│   ├── config/             # app.php y database.php
│   ├── controllers/        # AuthController, VehiculoController, CatalogoController, PasoController
│   ├── services/           # GoogleTokenVerifier, AuthService, VehiculoService, CatalogoService, PasoService, InferenceService, EvidenceService, AgenteService, Installer
│   ├── middleware/         # AuthMiddleware (Bearer + Cookie HttpOnly + CSRF + Roles)
│   ├── models/             # Categoria
│   └── helpers/            # Env, Config, Database, Router, Request, Response, Plate, Input, View, Logger, Dates
├── routes/
│   └── api.php             # Definición de rutas REST /api/v1/*
├── database/
│   ├── schema.sql          # Esquema relacional SQLite (12 tablas, FK, CHECK, índices)
│   └── seeders/            # TarifasBase.php y DemoSeeder.php (22 vehículos DEMO, 2 casetas, 36 pasos, alertas)
├── storage/                # Fuera del directorio público (protegido con .htaccess)
│   ├── database/           # Base de datos SQLite (WAL)
│   ├── evidencias/         # Imágenes de evidencia con nombre aleatorio y ejecución deshabilitada
│   └── logs/               # Logs con redacción automática de tokens
├── docs/
│   └── API.md              # Documentación completa de la API REST
├── tests/                  # Suites de pruebas automatizadas (etapa1, etapa3, etapa4, http_api)
├── .env.example            # Plantilla de configuración
└── install.php             # Instalador seguro con autobloqueo
```

---

## 2. Requisitos del servidor (HostGator / cPanel)

- **PHP 8.3** o superior.
- Extensiones PHP: `PDO`, `pdo_sqlite`, `curl`, `openssl`, `json`, `mbstring`, `fileinfo`.
- Permisos de escritura (`0750` o `0755`) en `storage/database`, `storage/evidencias` y `storage/logs`.

---

## 3. Despliegue en HostGator (FTP o Administrador de archivos de cPanel)

### Opción A (Recomendada): Separar archivos privados fuera de `public_html`
1. En cPanel, cree una carpeta privada fuera de `public_html`, por ejemplo `/home/usuario/sistema-peaje/`.
2. Suba allí `app/`, `routes/`, `database/`, `storage/`, `install.php` y cree el archivo `.env` a partir de `.env.example`.
3. Suba el contenido de `public/` dentro del directorio del subdominio (ej. `/home/usuario/public_html/peaje/`).
4. Si la ruta entre `public/` y la raíz cambia, ajuste `BASE_PATH` en `app/bootstrap.php`.

### Opción B: Todo dentro de la carpeta del subdominio
Si el subdominio apunta a la raíz del proyecto:
- Los archivos `.htaccess` incluidos en la raíz, `app/`, `storage/` y `database/` bloquean todo acceso directo (`Require all denied`) a archivos `.sqlite`, `.env`, `.log` y código fuente.

### Configuración de `.env`
Copie `.env.example` a `.env` y configure:
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://peaje.su-dominio.com
APP_KEY=<64_caracteres_hex_aleatorios>
INSTALL_TOKEN=<token_secreto_para_instalar>

GOOGLE_CLIENT_ID=<su-client-id>.apps.googleusercontent.com
INFERENCE_URL=https://<su-endpoint-runpod-o-colab>
INFERENCE_API_KEY=<su-api-key-de-inferencia>
DIAG_ENABLED=false
```

### Ejecución del instalador
- **Por navegador (sin SSH):** visite `install.php`, ingrese el `INSTALL_TOKEN` definido en `.env`, marque "Insertar datos de DEMOSTRACION" si desea los 22 vehículos y 2 casetas de prueba, y pulse **Instalar**. Al finalizar se crea `storage/installed.lock` y **debe eliminar `install.php`**.
- **En desarrollo (CLI):**
  ```bash
  php install.php --demo
  ```

---

## 4. Cuenta autorizada por defecto

El instalador registra automáticamente en `lista_blanca`:
- `doc.alvaro.pinto.ma@unifranz.edu.bo` — Rol: **`ADMIN`**

Cualquier otra cuenta de Google recibirá `403 Forbidden` hasta que un administrador la agregue en **Administración → Usuarios autorizados**.

---

## 5. Ejecución de las pruebas obligatorias

```bash
php tests/etapa1.php
php tests/etapa3.php
php tests/etapa4.php
php tests/http_api.php
```
