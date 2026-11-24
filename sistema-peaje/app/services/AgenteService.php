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
 * Agente conversacional del sistema de peaje.
 * Responde EXCLUSIVAMENTE con datos reales de SQLite (nunca inventa placas, propietarios, saldos ni pagos).
 * Soporta consulta por texto o por imagen (M1) y clasifica en los 4 estados obligatorios.
 * Si hay proveedor de IA externo configurado (AGENT_PROVIDER_URL/KEY), lo utiliza con contexto real inyectado;
 * de lo contrario, indica modo DEMOSTRACION y responde de forma estructurada.
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
        return trim((string)Config::get('app.agent.provider_url', '')) !== ''
            && trim((string)Config::get('app.agent.provider_key', '')) !== '';
    }

    /**
     * Procesa un mensaje de texto y/o una imagen opcional subida al chat.
     * @param ?array{path:string,mime:string,cleanup:bool} $tempImage
     */
    public function chat(string $mensaje, ?array $tempImage, array $user): array
    {
        $modoAgente = $this->isProviderConfigured() ? 'IA_EXTERNA' : 'DEMOSTRACION';
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

        // Determinar intencion y extraer placa si existe en el mensaje o en la imagen
        $placaObjetivo = $placaDetectada ?? $this->extraerPlacaDeTexto($mensaje);
        $msgLower = mb_strtolower($mensaje);

        if ($placaObjetivo !== null) {
            $consulta = $this->pasos->consultarPlaca($placaObjetivo);
            $historial = $this->pasos->historialPorPlaca($consulta['placa_normalizada'], 5);
            $consulta['historial_reciente'] = $historial;
            $respuestaBase = $this->formatearRespuestaPlaca($consulta, $inferenciaImagen);
            $respuestaFinal = $this->isProviderConfigured()
                ? ($this->llamarProveedorExterno($mensaje, $consulta, $respuestaBase) ?? $respuestaBase)
                : $respuestaBase;

            return [
                'modo_agente' => $modoAgente,
                'tipo_consulta' => 'CONSULTA_PLACA',
                'estado_placa' => $consulta['estado_placa'],
                'respuesta' => $respuestaFinal,
                'datos' => $consulta + ($inferenciaImagen ? ['inferencia_imagen' => $inferenciaImagen] : []),
            ];
        }

        // Consultas de recaudacion / resumen
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

        // Consultas de ultimos pasos o alertas
        if (preg_match('/(ultimos?\s+pasos?|recientes|historial|pasos)/u', $msgLower)) {
            $lista = $this->pasos->list(['per_page' => 5])['items'];
            $lineas = ["Ultimos 5 pasos registrados en el sistema:"];
            foreach ($lista as $p) {
                $lineas[] = sprintf(
                    "- #%d | Placa: %s | Cat: %s (%s ejes) | Monto: Bs %.2f | Caseta: %s | Estado: %s (%s)",
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
                $lineas[] = sprintf("- [%s] %s (Placa: %s)", $a['tipo'], $a['descripcion'], $a['placa_normalizada'] ?? 'N/A');
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

    private function extraerPlacaDeTexto(string $texto): ?string
    {
        $upper = strtoupper($texto);
        // Busca tokens con formato de placa boliviana o cercanos (con posibles confusiones OCR o guion)
        if (preg_match('/\b([0-9OIBS]{3,4}[-\s]?[A-Z0185]{3})\b/', $upper, $m)) {
            return Plate::normalize($m[1]);
        }
        // Si el usuario escribio una sola palabra alfanumerica de 5 a 8 caracteres, tratarla como consulta de placa
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

    private function llamarProveedorExterno(string $pregunta, array $datosReales, string $resumenEstructurado): ?string
    {
        $url = (string)Config::get('app.agent.provider_url', '');
        $key = (string)Config::get('app.agent.provider_key', '');
        $model = (string)Config::get('app.agent.provider_model', 'gpt-4o-mini');
        if ($url === '' || $key === '') {
            return null;
        }
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Eres el agente oficial del Sistema de Control de Peaje. Responde en espanol de forma breve y precisa USANDO UNICAMENTE los datos reales proporcionados en JSON. Prohibido inventar saldos, propietarios, pagos o pasos.',
                ],
                [
                    'role' => 'user',
                    'content' => "Consulta del usuario: $pregunta\nDatos reales de la base de datos:\n" . json_encode($datosReales, JSON_UNESCAPED_UNICODE) . "\nResumen verificado:\n$resumenEstructurado",
                ],
            ],
            'temperature' => 0.1,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($raw)) {
            Logger::warning("Proveedor de IA respondio HTTP $code; se usa respuesta estructurada.");
            return null;
        }
        $j = json_decode($raw, true);
        return $j['choices'][0]['message']['content'] ?? null;
    }
}
