<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\ApiException;
use App\Helpers\Database;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Plate;
use App\Models\Categoria;
use PDO;

/** CRUD de vehiculos (siempre PDO con consultas preparadas). */
final class VehiculoService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'placa' => $r['placa'], 'propietario' => $r['propietario'],
            'categoria' => $r['categoria'], 'ejes' => (int)$r['ejes'], 'saldo' => (float)$r['saldo'],
            'activo' => (bool)$r['activo'], 'es_demo' => (bool)$r['es_demo'],
            'created_at' => Dates::iso($r['created_at']), 'updated_at' => Dates::iso($r['updated_at']),
        ];
    }

    public function list(array $q): array
    {
        $page = max(1, (int)($q['page'] ?? 1));
        $per = min(100, max(1, (int)($q['per_page'] ?? 20)));
        $where = []; $p = [];
        $term = trim((string)($q['q'] ?? ''));
        if ($term !== '') {
            $like = '%' . addcslashes(Plate::normalize($term) ?: $term, '%_\\') . '%';
            $where[] = "(placa LIKE :q ESCAPE '\\' OR propietario LIKE :q2 ESCAPE '\\')";
            $p[':q'] = $like;
            $p[':q2'] = '%' . addcslashes($term, '%_\\') . '%';
        }
        if (!empty($q['categoria'])) {
            if (!Categoria::esValida((string)$q['categoria'])) {
                throw new ApiException(422, 'Datos invalidos.', ['categoria' => 'Categoria no valida.']);
            }
            $where[] = 'categoria = :cat';
            $p[':cat'] = $q['categoria'];
        }
        if (isset($q['activo']) && $q['activo'] !== '') {
            $where[] = 'activo = :act';
            $p[':act'] = filter_var($q['activo'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $c = $this->db->prepare("SELECT COUNT(*) FROM vehiculos $w");
        $c->execute($p);
        $total = (int)$c->fetchColumn();

        $st = $this->db->prepare("SELECT * FROM vehiculos $w ORDER BY id DESC LIMIT :l OFFSET :o");
        foreach ($p as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':l', $per, PDO::PARAM_INT);
        $st->bindValue(':o', ($page - 1) * $per, PDO::PARAM_INT);
        $st->execute();
        return [
            'items' => array_map([self::class, 'present'], $st->fetchAll()),
            'total' => $total, 'page' => $page, 'per_page' => $per,
        ];
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM vehiculos WHERE id = :id');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ? self::present($r) : null;
    }

    public function findByPlaca(string $normalized): ?array
    {
        $st = $this->db->prepare('SELECT * FROM vehiculos WHERE placa = :p');
        $st->execute([':p' => $normalized]);
        $r = $st->fetch();
        return $r ? self::present($r) : null;
    }

    /** Valida y normaliza los datos; $partial=true para actualizaciones parciales. */
    public function parse(array $body, bool $partial, ?array $current = null): array
    {
        $in = new Input($body);
        $d = [];
        if (!$partial || $in->has('placa')) {
            $raw = $in->str('placa', 20, true);
            if ($raw !== null) {
                $n = Plate::normalize($raw);
                if (!Plate::isValid($n)) {
                    $in->error('placa', 'Placa invalida. Formato esperado: 3-4 digitos + 3 letras (ej. 1234ABC).');
                } else {
                    $d['placa'] = $n;
                }
            }
        }
        if (!$partial || $in->has('propietario')) {
            $d['propietario'] = $in->str('propietario', 120);
        }
        if (!$partial || $in->has('categoria')) {
            $d['categoria'] = $in->enum('categoria', Categoria::LISTA, true);
        }
        if (!$partial || $in->has('ejes')) {
            $d['ejes'] = $in->int('ejes', 2, 9, true);
        }
        if ($in->has('saldo')) {
            $d['saldo'] = $in->num('saldo', 0, 1000000);
        }
        if ($in->has('activo')) {
            $a = $in->bool('activo');
            if ($a !== null) {
                $d['activo'] = $a ? 1 : 0;
            }
        }
        $cat = $d['categoria'] ?? $current['categoria'] ?? null;
        $ejes = $d['ejes'] ?? $current['ejes'] ?? null;
        if ($cat && $ejes !== null && !isset($in->errors['categoria']) && !isset($in->errors['ejes'])
            && !Categoria::ejesCoherentes($cat, (int)$ejes)) {
            $in->error('ejes', "Cantidad de ejes incoherente para la categoria $cat.");
        }
        $in->validate();
        return array_filter($d, static fn($v, $k) => $v !== null || $k === 'propietario', ARRAY_FILTER_USE_BOTH);
    }

    public function create(array $d, int $userId, string $ip): array
    {
        if ($this->findByPlaca($d['placa'])) {
            throw new ApiException(409, 'Ya existe un vehiculo con esa placa.', ['placa' => 'Placa duplicada.']);
        }
        $now = Dates::now();
        $this->db->prepare('INSERT INTO vehiculos (placa, propietario, categoria, ejes, saldo, activo, created_at, updated_at)
                            VALUES (:p,:o,:c,:e,:s,:a,:t,:t)')
            ->execute([':p' => $d['placa'], ':o' => $d['propietario'] ?? null, ':c' => $d['categoria'], ':e' => $d['ejes'],
                       ':s' => $d['saldo'] ?? 0, ':a' => $d['activo'] ?? 1, ':t' => $now]);
        $id = (int)$this->db->lastInsertId();
        ActivityService::log($this->db, $userId, 'VEHICULO_CREADO', 'vehiculos', $id, 'Placa ' . $d['placa'], $ip);
        return $this->find($id);
    }

    public function update(int $id, array $d, int $userId, string $ip): array
    {
        $cur = $this->find($id) ?? throw new ApiException(404, 'Vehiculo no encontrado.');
        if (isset($d['placa']) && $d['placa'] !== $cur['placa'] && $this->findByPlaca($d['placa'])) {
            throw new ApiException(409, 'Ya existe un vehiculo con esa placa.', ['placa' => 'Placa duplicada.']);
        }
        $cols = [];
        $p = [':id' => $id, ':t' => Dates::now()];
        foreach (['placa', 'propietario', 'categoria', 'ejes', 'saldo', 'activo'] as $c) {
            if (array_key_exists($c, $d)) {
                $cols[] = "$c = :$c";
                $p[":$c"] = $d[$c];
            }
        }
        if ($cols) {
            $this->db->prepare('UPDATE vehiculos SET ' . implode(', ', $cols) . ', updated_at = :t WHERE id = :id')->execute($p);
            $detalle = 'Campos: ' . implode(',', array_keys($d));
            if (isset($d['saldo']) && (float)$d['saldo'] !== $cur['saldo']) {
                $detalle .= sprintf(' | saldo %.2f -> %.2f (ajuste manual)', $cur['saldo'], $d['saldo']);
            }
            ActivityService::log($this->db, $userId, 'VEHICULO_EDITADO', 'vehiculos', $id, $detalle, $ip);
        }
        return $this->find($id);
    }

    public function deactivate(int $id, int $userId, string $ip): array
    {
        $this->find($id) ?? throw new ApiException(404, 'Vehiculo no encontrado.');
        $this->db->prepare('UPDATE vehiculos SET activo = 0, updated_at = :t WHERE id = :id')
            ->execute([':t' => Dates::now(), ':id' => $id]);
        ActivityService::log($this->db, $userId, 'VEHICULO_DESACTIVADO', 'vehiculos', $id, '', $ip);
        return $this->find($id);
    }
}
