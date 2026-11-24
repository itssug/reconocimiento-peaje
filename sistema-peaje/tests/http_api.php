<?php
declare(strict_types=1);
/**
 * Pruebas HTTP end-to-end contra el servidor embebido de PHP.
 * Uso: php tests/http_api.php [etapa4|...]   (requiere permitir conexiones loopback)
 * Levanta el servidor con una BD temporal, ejecuta las suites y lo detiene.
 */
$root = dirname(__DIR__);
$php = PHP_BINARY;
passthru('"' . $php . '" ' . escapeshellarg(__DIR__ . '/prepare_test_db.php'));
$tok = json_decode((string)file_get_contents($root . '/storage/database/test_tokens.json'), true);
$port = 8098;
$base = "http://127.0.0.1:$port";

$env = getenv() + [];
$env['DB_PATH'] = $root . '/storage/database/test_api.sqlite';
$env['GOOGLE_CLIENT_ID'] = 'test-client-id.apps.googleusercontent.com';
$env['APP_KEY'] = str_repeat('ab', 32);
$env['INFERENCE_URL'] = $env['INFERENCE_URL'] ?? '';
$proc = proc_open([$php, '-S', "127.0.0.1:$port", '-t', $root . '/public', $root . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/srv_out.txt', 'w'], 2 => ['file', sys_get_temp_dir() . '/srv_err.txt', 'w']],
    $pipes, $root, $env);
register_shutdown_function(function () use ($proc) { @proc_terminate($proc); });

function call(string $method, string $path, ?string $token = null, ?array $body = null, array $extraHeaders = [], bool $raw = false): array
{
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Accept: application/json'] + $extraHeaders;
    if ($token) { $h[] = 'Authorization: Bearer ' . $token; }
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
    $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $text = substr((string)$res, $hs);
    return [$code, $raw ? $text : json_decode($text, true), substr((string)$res, 0, $hs)];
}

$ready = false;
for ($i = 0; $i < 60 && !$ready; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.3);
    if ($c) { fclose($c); $ready = true; } else { usleep(300000); }
}
if (!$ready) { fwrite(STDERR, "El servidor no arranco. " . @file_get_contents(sys_get_temp_dir() . '/srv_err.txt') . "\n"); exit(2); }

$fail = 0;
$t = function (string $n, bool $ok, string $d = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $n . (!$ok && $d ? " -> $d" : '') . "\n";
    if (!$ok) $fail++;
};
$A = $tok['admin']; $O = $tok['op'];

$suites = [];
foreach (glob(__DIR__ . '/http/*.php') as $f) {
    $suites[basename($f, '.php')] = $f;
}
ksort($suites);
$only = $argv[1] ?? null;
foreach ($suites as $name => $file) {
    if ($only && $only !== $name) { continue; }
    echo "== $name ==\n";
    (require $file)($t, $A, $O, $tok);
}
@proc_terminate($proc);
echo "Fallos HTTP: $fail\n";
exit($fail ? 1 : 0);
