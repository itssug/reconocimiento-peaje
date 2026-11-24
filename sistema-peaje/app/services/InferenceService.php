<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\ApiException;
use App\Helpers\Config;
use App\Helpers\Database;
use App\Helpers\Dates;
use App\Helpers\Logger;
use App\Helpers\Plate;

/**
 * Integracion HTTP con el endpoint externo de inferencia (M1 placas/OCR, M2 vehiculo, M3 ejes, M4 pasajeros).
 * Si INFERENCE_URL esta vacia, opera en modo DEMO claramente identificado (origen='DEMO'),
 * con datos simulados deterministicos que NUNCA se guardan como inferencias reales.
 */
final class InferenceService
{
    public function isConfigured(): bool
    {
        return trim((string)Config::get('app.inference.url', '')) !== '';
    }

    /**
     * Envia una imagen al endpoint de inferencia (o devuelve simulacion DEMO si no hay endpoint configurado).
     * @return array{ok:bool,modo:string,placa:?array,vehiculo:?array,ejes:?array,pasajeros:?array,latencia_ms:int,error:?string}
     */
    public function infer(string $imagePath, string $mime = 'image/jpeg'): array
    {
        if (!$this->isConfigured()) {
            return $this->demoInfer($imagePath);
        }

        $url = rtrim((string)Config::get('app.inference.url'), '/');
        if (!str_ends_with($url, '/inferir')) {
            $url .= '/inferir';
        }
        $key = (string)Config::get('app.inference.api_key', '');
        $timeout = max(3, (int)Config::get('app.inference.timeout', 15));

        $t0 = microtime(true);
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($key !== '') {
            $headers[] = 'X-API-Key: ' . $key;
        }
        $cfile = new \CURLFile($imagePath, $mime, basename($imagePath));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['imagen' => $cfile],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $lat = (int)round((microtime(true) - $t0) * 1000);

        if ($raw === false || $code < 200 || $code >= 300) {
            $msg = $cerr !== '' ? "Error de conexion: $cerr" : "HTTP $code desde endpoint de inferencia.";
            Logger::error("Inferencia fallida ($lat ms): $msg");
            $this->recordStatus(false, $lat, $msg);
            return [
                'ok' => false, 'modo' => 'REAL',
                'placa' => null, 'vehiculo' => null, 'ejes' => null, 'pasajeros' => null,
                'latencia_ms' => $lat, 'error' => $msg,
            ];
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json) || empty($json['success']) || !isset($json['inference']) || !is_array($json['inference'])) {
            $msg = $json['message'] ?? 'Respuesta JSON invalida del servicio de inferencia.';
            Logger::error("Inferencia respuesta invalida: $msg");
            $this->recordStatus(false, $lat, (string)$msg);
            return [
                'ok' => false, 'modo' => 'REAL',
                'placa' => null, 'vehiculo' => null, 'ejes' => null, 'pasajeros' => null,
                'latencia_ms' => $lat, 'error' => (string)$msg,
            ];
        }

        $inf = $json['inference'];
        $repLat = isset($json['latencia_ms']) && is_numeric($json['latencia_ms']) ? (int)$json['latencia_ms'] : $lat;
        $this->recordStatus(true, $repLat, null);

