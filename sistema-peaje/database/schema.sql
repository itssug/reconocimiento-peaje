-- Sistema Inteligente de Control de Peaje - Esquema SQLite
-- Los PRAGMA de conexion (foreign_keys, WAL, busy_timeout) se aplican en app/helpers/Database.php

CREATE TABLE IF NOT EXISTS usuarios (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    email_google TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    nombre       TEXT    NOT NULL,
    foto_url     TEXT,
    rol          TEXT    NOT NULL CHECK (rol IN ('ADMIN','OPERADOR')),
    activo       INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0,1)),
    created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Lista blanca de cuentas autorizadas (solo estas cuentas Google pueden ingresar)
CREATE TABLE IF NOT EXISTS lista_blanca (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    rol        TEXT    NOT NULL CHECK (rol IN ('ADMIN','OPERADOR')),
    activo     INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0,1)),
    creado_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Tokens de sesion de la aplicacion: solo se guarda el hash SHA-256
CREATE TABLE IF NOT EXISTS sesiones (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id  INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    token_hash  TEXT    NOT NULL UNIQUE,
    ip          TEXT,
    user_agent  TEXT,
    expira_en   TEXT    NOT NULL,
    revocada    INTEGER NOT NULL DEFAULT 0 CHECK (revocada IN (0,1)),
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_sesiones_usuario ON sesiones(usuario_id);
CREATE INDEX IF NOT EXISTS idx_sesiones_expira  ON sesiones(expira_en);

-- Control de intentos de acceso (limitacion de fuerza bruta)
CREATE TABLE IF NOT EXISTS intentos_login (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         TEXT    NOT NULL,
    email      TEXT,
    exitoso    INTEGER NOT NULL DEFAULT 0 CHECK (exitoso IN (0,1)),
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_intentos_ip ON intentos_login(ip, created_at);

CREATE TABLE IF NOT EXISTS vehiculos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    placa       TEXT    NOT NULL UNIQUE,            -- siempre normalizada (mayusculas, sin separadores)
    propietario TEXT,
    categoria   TEXT    NOT NULL CHECK (categoria IN ('motocicleta','automovil','minibus','camion','bus')),
    ejes        INTEGER NOT NULL DEFAULT 2 CHECK (ejes BETWEEN 2 AND 9),
    saldo       REAL    NOT NULL DEFAULT 0 CHECK (saldo >= 0),
    activo      INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0,1)),
    es_demo     INTEGER NOT NULL DEFAULT 0 CHECK (es_demo IN (0,1)),
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_vehiculos_categoria ON vehiculos(categoria);

CREATE TABLE IF NOT EXISTS casetas (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre     TEXT    NOT NULL UNIQUE,
    ubicacion  TEXT,
    activa     INTEGER NOT NULL DEFAULT 1 CHECK (activa IN (0,1)),
    es_demo    INTEGER NOT NULL DEFAULT 0 CHECK (es_demo IN (0,1)),
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS tarifas (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    categoria TEXT    NOT NULL CHECK (categoria IN ('motocicleta','automovil','minibus','camion','bus')),
    ejes      INTEGER NOT NULL CHECK (ejes BETWEEN 2 AND 9),
    monto     REAL    NOT NULL CHECK (monto >= 0),
    activa    INTEGER NOT NULL DEFAULT 1 CHECK (activa IN (0,1)),
    UNIQUE (categoria, ejes)
);

CREATE TABLE IF NOT EXISTS pasos (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    placa_leida         TEXT,
    placa_normalizada   TEXT,
    confianza_ocr       REAL CHECK (confianza_ocr IS NULL OR (confianza_ocr >= 0 AND confianza_ocr <= 1)),
    categoria_detectada TEXT CHECK (categoria_detectada IS NULL OR categoria_detectada IN ('motocicleta','automovil','minibus','camion','bus')),
    ejes_detectados     INTEGER CHECK (ejes_detectados IS NULL OR ejes_detectados BETWEEN 1 AND 12),
    pasajeros           INTEGER CHECK (pasajeros IS NULL OR pasajeros BETWEEN 0 AND 99), -- conteo (M4), nunca rostros
    monto               REAL    NOT NULL DEFAULT 0 CHECK (monto >= 0),
    caseta_id           INTEGER NOT NULL REFERENCES casetas(id) ON DELETE RESTRICT,
    usuario_id          INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    imagen_url          TEXT,
    estado              TEXT    NOT NULL DEFAULT 'REGISTRADO'
                        CHECK (estado IN ('REGISTRADO','OBSERVADO','ERROR_INFERENCIA')),
    origen              TEXT    NOT NULL DEFAULT 'REAL' CHECK (origen IN ('REAL','DEMO')),
    created_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_pasos_placa   ON pasos(placa_normalizada);
CREATE INDEX IF NOT EXISTS idx_pasos_fecha   ON pasos(created_at);
CREATE INDEX IF NOT EXISTS idx_pasos_caseta  ON pasos(caseta_id, created_at);
CREATE INDEX IF NOT EXISTS idx_pasos_estado  ON pasos(estado);

CREATE TABLE IF NOT EXISTS alertas (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    paso_id      INTEGER REFERENCES pasos(id) ON DELETE CASCADE,
    tipo         TEXT    NOT NULL CHECK (tipo IN (
                    'PLACA_NO_REGISTRADA','SIN_SALDO','OCR_BAJA_CONFIANZA','VEHICULO_NO_IDENTIFICADO',
                    'DATOS_INCOMPLETOS','ERROR_INFERENCIA','INCONSISTENCIA_CATEGORIA_EJES')),
    descripcion  TEXT    NOT NULL,
    atendida     INTEGER NOT NULL DEFAULT 0 CHECK (atendida IN (0,1)),
    atendida_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    atendida_en  TEXT,
    es_demo      INTEGER NOT NULL DEFAULT 0 CHECK (es_demo IN (0,1)),
    created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_alertas_atendida ON alertas(atendida, created_at);

CREATE TABLE IF NOT EXISTS historial_actividad (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    accion     TEXT NOT NULL,
    entidad    TEXT,
    entidad_id INTEGER,
    detalle    TEXT,        -- nunca incluir tokens ni credenciales
    ip         TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_actividad_fecha ON historial_actividad(created_at);

CREATE TABLE IF NOT EXISTS configuracion (
    clave      TEXT PRIMARY KEY,
    valor      TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Resultado de la ultima comprobacion del servicio de inferencia (pagina de estado)
CREATE TABLE IF NOT EXISTS estado_servicios (
    servicio        TEXT PRIMARY KEY,
    ok              INTEGER NOT NULL DEFAULT 0,
    latencia_ms     INTEGER,
    ultimo_error    TEXT,
    comprobado_en   TEXT NOT NULL DEFAULT (datetime('now'))
);
