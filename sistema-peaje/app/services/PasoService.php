<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\ApiException;
use App\Helpers\Config;
use App\Helpers\Database;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Plate;
use App\Models\Categoria;
use PDO;

/**
 * Registro de pasos por caseta (transaccional), consulta e historial de placas,
 * gestion de alertas y resumenes en tiempo real para el dashboard.
 */
final class PasoService
{
    private PDO $db;
    private CatalogoService $catalogo;
    private VehiculoService $vehiculos;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->catalogo = new CatalogoService();
        $this->vehiculos = new VehiculoService();
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'placa_leida' => $r['placa_leida'],
            'placa_normalizada' => $r['placa_normalizada'],
            'confianza_ocr' => $r['confianza_ocr'] !== null ? (float)$r['confianza_ocr'] : null,
            'categoria_detectada' => $r['categoria_detectada'],
            'ejes_detectados' => $r['ejes_detectados'] !== null ? (int)$r['ejes_detectados'] : null,
            'pasajeros' => ($r['pasajeros'] ?? null) !== null ? (int)$r['pasajeros'] : null,
            'monto' => (float)$r['monto'],
            'caseta_id' => (int)$r['caseta_id'],
            'caseta_nombre' => $r['caseta_nombre'] ?? null,
            'usuario_id' => $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null,
            'operador_nombre' => $r['operador_nombre'] ?? null,
            'imagen_url' => $r['imagen_url'],
            'estado' => $r['estado'],
            'origen' => $r['origen'] ?? 'REAL',
            'created_at' => Dates::iso($r['created_at']),
        ];
    }

    public static function presentAlerta(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'paso_id' => $r['paso_id'] !== null ? (int)$r['paso_id'] : null,
            'placa_normalizada' => $r['placa_normalizada'] ?? null,
            'caseta_nombre' => $r['caseta_nombre'] ?? null,
            'tipo' => $r['tipo'],
            'descripcion' => $r['descripcion'],
            'atendida' => (bool)$r['atendida'],
            'atendida_por' => $r['atendida_por'] !== null ? (int)$r['atendida_por'] : null,
            'atendida_por_nombre' => $r['atendida_por_nombre'] ?? null,
            'atendida_en' => isset($r['atendida_en']) ? Dates::iso($r['atendida_en']) : null,
            'es_demo' => (bool)$r['es_demo'],
            'created_at' => Dates::iso($r['created_at']),
        ];
    }

    /**
     * Registra un paso a partir de un frame recibido por POST /api/v1/pasos/frame.
     * Todo el calculo de tarifa, descuento de saldo y creacion de alertas ocurre en una transaccion SQLite.
     */
    public function registrarDesdeFrame(int $casetaId, array $evidencia, array $inferencia, int $userId, string $ip, array $overrides = []): array
    {
        $caseta = $this->catalogo->caseta($casetaId);
        if (!$caseta || !$caseta['activa']) {
            throw new ApiException(422, 'Caseta inexistente o inactiva.', ['caseta_id' => 'Seleccione una caseta activa.']);
        }

        $minConf = (float)Config::get('app.inference.ocr_min_conf', 0.80);
        $modo = $inferencia['modo'] ?? 'REAL';
        $esDemo = $modo === 'DEMO' ? 1 : 0;

        $placaRaw = $overrides['placa'] ?? ($inferencia['placa']['texto'] ?? null);
        $placaLeida = is_string($placaRaw) && trim($placaRaw) !== '' ? trim($placaRaw) : null;
        $placaNorm = $placaLeida !== null ? Plate::normalize($placaLeida) : null;
        if ($placaNorm !== null && !Plate::isValid($placaNorm)) {
            $corr = Plate::autocorrect($placaNorm);
            if ($corr !== null) {
                $placaNorm = $corr;
            }
        }
        if ($placaNorm === '') {
            $placaNorm = null;
        }

        $confOcr = isset($inferencia['placa']['confianza']) && is_numeric($inferencia['placa']['confianza'])
            ? round(max(0.0, min(1.0, (float)$inferencia['placa']['confianza'])), 4)
            : null;

        $catRaw = $overrides['categoria'] ?? ($inferencia['vehiculo']['categoria'] ?? null);
        $cat = is_string($catRaw) && Categoria::esValida(strtolower($catRaw)) ? strtolower($catRaw) : null;

        $ejesRaw = $overrides['ejes'] ?? ($inferencia['ejes']['cantidad'] ?? null);
        $ejes = is_numeric($ejesRaw) ? max(1, min(12, (int)$ejesRaw)) : null;

        $pasRaw = $overrides['pasajeros'] ?? ($inferencia['pasajeros']['cantidad'] ?? null);
        $pasajeros = is_numeric($pasRaw) ? max(0, min(99, (int)$pasRaw)) : null;

        return Database::transaction(function (PDO $db) use (
            $casetaId, $userId, $evidencia, $inferencia, $placaLeida, $placaNorm,
            $confOcr, $cat, $ejes, $pasajeros, $minConf, $modo, $esDemo, $ip
        ) {
            $alertasCrear = [];
            $estado = 'REGISTRADO';

            if (!$inferencia['ok']) {
                $estado = 'ERROR_INFERENCIA';
                $alertasCrear[] = ['ERROR_INFERENCIA', 'Fallo la comunicacion o respuesta del servicio de inferencia: ' . ($inferencia['error'] ?? 'Error desconocido')];
            }

            $vehiculo = null;
            if ($placaNorm !== null) {
                $st = $db->prepare('SELECT * FROM vehiculos WHERE placa = :p');
                $st->execute([':p' => $placaNorm]);
                $vehiculo = $st->fetch() ?: null;
            }

            if ($placaNorm === null) {
                if ($estado !== 'ERROR_INFERENCIA') {
                    $estado = 'OBSERVADO';
                }
                $alertasCrear[] = ['VEHICULO_NO_IDENTIFICADO', 'No se pudo identificar una placa legible en la imagen.'];
            } elseif ($vehiculo === null || !(int)$vehiculo['activo']) {
                if ($estado !== 'ERROR_INFERENCIA') {
                    $estado = 'OBSERVADO';
                }
                $alertasCrear[] = ['PLACA_NO_REGISTRADA', "La placa $placaNorm no se encuentra registrada activa en el padron."];
            }

            if ($confOcr !== null && $confOcr < $minConf) {
                if ($estado === 'REGISTRADO') {
                    $estado = 'OBSERVADO';
                }
                $alertasCrear[] = ['OCR_BAJA_CONFIANZA', sprintf('Confianza OCR baja (%.2f < %.2f) para la lectura "%s".', $confOcr, $minConf, $placaLeida ?? '')];
            }

            // Si M2 o M3 no devolvieron categoria/ejes pero el vehiculo esta registrado, usar los del padron como respaldo
            $catEfectiva = $cat ?? ($vehiculo['categoria'] ?? null);
            $ejesEfectivos = $ejes ?? (isset($vehiculo['ejes']) ? (int)$vehiculo['ejes'] : null);

            if ($cat === null && $ejes === null && $vehiculo === null && $inferencia['ok']) {
                if ($estado === 'REGISTRADO') {
                    $estado = 'OBSERVADO';
                }
                $alertasCrear[] = ['DATOS_INCOMPLETOS', 'La inferencia no devolvio categoria ni cantidad de ejes.'];
            }

            if ($catEfectiva !== null && $ejesEfectivos !== null && !Categoria::ejesCoherentes($catEfectiva, $ejesEfectivos)) {
                if ($estado === 'REGISTRADO') {
                    $estado = 'OBSERVADO';
                }
                $alertasCrear[] = ['INCONSISTENCIA_CATEGORIA_EJES', "Inconsistencia detectada: categoria '$catEfectiva' con $ejesEfectivos ejes."];
            }

            $monto = 0.0;
            if ($catEfectiva !== null && $ejesEfectivos !== null) {
                $monto = $this->catalogo->calcularTarifa($catEfectiva, max(2, min(9, $ejesEfectivos))) ?? 0.0;
            } elseif ($catEfectiva !== null) {
                $monto = $this->catalogo->calcularTarifa($catEfectiva, 2) ?? 0.0;
            }

            // Control y descuento de saldo si el vehiculo esta registrado
            if ($vehiculo !== null && (int)$vehiculo['activo'] === 1) {
                $saldoActual = (float)$vehiculo['saldo'];
                if ($saldoActual < $monto || $saldoActual <= 0.0) {
                    if ($estado === 'REGISTRADO') {
                        $estado = 'OBSERVADO';
                    }
                    $alertasCrear[] = ['SIN_SALDO', sprintf('Vehiculo %s sin saldo suficiente (saldo Bs %.2f, tarifa Bs %.2f).', $placaNorm, $saldoActual, $monto)];
                } elseif ($modo === 'REAL' && $monto > 0) {
                    $nuevoSaldo = round(max(0.0, $saldoActual - $monto), 2);
                    $db->prepare('UPDATE vehiculos SET saldo = :s, updated_at = :t WHERE id = :id')
                        ->execute([':s' => $nuevoSaldo, ':t' => Dates::now(), ':id' => $vehiculo['id']]);
                }
            }

            $now = Dates::now();
            $db->prepare(
                'INSERT INTO pasos (placa_leida, placa_normalizada, confianza_ocr, categoria_detectada, ejes_detectados,
                    pasajeros, monto, caseta_id, usuario_id, imagen_url, estado, origen, created_at)
                 VALUES (:pl, :pn, :co, :cat, :ej, :pas, :m, :ca, :u, :img, :es, :or, :t)'
            )->execute([
                ':pl' => $placaLeida,
                ':pn' => $placaNorm,
                ':co' => $confOcr,
                ':cat' => $catEfectiva,
                ':ej' => $ejesEfectivos,
                ':pas' => $pasajeros,
                ':m' => $monto,
                ':ca' => $casetaId,
                ':u' => $userId,
                ':img' => $evidencia['url'],
                ':es' => $estado,
                ':or' => $modo,
                ':t' => $now,
            ]);
            $pasoId = (int)$db->lastInsertId();

            $insAlerta = $db->prepare(
                'INSERT INTO alertas (paso_id, tipo, descripcion, atendida, es_demo, created_at)
                 VALUES (:p, :t, :d, 0, :demo, :c)'
            );
            $alertasOut = [];
            foreach ($alertasCrear as [$tipo, $desc]) {
                $insAlerta->execute([':p' => $pasoId, ':t' => $tipo, ':d' => $desc, ':demo' => $esDemo, ':c' => $now]);
                $aid = (int)$db->lastInsertId();
                $alertasOut[] = ['id' => $aid, 'tipo' => $tipo, 'descripcion' => $desc];
            }

            ActivityService::log($db, $userId, 'PASO_REGISTRADO', 'pasos', $pasoId,
                sprintf('Placa=%s Estado=%s Monto=%.2f Modo=%s', $placaNorm ?? 'N/A', $estado, $monto, $modo), $ip);

            return [
                'paso' => $this->find($pasoId),
                'vehiculo' => $vehiculo ? VehiculoService::present($this->vehiculos->find((int)$vehiculo['id']) ? $db->query('SELECT * FROM vehiculos WHERE id=' . (int)$vehiculo['id'])->fetch() : $vehiculo) : null,
                'alertas_generadas' => $alertasOut,
                'inferencia' => [
                    'modo' => $modo,
                    'latencia_ms' => $inferencia['latencia_ms'] ?? null,
                    'ok' => $inferencia['ok'],
                ],
            ];
        });
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.*, c.nombre AS caseta_nombre, u.nombre AS operador_nombre
               FROM pasos p
               LEFT JOIN casetas c ON c.id = p.caseta_id
               LEFT JOIN usuarios u ON u.id = p.usuario_id
              WHERE p.id = :id'
        );
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ? self::present($r) : null;
    }

    public function list(array $q): array
    {
        $page = max(1, (int)($q['page'] ?? 1));
        $per = min(100, max(1, (int)($q['per_page'] ?? 20)));
        $where = [];
        $p = [];

        if (!empty($q['placa'])) {
            $n = Plate::normalize((string)$q['placa']);
            if ($n !== '') {
                $where[] = "(p.placa_normalizada LIKE :pl ESCAPE '\\' OR p.placa_leida LIKE :pl ESCAPE '\\')";
                $p[':pl'] = '%' . addcslashes($n, '%_\\') . '%';
            }
        }
        if (!empty($q['categoria'])) {
            if (!Categoria::esValida((string)$q['categoria'])) {
                throw new ApiException(422, 'Categoria invalida.', ['categoria' => 'Categoria no permitida.']);
            }
            $where[] = 'p.categoria_detectada = :cat';
            $p[':cat'] = $q['categoria'];
        }
        if (!empty($q['caseta_id']) && ctype_digit((string)$q['caseta_id'])) {
            $where[] = 'p.caseta_id = :ca';
            $p[':ca'] = (int)$q['caseta_id'];
        }
        if (!empty($q['estado'])) {
            if (!in_array($q['estado'], ['REGISTRADO', 'OBSERVADO', 'ERROR_INFERENCIA'], true)) {
                throw new ApiException(422, 'Estado invalido.', ['estado' => 'Estado no permitido.']);
            }
            $where[] = 'p.estado = :es';
            $p[':es'] = $q['estado'];
        }
        if (!empty($q['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$q['desde'])) {
            $where[] = 'p.created_at >= :d';
            $p[':d'] = $q['desde'] . ' 00:00:00';
        }
        if (!empty($q['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$q['hasta'])) {
            $where[] = 'p.created_at <= :h';
            $p[':h'] = $q['hasta'] . ' 23:59:59';
        }
        if (!empty($q['since_id']) && ctype_digit((string)$q['since_id'])) {
            $where[] = 'p.id > :sid';
            $p[':sid'] = (int)$q['since_id'];
        }

        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $cnt = $this->db->prepare("SELECT COUNT(*) FROM pasos p $w");
        $cnt->execute($p);
        $total = (int)$cnt->fetchColumn();

        $st = $this->db->prepare(
            "SELECT p.*, c.nombre AS caseta_nombre, u.nombre AS operador_nombre
               FROM pasos p
               LEFT JOIN casetas c ON c.id = p.caseta_id
               LEFT JOIN usuarios u ON u.id = p.usuario_id
               $w
              ORDER BY p.id DESC
              LIMIT :l OFFSET :o"
        );
        foreach ($p as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':l', $per, PDO::PARAM_INT);
        $st->bindValue(':o', ($page - 1) * $per, PDO::PARAM_INT);
        $st->execute();

        return [
            'items' => array_map([self::class, 'present'], $st->fetchAll()),
            'total' => $total,
            'page' => $page,
            'per_page' => $per,
        ];
    }

    /**
     * Consulta una placa y devuelve uno de los 4 estados requeridos por la seccion 4.8:
     *  - REGISTRADA_CON_PASO
     *  - REGISTRADA_SIN_PASO
     *  - IDENTIFICADA_NO_REGISTRADA
     *  - NO_EXISTE
     * Incluye sugerencias ante errores de OCR (O<->0, I<->1, B<->8, S<->5).
     */
    public function consultarPlaca(string $rawPlaca): array
    {
        $norm = Plate::normalize($rawPlaca);
        if ($norm === '') {
            throw new ApiException(422, 'Ingrese una placa valida.', ['placa' => 'La placa no puede estar vacia.']);
        }
        $formatoValido = Plate::isValid($norm);
        $autocorregida = !$formatoValido ? Plate::autocorrect($norm) : null;
        $buscada = $autocorregida ?? $norm;

        $vehiculo = $this->vehiculos->findByPlaca($buscada);
        $historial = $this->historialPorPlaca($buscada, 20);
        $totalPasos = count($historial);

        if ($vehiculo !== null && $totalPasos > 0) {
            $estado = 'REGISTRADA_CON_PASO';
            $etiqueta = 'Registrada y con paso';
        } elseif ($vehiculo !== null) {
            $estado = 'REGISTRADA_SIN_PASO';
            $etiqueta = 'Registrada sin paso';
        } elseif ($totalPasos > 0) {
            $estado = 'IDENTIFICADA_NO_REGISTRADA';
            $etiqueta = 'Identificada no registrada (posible evasion)';
        } else {
            $estado = 'NO_EXISTE';
            $etiqueta = 'No existe';
        }

        // Sugerencias cercanas por confusion OCR si no existe o no esta registrada
        $sugerencias = [];
        if ($vehiculo === null) {
            $variantes = Plate::variants($buscada, 2);
            if ($variantes) {
                $in = implode(',', array_fill(0, count($variantes), '?'));
                $st = $this->db->prepare("SELECT placa FROM vehiculos WHERE placa IN ($in) LIMIT 5");
                $st->execute($variantes);
                $sugerencias = $st->fetchAll(PDO::FETCH_COLUMN);
                if (!$sugerencias) {
                    $st2 = $this->db->prepare("SELECT DISTINCT placa_normalizada FROM pasos WHERE placa_normalizada IN ($in) LIMIT 5");
                    $st2->execute($variantes);
                    $sugerencias = $st2->fetchAll(PDO::FETCH_COLUMN);
                }
            }
        }

        return [
            'placa_consultada' => $rawPlaca,
            'placa_normalizada' => $buscada,
            'formato_boliviano' => Plate::isValid($buscada),
            'autocorregida_desde' => $autocorregida !== null ? $norm : null,
            'estado_placa' => $estado,
            'estado_descripcion' => $etiqueta,
            'vehiculo' => $vehiculo,
            'ultimo_paso' => $historial[0] ?? null,
            'total_pasos' => $totalPasos,
            'sugerencias' => $sugerencias,
        ];
    }

    public function historialPorPlaca(string $rawPlaca, int $limit = 50): array
    {
        $norm = Plate::normalize($rawPlaca);
        if ($norm === '') {
            throw new ApiException(422, 'Placa invalida.', ['placa' => 'Placa vacia.']);
        }
        $st = $this->db->prepare(
            'SELECT p.*, c.nombre AS caseta_nombre, u.nombre AS operador_nombre
               FROM pasos p
               LEFT JOIN casetas c ON c.id = p.caseta_id
               LEFT JOIN usuarios u ON u.id = p.usuario_id
              WHERE p.placa_normalizada = :p
              ORDER BY p.id DESC
              LIMIT :l'
        );
        $st->bindValue(':p', $norm);
        $st->bindValue(':l', max(1, min(200, $limit)), PDO::PARAM_INT);
        $st->execute();
        return array_map([self::class, 'present'], $st->fetchAll());
    }

    public function resumenCaseta(int $casetaId): array
    {
        $caseta = $this->catalogo->caseta($casetaId) ?? throw new ApiException(404, 'Caseta no encontrada.');
        [$ini, $fin] = Dates::todayRangeUtc();

        $st = $this->db->prepare(
            'SELECT COUNT(*) AS pasos_hoy,
                    COALESCE(SUM(monto), 0) AS recaudacion_hoy,
                    COALESCE(SUM(CASE WHEN estado = \'OBSERVADO\' OR estado = \'ERROR_INFERENCIA\' THEN 1 ELSE 0 END), 0) AS observados_hoy
               FROM pasos
              WHERE caseta_id = :c AND created_at >= :i AND created_at < :f'
        );
        $st->execute([':c' => $casetaId, ':i' => $ini, ':f' => $fin]);
        $hoy = $st->fetch();

        $st2 = $this->db->prepare('SELECT COUNT(*) AS pasos_total, COALESCE(SUM(monto), 0) AS recaudacion_total FROM pasos WHERE caseta_id = :c');
        $st2->execute([':c' => $casetaId]);
        $tot = $st2->fetch();

        $st3 = $this->db->prepare(
            'SELECT categoria_detectada AS categoria, COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS recaudacion
               FROM pasos
              WHERE caseta_id = :c AND created_at >= :i AND created_at < :f
              GROUP BY categoria_detectada'
        );
        $st3->execute([':c' => $casetaId, ':i' => $ini, ':f' => $fin]);

        return [
            'caseta' => $caseta,
            'fecha' => (new \DateTimeImmutable('today', new \DateTimeZone((string)Config::get('app.timezone', 'America/La_Paz'))))->format('Y-m-d'),
            'pasos_hoy' => (int)$hoy['pasos_hoy'],
            'recaudacion_hoy' => round((float)$hoy['recaudacion_hoy'], 2),
            'observados_hoy' => (int)$hoy['observados_hoy'],
            'pasos_total' => (int)$tot['pasos_total'],
            'recaudacion_total' => round((float)$tot['recaudacion_total'], 2),
            'por_categoria_hoy' => $st3->fetchAll(),
        ];
    }

    // ---------------- Alertas ----------------
    public function listAlertas(array $q): array
    {
        $page = max(1, (int)($q['page'] ?? 1));
        $per = min(100, max(1, (int)($q['per_page'] ?? 20)));
        $where = [];
        $p = [];

        if (isset($q['atendida']) && $q['atendida'] !== '') {
            $where[] = 'a.atendida = :at';
            $p[':at'] = filter_var($q['atendida'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (!empty($q['tipo'])) {
            $where[] = 'a.tipo = :t';
            $p[':t'] = (string)$q['tipo'];
        }
        if (!empty($q['since_id']) && ctype_digit((string)$q['since_id'])) {
            $where[] = 'a.id > :sid';
            $p[':sid'] = (int)$q['since_id'];
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $cnt = $this->db->prepare("SELECT COUNT(*) FROM alertas a $w");
        $cnt->execute($p);
        $total = (int)$cnt->fetchColumn();

        $st = $this->db->prepare(
            "SELECT a.*, p.placa_normalizada, c.nombre AS caseta_nombre, u.nombre AS atendida_por_nombre
               FROM alertas a
               LEFT JOIN pasos p ON p.id = a.paso_id
               LEFT JOIN casetas c ON c.id = p.caseta_id
               LEFT JOIN usuarios u ON u.id = a.atendida_por
               $w
              ORDER BY a.id DESC
              LIMIT :l OFFSET :o"
        );
        foreach ($p as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':l', $per, PDO::PARAM_INT);
        $st->bindValue(':o', ($page - 1) * $per, PDO::PARAM_INT);
        $st->execute();

        return [
            'items' => array_map([self::class, 'presentAlerta'], $st->fetchAll()),
            'total' => $total,
            'page' => $page,
            'per_page' => $per,
        ];
    }

    public function atenderAlerta(int $id, int $userId, string $ip): array
    {
        $st = $this->db->prepare('SELECT * FROM alertas WHERE id = :id');
        $st->execute([':id' => $id]);
        $a = $st->fetch() ?? throw new ApiException(404, 'Alerta no encontrada.');
        if (!(int)$a['atendida']) {
            $this->db->prepare('UPDATE alertas SET atendida = 1, atendida_por = :u, atendida_en = :t WHERE id = :id')
                ->execute([':u' => $userId, ':t' => Dates::now(), ':id' => $id]);
            ActivityService::log($this->db, $userId, 'ALERTA_ATENDIDA', 'alertas', $id, $a['tipo'], $ip);
        }
        $st2 = $this->db->prepare(
            'SELECT a.*, p.placa_normalizada, c.nombre AS caseta_nombre, u.nombre AS atendida_por_nombre
               FROM alertas a
               LEFT JOIN pasos p ON p.id = a.paso_id
               LEFT JOIN casetas c ON c.id = p.caseta_id
               LEFT JOIN usuarios u ON u.id = a.atendida_por
              WHERE a.id = :id'
        );
        $st2->execute([':id' => $id]);
        return self::presentAlerta($st2->fetch());
    }

    /** Datos incrementales para el dashboard en tiempo real (polling cada 2 s). */
    public function dashboardPoll(int $sincePasoId = 0, int $sinceAlertaId = 0): array
    {
        [$ini, $fin] = Dates::todayRangeUtc();

        $totalVehiculos = (int)$this->db->query('SELECT COUNT(*) FROM vehiculos WHERE activo = 1')->fetchColumn();
        $alertasPendientes = (int)$this->db->query('SELECT COUNT(*) FROM alertas WHERE atendida = 0')->fetchColumn();

        $st = $this->db->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(monto), 0) AS m FROM pasos WHERE created_at >= :i AND created_at < :f');
        $st->execute([':i' => $ini, ':f' => $fin]);
        $hoy = $st->fetch();

        // Pasos nuevos (si sincePasoId==0 devuelve los 15 mas recientes)
        if ($sincePasoId > 0) {
            $stP = $this->db->prepare(
                'SELECT p.*, c.nombre AS caseta_nombre, u.nombre AS operador_nombre
                   FROM pasos p LEFT JOIN casetas c ON c.id = p.caseta_id LEFT JOIN usuarios u ON u.id = p.usuario_id
                  WHERE p.id > :id ORDER BY p.id DESC LIMIT 50'
            );
            $stP->execute([':id' => $sincePasoId]);
        } else {
            $stP = $this->db->query(
                'SELECT p.*, c.nombre AS caseta_nombre, u.nombre AS operador_nombre
                   FROM pasos p LEFT JOIN casetas c ON c.id = p.caseta_id LEFT JOIN usuarios u ON u.id = p.usuario_id
                  ORDER BY p.id DESC LIMIT 15'
            );
        }
        $pasos = array_map([self::class, 'present'], $stP->fetchAll());

        // Alertas pendientes recientes
        if ($sinceAlertaId > 0) {
            $stA = $this->db->prepare(
                'SELECT a.*, p.placa_normalizada, c.nombre AS caseta_nombre
                   FROM alertas a LEFT JOIN pasos p ON p.id = a.paso_id LEFT JOIN casetas c ON c.id = p.caseta_id
                  WHERE a.id > :id AND a.atendida = 0 ORDER BY a.id DESC LIMIT 20'
            );
            $stA->execute([':id' => $sinceAlertaId]);
        } else {
            $stA = $this->db->query(
                'SELECT a.*, p.placa_normalizada, c.nombre AS caseta_nombre
                   FROM alertas a LEFT JOIN pasos p ON p.id = a.paso_id LEFT JOIN casetas c ON c.id = p.caseta_id
                  WHERE a.atendida = 0 ORDER BY a.id DESC LIMIT 8'
            );
        }
        $alertas = array_map([self::class, 'presentAlerta'], $stA->fetchAll());

        // Ultimos vehiculos registrados
        $ultVeh = array_map(
            [VehiculoService::class, 'present'],
            $this->db->query('SELECT * FROM vehiculos ORDER BY id DESC LIMIT 6')->fetchAll()
        );

        // Distribucion por categoria (pasos)
        $dist = $this->db->query(
            'SELECT COALESCE(categoria_detectada, \'sin_identificar\') AS categoria, COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS recaudacion
               FROM pasos GROUP BY categoria_detectada ORDER BY cantidad DESC'
        )->fetchAll();

        // Recaudacion por caseta
        $porCaseta = $this->db->query(
            'SELECT c.id, c.nombre, COUNT(p.id) AS pasos, COALESCE(SUM(p.monto), 0) AS recaudacion
               FROM casetas c LEFT JOIN pasos p ON p.caseta_id = c.id
              GROUP BY c.id ORDER BY c.id'
        )->fetchAll();

        $maxPasoId = (int)$this->db->query('SELECT COALESCE(MAX(id), 0) FROM pasos')->fetchColumn();
        $maxAlertaId = (int)$this->db->query('SELECT COALESCE(MAX(id), 0) FROM alertas')->fetchColumn();

        return [
            'contadores' => [
                'total_vehiculos' => $totalVehiculos,
                'pasos_hoy' => (int)$hoy['c'],
                'recaudacion_hoy' => round((float)$hoy['m'], 2),
                'alertas_pendientes' => $alertasPendientes,
            ],
            'max_paso_id' => $maxPasoId,
            'max_alerta_id' => $maxAlertaId,
            'pasos_nuevos' => $pasos,
            'alertas_nuevas' => $alertas,
            'ultimos_vehiculos' => $ultVeh,
            'distribucion_categorias' => $dist,
            'recaudacion_casetas' => $porCaseta,
            'inferencia' => (new InferenceService())->lastKnownStatus(),
            'timestamp' => Dates::iso(Dates::now()),
        ];
    }
}
