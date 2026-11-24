<?php
declare(strict_types=1);
// Pruebas ETAPA 4 a nivel de servicio (sin HTTP): php tests/etapa4.php
$ret = shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/prepare_test_db.php'));
putenv('DB_PATH=' . dirname(__DIR__) . '/storage/database/test_api.sqlite');
putenv('GOOGLE_CLIENT_ID=test-client-id.apps.googleusercontent.com');
putenv('APP_KEY=' . str_repeat('ab', 32));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\ApiException;
use App\Helpers\Database;
use App\Helpers\Plate;
use App\Models\Categoria;
use App\Services\CatalogoService;
use App\Services\VehiculoService;

$fail = 0;
$t = static function (string $n, bool $ok, string $d = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $n . ($d ? " -> $d" : '') . "\n";
    if (!$ok) $fail++;
};
$pdo = Database::connection();
$one = static fn(string $sql) => $pdo->query($sql)->fetchColumn();

// ---- Placas ----
$t('normalize: " 1234-abc " => 1234ABC', Plate::normalize(' 1234-abc ') === '1234ABC');
$t('valida 1234ABC', Plate::isValid('1234ABC'));
$t('valida 123ABC', Plate::isValid('123ABC'));
$t('rechaza 12ABC', !Plate::isValid('12ABC'));
$t('rechaza 1234AB', !Plate::isValid('1234AB'));
$t('rechaza 12345ABC', !Plate::isValid('12345ABC'));
$t('rechaza vacio', !Plate::isValid(''));
$t('autocorrect IZ34ABC no aplica (Z no es confusion)', Plate::autocorrect('IZ34ABC') === null);
$t('autocorrect 1234A8C => 1234ABC', Plate::autocorrect('1234A8C') === '1234ABC');
$t('autocorrect O234ABC => 0234ABC', Plate::autocorrect('O234ABC') === '0234ABC');
$t('autocorrect 123ABS => igual (ya valida) null', Plate::autocorrect('123ABS') === null);
$v = Plate::variants('1234ABC');
$t('variants incluye 1234A8C y 1Z34...', in_array('1234A8C', $v, true) && !in_array('1234ABC', $v, true));
$t('variants con 2 cambios', in_array('1234A8C', Plate::variants('1234ABC', 1), true) && in_array('I234A8C', Plate::variants('1234ABC', 2), true));

// ---- Categorias ----
$t('moto 2 ejes coherente', Categoria::ejesCoherentes('motocicleta', 2));
$t('moto 4 ejes incoherente', !Categoria::ejesCoherentes('motocicleta', 4));
$t('camion 5 ejes coherente', Categoria::ejesCoherentes('camion', 5));
$t('camion 1 eje incoherente', !Categoria::ejesCoherentes('camion', 1));

// ---- Seeder DEMO ----
$t('>= 20 vehiculos DEMO', (int)$one('SELECT COUNT(*) FROM vehiculos WHERE es_demo=1') >= 20, (string)$one('SELECT COUNT(*) FROM vehiculos'));
$t('2 casetas DEMO', (int)$one('SELECT COUNT(*) FROM casetas WHERE es_demo=1') === 2);
$t('5 categorias presentes', (int)$one('SELECT COUNT(DISTINCT categoria) FROM vehiculos') === 5);
$t('variedad de ejes (>=4 valores)', (int)$one('SELECT COUNT(DISTINCT ejes) FROM vehiculos') >= 4);
$t('tarifas para las 5 categorias', (int)$one('SELECT COUNT(DISTINCT categoria) FROM tarifas') === 5);
$t('pasos DEMO marcados origen DEMO', (int)$one("SELECT COUNT(*) FROM pasos WHERE origen='DEMO'") >= 30 && (int)$one("SELECT COUNT(*) FROM pasos WHERE origen<>'DEMO'") === 0);
$t('alertas DEMO existentes y marcadas', (int)$one('SELECT COUNT(*) FROM alertas WHERE es_demo=1') >= 5 && (int)$one('SELECT COUNT(*) FROM alertas WHERE es_demo=0') === 0);
$t('pasos DEMO sin imagen inventada', (int)$one("SELECT COUNT(*) FROM pasos WHERE imagen_url IS NOT NULL") === 0);
$t('todas las placas DEMO terminan en DEM', (int)$one("SELECT COUNT(*) FROM vehiculos WHERE es_demo=1 AND placa NOT LIKE '%DEM'") === 0);
$t('no hay pagos/transacciones (sin tabla de pagos)', (int)$one("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%pago%' OR name LIKE '%transac%'") === 0);
$t('seeder idempotente', (function () use ($pdo) {
    $f = require BASE_PATH . '/database/seeders/DemoSeeder.php';
    $m = $f($pdo);
    return str_contains($m[0], 'omite');
})());

