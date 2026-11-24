<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ApiException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Services\AgenteService;
use App\Services\EvidenceService;
use App\Services\InferenceService;
use App\Services\PasoService;

/**
 * Controlador de pasos, placas, alertas, dashboard, agente y estado de servicios.
 */
final class PasoController
{
    private static function id(Request $r, string $param = 'id'): int
    {
        $id = $r->params[$param] ?? '';
        if (!ctype_digit((string)$id) || strlen((string)$id) > 12) {
            throw new ApiException(422, 'Identificador invalido.');
        }
        return (int)$id;
    }

    /**
     * POST /api/v1/pasos/frame
     * Recibe imagen/frame (multipart "imagen" o JSON "imagen_base64") + caseta_id.
     */
    public static function registrarFrame(Request $r): void
    {
        $body = $r->body();
        $casetaRaw = $body['caseta_id'] ?? $r->query['caseta_id'] ?? null;
        if ($casetaRaw === null || !ctype_digit((string)$casetaRaw)) {
            throw new ApiException(422, 'Datos invalidos.', ['caseta_id' => 'El ID de caseta es obligatorio y numerico.']);
        }
        $casetaId = (int)$casetaRaw;

        $evSvc = new EvidenceService();
        $evidencia = $evSvc->storeUploaded($r->file('imagen'), is_string($body['imagen_base64'] ?? null) ? $body['imagen_base64'] : null);

        $infSvc = new InferenceService();
        $inferencia = $infSvc->infer($evidencia['full_path'], $evidencia['mime']);

        // Si el servicio de inferencia devolvio la imagen anotada (segmentacion + placa + ejes + mascara pasajeros),
        // reemplazar la evidencia almacenada con esa imagen anotada (siempre validando que sea JPEG/PNG valido).
        if (!empty($inferencia['imagen_anotada_base64']) && is_string($inferencia['imagen_anotada_base64'])) {
            $rawImg = base64_decode($inferencia['imagen_anotada_base64'], true);
            if ($rawImg !== false && strlen($rawImg) > 512 && str_starts_with($rawImg, "\xFF\xD8\xFF")) {
                $newFile = preg_replace('/\.\w+$/', '.jpg', $evidencia['filename']);
                $newPath = dirname($evidencia['full_path']) . '/' . $newFile;
                if (@file_put_contents($newPath, $rawImg) !== false) {
                    if ($newPath !== $evidencia['full_path']) {
                        @unlink($evidencia['full_path']);
                    }
                    $evidencia['filename'] = $newFile;
                    $evidencia['full_path'] = $newPath;
                    $evidencia['url'] = '/api/v1/evidencias/' . $newFile;
                }
            }
        }

        $overrides = [];
        foreach (['placa', 'categoria', 'ejes', 'pasajeros'] as $k) {
            if (isset($body[$k]) && $body[$k] !== '') {
                $overrides[$k] = $body[$k];
            }
        }

        $pasoSvc = new PasoService();
        $res = $pasoSvc->registrarDesdeFrame($casetaId, $evidencia, $inferencia, (int)$r->user['id'], $r->ip(), $overrides);
        if (!empty($inferencia['overlay'])) {
            $res['overlay'] = $inferencia['overlay'];
        }
        if (!empty($inferencia['imagen_anotada_base64'])) {
            $res['imagen_anotada_base64'] = $inferencia['imagen_anotada_base64'];
        }
        Response::ok($res, 'Paso registrado.', 201);
    }

    /** GET /api/v1/pasos */
    public static function listarPasos(Request $r): void
    {
        Response::ok((new PasoService())->list($r->query));
    }

    /** POST /api/v1/placas/consultar */
    public static function consultarPlacaTexto(Request $r): void
    {
        $b = $r->body();
        $placa = is_string($b['placa'] ?? null) ? $b['placa'] : '';
        Response::ok((new PasoService())->consultarPlaca($placa));
    }

    /** POST /api/v1/placas/consultar-imagen */
    public static function consultarPlacaImagen(Request $r): void
    {
        $b = $r->body();
        $evSvc = new EvidenceService();
        $tmp = $evSvc->validateTemp($r->file('imagen'), is_string($b['imagen_base64'] ?? null) ? $b['imagen_base64'] : null);
        try {
            $inf = (new InferenceService())->infer($tmp['path'], $tmp['mime']);
        } finally {
            if (!empty($tmp['cleanup']) && is_file($tmp['path'])) {
                @unlink($tmp['path']);
            }
        }
        if (empty($inf['placa']['texto'])) {
            Response::ok([
                'placa_detectada' => false,
                'mensaje' => 'No se detecto una placa legible en la imagen enviada.',
                'inferencia' => $inf,
                'consulta' => null,
            ], 'Sin placa legible en la imagen.');
        }
        $consulta = (new PasoService())->consultarPlaca((string)$inf['placa']['texto']);
        Response::ok([
            'placa_detectada' => true,
            'inferencia' => $inf,
            'consulta' => $consulta,
        ]);
    }

