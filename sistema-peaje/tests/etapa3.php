<?php
declare(strict_types=1);
// Pruebas ETAPA 3: php tests/etapa3.php  (usa una base temporal, no toca la real)

$tmpDb = dirname(__DIR__) . '/storage/database/test_etapa3.sqlite';
foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) { @unlink($f); }
putenv('DB_PATH=' . $tmpDb);
putenv('GOOGLE_CLIENT_ID=test-client-id.apps.googleusercontent.com');
putenv('APP_KEY=' . str_repeat('ab', 32));
putenv('LOGIN_MAX_ATTEMPTS=5');

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\ApiException;
use App\Helpers\Database;
use App\Helpers\Logger;
use App\Helpers\Request;
use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use App\Services\GoogleTokenVerifier;
use App\Services\Installer;

// Esquema + datos base en la BD temporal (sin tocar el lock real)
$pdo = Database::connection();
$pdo->exec((string)file_get_contents(BASE_PATH . '/database/schema.sql'));
$pdo->exec("INSERT INTO lista_blanca(email,rol) VALUES ('" . Installer::ADMIN_EMAIL . "','ADMIN')");
$pdo->exec("INSERT INTO lista_blanca(email,rol) VALUES ('operador.demo@example.com','OPERADOR')");

$fail = 0;
$t = static function (string $n, bool $ok, string $d = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $n . ($d ? " -> $d" : '') . "\n";
    if (!$ok) $fail++;
};
$status = static function (callable $fn): int {
    try { $fn(); return 200; } catch (ApiException $e) { return $e->status; }
};

