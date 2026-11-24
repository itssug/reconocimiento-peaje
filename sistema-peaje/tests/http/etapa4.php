<?php
declare(strict_types=1);
// Suite HTTP ETAPA 4: vehiculos, casetas, tarifas, usuarios, paginas.
return function (callable $t, string $A, string $O, array $tok): void {
    $j = static fn(array $r) => $r[1];

    // ---- Vehiculos: lectura ----
    [$c, $b] = call('GET', '/api/v1/vehiculos?per_page=100', $O);
    $t('Operador lista vehiculos (200, >=20)', $c === 200 && $b['success'] === true && $b['data']['total'] >= 20 && isset($b['errors']), "$c");
    $t('Sobre JSON consistente', array_keys($b) === ['success', 'message', 'data', 'errors']);
    [$c, $b] = call('GET', '/api/v1/vehiculos?q=1001', $O);
    $t('Buscar por placa', $c === 200 && $b['data']['total'] === 1 && $b['data']['items'][0]['placa'] === '1001DEM');
    [$c, $b] = call('GET', '/api/v1/vehiculos?categoria=camion', $O);
    $ok = $c === 200 && $b['data']['total'] > 0;
    foreach ($b['data']['items'] ?? [] as $v) { $ok = $ok && $v['categoria'] === 'camion'; }
    $t('Filtrar por categoria', $ok);
    [$c] = call('GET', '/api/v1/vehiculos?categoria=tanque', $O);
    $t('Categoria invalida => 422', $c === 422);
    [$c, $b] = call('GET', "/api/v1/vehiculos?q=" . rawurlencode("' OR 1=1 --"), $O);
    $t('Inyeccion SQL => 0 resultados', $c === 200 && $b['data']['total'] === 0);
    [$c] = call('GET', '/api/v1/vehiculos');
    $t('Sin token => 401', $c === 401);
    [$c] = call('GET', '/api/v1/vehiculos/999999', $O);
    $t('Vehiculo inexistente => 404', $c === 404);
    [$c] = call('GET', '/api/v1/vehiculos/abc', $O);
    $t('Id no numerico => 422', $c === 422);

    // ---- Vehiculos: escritura (solo ADMIN) ----
    $nuevo = ['placa' => ' 4321-xyz ', 'propietario' => 'Prueba HTTP', 'categoria' => 'minibus', 'ejes' => 2, 'saldo' => 20];
    [$c] = call('POST', '/api/v1/vehiculos', $O, $nuevo);
    $t('Operador NO puede crear vehiculos (403)', $c === 403);
    [$c, $b] = call('POST', '/api/v1/vehiculos', $A, ['placa' => 'xx', 'categoria' => 'automovil', 'ejes' => 2]);
    $t('Placa invalida => 422 con errors.placa', $c === 422 && isset($b['errors']['placa']));
    [$c, $b] = call('POST', '/api/v1/vehiculos', $A, ['placa' => '1234ABC', 'categoria' => 'motocicleta', 'ejes' => 4]);
    $t('Ejes incoherentes => 422', $c === 422 && isset($b['errors']['ejes']));
    [$c, $b] = call('POST', '/api/v1/vehiculos', $A, $nuevo);
    $t('Admin crea vehiculo (201, placa normalizada)', $c === 201 && $b['data']['placa'] === '4321XYZ', json_encode($b));
    $id = $b['data']['id'] ?? 0;
    [$c, $b] = call('POST', '/api/v1/vehiculos', $A, $nuevo);
    $t('Placa duplicada => 409', $c === 409);
    [$c] = call('POST', '/api/v1/vehiculos', $A, null, ['Content-Type: application/json']);
    $t('Cuerpo vacio => 422/400', in_array($c, [400, 422], true));
    [$c, $b] = call('PUT', "/api/v1/vehiculos/$id", $A, ['propietario' => 'Editado', 'saldo' => 55.5]);
    $t('Admin edita vehiculo', $c === 200 && $b['data']['propietario'] === 'Editado' && $b['data']['saldo'] === 55.5);
    [$c] = call('PUT', "/api/v1/vehiculos/$id", $O, ['propietario' => 'Hack']);
    $t('Operador NO puede editar (403)', $c === 403);
    [$c] = call('PUT', "/api/v1/vehiculos/$id", $A, ['placa' => '1001DEM']);
    $t('Editar a placa existente => 409', $c === 409);
    [$c] = call('DELETE', "/api/v1/vehiculos/$id", $O);
    $t('Operador NO puede desactivar (403)', $c === 403);
    [$c, $b] = call('DELETE', "/api/v1/vehiculos/$id", $A);
    $t('Admin desactiva vehiculo', $c === 200 && $b['data']['activo'] === false);
    [$c, $b] = call('GET', '/api/v1/vehiculos?activo=0&q=4321', $A);
    $t('Filtrar inactivos', $c === 200 && $b['data']['total'] === 1);

    // ---- Casetas ----
    [$c, $b] = call('GET', '/api/v1/casetas', $O);
    $t('Operador lista casetas (>=2)', $c === 200 && count($b['data']) >= 2);
    [$c] = call('POST', '/api/v1/casetas', $O, ['nombre' => 'X']);
    $t('Operador NO crea casetas (403)', $c === 403);
    [$c, $b] = call('POST', '/api/v1/casetas', $A, ['nombre' => 'Caseta HTTP', 'ubicacion' => 'Prueba']);
    $t('Admin crea caseta (201)', $c === 201 && $b['data']['activa'] === true);
    $cid = $b['data']['id'] ?? 0;
    [$c] = call('POST', '/api/v1/casetas', $A, ['nombre' => 'caseta http']);
    $t('Nombre duplicado (sin distinguir mayusculas) => 409', $c === 409);
    [$c, $b] = call('POST', '/api/v1/casetas', $A, ['ubicacion' => 'sin nombre']);
    $t('Nombre obligatorio => 422', $c === 422 && isset($b['errors']['nombre']));
    [$c, $b] = call('PUT', "/api/v1/casetas/$cid", $A, ['ubicacion' => 'Nueva']);
    $t('Admin edita caseta', $c === 200 && $b['data']['ubicacion'] === 'Nueva' && $b['data']['nombre'] === 'Caseta HTTP');
    [$c, $b] = call('DELETE', "/api/v1/casetas/$cid", $A);
    $t('Admin desactiva caseta', $c === 200 && $b['data']['activa'] === false);
    [$c, $b] = call('GET', '/api/v1/casetas?activas=1', $O);
    $ok = $c === 200;
    foreach ($b['data'] ?? [] as $x) { $ok = $ok && $x['activa']; }
    $t('Filtro solo activas', $ok);

    // ---- Tarifas ----
    [$c, $b] = call('GET', '/api/v1/tarifas', $O);
    $t('Operador lista tarifas', $c === 200 && count($b['data']) >= 10);
    [$c] = call('POST', '/api/v1/tarifas', $O, ['categoria' => 'bus', 'ejes' => 4, 'monto' => 1]);
    $t('Operador NO crea tarifas (403)', $c === 403);
    [$c, $b] = call('POST', '/api/v1/tarifas', $A, ['categoria' => 'bus', 'ejes' => 9, 'monto' => 1]);
    $t('Tarifa con ejes incoherentes => 422', $c === 422 && isset($b['errors']['ejes']));
    [$c, $b] = call('POST', '/api/v1/tarifas', $A, ['categoria' => 'bus', 'ejes' => 3, 'monto' => -5]);
    $t('Monto negativo => 422', $c === 422 && isset($b['errors']['monto']));
    [$c, $b] = call('POST', '/api/v1/tarifas', $A, ['categoria' => 'automovil', 'ejes' => 2, 'monto' => 6.5]);
    $t('Upsert de tarifa existente (200)', $c === 200 && $b['data']['monto'] === 6.5);
    [$c, $b] = call('POST', '/api/v1/tarifas', $A, ['categoria' => 'camion', 'ejes' => 7, 'monto' => 70]);
    $t('Tarifa nueva (201)', $c === 201);
    $tid = $b['data']['id'] ?? 0;
    [$c, $b] = call('PUT', "/api/v1/tarifas/$tid", $A, ['monto' => 71]);
    $t('Editar monto', $c === 200 && (float)($b['data']['monto'] ?? -1) === 71.0, json_encode($b));
    [$c, $b] = call('DELETE', "/api/v1/tarifas/$tid", $A);
    $t('Desactivar tarifa', $c === 200 && $b['data']['activa'] === false);

    // ---- Usuarios autorizados ----
    [$c] = call('GET', '/api/v1/usuarios', $O);
    $t('Operador NO ve usuarios (403)', $c === 403);
    [$c, $b] = call('GET', '/api/v1/usuarios', $A);
    $t('Admin lista usuarios', $c === 200 && count($b['data']) >= 2);
    [$c, $b] = call('POST', '/api/v1/usuarios', $A, ['email' => 'nuevo.operador@example.com', 'rol' => 'OPERADOR']);
    $t('Admin autoriza cuenta (201)', $c === 201 && $b['data']['rol'] === 'OPERADOR');
    [$c] = call('POST', '/api/v1/usuarios', $A, ['email' => 'NUEVO.operador@example.com', 'rol' => 'ADMIN']);
    $t('Correo duplicado => 409', $c === 409);
    [$c, $b] = call('POST', '/api/v1/usuarios', $A, ['email' => 'no-es-correo', 'rol' => 'ADMIN']);
    $t('Correo invalido => 422', $c === 422 && isset($b['errors']['email']));
    [$c] = call('POST', '/api/v1/usuarios', $A, ['email' => 'x@example.com', 'rol' => 'SUPERUSER']);
    $t('Rol invalido => 422', $c === 422);
    [$c, $b] = call('GET', '/api/v1/usuarios', $A);
    $rows = array_column($b['data'], null, 'email');
    $docId = $rows['doc.alvaro.pinto.ma@unifranz.edu.bo']['id'];
    $opId = $rows['operador.demo@example.com']['id'];
    [$c] = call('PUT', "/api/v1/usuarios/$docId", $A, ['rol' => 'OPERADOR']);
    $t('No se puede degradar al admin principal (409)', $c === 409);
    [$c] = call('PUT', "/api/v1/usuarios/$docId", $A, ['activo' => false]);
    $t('No se puede desactivar al admin principal (409)', $c === 409);
    [$c] = call('GET', '/api/v1/auth/me', $O);
    $t('Sesion del operador valida antes de desactivarlo', $c === 200);
    [$c, $b] = call('PUT', "/api/v1/usuarios/$opId", $A, ['activo' => false]);
    $t('Admin desactiva operador', $c === 200 && $b['data']['activo'] === false);
    [$c] = call('GET', '/api/v1/auth/me', $O);
    $t('Sesiones del operador desactivado quedan revocadas (401)', $c === 401);

    // ---- Paginas ----
    $page = static function (string $path, ?string $tokenCookie): array {
        global $base;
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $tokenCookie ? ['Cookie: peaje_session=' . $tokenCookie] : []]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [$code, substr((string)$res, $hs), substr((string)$res, 0, $hs)];
    };
    [$c, , $h] = $page('/vehiculos.php', null);
    $t('vehiculos.php sin sesion => 302 a login', $c === 302 && stripos($h, 'login.php') !== false);
    [$c, $body] = $page('/vehiculos.php', $A);
    $t('vehiculos.php admin => boton Nuevo', $c === 200 && str_contains($body, 'btn-nuevo') && str_contains($body, 'vehiculos.js'));
    [$c, $body] = $page('/administracion.php', $A);
    $t('administracion.php admin => 200', $c === 200 && str_contains($body, 'tab-tarifas'));
    [$c] = $page('/administracion.php', $tok['op']);
    $t('administracion.php operador => 401->302 (sesion revocada)', in_array($c, [302, 403], true));
    foreach (['/assets/js/vehiculos.js', '/assets/js/admin.js', '/assets/js/app.js', '/assets/css/app.css'] as $a) {
        [$c] = $page($a, null);
        $t("Asset $a servido", $c === 200);
    }
    foreach (['/.env', '/../.env', '/storage/database/test_api.sqlite'] as $p) {
        [$c, $body] = $page($p, null);
        $t("No se sirve $p", $c !== 200 || !str_contains($body, 'APP_KEY'));
    }
};