// ---- Tarifas ----
$cat = new CatalogoService();
$t('tarifa automovil/2 = 5.00', $cat->calcularTarifa('automovil', 2) === 5.0);
$t('tarifa camion/4 = 35.00', $cat->calcularTarifa('camion', 4) === 35.0);
$t('tarifa camion/8 usa la de 6 ejes', $cat->calcularTarifa('camion', 8) === 55.0);
$t('tarifa bus/3 = 18.00', $cat->calcularTarifa('bus', 3) === 18.0);
$t('tarifa moto/2 = 2.00', $cat->calcularTarifa('motocicleta', 2) === 2.0);

// ---- Servicio de vehiculos ----
$vs = new VehiculoService();
$exc = static function (callable $f): int { try { $f(); return 0; } catch (ApiException $e) { return $e->status; } };
$t('parse rechaza placa invalida (422)', $exc(fn() => $vs->parse(['placa' => 'xx', 'categoria' => 'automovil', 'ejes' => 2], false)) === 422);
$t('parse rechaza categoria invalida (422)', $exc(fn() => $vs->parse(['placa' => '1234ABC', 'categoria' => 'tanque', 'ejes' => 2], false)) === 422);
$t('parse rechaza incoherencia categoria/ejes (422)', $exc(fn() => $vs->parse(['placa' => '1234ABC', 'categoria' => 'automovil', 'ejes' => 5], false)) === 422);
$t('parse rechaza saldo negativo (422)', $exc(fn() => $vs->parse(['placa' => '1234ABC', 'categoria' => 'automovil', 'ejes' => 2, 'saldo' => -1], false)) === 422);
$ok = $vs->parse(['placa' => ' 5555-xyz ', 'categoria' => 'bus', 'ejes' => 3, 'propietario' => 'Prueba'], false);
$t('parse normaliza la placa', $ok['placa'] === '5555XYZ');
$new = $vs->create($ok, 1, '127.0.0.1');
$t('create devuelve vehiculo con id', $new['id'] > 0 && $new['placa'] === '5555XYZ' && $new['saldo'] === 0.0);
$t('create duplicado => 409', $exc(fn() => $vs->create($ok, 1, '127.0.0.1')) === 409);
$upd = $vs->update($new['id'], $vs->parse(['propietario' => 'Otro', 'saldo' => 10.5], true, $new), 1, '127.0.0.1');
$t('update parcial', $upd['propietario'] === 'Otro' && $upd['saldo'] === 10.5 && $upd['placa'] === '5555XYZ');
$t('update parcial valida coherencia con datos actuales', $exc(fn() => $vs->parse(['categoria' => 'motocicleta'], true, $new)) === 422);
$dz = $vs->deactivate($new['id'], 1, '127.0.0.1');
$t('deactivate (borrado logico)', $dz['activo'] === false && $vs->find($new['id']) !== null);
$t('actividad registrada', (int)$one("SELECT COUNT(*) FROM historial_actividad WHERE accion LIKE 'VEHICULO_%'") === 3);
$r = $vs->list(['q' => "' OR 1=1 --"]);
$t('inyeccion SQL en busqueda es inofensiva', $r['total'] === 0);
$t('busqueda por placa parcial', $vs->list(['q' => '1001'])['total'] === 1);
$t('busqueda con guion se normaliza', $vs->list(['q' => '1001-dem'])['total'] === 1);
$t('filtro por categoria', $vs->list(['categoria' => 'camion'])['total'] === (int)$one("SELECT COUNT(*) FROM vehiculos WHERE categoria='camion'"));
$t('paginacion', count($vs->list(['per_page' => 5, 'page' => 2])['items']) === 5);
$t('comodin % escapado', $vs->list(['q' => '%'])['total'] === 0);

exit($fail ? 1 : 0);
