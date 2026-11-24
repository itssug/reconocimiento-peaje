<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\ApiException;
use App\Helpers\Config;
use App\Helpers\Logger;

/**
 * Valida ID tokens de Google (JWT RS256) con las claves publicas oficiales (JWKS)
 * y comprueba firma, emisor, audiencia, expiracion y correo verificado.
 */
final class GoogleTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** @param null|callable():array $jwksFetcher inyectable para pruebas */
    public function __construct(private $jwksFetcher = null) {}

    /** @return array claims validados */
    public function verify(string $idToken): array
    {
        $clientId = (string)Config::get('app.google.client_id', '');
        if ($clientId === '') {
            throw new ApiException(503, 'Google Sign-In no esta configurado en el servidor.');
        }
        if (strlen($idToken) > 4096 || substr_count($idToken, '.') !== 2) {
            throw new ApiException(401, 'Token de Google invalido.');
        }
        [$h64, $p64, $s64] = explode('.', $idToken);
        $header = json_decode((string)self::b64d($h64), true);
        $claims = json_decode((string)self::b64d($p64), true);
        $sig = self::b64d($s64);
        if (!is_array($header) || !is_array($claims) || $sig === false || $sig === '') {
            throw new ApiException(401, 'Token de Google invalido.');
        }
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new ApiException(401, 'Algoritmo de token no admitido.');
        }

        $jwk = $this->findKey((string)$header['kid']);
        $pem = self::jwkToPem($jwk);
        $ok = openssl_verify($h64 . '.' . $p64, $sig, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new ApiException(401, 'Firma del token invalida.');
        }

        $now = time();
        $leeway = 60;
        if (!in_array($claims['iss'] ?? '', (array)Config::get('app.google.issuers'), true)) {
            throw new ApiException(401, 'Emisor del token invalido.');
        }
        $aud = $claims['aud'] ?? '';
        if (!is_string($aud) || !hash_equals($clientId, $aud)) {
            throw new ApiException(401, 'Audiencia del token invalida.');
        }
        if (!isset($claims['exp']) || (int)$claims['exp'] + $leeway < $now) {
            throw new ApiException(401, 'El token de Google expiro.');
        }
        if (isset($claims['iat']) && (int)$claims['iat'] - $leeway > $now) {
            throw new ApiException(401, 'Token emitido en el futuro.');
        }
        $verified = $claims['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true') {
            throw new ApiException(401, 'El correo de Google no esta verificado.');
        }
        if (empty($claims['email']) || !is_string($claims['email']) || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(401, 'El token no contiene un correo valido.');
        }
        return $claims;
    }

    private function findKey(string $kid): array
    {
        foreach ([false, true] as $forceRefresh) {   // reintenta con claves frescas si rotaron
            $keys = $this->jwks($forceRefresh);
            foreach ($keys['keys'] ?? [] as $k) {
                if (($k['kid'] ?? '') === $kid && ($k['kty'] ?? '') === 'RSA') {
                    return $k;
                }
            }
            if ($this->jwksFetcher !== null) {
                break;
            }
        }
        throw new ApiException(401, 'Clave de firma desconocida.');
    }

    private function jwks(bool $force): array
    {
        if ($this->jwksFetcher !== null) {
            return ($this->jwksFetcher)();
        }
        $dir = BASE_PATH . '/storage/cache';
        $file = $dir . '/google_jwks.json';
        if (!$force && is_file($file) && filemtime($file) > time() - 3600) {
            $d = json_decode((string)file_get_contents($file), true);
            if (is_array($d) && !empty($d['keys'])) {
                return $d;
            }
        }
        $ch = curl_init(self::JWKS_URL);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $ca = \App\Helpers\Env::get('CA_BUNDLE_PATH');
        if ($ca !== null && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $d = is_string($body) ? json_decode($body, true) : null;
        if ($code !== 200 || !is_array($d) || empty($d['keys'])) {
            Logger::error("No se pudieron obtener las claves de Google (HTTP $code) $err");
            throw new ApiException(503, 'No se pudo contactar a Google para validar el token.');
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, $body, LOCK_EX);
        return $d;
    }

    public static function b64d(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public static function jwkToPem(array $jwk): string
    {
        $n = self::b64d((string)($jwk['n'] ?? ''));
        $e = self::b64d((string)($jwk['e'] ?? ''));
        if ($n === false || $e === false || $n === '' || $e === '') {
            throw new ApiException(401, 'Clave de firma invalida.');
        }
        $len = static function (int $l): string {
            if ($l < 128) {
                return chr($l);
            }
            $b = ltrim(pack('N', $l), "\0");
            return chr(0x80 | strlen($b)) . $b;
        };
        $int = static function (string $b) use ($len): string {
            if (ord($b[0]) > 127) {
                $b = "\0" . $b;
            }
            return "\x02" . $len(strlen($b)) . $b;
        };
        $seq = static fn(string $c): string => "\x30" . $len(strlen($c)) . $c;
        $rsa = $seq($int($n) . $int($e));
        $bit = "\x03" . $len(strlen($rsa) + 1) . "\0" . $rsa;
        $spki = $seq(hex2bin('300d06092a864886f70d0101010500') . $bit);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
