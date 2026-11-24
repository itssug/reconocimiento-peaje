<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CatalogoController;
use App\Controllers\PasoController;
use App\Controllers\VehiculoController;
use App\Helpers\Router;

/** @var Router $router */
$ADMIN = ['roles' => ['ADMIN']];

// ---- 1. Autenticacion ----
$router->add('POST', '/api/v1/auth/google', [AuthController::class, 'google'], ['auth' => false]);
$router->add('GET',  '/api/v1/auth/me',     [AuthController::class, 'me']);
$router->add('POST', '/api/v1/auth/logout', [AuthController::class, 'logout']);

// ---- 2. Rutas obligatorias de la especificacion (Seccion 8 / 4.5) ----
$router->add('POST', '/api/v1/pasos/frame',             [PasoController::class, 'registrarFrame']);
$router->add('POST', '/api/v1/placas/consultar',        [PasoController::class, 'consultarPlacaTexto']);
$router->add('POST', '/api/v1/placas/consultar-imagen', [PasoController::class, 'consultarPlacaImagen']);
$router->add('GET',  '/api/v1/placas/{placa}/historial',[PasoController::class, 'historialPlaca']);
$router->add('GET',  '/api/v1/casetas/{id}/resumen',    [PasoController::class, 'resumenCaseta']);
$router->add('POST', '/api/v1/agente/chat',             [PasoController::class, 'agenteChat']);

// ---- 3. Pasos, evidencias, alertas, dashboard y estado ----
$router->add('GET',  '/api/v1/pasos',                 [PasoController::class, 'listarPasos']);
$router->add('GET',  '/api/v1/evidencias/{archivo}',  [PasoController::class, 'servirEvidencia']);
$router->add('GET',  '/api/v1/alertas',               [PasoController::class, 'listarAlertas']);
$router->add('PUT',  '/api/v1/alertas/{id}/atender',  [PasoController::class, 'atenderAlerta']);
$router->add('GET',  '/api/v1/dashboard/poll',        [PasoController::class, 'dashboardPoll']);
$router->add('GET',  '/api/v1/estado/servicios',      [PasoController::class, 'estadoServicios']);

// ---- 4. Vehiculos (lectura ADMIN y OPERADOR; escritura solo ADMIN) ----
$router->add('GET',    '/api/v1/vehiculos',      [VehiculoController::class, 'index']);
$router->add('GET',    '/api/v1/vehiculos/{id}', [VehiculoController::class, 'show']);
$router->add('POST',   '/api/v1/vehiculos',      [VehiculoController::class, 'store'], $ADMIN);
$router->add('PUT',    '/api/v1/vehiculos/{id}', [VehiculoController::class, 'update'], $ADMIN);
$router->add('DELETE', '/api/v1/vehiculos/{id}', [VehiculoController::class, 'destroy'], $ADMIN);

// ---- 5. Casetas ----
$router->add('GET',    '/api/v1/casetas',      [CatalogoController::class, 'casetas']);
$router->add('POST',   '/api/v1/casetas',      [CatalogoController::class, 'casetaCrear'], $ADMIN);
$router->add('PUT',    '/api/v1/casetas/{id}', [CatalogoController::class, 'casetaEditar'], $ADMIN);
$router->add('DELETE', '/api/v1/casetas/{id}', [CatalogoController::class, 'casetaDesactivar'], $ADMIN);

// ---- 6. Tarifas ----
$router->add('GET',    '/api/v1/tarifas',      [CatalogoController::class, 'tarifas']);
$router->add('POST',   '/api/v1/tarifas',      [CatalogoController::class, 'tarifaGuardar'], $ADMIN);
$router->add('PUT',    '/api/v1/tarifas/{id}', [CatalogoController::class, 'tarifaEditar'], $ADMIN);
$router->add('DELETE', '/api/v1/tarifas/{id}', [CatalogoController::class, 'tarifaDesactivar'], $ADMIN);

// ---- 7. Usuarios autorizados (lista blanca) ----
$router->add('GET',  '/api/v1/usuarios',      [CatalogoController::class, 'usuarios'], $ADMIN);
$router->add('POST', '/api/v1/usuarios',      [CatalogoController::class, 'usuarioAgregar'], $ADMIN);
$router->add('PUT',  '/api/v1/usuarios/{id}', [CatalogoController::class, 'usuarioEditar'], $ADMIN);