// ---- JWT de prueba firmados con un par RSA generado aqui ----
$b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$cnf = getenv('OPENSSL_CONF') ?: 'C:/xampp/php/extras/ssl/openssl.cnf';
$mkKey = static function () use ($cnf) {
    $o = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    if (is_file($cnf)) { $o['config'] = $cnf; }   // solo pruebas en Windows
    return openssl_pkey_new($o);
};
$key = $mkKey();
$other = $mkKey();
$d = openssl_pkey_get_details($key)['rsa'];
$jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'n' => $b64($d['n']), 'e' => $b64($d['e'])]]];
$clientId = 'test-client-id.apps.googleusercontent.com';
$jwt = static function (array $over = [], $signKey = null, array $hdr = []) use ($b64, $key, $clientId): string {
    $claims = array_merge([
        'iss' => 'https://accounts.google.com', 'aud' => $clientId, 'sub' => '123',
        'email' => Installer::ADMIN_EMAIL, 'email_verified' => true, 'name' => 'Admin Prueba',
        'picture' => 'https://example.com/p.png', 'iat' => time(), 'exp' => time() + 3600,
    ], $over);
    $h = $b64(json_encode(array_merge(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT'], $hdr)));
    $p = $b64(json_encode($claims));
    openssl_sign("$h.$p", $sig, $signKey ?? $key, OPENSSL_ALGO_SHA256);
    return "$h.$p." . $b64($sig);
};
$svc = new AuthService(new GoogleTokenVerifier(static fn() => $jwks));

// ---- Validacion del token ----
$r = $svc->loginWithGoogle($jwt(), '10.0.0.1', 'ua');
$t('Login Google valido (admin en lista blanca)', $r['user']['rol'] === 'ADMIN' && strlen($r['token']) === 64);
$adminToken = $r['token'];
$cnt = $pdo->prepare('SELECT COUNT(*) FROM sesiones WHERE token_hash = ?');
$cnt->execute([$adminToken]);
$t('El token en claro NO se almacena', (int)$cnt->fetchColumn() === 0);
$cnt->execute([AuthService::hash($adminToken)]);
$t('Se almacena solo el hash SHA-256', (int)$cnt->fetchColumn() === 1);
$t('Usuario creado desde Google', (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE email_google='" . Installer::ADMIN_EMAIL . "'")->fetchColumn() === 1);

$deny = [
    'Rechaza audiencia incorrecta'  => $jwt(['aud' => 'otro-client']),
    'Rechaza emisor incorrecto'     => $jwt(['iss' => 'https://evil.example']),
    'Rechaza token expirado'        => $jwt(['exp' => time() - 7200, 'iat' => time() - 9000]),
    'Rechaza correo no verificado'  => $jwt(['email_verified' => false]),
    'Rechaza firma de otra clave'   => $jwt([], $other),
    'Rechaza alg distinto de RS256' => $jwt([], null, ['alg' => 'HS256']),
    'Rechaza alg none'              => $jwt([], null, ['alg' => 'none']),
    'Rechaza kid desconocido'       => $jwt([], null, ['kid' => 'zzz']),
    'Rechaza token malformado'      => 'abc.def',
];
$ip = 20;
foreach ($deny as $name => $tok) {
    $addr = '10.0.1.' . $ip++;
    $t($name, $status(fn() => $svc->loginWithGoogle($tok, $addr, 'ua')) === 401);
}

// ---- Lista blanca ----
$t('Deniega cuenta no autorizada (403)', $status(fn() => $svc->loginWithGoogle($jwt(['email' => 'intruso@gmail.com']), '10.0.2.1', 'ua')) === 403);
$t('No crea usuario para cuenta denegada', (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE email_google='intruso@gmail.com'")->fetchColumn() === 0);
$t('Registra intento denegado en historial', (int)$pdo->query("SELECT COUNT(*) FROM historial_actividad WHERE accion='LOGIN_DENEGADO'")->fetchColumn() === 1);
$op = $svc->loginWithGoogle($jwt(['email' => 'Operador.Demo@Example.com', 'name' => 'Operador Demo']), '10.0.2.2', 'ua');
$t('Operador autorizado recibe rol OPERADOR (case-insensitive)', $op['user']['rol'] === 'OPERADOR');

// ---- Sesiones ----
$u = $svc->authenticate($adminToken);
$t('authenticate() devuelve usuario y rol de BD', $u && $u['rol'] === 'ADMIN');
$t('Token inexistente => null', $svc->authenticate(str_repeat('0', 64)) === null);
$svc->revoke($op['token']);
$t('Sesion revocada => null', $svc->authenticate($op['token']) === null);
$op2 = $svc->loginWithGoogle($jwt(['email' => 'operador.demo@example.com']), '10.0.2.3', 'ua');
$pdo->prepare('UPDATE sesiones SET expira_en = ? WHERE token_hash = ?')->execute([gmdate('Y-m-d H:i:s', time() - 5), AuthService::hash($op2['token'])]);
$t('Sesion expirada => null', $svc->authenticate($op2['token']) === null);
$op3 = $svc->loginWithGoogle($jwt(['email' => 'operador.demo@example.com']), '10.0.2.4', 'ua');
$pdo->exec("UPDATE lista_blanca SET activo=0 WHERE email='operador.demo@example.com'");
$t('Retirar de lista blanca invalida sesiones activas', $svc->authenticate($op3['token']) === null);
$t('Cuenta desactivada en lista blanca no puede iniciar', $status(fn() => $svc->loginWithGoogle($jwt(['email' => 'operador.demo@example.com']), '10.0.2.5', 'ua')) === 403);
$pdo->exec("UPDATE lista_blanca SET activo=1 WHERE email='operador.demo@example.com'");
$pdo->exec("UPDATE lista_blanca SET rol='ADMIN' WHERE email='operador.demo@example.com'");
$t('El rol se toma siempre de la lista blanca en el backend', $svc->authenticate($op3['token'])['rol'] === 'ADMIN');
$pdo->exec("UPDATE lista_blanca SET rol='OPERADOR' WHERE email='operador.demo@example.com'");
$svc->revokeAllForUser((int)$u['id']);
$t('revokeAllForUser', $svc->authenticate($adminToken) === null);

// ---- Middleware: roles y CSRF ----
$fresh = $svc->loginWithGoogle($jwt(), '10.0.3.1', 'ua');
$opS = $svc->loginWithGoogle($jwt(['email' => 'operador.demo@example.com']), '10.0.3.2', 'ua');
$mk = static fn(string $m, array $server, array $cookies = []) => new Request($m, '/api/v1/auth/me', [], $server + ['REMOTE_ADDR' => '127.0.0.1'], $cookies, [], []);

$t('Sin credenciales => 401', $status(fn() => AuthMiddleware::authenticate($mk('GET', []))) === 401);
$t('Bearer valido => autenticado', $status(fn() => AuthMiddleware::authenticate($mk('GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $fresh['token']]))) === 200);
$t('Bearer POST no exige CSRF', $status(fn() => AuthMiddleware::authenticate($mk('POST', ['HTTP_AUTHORIZATION' => 'Bearer ' . $fresh['token']]))) === 200);
$t('Cookie GET permitido', $status(fn() => AuthMiddleware::authenticate($mk('GET', [], [AuthService::COOKIE => $fresh['token']]))) === 200);
$t('Cookie POST sin CSRF => 403', $status(fn() => AuthMiddleware::authenticate($mk('POST', [], [AuthService::COOKIE => $fresh['token']]))) === 403);
$csrf = AuthService::csrfToken(AuthService::hash($fresh['token']));
$t('Cookie POST con CSRF valido', $status(fn() => AuthMiddleware::authenticate($mk('POST', ['HTTP_X_CSRF_TOKEN' => $csrf], [AuthService::COOKIE => $fresh['token']]))) === 200);
$t('Cookie POST con CSRF de otra sesion => 403', $status(fn() => AuthMiddleware::authenticate($mk('POST', ['HTTP_X_CSRF_TOKEN' => AuthService::csrfToken('x')], [AuthService::COOKIE => $fresh['token']]))) === 403);

$reqAdmin = $mk('GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $fresh['token']]); AuthMiddleware::authenticate($reqAdmin);
$reqOp = $mk('GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $opS['token']]); AuthMiddleware::authenticate($reqOp);
$t('ADMIN accede a ruta ADMIN', $status(fn() => AuthMiddleware::requireRole($reqAdmin, ['ADMIN'])) === 200);
$t('OPERADOR denegado en ruta ADMIN (403)', $status(fn() => AuthMiddleware::requireRole($reqOp, ['ADMIN'])) === 403);
$t('OPERADOR accede a ruta OPERADOR', $status(fn() => AuthMiddleware::requireRole($reqOp, ['ADMIN', 'OPERADOR'])) === 200);

// ---- Logs sin secretos ----
$red = Logger::redact('Authorization: Bearer abcdefghijklmnopqrstuvwxyz token=SECRET123 eyJhbGciOiJSUzI1NiJ9aaaa.eyJzdWIiOiIxMjMifQaaaa.firmaXXXX');
$t('Logger redacta tokens', !str_contains($red, 'abcdefghijklmnop') && !str_contains($red, 'SECRET123') && !str_contains($red, 'eyJhbGci'), $red);

// ---- Limite de intentos (al final: usa una IP dedicada) ----
for ($i = 0; $i < 5; $i++) { $status(fn() => $svc->loginWithGoogle('a.b.c', '10.9.9.9', 'ua')); }
$t('Bloqueo tras 5 intentos fallidos (429)', $status(fn() => $svc->loginWithGoogle($jwt(), '10.9.9.9', 'ua')) === 429);
$t('Otra IP no esta bloqueada', $status(fn() => $svc->loginWithGoogle($jwt(), '10.9.9.10', 'ua')) === 200);

// Para pruebas HTTP: tokens vigentes
$httpAdmin = $svc->loginWithGoogle($jwt(), '10.0.4.1', 'ua');
$httpOp = $svc->loginWithGoogle($jwt(['email' => 'operador.demo@example.com']), '10.0.4.2', 'ua');
file_put_contents(dirname($tmpDb) . '/test_tokens.json', json_encode(['admin' => $httpAdmin['token'], 'op' => $httpOp['token']]));

exit($fail ? 1 : 0);
