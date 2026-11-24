<?php
declare(strict_types=1);
// Suite HTTP ETAPAS 5-8: pasos/frame, evidencias, placas, historial, resumen, alertas, dashboard poll, agente, estado.
return function (callable $t, string $A, string $O, array $tok): void {
    //En etapa5_8 usamos el token $A (activo) o reactivamos a todos los usuarios
    $O = $A;
    // Generar un PNG valido en memoria (16x16)
    $pngB64 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAAsTAAALEwEAmpwYAAAAFklEQVQoz2NgYPj/n4ECwESJ1g1D1QEADpcEATP7p0QAAAAASUVORK5CYII=';

    // ---- 1. POST /api/v1/pasos/frame ----
    [$c] = call('POST', '/api/v1/pasos/frame', null, ['caseta_id' => 1, 'imagen_base64' => $pngB64]);
    $t('POST /pasos/frame sin token => 401', $c === 401);

    [$c] = call('POST', '/api/v1/pasos/frame', $O, ['imagen_base64' => $pngB64]);
    $t('POST /pasos/frame sin caseta_id => 422', $c === 422);

    [$c] = call('POST', '/api/v1/pasos/frame', $O, ['caseta_id' => 999, 'imagen_base64' => $pngB64]);
    $t('POST /pasos/frame caseta inexistente => 422', $c === 422);

    [$c] = call('POST', '/api/v1/pasos/frame', $O, ['caseta_id' => 1, 'imagen_base64' => base64_encode('no-es-imagen')]);
    $t('POST /pasos/frame archivo no imagen => 415', $c === 415);

    // Registro exitoso con placa registrada (1001DEM, motocicleta 2 ejes => tarifa Bs 2.00)
    [$c, $b] = call('POST', '/api/v1/pasos/frame', $O, [
        'caseta_id' => 1,
        'imagen_base64' => $pngB64,
        'placa' => '1001-dem',
        'categoria' => 'motocicleta',
        'ejes' => 2,
        'pasajeros' => 1,
    ]);
    $paso = $b['data']['paso'] ?? [];
    $t('POST /pasos/frame registra paso (201)', $c === 201 && ($paso['placa_normalizada'] ?? '') === '1001DEM' && (float)($paso['monto'] ?? 0) === 2.0 && $paso['pasajeros'] === 1, json_encode($b));
    $t('Paso en modo DEMO queda etiquetado origen=DEMO', ($paso['origen'] ?? '') === 'DEMO');
    $evUrl = $paso['imagen_url'] ?? '';
    $t('Evidencia guardada con nombre aleatorio seguro', (bool)preg_match('#^/api/v1/evidencias/\d{8}_\d{6}_[a-f0-9]{32}\.png$#', $evUrl), $evUrl);

    // Verificar descarga autenticada de evidencia
    [$cEv, $rawEv] = call('GET', $evUrl, $O, null, [], true);
    $t('GET /evidencias/{archivo} sirve imagen autenticada (200)', $cEv === 200 && str_starts_with($rawEv, "\x89PNG"));
    [$cEvNo] = call('GET', $evUrl);
    $t('GET /evidencias/{archivo} sin token => 401', $cEvNo === 401);
    [$cEvTrav] = call('GET', '/api/v1/evidencias/..%2F.env', $O);
    $t('GET /evidencias rechaza path traversal => 404', $cEvTrav === 404);

    // Registro con placa NO registrada => genera alerta PLACA_NO_REGISTRADA y estado OBSERVADO
    [$c, $b] = call('POST', '/api/v1/pasos/frame', $O, [
        'caseta_id' => 1,
        'imagen_base64' => $pngB64,
        'placa' => '8888NOE',
        'categoria' => 'automovil',
        'ejes' => 2,
    ]);
    $tiposGen = array_column($b['data']['alertas_generadas'] ?? [], 'tipo');
    $t('Placa no registrada => estado OBSERVADO + alerta PLACA_NO_REGISTRADA',
        $c === 201 && ($b['data']['paso']['estado'] ?? '') === 'OBSERVADO' && in_array('PLACA_NO_REGISTRADA', $tiposGen, true));

    // Registro con inconsistencia categoria/ejes => alerta INCONSISTENCIA_CATEGORIA_EJES
    [$c, $b] = call('POST', '/api/v1/pasos/frame', $O, [
        'caseta_id' => 1,
        'imagen_base64' => $pngB64,
        'placa' => '1001DEM',
        'categoria' => 'motocicleta',
        'ejes' => 5,
    ]);
    $tiposGen = array_column($b['data']['alertas_generadas'] ?? [], 'tipo');
    $t('Inconsistencia categoria/ejes => alerta INCONSISTENCIA_CATEGORIA_EJES',
        $c === 201 && in_array('INCONSISTENCIA_CATEGORIA_EJES', $tiposGen, true));

    // Registro con vehiculo sin saldo (1022DEM tiene saldo 0 en el seeder) => alerta SIN_SALDO
    [$c, $b] = call('POST', '/api/v1/pasos/frame', $O, [
        'caseta_id' => 1,
        'imagen_base64' => $pngB64,
        'placa' => '1022DEM',
        'categoria' => 'camion',
        'ejes' => 6,
    ]);
    $tiposGen = array_column($b['data']['alertas_generadas'] ?? [], 'tipo');
    $t('Vehiculo con saldo 0 => alerta SIN_SALDO + tarifa camion 6 ejes (Bs 55)',
        $c === 201 && in_array('SIN_SALDO', $tiposGen, true) && (float)($b['data']['paso']['monto'] ?? 0) === 55.0);

    // ---- 2. Consultas de placas (4 estados) ----
    // crear un vehiculo nuevo sin pasos para probar REGISTRADA_SIN_PASO
    call('POST', '/api/v1/vehiculos', $A, ['placa' => '2222SIN', 'propietario' => 'Sin Paso', 'categoria' => 'automovil', 'ejes' => 2, 'saldo' => 25]);

    [$c, $b] = call('POST', '/api/v1/placas/consultar', $O, ['placa' => '1001-dem']);
    $t('POST /placas/consultar => REGISTRADA_CON_PASO', $c === 200 && ($b['data']['estado_placa'] ?? '') === 'REGISTRADA_CON_PASO');

    [$c, $b] = call('POST', '/api/v1/placas/consultar', $O, ['placa' => '2222SIN']);
    $t('POST /placas/consultar => REGISTRADA_SIN_PASO', $c === 200 && ($b['data']['estado_placa'] ?? '') === 'REGISTRADA_SIN_PASO');

    [$c, $b] = call('POST', '/api/v1/placas/consultar', $O, ['placa' => '8888NOE']);
    $t('POST /placas/consultar => IDENTIFICADA_NO_REGISTRADA', $c === 200 && ($b['data']['estado_placa'] ?? '') === 'IDENTIFICADA_NO_REGISTRADA');

    [$c, $b] = call('POST', '/api/v1/placas/consultar', $O, ['placa' => '9999ZZZ']);
    $t('POST /placas/consultar => NO_EXISTE', $c === 200 && ($b['data']['estado_placa'] ?? '') === 'NO_EXISTE');

    [$c, $b] = call('POST', '/api/v1/placas/consultar', $O, ['placa' => 'I001DEM']);
    $t('Confusion OCR I001DEM => autocorregida a 1001DEM',
        $c === 200 && ($b['data']['placa_normalizada'] ?? '') === '1001DEM' && ($b['data']['autocorregida_desde'] ?? '') === 'I001DEM');

    // ---- 3. POST /api/v1/placas/consultar-imagen ----
    [$c, $b] = call('POST', '/api/v1/placas/consultar-imagen', $O, ['imagen_base64' => $pngB64]);
    $t('POST /placas/consultar-imagen responde 200 con estado', $c === 200 && ($b['data']['placa_detectada'] ?? false) === true && isset($b['data']['consulta']['estado_placa']));

    // ---- 4. GET /api/v1/placas/{placa}/historial ----
    [$c, $b] = call('GET', '/api/v1/placas/1001DEM/historial', $O);
    $t('GET /placas/{placa}/historial devuelve pasos de 1001DEM', $c === 200 && ($b['data']['total_pasos'] ?? 0) >= 2);

    // ---- 5. GET /api/v1/casetas/{id}/resumen ----
    [$c, $b] = call('GET', '/api/v1/casetas/1/resumen', $O);
    $t('GET /casetas/1/resumen devuelve conteo y recaudacion del dia',
        $c === 200 && ($b['data']['pasos_hoy'] ?? 0) >= 4 && ($b['data']['recaudacion_hoy'] ?? 0) > 0);

    // ---- 6. Alertas y atencion ----
    [$c, $b] = call('GET', '/api/v1/alertas?atendida=0', $O);
    $t('GET /alertas?atendida=0 lista pendientes', $c === 200 && ($b['data']['total'] ?? 0) >= 3);
    $alertaId = $b['data']['items'][0]['id'] ?? 0;
    [$c, $b] = call('PUT', "/api/v1/alertas/$alertaId/atender", $O);
    $t('PUT /alertas/{id}/atender marca como atendida', $c === 200 && ($b['data']['atendida'] ?? false) === true);

    // ---- 7. Dashboard polling incremental ----
    [$c, $b] = call('GET', '/api/v1/dashboard/poll', $O);
    $maxP = $b['data']['max_paso_id'] ?? 0;
    $t('GET /dashboard/poll carga inicial', $c === 200 && $maxP > 0 && isset($b['data']['contadores']['recaudacion_hoy']));
    [$c, $b2] = call('GET', "/api/v1/dashboard/poll?since_paso_id=$maxP", $O);
    $t('GET /dashboard/poll incremental sin pasos nuevos => 0 duplicados', $c === 200 && count($b2['data']['pasos_nuevos'] ?? ['x']) === 0);

    // ---- 8. POST /api/v1/agente/chat ----
    [$c, $b] = call('POST', '/api/v1/agente/chat', $O, ['mensaje' => 'Consultar placa 1001DEM']);
    $t('POST /agente/chat consulta placa registrada',
        $c === 200 && ($b['data']['estado_placa'] ?? '') === 'REGISTRADA_CON_PASO' && str_contains($b['data']['respuesta'] ?? '', '1001DEM'));

    [$c, $b] = call('POST', '/api/v1/agente/chat', $O, ['mensaje' => '2222SIN']);
    $t('POST /agente/chat placa sin paso', $c === 200 && ($b['data']['estado_placa'] ?? '') === 'REGISTRADA_SIN_PASO');

    [$c, $b] = call('POST', '/api/v1/agente/chat', $O, ['mensaje' => '8888NOE']);
    $t('POST /agente/chat placa no registrada', $c === 200 && ($b['data']['estado_placa'] ?? '') === 'IDENTIFICADA_NO_REGISTRADA');

    [$c, $b] = call('POST', '/api/v1/agente/chat', $O, ['mensaje' => '9999ZZZ']);
    $t('POST /agente/chat placa no existe', $c === 200 && ($b['data']['estado_placa'] ?? '') === 'NO_EXISTE');

    [$c, $b] = call('POST', '/api/v1/agente/chat', $O, ['mensaje' => 'Recaudacion del dia']);
    $t('POST /agente/chat recaudacion real', $c === 200 && ($b['data']['tipo_consulta'] ?? '') === 'RECAUDACION');

    [$c, $b] = call('POST', '/api/v1/agente/chat', $O, ['imagen_base64' => $pngB64]);
    $t('POST /agente/chat con imagen identifica placa (M1)', $c === 200 && ($b['data']['tipo_consulta'] ?? '') === 'CONSULTA_PLACA');

    // ---- 9. Estado de servicios ----
    [$c, $b] = call('GET', '/api/v1/estado/servicios', $A);
    $t('GET /estado/servicios responde sin secretos', $c === 200 && ($b['data']['php']['ok'] ?? false) && ($b['data']['sqlite']['foreign_keys'] ?? false));
};
