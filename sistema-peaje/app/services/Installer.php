<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Config;
use App\Helpers\Database;
use App\Helpers\Env;
use PDO;

/** Logica del instalador: comprobaciones, esquema, datos base, datos demo y bloqueo. */
final class Installer
{
    public const ADMIN_EMAIL = 'doc.alvaro.pinto.ma@unifranz.edu.bo';

    public static function isInstalled(): bool
    {
        return is_file(Config::get('app.paths.lock'));
    }

    /** @return array<int,array{nombre:string,ok:bool,detalle:string}> */
    public static function checks(): array
    {
        $c = [];
        $add = static function (string $n, bool $ok, string $d = '') use (&$c): void {
            $c[] = ['nombre' => $n, 'ok' => $ok, 'detalle' => $d];
        };
        $add('PHP >= 8.3', PHP_VERSION_ID >= 80300, PHP_VERSION);
        foreach (['PDO', 'pdo_sqlite', 'curl', 'openssl', 'json', 'mbstring', 'fileinfo'] as $ext) {
            $add("Extension $ext", extension_loaded($ext));
        }
        $add('Extension sqlite3 (opcional)', true, extension_loaded('sqlite3') ? 'cargada' : 'no cargada (no requerida, se usa PDO)');
        foreach (['storage/database', 'storage/evidencias', 'storage/logs'] as $dir) {
            $path = BASE_PATH . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
            // Prueba real de escritura (is_writable no es fiable en Windows/ACL)
            $probe = $path . '/.w' . bin2hex(random_bytes(4));
            $ok = is_dir($path) && @file_put_contents($probe, 'x') !== false;
            if ($ok) {
                @unlink($probe);
            }
            $add("Escritura en $dir", $ok);
        }
        $add('Archivo .env presente', is_file(BASE_PATH . '/.env'), is_file(BASE_PATH . '/.env') ? '' : 'copie .env.example a .env');
        return $c;
    }

    public static function checksPassed(): bool
    {
        foreach (self::checks() as $c) {
            if (!$c['ok'] && $c['nombre'] !== 'Archivo .env presente') {
                return false;
            }
        }
        return true;
    }

    /** Crea .env a partir de .env.example si no existe (solo CLI). */
    public static function ensureEnv(): bool
    {
        $env = BASE_PATH . '/.env';
        if (is_file($env)) {
            return false;
        }
        $tpl = (string)file_get_contents(BASE_PATH . '/.env.example');
        $tpl = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . bin2hex(random_bytes(32)), $tpl) ?? $tpl;
        $tpl = preg_replace('/^APP_ENV=.*$/m', 'APP_ENV=local            # production | local', $tpl) ?? $tpl;
        file_put_contents($env, $tpl);
        return true;
    }

    /** @return string[] mensajes de progreso */
    public static function run(bool $demo): array
    {
        $log = self::provision($demo);
        file_put_contents(
            Config::get('app.paths.lock'),
            'Instalado: ' . date('c') . "\n"
        );
        $log[] = 'Instalador bloqueado (storage/installed.lock).';
        return $log;
    }

    /** Esquema + datos base (+ demo) sobre la BD configurada, sin bloquear el instalador. */
    public static function provision(bool $demo): array
    {
        $log = [];
        $pdo = Database::connection();
        $log[] = 'Conexion SQLite establecida (foreign_keys=ON, busy_timeout aplicado).';

        $pdo->exec((string)file_get_contents(BASE_PATH . '/database/schema.sql'));
        $log[] = 'Esquema ejecutado.';

        self::seedBase($pdo);
        $log[] = 'Datos base: lista blanca (' . self::ADMIN_EMAIL . ' ADMIN), tarifas iniciales y configuracion.';

        if ($demo) {
            $fn = require BASE_PATH . '/database/seeders/DemoSeeder.php';
            foreach ($fn($pdo) as $m) {
                $log[] = $m;
            }
        }
        return $log;
    }

    private static function seedBase(PDO $pdo): void
    {
        $pdo->prepare(
            "INSERT OR IGNORE INTO lista_blanca (email, rol, activo) VALUES (:e, 'ADMIN', 1)"
        )->execute([':e' => self::ADMIN_EMAIL]);

        $defaults = [
            'modo_inferencia' => Env::get('INFERENCE_URL') ? 'REAL' : 'DEMO',
            'ocr_confianza_minima' => (string)Config::get('app.inference.ocr_min_conf'),
            'moneda' => 'BOB',
            'version_esquema' => '1',
        ];
        $st = $pdo->prepare('INSERT OR IGNORE INTO configuracion (clave, valor) VALUES (:k, :v)');
        foreach ($defaults as $k => $v) {
            $st->execute([':k' => $k, ':v' => $v]);
        }

        // Tarifas iniciales (valores de ejemplo en BOB, el administrador debe ajustarlos)
        $tar = $pdo->prepare('INSERT OR IGNORE INTO tarifas (categoria, ejes, monto) VALUES (:c, :e, :m)');
        foreach (require BASE_PATH . '/database/seeders/TarifasBase.php' as [$cat, $ejes, $monto]) {
            $tar->execute([':c' => $cat, ':e' => $ejes, ':m' => $monto]);
        }
    }
}
