<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\ApiException;
use App\Helpers\Database;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Models\Categoria;
use PDO;

/** Casetas, tarifas y usuarios autorizados (lista blanca). */
final class CatalogoService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    // ---------------- Casetas ----------------
    public static function presentCaseta(array $r): array
    {
        return ['id' => (int)$r['id'], 'nombre' => $r['nombre'], 'ubicacion' => $r['ubicacion'],
                'activa' => (bool)$r['activa'], 'es_demo' => (bool)$r['es_demo'], 'created_at' => Dates::iso($r['created_at'])];
    }

    public function casetas(bool $soloActivas = false): array
    {
        $rows = $this->db->query('SELECT * FROM casetas ' . ($soloActivas ? 'WHERE activa = 1 ' : '') . 'ORDER BY id')->fetchAll();
        return array_map([self::class, 'presentCaseta'], $rows);
    }

    public function caseta(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM casetas WHERE id = :id');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ? self::presentCaseta($r) : null;
    }

    public function saveCaseta(?int $id, array $body, int $uid, string $ip): array
    {
        $in = new Input($body);
        $partial = $id !== null;
        $d = [];
        if (!$partial || $in->has('nombre')) { $d['nombre'] = $in->str('nombre', 80, true); }
        if (!$partial || $in->has('ubicacion')) { $d['ubicacion'] = $in->str('ubicacion', 160); }
        if ($in->has('activa')) { $a = $in->bool('activa'); if ($a !== null) { $d['activa'] = $a ? 1 : 0; } }
        $in->validate();

        if (isset($d['nombre'])) {
            $st = $this->db->prepare('SELECT id FROM casetas WHERE nombre = :n COLLATE NOCASE AND id <> :id');
            $st->execute([':n' => $d['nombre'], ':id' => $id ?? 0]);
            if ($st->fetchColumn()) {
                throw new ApiException(409, 'Ya existe una caseta con ese nombre.', ['nombre' => 'Nombre duplicado.']);
            }
        }
        if ($id === null) {
            $this->db->prepare('INSERT INTO casetas (nombre, ubicacion, activa) VALUES (:n,:u,:a)')
                ->execute([':n' => $d['nombre'], ':u' => $d['ubicacion'] ?? null, ':a' => $d['activa'] ?? 1]);
            $id = (int)$this->db->lastInsertId();
            ActivityService::log($this->db, $uid, 'CASETA_CREADA', 'casetas', $id, $d['nombre'], $ip);
        } else {
            $this->caseta($id) ?? throw new ApiException(404, 'Caseta no encontrada.');
            $cols = []; $p = [':id' => $id];
            foreach ($d as $k => $v) { $cols[] = "$k = :$k"; $p[":$k"] = $v; }
            if ($cols) {
                $this->db->prepare('UPDATE casetas SET ' . implode(', ', $cols) . ' WHERE id = :id')->execute($p);
                ActivityService::log($this->db, $uid, 'CASETA_EDITADA', 'casetas', $id, 'Campos: ' . implode(',', array_keys($d)), $ip);
            }
        }
        return $this->caseta($id);
    }

    // ---------------- Tarifas ----------------
    public static function presentTarifa(array $r): array
    {
        return ['id' => (int)$r['id'], 'categoria' => $r['categoria'], 'ejes' => (int)$r['ejes'],
                'monto' => (float)$r['monto'], 'activa' => (bool)$r['activa']];
    }

    public function tarifas(): array
    {
        $rows = $this->db->query('SELECT * FROM tarifas ORDER BY categoria, ejes')->fetchAll();
        return array_map([self::class, 'presentTarifa'], $rows);
    }

    public function tarifa(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM tarifas WHERE id = :id');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ? self::presentTarifa($r) : null;
    }

    /** Crea o actualiza (por categoria+ejes). Devuelve [tarifa, creada]. */
    public function saveTarifa(?int $id, array $body, int $uid, string $ip): array
    {
        $in = new Input($body);
        $cur = $id !== null ? ($this->tarifa($id) ?? throw new ApiException(404, 'Tarifa no encontrada.')) : null;
        $cat = $in->has('categoria') ? $in->enum('categoria', Categoria::LISTA, true) : ($cur['categoria'] ?? $in->enum('categoria', Categoria::LISTA, true));
        $ejes = $in->has('ejes') ? $in->int('ejes', 2, 9, true) : ($cur['ejes'] ?? $in->int('ejes', 2, 9, true));
        $monto = $in->has('monto') ? $in->num('monto', 0, 100000, true) : ($cur !== null ? null : $in->num('monto', 0, 100000, true));
        if ($cat && $ejes !== null && !isset($in->errors['categoria']) && !isset($in->errors['ejes'])
            && !Categoria::ejesCoherentes($cat, (int)$ejes)) {
            $in->error('ejes', "Cantidad de ejes incoherente para la categoria $cat.");
        }
        $activa = null;
        if ($in->has('activa')) { $a = $in->bool('activa'); $activa = $a === null ? null : ($a ? 1 : 0); }
        $in->validate();

        $st = $this->db->prepare('SELECT id FROM tarifas WHERE categoria = :c AND ejes = :e');
        $st->execute([':c' => $cat, ':e' => $ejes]);
        $existing = $st->fetchColumn();
        if ($cur === null && $existing) {
            $id = (int)$existing;        // upsert
            $cur = $this->tarifa($id);
        }
        if ($cur !== null) {
            if ($existing && (int)$existing !== $id) {
                throw new ApiException(409, 'Ya existe una tarifa para esa categoria y ejes.');
            }
            $this->db->prepare('UPDATE tarifas SET categoria=:c, ejes=:e, monto=:m, activa=:a WHERE id=:id')
                ->execute([':c' => $cat, ':e' => $ejes, ':m' => $monto ?? $cur['monto'], ':a' => $activa ?? (int)$cur['activa'], ':id' => $id]);
            ActivityService::log($this->db, $uid, 'TARIFA_EDITADA', 'tarifas', $id, "$cat/$ejes ejes: {$cur['monto']} -> " . ($monto ?? $cur['monto']), $ip);
            return [$this->tarifa($id), false];
        }
        $this->db->prepare('INSERT INTO tarifas (categoria, ejes, monto, activa) VALUES (:c,:e,:m,:a)')
            ->execute([':c' => $cat, ':e' => $ejes, ':m' => $monto, ':a' => $activa ?? 1]);
        $nid = (int)$this->db->lastInsertId();
        ActivityService::log($this->db, $uid, 'TARIFA_CREADA', 'tarifas', $nid, "$cat/$ejes ejes: $monto", $ip);
        return [$this->tarifa($nid), true];
    }

    /** Monto aplicable: tarifa exacta o, si no existe, la de mayor cantidad de ejes <= detectada. */
    public function calcularTarifa(string $categoria, int $ejes): ?float
    {
        $st = $this->db->prepare('SELECT monto FROM tarifas WHERE categoria = :c AND ejes <= :e AND activa = 1 ORDER BY ejes DESC LIMIT 1');
        $st->execute([':c' => $categoria, ':e' => $ejes]);
        $m = $st->fetchColumn();
        return $m === false ? null : (float)$m;
    }

    // ---------------- Usuarios autorizados (lista blanca) ----------------
    public static function presentUsuario(array $r): array
    {
        return ['id' => (int)$r['id'], 'email' => $r['email'], 'rol' => $r['rol'], 'activo' => (bool)$r['activo'],
                'nombre' => $r['nombre'] ?? null, 'foto_url' => $r['foto_url'] ?? null,
                'ha_ingresado' => $r['usuario_id'] !== null, 'created_at' => Dates::iso($r['created_at'])];
    }

    public function usuarios(): array
    {
        $rows = $this->db->query(
            'SELECT w.id, w.email, w.rol, w.activo, w.created_at, u.id AS usuario_id, u.nombre, u.foto_url
               FROM lista_blanca w LEFT JOIN usuarios u ON u.email_google = w.email ORDER BY w.id'
        )->fetchAll();
        return array_map([self::class, 'presentUsuario'], $rows);
    }

    private function usuarioRow(int $id): ?array
    {
        $st = $this->db->prepare('SELECT w.id, w.email, w.rol, w.activo, w.created_at, u.id AS usuario_id, u.nombre, u.foto_url
                                    FROM lista_blanca w LEFT JOIN usuarios u ON u.email_google = w.email WHERE w.id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch() ?: null;
    }

    public function addUsuario(array $body, int $uid, string $ip): array
    {
        $in = new Input($body);
        $email = $in->email('email');
        $rol = $in->enum('rol', ['ADMIN', 'OPERADOR'], true);
        $in->validate();
        $st = $this->db->prepare('SELECT id FROM lista_blanca WHERE email = :e');
        $st->execute([':e' => $email]);
        if ($st->fetchColumn()) {
            throw new ApiException(409, 'Ese correo ya esta en la lista blanca.', ['email' => 'Correo duplicado.']);
        }
        $this->db->prepare('INSERT INTO lista_blanca (email, rol, activo, creado_por) VALUES (:e,:r,1,:u)')
            ->execute([':e' => $email, ':r' => $rol, ':u' => $uid]);
        $id = (int)$this->db->lastInsertId();
        ActivityService::log($this->db, $uid, 'USUARIO_AUTORIZADO', 'lista_blanca', $id, "$email como $rol", $ip);
        return self::presentUsuario($this->usuarioRow($id));
    }

    public function updateUsuario(int $id, array $body, array $actor, string $ip): array
    {
        $row = $this->usuarioRow($id) ?? throw new ApiException(404, 'Usuario no encontrado.');
        $in = new Input($body);
        $rol = $in->enum('rol', ['ADMIN', 'OPERADOR']);
        $activo = null;
        if ($in->has('activo')) { $a = $in->bool('activo'); $activo = $a === null ? null : ($a ? 1 : 0); }
        $in->validate();

        $newRol = $rol ?? $row['rol'];
        $newAct = $activo ?? (int)$row['activo'];
        $esDocente = strcasecmp($row['email'], Installer::ADMIN_EMAIL) === 0;
        $esPropio = strcasecmp($row['email'], (string)$actor['email']) === 0;
        if (($esDocente || $esPropio) && ($newRol !== 'ADMIN' || $newAct !== 1)) {
            throw new ApiException(409, $esDocente
                ? 'La cuenta del administrador principal no puede degradarse ni desactivarse.'
                : 'No puede quitarse su propio acceso de administrador.');
        }
        if ($row['rol'] === 'ADMIN' && (int)$row['activo'] === 1 && ($newRol !== 'ADMIN' || $newAct !== 1)) {
            $n = (int)$this->db->query("SELECT COUNT(*) FROM lista_blanca WHERE rol='ADMIN' AND activo=1")->fetchColumn();
            if ($n <= 1) {
                throw new ApiException(409, 'Debe existir al menos un administrador activo.');
            }
        }
        $this->db->prepare('UPDATE lista_blanca SET rol = :r, activo = :a WHERE id = :id')
            ->execute([':r' => $newRol, ':a' => $newAct, ':id' => $id]);
        if ($newAct === 0 && $row['usuario_id'] !== null) {   // revoca sesiones abiertas
            $this->db->prepare('UPDATE sesiones SET revocada = 1 WHERE usuario_id = :u AND revocada = 0')
                ->execute([':u' => $row['usuario_id']]);
        }
        ActivityService::log($this->db, (int)$actor['id'], 'USUARIO_EDITADO', 'lista_blanca', $id,
            "{$row['email']}: rol {$row['rol']}->$newRol, activo {$row['activo']}->$newAct", $ip);
        return self::presentUsuario($this->usuarioRow($id));
    }
}
