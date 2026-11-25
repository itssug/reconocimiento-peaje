<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Config;
use App\Helpers\Database;
use App\Helpers\Dates;
use App\Helpers\Logger;
use App\Helpers\Plate;
use PDO;

/**
 * Agente conversacional del sistema de peaje (Seccion 4.8).
 * - Disponible solo para usuarios autenticados con Google.
 * - Responde EXCLUSIVAMENTE con datos reales de SQLite / API (nunca inventa placas, saldos ni pasos).
 * - Soporta consulta por texto o por imagen (M1 + OCR), normalizacion boliviana y sugerencias OCR.
 * - Implementa Function Calling real (compatible con Gemini OpenAI-endpoint, Groq, OpenAI y Ollama en RunPod).
 * - Si no hay proveedor configurado (AGENT_PROVIDER_URL vacio), opera en modo DEMOSTRACION estructurado.
 */
final class AgenteService
{
    private PasoService $pasos;
    private InferenceService $inference;
    private PDO $db;

    public function __construct()
    {
        $this->pasos = new PasoService();
        $this->inference = new InferenceService();
        $this->db = Database::connection();
    }

    public function isProviderConfigured(): bool
    {
        return trim((string)Config::get('app.agent.provider_url', '')) !== '';
    }

    /**
     * Procesa un mensaje de texto y/o una imagen opcional subida al chat.
     * @param ?array{path:string,mime:string,cleanup:bool} $tempImage
     */
    public function chat(string $mensaje, ?array $tempImage, array $user): array
    {
        $modoAgente = $this->isProviderConfigured() ? 'IA_FUNCTION_CALLING' : 'DEMOSTRACION';
        $inferenciaImagen = null;
        $placaDetectada = null;

        if ($tempImage !== null) {
            try {
                $inf = $this->inference->infer($tempImage['path'], $tempImage['mime']);
                $inferenciaImagen = [
                    'modo' => $inf['modo'],
                    'ok' => $inf['ok'],
                    'placa' => $inf['placa'],
                    'latencia_ms' => $inf['latencia_ms'],
                ];
                if (!empty($inf['placa']['texto'])) {
                    $placaDetectada = (string)$inf['placa']['texto'];
                }
            } finally {
                if (!empty($tempImage['cleanup']) && is_file($tempImage['path'])) {
                    @unlink($tempImage['path']);
                }
            }

            if ($placaDetectada === null) {
                return [
                    'modo_agente' => $modoAgente,
                    'tipo_consulta' => 'IMAGEN_SIN_PLACA',
                    'respuesta' => 'No se detecto una placa legible en la imagen enviada. Por favor, envie otra fotografia con mejor iluminacion y enfoque sobre la placa.',
                    'datos' => ['inferencia' => $inferenciaImagen],
                ];
            }
        }

        // Si hay proveedor LLM configurado, ejecutar bucle real de Function Calling sobre las herramientas del backend
        if ($this->isProviderConfigured()) {
            $prompt = $mensaje;
            if ($placaDetectada !== null) {
                $conf = isset($inferenciaImagen['placa']['confianza'])
                    ? round((float)$inferenciaImagen['placa']['confianza'] * 100, 1) . '%'
                    : 'N/A';
                $prompt = trim("El usuario envio una imagen donde M1+OCR leyo la placa '$placaDetectada' (confianza $conf). " . $mensaje);
            }
            $fc = $this->ejecutarFunctionCalling($prompt, $placaDetectada, $inferenciaImagen);
            if ($fc !== null) {
                return $fc;
            }
        }

        // Fallback / Modo Demostracion estructurado (100% datos reales de SQLite)
        return $this->responderEstructurado($mensaje, $placaDetectada, $inferenciaImagen, $modoAgente);
    }

