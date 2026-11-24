<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\ApiException;
use App\Helpers\Config;
use App\Helpers\Database;
use App\Helpers\Logger;
use PDO;

/** Login con Google, lista blanca, sesiones propias (solo hash) y revocacion. */
final class AuthService
{
    public const COOKIE = 'peaje_session';

    public function __construct(private ?GoogleTokenVerifier $verifier = null)
    {
        $this->verifier ??= new GoogleTokenVerifier();
    }

    /** @return array{token:string,expires_at:string,user:array} */
    public function loginWithGoogle(string $idToken, string $ip, string $ua): array
    {
        $pdo = Database::connection();
        $this->assertNotThrottled($pdo, $ip);

        try {
            $claims = $this->verifier->verify($idToken);
        } catch (ApiException $e) {
            if ($e->status === 401) {
                $this->recordAttempt($pdo, $ip, null, false);
            }
            throw $e;
        }

        $email = strtolower((string)$claims['email']);
        $st = $pdo->prepare('SELECT rol, activo FROM lista_blanca WHERE email = :e');
        $st->execute([':e' => $email]);
        $wl = $st->fetch();
        if (!$wl || (int)$wl['activo'] !== 1) {
            $this->recordAttempt($pdo, $ip, $email, false);
            $this->activity($pdo, null, 'LOGIN_DENEGADO', null, null, 'Cuenta no incluida en la lista blanca', $ip);
            Logger::warning('Acceso denegado: cuenta fuera de la lista blanca');
            throw new ApiException(403, 'Su cuenta no esta autorizada para acceder al sistema.');
        }

        $result = Database::transaction(function (PDO $db) use ($claims, $email, $wl, $ip, $ua) {
            $nombre = trim((string)($claims['name'] ?? $email)) ?: $email;
            $foto = isset($claims['picture']) && is_string($claims['picture']) && str_starts_with($claims['picture'], 'https://')
                ? substr($claims['picture'], 0, 500) : null;

            $u = $db->prepare('SELECT id, activo FROM usuarios WHERE email_google = :e');
            $u->execute([':e' => $email]);
            $row = $u->fetch();
            if ($row && (int)$row['activo'] !== 1) {
                throw new ApiException(403, 'Su usuario esta desactivado.');
            }
            if ($row) {
                $uid = (int)$row['id'];
                $db->prepare('UPDATE usuarios SET nombre=:n, foto_url=:f, rol=:r WHERE id=:id')
                    ->execute([':n' => $nombre, ':f' => $foto, ':r' => $wl['rol'], ':id' => $uid]);
            } else {
                $db->prepare('INSERT INTO usuarios (email_google, nombre, foto_url, rol) VALUES (:e,:n,:f,:r)')
                    ->execute([':e' => $email, ':n' => $nombre, ':f' => $foto, ':r' => $wl['rol']]);
                $uid = (int)$db->lastInsertId();
            }
            $sess = $this->createSession($db, $uid, $ip, $ua);
            $this->recordAttempt($db, $ip, $email, true);
            $this->activity($db, $uid, 'LOGIN', 'usuarios', $uid, 'Inicio de sesion con Google', $ip);
            return [
                'token' => $sess['token'],
                'expires_at' => $sess['expires_at'],
                'user' => ['id' => $uid, 'email' => $email, 'nombre' => $nombre, 'foto_url' => $foto, 'rol' => $wl['rol']],
            ];
        });

        // limpieza oportunista de sesiones vencidas
        $pdo->prepare('DELETE FROM sesiones WHERE expira_en < :t')->execute([':t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        return $result;
    }

    /** @return array{token:string,expires_at:string} el token en claro solo se devuelve una vez */
    public function createSession(PDO $db, int $userId, string $ip, string $ua): array
    {
        $token = bin2hex(random_bytes(32));
        $exp = gmdate('Y-m-d H:i:s', time() + 3600 * (int)Config::get('app.session.ttl_hours', 8));
        $db->prepare('INSERT INTO sesiones (usuario_id, token_hash, ip, user_agent, expira_en) VALUES (:u,:h,:ip,:ua,:e)')
            ->execute([':u' => $userId, ':h' => self::hash($token), ':ip' => $ip, ':ua' => $ua, ':e' => $exp]);
        return ['token' => $token, 'expires_at' => $exp];
    }

    /** Devuelve el usuario de una sesion valida, o null. Revalida lista blanca y estado en cada llamada. */
    public function authenticate(string $token): ?array
    {
        $st = Database::connection()->prepare(
            "SELECT s.id AS session_id, s.token_hash, s.expira_en, u.id, u.email_google AS email, u.nombre, u.foto_url,
                    w.rol AS rol
               FROM sesiones s
               JOIN usuarios u ON u.id = s.usuario_id
               JOIN lista_blanca w ON w.email = u.email_google
              WHERE s.token_hash = :h AND s.revocada = 0 AND s.expira_en > :now
                AND u.activo = 1 AND w.activo = 1"
        );
        $st->execute([':h' => self::hash($token), ':now' => gmdate('Y-m-d H:i:s')]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function revoke(string $token): void
    {
        Database::connection()->prepare('UPDATE sesiones SET revocada = 1 WHERE token_hash = :h')
            ->execute([':h' => self::hash($token)]);
    }

    public function revokeAllForUser(int $userId): int
    {
        $st = Database::connection()->prepare('UPDATE sesiones SET revocada = 1 WHERE usuario_id = :u AND revocada = 0');
        $st->execute([':u' => $userId]);
        return $st->rowCount();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Token CSRF derivado de la sesion (para solicitudes autenticadas por cookie). */
    public static function csrfToken(string $tokenHash): string
    {
        $key = (string)Config::get('app.key', '');
        if ($key === '') {
            throw new ApiException(500, 'APP_KEY no esta configurada.');
        }
        return hash_hmac('sha256', 'csrf|' . $tokenHash, $key);
    }

    private function assertNotThrottled(PDO $pdo, string $ip): void
    {
        $win = (int)Config::get('app.session.window_minutes', 15);
        $max = (int)Config::get('app.session.max_attempts', 5);
        $st = $pdo->prepare('SELECT COUNT(*) FROM intentos_login WHERE ip = :ip AND exitoso = 0 AND created_at > :t');
        $st->execute([':ip' => $ip, ':t' => gmdate('Y-m-d H:i:s', time() - $win * 60)]);
        if ((int)$st->fetchColumn() >= $max) {
            throw new ApiException(429, "Demasiados intentos fallidos. Intente de nuevo en $win minutos.");
        }
    }

    private function recordAttempt(PDO $db, string $ip, ?string $email, bool $ok): void
    {
        $db->prepare('INSERT INTO intentos_login (ip, email, exitoso, created_at) VALUES (:ip,:e,:o,:t)')
            ->execute([':ip' => $ip, ':e' => $email, ':o' => $ok ? 1 : 0, ':t' => gmdate('Y-m-d H:i:s')]);
    }

    public function activity(PDO $db, ?int $uid, string $accion, ?string $entidad, ?int $entidadId, string $detalle, string $ip): void
    {
        $db->prepare('INSERT INTO historial_actividad (usuario_id, accion, entidad, entidad_id, detalle, ip, created_at)
                      VALUES (:u,:a,:e,:i,:d,:ip,:t)')
            ->execute([':u' => $uid, ':a' => $accion, ':e' => $entidad, ':i' => $entidadId,
                       ':d' => \App\Helpers\Logger::redact($detalle), ':ip' => $ip, ':t' => gmdate('Y-m-d H:i:s')]);
    }
}