        return [
            'ok' => true,
            'modo' => 'REAL',
            'placa' => is_array($inf['placa'] ?? null) ? $inf['placa'] : null,
            'vehiculo' => is_array($inf['vehiculo'] ?? null) ? $inf['vehiculo'] : null,
            'ejes' => is_array($inf['ejes'] ?? null) ? $inf['ejes'] : null,
            'pasajeros' => is_array($inf['pasajeros'] ?? null) ? $inf['pasajeros'] : null,
            'overlay' => is_array($json['overlay'] ?? null) ? $json['overlay'] : null,
            'imagen_anotada_base64' => is_string($json['imagen_anotada_base64'] ?? null) ? $json['imagen_anotada_base64'] : null,
            'latencia_ms' => $repLat,
            'error' => null,
        ];
    }

    /** Comprueba la salud del endpoint de inferencia (GET /salud) sin exponer secretos. */
    public function checkHealth(): array
    {
        if (!$this->isConfigured()) {
            $this->recordStatus(false, 0, 'Modo DEMO activo (INFERENCE_URL no configurado).');
            return [
                'configurado' => false,
                'modo' => 'DEMO',
                'ok' => false,
                'latencia_ms' => 0,
                'ultimo_error' => 'Modo DEMO activo (INFERENCE_URL no configurado).',
                'comprobado_en' => Dates::iso(Dates::now()),
                'modelos' => null,
            ];
        }
        $base = preg_replace('#/inferir/?$#', '', rtrim((string)Config::get('app.inference.url'), '/'));
        $url = $base . '/salud';
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $lat = (int)round((microtime(true) - $t0) * 1000);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        $ok = $code === 200 && is_array($json) && !empty($json['success']);
        $err = $ok ? null : ($cerr !== '' ? $cerr : "HTTP $code");
        $this->recordStatus($ok, $lat, $err);

        return [
            'configurado' => true,
            'modo' => 'REAL',
            'ok' => $ok,
            'latencia_ms' => $lat,
            'ultimo_error' => $err,
            'comprobado_en' => Dates::iso(Dates::now()),
            'modelos' => $ok ? ($json['modelos'] ?? null) : null,
        ];
    }

    public function lastKnownStatus(): array
    {
        $pdo = Database::connection();
        $st = $pdo->prepare("SELECT * FROM estado_servicios WHERE servicio = 'inferencia'");
        $st->execute();
        $row = $st->fetch();
        $url = (string)Config::get('app.inference.url', '');
        // Sanitizar URL: mostrar solo esquema + host + puerto + ruta sin credenciales ni query
        $displayUrl = 'No configurado (Modo DEMO)';
        if ($url !== '') {
            $p = parse_url($url);
            $displayUrl = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')
                . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '');
        }
        return [
            'configurado' => $url !== '',
            'modo' => $url !== '' ? 'REAL' : 'DEMO',
            'endpoint_url' => $displayUrl,
            'ok' => $row ? (bool)$row['ok'] : false,
            'latencia_ms' => $row && $row['latencia_ms'] !== null ? (int)$row['latencia_ms'] : null,
            'ultimo_error' => $row['ultimo_error'] ?? null,
            'comprobado_en' => $row ? Dates::iso($row['comprobado_en']) : null,
        ];
    }

    private function recordStatus(bool $ok, int $latMs, ?string $err): void
    {
        try {
            $pdo = Database::connection();
            $now = Dates::now();
            $pdo->prepare(
                "INSERT INTO estado_servicios (servicio, ok, latencia_ms, ultimo_error, comprobado_en)
                 VALUES ('inferencia', :o, :l, :e, :t)
                 ON CONFLICT(servicio) DO UPDATE SET ok=:o, latencia_ms=:l, ultimo_error=:e, comprobado_en=:t"
            )->execute([':o' => $ok ? 1 : 0, ':l' => $latMs, ':e' => $err, ':t' => $now]);
        } catch (\Throwable) {
            // no bloquear flujo principal
        }
    }

    /** Simulacion deterministica en modo DEMO (nunca se guarda como REAL). */
    private function demoInfer(string $imagePath): array
    {
        $hash = is_file($imagePath) ? crc32((string)file_get_contents($imagePath)) : 0;
        $num = 1001 + ($hash % 22);
        $placa = sprintf('%04dDEM', $num);
        $cats = ['automovil', 'minibus', 'camion', 'bus', 'motocicleta'];
        $cat = $cats[$hash % count($cats)];
        $ejes = $cat === 'camion' ? (2 + ($hash % 3)) : 2;
        return [
            'ok' => true,
            'modo' => 'DEMO',
            'placa' => ['texto' => $placa, 'confianza' => 0.92],
            'vehiculo' => ['categoria' => $cat, 'confianza' => 0.90],
            'ejes' => ['cantidad' => $ejes, 'confianza' => 0.89],
            'pasajeros' => ['cantidad' => 1 + ($hash % 3)],
            'latencia_ms' => 15,
            'error' => null,
        ];
    }
}