    private function responderEstructurado(string $mensaje, ?string $placaDetectada, ?array $inferenciaImagen, string $modoAgente): array
    {
        $placaObjetivo = $placaDetectada ?? $this->extraerPlacaDeTexto($mensaje);
        $msgLower = mb_strtolower($mensaje);

        if ($placaObjetivo !== null) {
            $consulta = $this->pasos->consultarPlaca($placaObjetivo);
            $consulta['historial_reciente'] = $this->pasos->historialPorPlaca($consulta['placa_normalizada'], 5);
            return [
                'modo_agente' => $modoAgente,
                'tipo_consulta' => 'CONSULTA_PLACA',
                'estado_placa' => $consulta['estado_placa'],
                'respuesta' => $this->formatearRespuestaPlaca($consulta, $inferenciaImagen),
                'datos' => $consulta + ($inferenciaImagen ? ['inferencia_imagen' => $inferenciaImagen] : []),
            ];
        }

        if (preg_match('/(recaudaci|resumen|casetas?|ingresos?|cuanto|estadistic)/u', $msgLower)) {
            $datos = $this->datosRecaudacion();
            $txt = sprintf(
                "Resumen del sistema (datos reales en SQLite):\n- Pasos registrados hoy: %d (Recaudacion hoy: Bs %.2f)\n- Total historico de pasos: %d (Recaudacion acumulada: Bs %.2f)\n- Alertas pendientes de atencion: %d",
                $datos['pasos_hoy'], $datos['recaudacion_hoy'], $datos['pasos_total'], $datos['recaudacion_total'], $datos['alertas_pendientes']
            );
            foreach ($datos['casetas'] as $c) {
                $txt .= sprintf("\n  • %s: %d pasos (Bs %.2f)", $c['nombre'], (int)$c['pasos'], (float)$c['recaudacion']);
            }
            return [
                'modo_agente' => $modoAgente,
                'tipo_consulta' => 'RECAUDACION',
                'respuesta' => $txt,
                'datos' => $datos,
            ];
        }

        if (preg_match('/(ultimos?\s+pasos?|recientes|historial|pasos)/u', $msgLower)) {
            $lista = $this->pasos->list(['per_page' => 5])['items'];
            $lineas = ['Ultimos 5 pasos registrados en el sistema:'];
            foreach ($lista as $p) {
                $lineas[] = sprintf(
                    '- #%d | Placa: %s | Cat: %s (%s ejes) | Monto: Bs %.2f | Caseta: %s | Estado: %s (%s)',
                    $p['id'], $p['placa_normalizada'] ?? 'N/A', $p['categoria_detectada'] ?? '-',
                    $p['ejes_detectados'] ?? '-', $p['monto'], $p['caseta_nombre'] ?? '-', $p['estado'], $p['origen']
                );
            }
            return [
                'modo_agente' => $modoAgente,
                'tipo_consulta' => 'ULTIMOS_PASOS',
                'respuesta' => implode("\n", $lineas),
                'datos' => ['pasos' => $lista],
            ];
        }

        if (preg_match('/(alertas?|pendientes|evasi)/u', $msgLower)) {
            $al = $this->pasos->listAlertas(['atendida' => '0', 'per_page' => 5]);
            $lineas = ["Alertas pendientes ({$al['total']} en total):"];
            foreach ($al['items'] as $a) {
                $lineas[] = sprintf('- [%s] %s (Placa: %s)', $a['tipo'], $a['descripcion'], $a['placa_normalizada'] ?? 'N/A');
            }
            return [
                'modo_agente' => $modoAgente,
                'tipo_consulta' => 'ALERTAS_PENDIENTES',
                'respuesta' => implode("\n", $lineas),
                'datos' => $al,
            ];
        }

        return [
            'modo_agente' => $modoAgente,
            'tipo_consulta' => 'AYUDA',
            'respuesta' => "Soy el agente de control de peaje (modo $modoAgente). Solo respondo con datos reales de la base de datos.\n"
                . "Puedes escribirme:\n"
                . "• Una placa (ej. \"1001DEM\" o \"consultar placa 1234ABC\") para conocer su estado, propietario, saldo e historial.\n"
                . "• Subir una fotografia de un vehiculo para identificar su placa mediante M1 + OCR.\n"
                . "• \"Recaudacion del dia\" o \"Resumen de casetas\".\n"
                . "• \"Ultimos pasos\" o \"Alertas pendientes\".",
            'datos' => null,
        ];
    }

    /**
     * Bucle real de Function Calling (OpenAI / Gemini OpenAI-compat / Groq / Ollama).
     * El LLM decide que funcion del backend llamar; PHP ejecuta la consulta real en SQLite y devuelve el JSON al LLM.
     */
    private function ejecutarFunctionCalling(string $prompt, ?string $placaDetectada, ?array $inferenciaImagen): ?array
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_placa',
                    'description' => 'Normaliza una placa boliviana, corrige confusiones OCR (O/0, I/1, B/8, S/5) y devuelve su estado oficial (REGISTRADA_CON_PASO, REGISTRADA_SIN_PASO, IDENTIFICADA_NO_REGISTRADA, NO_EXISTE), datos del vehiculo, ultimo paso y sugerencias.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['placa' => ['type' => 'string', 'description' => 'Texto de la placa a consultar']],
                        'required' => ['placa'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'historial_placa',
                    'description' => 'Devuelve los ultimos pasos registrados de una placa (fecha, caseta, categoria, ejes, monto, estado).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['placa' => ['type' => 'string']],
                        'required' => ['placa'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'resumen_recaudacion',
                    'description' => 'Devuelve el conteo de pasos y recaudacion del dia y acumulado, desglosado por caseta, mas alertas pendientes.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'alertas_pendientes',
                    'description' => 'Lista las alertas pendientes de atencion (evasion, placa no registrada, sin saldo, baja confianza OCR).',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ],
        ];