    /** GET /api/v1/placas/{placa}/historial */
    public static function historialPlaca(Request $r): void
    {
        $placa = urldecode((string)($r->params['placa'] ?? ''));
        $svc = new PasoService();
        $consulta = $svc->consultarPlaca($placa);
        $historial = $svc->historialPorPlaca($consulta['placa_normalizada'], 100);
        Response::ok([
            'placa_normalizada' => $consulta['placa_normalizada'],
            'estado_placa' => $consulta['estado_placa'],
            'vehiculo' => $consulta['vehiculo'],
            'total_pasos' => count($historial),
            'pasos' => $historial,
        ]);
    }

    /** GET /api/v1/casetas/{id}/resumen */
    public static function resumenCaseta(Request $r): void
    {
        Response::ok((new PasoService())->resumenCaseta(self::id($r)));
    }

    /** GET /api/v1/alertas */
    public static function listarAlertas(Request $r): void
    {
        Response::ok((new PasoService())->listAlertas($r->query));
    }

    /** PUT /api/v1/alertas/{id}/atender */
    public static function atenderAlerta(Request $r): void
    {
        Response::ok((new PasoService())->atenderAlerta(self::id($r), (int)$r->user['id'], $r->ip()), 'Alerta marcada como atendida.');
    }

    /** GET /api/v1/dashboard/poll */
    public static function dashboardPoll(Request $r): void
    {
        $sp = isset($r->query['since_paso_id']) && ctype_digit((string)$r->query['since_paso_id']) ? (int)$r->query['since_paso_id'] : 0;
        $sa = isset($r->query['since_alerta_id']) && ctype_digit((string)$r->query['since_alerta_id']) ? (int)$r->query['since_alerta_id'] : 0;
        Response::ok((new PasoService())->dashboardPoll($sp, $sa));
    }

    /** POST /api/v1/agente/chat */
    public static function agenteChat(Request $r): void
    {
        $b = $r->body();
        $mensaje = is_string($b['mensaje'] ?? null) ? trim($b['mensaje']) : '';
        $file = $r->file('imagen');
        $b64 = is_string($b['imagen_base64'] ?? null) ? trim($b['imagen_base64']) : '';
        $hasImg = ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) || $b64 !== '';

        if ($mensaje === '' && !$hasImg) {
            throw new ApiException(422, 'Envie un mensaje o una imagen.', ['mensaje' => 'Ingrese un mensaje o adjunte una imagen.']);
        }
        $tmp = $hasImg ? (new EvidenceService())->validateTemp($file, $b64 ?: null) : null;
        $res = (new AgenteService())->chat($mensaje, $tmp, $r->user);
        Response::ok($res);
    }

    /** GET /api/v1/estado/servicios */
    public static function estadoServicios(Request $r): void
    {
        $inf = new InferenceService();
        if (($r->query['ping'] ?? '') === '1') {
            $inf->checkHealth();
        }
        $pdo = \App\Helpers\Database::connection();
        $fk = (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $jm = strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn());
        $tablas = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();

        Response::ok([
            'php' => [
                'ok' => true,
                'version' => PHP_VERSION,
                'extensiones' => [
                    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                    'curl' => extension_loaded('curl'),
                    'openssl' => extension_loaded('openssl'),
                    'fileinfo' => extension_loaded('fileinfo'),
                ],
            ],
            'sqlite' => [
                'ok' => true,
                'foreign_keys' => $fk === 1,
                'journal_mode' => $jm,
                'tablas' => $tablas,
            ],
            'inferencia' => $inf->lastKnownStatus(),
            'agente' => [
                'modo' => (new AgenteService())->isProviderConfigured() ? 'IA_EXTERNA' : 'DEMOSTRACION',
            ],
            'servidor_hora' => \App\Helpers\Dates::iso(\App\Helpers\Dates::now()),
        ]);
    }

    /** GET /api/v1/evidencias/{archivo} */
    public static function servirEvidencia(Request $r): never
    {
        $archivo = (string)($r->params['archivo'] ?? '');
        $path = (new EvidenceService())->resolvePath($archivo);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($path);
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string)filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
        }
        readfile($path);
        exit;
    }
}
