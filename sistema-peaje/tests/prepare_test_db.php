<?php
declare(strict_types=1);
/**
 * Prepara una BD TEMPORAL de pruebas (esquema + datos base + DEMO) y tokens de sesion.
 * Uso: php tests/prepare_test_db.php  => storage/database/test_api.sqlite y test_tokens.json
 * No toca la BD real ni el lock del instalador.
 */
$db = dirname(__DIR__) . '/storage/database/test_api.sqlite';
foreach ([$db, $db . '-wal', $db . '-shm'] as $f) { @unlink($f); }
putenv('DB_PATH=' . $db);
putenv('GOOGLE_CLIENT_ID=test-client-id.apps.googleusercontent.com');
putenv('APP_KEY=' . str_repeat('ab', 32));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Database;
use App\Services\AuthService;
use App\Services\Installer;

Installer::provision(true);
$pdo = Database::connection();
$pdo->exec("INSERT INTO lista_blanca(email,rol) VALUES ('operador.demo@example.com','OPERADOR')");
$pdo->exec("INSERT INTO usuarios(email_google,nombre,rol) VALUES ('" . Installer::ADMIN_EMAIL . "','Admin Prueba','ADMIN')");
$pdo->exec("INSERT INTO usuarios(email_google,nombre,rol) VALUES ('operador.demo@example.com','Operador Prueba','OPERADOR')");
$svc = new AuthService();
$out = [];
foreach (['admin' => 1, 'op' => 2] as $k => $uid) {
    $out[$k] = $svc->createSession($pdo, $uid, '127.0.0.1', 'tests')['token'];
}
file_put_contents(dirname($db) . '/test_tokens.json', json_encode($out));
echo "BD de pruebas lista.\n";
