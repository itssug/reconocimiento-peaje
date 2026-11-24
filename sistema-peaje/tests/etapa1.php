<?php
declare(strict_types=1);
// Pruebas de la ETAPA 1-2 (CLI): php tests/etapa1.php
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Database;
use App\Services\Installer;

$fail = 0;
$t = static function (string $n, bool $ok, string $d = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $n . ($d ? " -> $d" : '') . "\n";
    if (!$ok) $fail++;
};

$p = Database::connection();
$t('Conexion SQLite', true);
$t('foreign_keys=ON', (int)$p->query('PRAGMA foreign_keys')->fetchColumn() === 1);
$t('journal_mode=wal', strtolower((string)$p->query('PRAGMA journal_mode')->fetchColumn()) === 'wal');
$t('busy_timeout>0', (int)$p->query('PRAGMA busy_timeout')->fetchColumn() > 0);

$need = ['usuarios','vehiculos','casetas','tarifas','pasos','alertas','lista_blanca','sesiones','historial_actividad','configuracion','intentos_login','estado_servicios'];
$have = $p->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$t('Tablas creadas', !array_diff($need, $have), implode(',', array_diff($need, $have)));

$st = $p->prepare('SELECT rol FROM lista_blanca WHERE email = ?');
$st->execute([strtoupper(Installer::ADMIN_EMAIL)]);
$t('Lista blanca: admin (case-insensitive)', $st->fetchColumn() === 'ADMIN');

try { $p->exec('INSERT INTO pasos(caseta_id,monto) VALUES (999,1)'); $t('FK rechaza caseta inexistente', false); }
catch (Throwable) { $t('FK rechaza caseta inexistente', true); }
try { $p->exec("INSERT INTO usuarios(email_google,nombre,rol) VALUES ('x@x.com','x','ROOT')"); $t('CHECK rechaza rol invalido', false); }
catch (Throwable) { $t('CHECK rechaza rol invalido', true); }

try { Database::transaction(function ($db) { $db->exec("INSERT INTO casetas(nombre) VALUES ('TX')"); throw new Exception('x'); }); }
catch (Throwable) {}
$t('Rollback de transaccion', (int)$p->query("SELECT COUNT(*) FROM casetas WHERE nombre='TX'")->fetchColumn() === 0);
$t('Instalador bloqueado', Installer::isInstalled());

exit($fail ? 1 : 0);