        $messages = [
            [
                'role' => 'system',
                'content' => 'Eres el agente oficial del Sistema Inteligente de Control de Peaje. '
                    . 'Reglas estrictas: 1) USA SIEMPRE las herramientas disponibles para obtener datos reales de la base de datos. '
                    . '2) NUNCA inventes placas, propietarios, saldos, pagos ni fechas. '
                    . '3) Cuando consultes una placa, indica explicitamente uno de estos cuatro estados: '
                    . '"Registrada y con paso" (con fecha, caseta, categoria y monto), "Registrada sin paso", '
                    . '"Identificada no registrada (posible evasion)" o "No existe". '
                    . '4) Si la herramienta devuelve sugerencias OCR, pregunta "¿Quisiste decir...?". Responde en espanol claro y conciso. /no_think',
            ],
            ['role' => 'user', 'content' => $prompt],
        ];

        $ultimoEstadoPlaca = null;
        $ultimoDato = null;
        $tipo = 'IA_CONVERSACIONAL';

        for ($turno = 0; $turno < 3; $turno++) {
            $resp = $this->llamarLlm($messages, $tools);
            if ($resp === null) {
                return null; // fallback al modo estructurado
            }
            $msg = $resp['choices'][0]['message'] ?? null;
            if (!is_array($msg)) {
                return null;
            }
            $toolCalls = $msg['tool_calls'] ?? [];
            if (empty($toolCalls)) {
                $texto = trim((string)($msg['content'] ?? ''));
                // Limpiar posibles etiquetas <think>...</think> de modelos razonadores (ej. qwen3)
                $texto = trim((string)preg_replace('/<think>.*?<\/think>/is', '', $texto));
                if ($texto === '') {
                    return null;
                }
                return [
                    'modo_agente' => 'IA_FUNCTION_CALLING',
                    'tipo_consulta' => $tipo,
                    'estado_placa' => $ultimoEstadoPlaca,
                    'respuesta' => $texto,
                    'datos' => $ultimoDato,
                ];
            }

            $messages[] = $msg;
            foreach ($toolCalls as $tc) {
                $fn = $tc['function']['name'] ?? '';
                $args = json_decode((string)($tc['function']['arguments'] ?? '{}'), true) ?: [];
                $out = $this->ejecutarHerramienta($fn, $args, $ultimoEstadoPlaca, $tipo);
                $ultimoDato = $out;
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc['id'] ?? 'call_1',
                    'name' => $fn,
                    'content' => json_encode($out, JSON_UNESCAPED_UNICODE),
                ];
            }
        }
        return null;
    }

    private function ejecutarHerramienta(string $fn, array $args, ?string &$estadoPlaca, string &$tipo): array
    {
        try {
            switch ($fn) {
                case 'consultar_placa':
                    $tipo = 'CONSULTA_PLACA';
                    $c = $this->pasos->consultarPlaca((string)($args['placa'] ?? ''));
                    $estadoPlaca = $c['estado_placa'];
                    return $c;
                case 'historial_placa':
                    $tipo = 'CONSULTA_PLACA';
                    $p = (string)($args['placa'] ?? '');
                    return ['placa' => Plate::normalize($p), 'pasos' => $this->pasos->historialPorPlaca($p, 10)];
                case 'resumen_recaudacion':
                    $tipo = 'RECAUDACION';
                    return $this->datosRecaudacion();
                case 'alertas_pendientes':
                    $tipo = 'ALERTAS_PENDIENTES';
                    return $this->pasos->listAlertas(['atendida' => '0', 'per_page' => 10]);
                default:
                    return ['error' => 'Herramienta desconocida'];
            }
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function llamarLlm(array $messages, array $tools): ?array
    {
        $url = trim((string)Config::get('app.agent.provider_url', ''));
        $key = trim((string)Config::get('app.agent.provider_key', ''));
        $model = trim((string)Config::get('app.agent.provider_model', 'gemini-2.5-flash'));
        if ($url === '') {
            return null;
        }
        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'temperature' => 0.1,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($raw)) {
            Logger::warning("Agente LLM respondio HTTP $code ($err); se usa modo estructurado.");
            return null;
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    private function extraerPlacaDeTexto(string $texto): ?string
    {
        $upper = strtoupper($texto);
        if (preg_match('/\b([0-9OIBS]{3,4}[-\s]?[A-Z0185]{3})\b/', $upper, $m)) {
            return Plate::normalize($m[1]);
        }
        $limpio = trim($upper);
        if (preg_match('/^[A-Z0-9-]{5,9}$/', $limpio) && preg_match('/\d/', $limpio) && preg_match('/[A-Z]/', $limpio)) {
            return Plate::normalize($limpio);
        }
        return null;
    }

    private function formatearRespuestaPlaca(array $c, ?array $infImg): string
    {
        $partes = [];
        if ($infImg !== null) {
            $conf = isset($infImg['placa']['confianza']) ? round((float)$infImg['placa']['confianza'] * 100, 1) . '%' : 'N/A';
            $partes[] = "Placa identificada en la imagen (M1 {$infImg['modo']}): {$c['placa_normalizada']} (confianza OCR: $conf).";
        }
        if ($c['autocorregida_desde'] !== null) {
            $partes[] = "Nota: se normalizo/corrigio '{$c['autocorregida_desde']}' -> '{$c['placa_normalizada']}' por posible confusion OCR.";
        }

        switch ($c['estado_placa']) {
            case 'REGISTRADA_CON_PASO':
                $v = $c['vehiculo'];
                $u = $c['ultimo_paso'];
                $partes[] = sprintf(
                    "Estado: REGISTRADA Y CON PASO.\n• Placa: %s | Propietario: %s | Categoria: %s (%d ejes) | Saldo: Bs %.2f (%s)%s\n• Total de pasos: %d. Ultimo paso: %s en %s, categoria %s, monto Bs %.2f (estado %s).",
                    $v['placa'], $v['propietario'] ?? 'Sin propietario', $v['categoria'], $v['ejes'],
                    $v['saldo'], $v['activo'] ? 'Activo' : 'Inactivo', $v['es_demo'] ? ' [DEMO]' : '',
                    $c['total_pasos'], $u['created_at'], $u['caseta_nombre'] ?? ('Caseta #' . $u['caseta_id']),
                    $u['categoria_detectada'] ?? '-', $u['monto'], $u['estado']
                );
                break;

            case 'REGISTRADA_SIN_PASO':
                $v = $c['vehiculo'];
                $partes[] = sprintf(
                    "Estado: REGISTRADA SIN PASO.\n• La placa %s esta registrada a nombre de %s (categoria %s, %d ejes, saldo Bs %.2f), pero nunca ha registrado un paso por el peaje.",
                    $v['placa'], $v['propietario'] ?? 'Sin propietario', $v['categoria'], $v['ejes'], $v['saldo']
                );
                break;

            case 'IDENTIFICADA_NO_REGISTRADA':
                $u = $c['ultimo_paso'];
                $partes[] = sprintf(
                    "Estado: IDENTIFICADA NO REGISTRADA (posible evasion).\n• La camara detecto la placa %s en %d paso(s) (ultimo: %s en %s, monto Bs %.2f), pero NO figura en el padron de vehiculos registrados.",
                    $c['placa_normalizada'], $c['total_pasos'], $u['created_at'],
                    $u['caseta_nombre'] ?? ('Caseta #' . $u['caseta_id']), $u['monto']
                );
                break;

            default:
                $partes[] = sprintf(
                    "Estado: NO EXISTE.\n• No hay ningun registro ni historial de pasos para la placa %s.",
                    $c['placa_normalizada']
                );
                if (!$c['formato_boliviano']) {
                    $partes[] = "• Advertencia: '{$c['placa_normalizada']}' no cumple el formato boliviano de 3-4 digitos seguidos de 3 letras.";
                }
                break;
        }

        if (!empty($c['sugerencias'])) {
            $partes[] = '¿Quisiste decir: ' . implode(', ', $c['sugerencias']) . '?';
        }
        return implode("\n", $partes);
    }

    private function datosRecaudacion(): array
    {
        [$ini, $fin] = Dates::todayRangeUtc();
        $st = $this->db->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(monto), 0) AS m FROM pasos WHERE created_at >= :i AND created_at < :f');
        $st->execute([':i' => $ini, ':f' => $fin]);
        $hoy = $st->fetch();
        $tot = $this->db->query('SELECT COUNT(*) AS c, COALESCE(SUM(monto), 0) AS m FROM pasos')->fetch();
        $al = (int)$this->db->query('SELECT COUNT(*) FROM alertas WHERE atendida = 0')->fetchColumn();
        $casetas = $this->db->query(
            'SELECT c.id, c.nombre, COUNT(p.id) AS pasos, COALESCE(SUM(p.monto), 0) AS recaudacion
               FROM casetas c LEFT JOIN pasos p ON p.caseta_id = c.id GROUP BY c.id ORDER BY c.id'
        )->fetchAll();
        return [
            'pasos_hoy' => (int)$hoy['c'],
            'recaudacion_hoy' => round((float)$hoy['m'], 2),
            'pasos_total' => (int)$tot['c'],
            'recaudacion_total' => round((float)$tot['m'], 2),
            'alertas_pendientes' => $al,
            'casetas' => $casetas,
        ];
    }
}
