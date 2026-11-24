<?php
declare(strict_types=1);

namespace App\Helpers;

use PDO;
use PDOException;
use RuntimeException;

/** Conexion PDO unica a SQLite con los PRAGMA requeridos. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('La extension pdo_sqlite no esta disponible.');
        }
        $cfg = Config::get('database');
        $dir = dirname($cfg['path']);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de la base de datos.');
        }

        try {
            $pdo = new PDO('sqlite:' . $cfg['path'], null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            Logger::error('No se pudo abrir SQLite: ' . $e->getMessage());
            throw new RuntimeException('No se pudo abrir la base de datos.');
        }

        $pdo->exec('PRAGMA busy_timeout = ' . (int)$cfg['busy_timeout_ms']);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($cfg['wal']) {
            try {
                $pdo->query('PRAGMA journal_mode = WAL');
            } catch (PDOException $e) {
                Logger::warning('WAL no disponible en este entorno; se usa el modo por defecto.');
            }
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');

        return self::$pdo = $pdo;
    }

    /** Ejecuta $fn dentro de una transaccion; hace rollback ante cualquier excepcion. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // no habia transaccion activa
            }
            throw $e;
        }
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
