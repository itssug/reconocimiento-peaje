<?php
declare(strict_types=1);

/**
 * Seeder de DEMOSTRACION. Todo registro queda marcado (es_demo=1 / origen='DEMO')
 * y usa datos ficticios: placas terminadas en "DEM", propietarios "DEMO - ...".
 * No se crean pagos ni transacciones; los saldos son un valor de prueba marcado como DEMO.
 * Devuelve una funcion (PDO) => string[] de mensajes. Es idempotente.
 */
return function (PDO $pdo): array {
    $log = [];
    if ((int)$pdo->query('SELECT COUNT(*) FROM vehiculos WHERE es_demo = 1')->fetchColumn() > 0) {
        return ['Datos DEMO ya existentes: se omite el seeder.'];
    }
    mt_srand(20260101); // resultados reproducibles
    $now = time();
    $ts = static fn(int $secAgo): string => gmdate('Y-m-d H:i:s', $now - $secAgo);

    $pdo->beginTransaction();
    try {
        // --- Casetas ---
        $c = $pdo->prepare('INSERT INTO casetas (nombre, ubicacion, es_demo) VALUES (:n, :u, 1)');
        $c->execute([':n' => 'DEMO Caseta Norte', ':u' => 'Ubicacion ficticia - sentido norte']);
        $c->execute([':n' => 'DEMO Caseta Sur', ':u' => 'Ubicacion ficticia - sentido sur']);
        $casetas = $pdo->query('SELECT id FROM casetas WHERE es_demo = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $log[] = 'DEMO: ' . count($casetas) . ' casetas.';

        // --- Vehiculos (20+) con distintas categorias y ejes ---
        $plan = [
            ['motocicleta', 2], ['motocicleta', 2], ['motocicleta', 2],
            ['automovil', 2], ['automovil', 2], ['automovil', 2], ['automovil', 2], ['automovil', 2], ['automovil', 2],
            ['minibus', 2], ['minibus', 2], ['minibus', 2],
            ['bus', 2], ['bus', 3], ['bus', 2],
            ['camion', 2], ['camion', 3], ['camion', 4], ['camion', 5], ['camion', 3],
            ['automovil', 2], ['camion', 6],
        ];
        $v = $pdo->prepare('INSERT INTO vehiculos (placa, propietario, categoria, ejes, saldo, es_demo, created_at, updated_at)
                            VALUES (:p, :o, :c, :e, :s, 1, :t, :t)');
        $veh = [];
        foreach ($plan as $i => [$cat, $ejes]) {
            $n = $i + 1;
            $placa = sprintf('%04dDEM', 1000 + $n);
            // Saldo de PRUEBA (DEMO). Los ultimos 3 quedan en 0 para probar la alerta SIN_SALDO.
            $saldo = $n > count($plan) - 3 ? 0.0 : 100.0;
            $v->execute([':p' => $placa, ':o' => sprintf('DEMO - Propietario ficticio %02d', $n),
                         ':c' => $cat, ':e' => $ejes, ':s' => $saldo, ':t' => $ts(86400 * 5)]);
            $veh[] = ['placa' => $placa, 'cat' => $cat, 'ejes' => $ejes, 'saldo' => $saldo];
        }
        $log[] = 'DEMO: ' . count($veh) . ' vehiculos (placas "####DEM").';

        $tarifa = static function (string $cat, int $ejes) use ($pdo): ?float {
            $st = $pdo->prepare('SELECT monto FROM tarifas WHERE categoria=:c AND ejes<=:e AND activa=1 ORDER BY ejes DESC LIMIT 1');
            $st->execute([':c' => $cat, ':e' => $ejes]);
            $m = $st->fetchColumn();
            return $m === false ? null : (float)$m;
        };

        // --- Pasos de demostracion (sin imagen de evidencia: no se inventan imagenes) ---
        $p = $pdo->prepare('INSERT INTO pasos (placa_leida, placa_normalizada, confianza_ocr, categoria_detectada, ejes_detectados,
                                pasajeros, monto, caseta_id, usuario_id, imagen_url, estado, origen, created_at)
                            VALUES (:pl, :pn, :co, :cat, :ej, :pas, :m, :ca, NULL, NULL, :es, \'DEMO\', :t)');
        $a = $pdo->prepare('INSERT INTO alertas (paso_id, tipo, descripcion, es_demo, atendida, created_at)
                            VALUES (:p, :t, :d, 1, :at, :c)');
        $pasos = 0; $alertas = 0;
        for ($i = 0; $i < 36; $i++) {
            $ago = 60 * (8 + $i * 25) + mt_rand(0, 600);       // repartidos en ~15 horas
            $x = $veh[mt_rand(0, count($veh) - 1)];
            $conf = round(mt_rand(55, 99) / 100, 2);
            $placaLeida = $x['placa'];
            $estado = 'REGISTRADO';
            $alerta = null;
            $monto = $tarifa($x['cat'], $x['ejes']) ?? 0.0;

            if ($i % 9 === 4) {                                // OCR con baja confianza
                $conf = round(mt_rand(40, 69) / 100, 2);
                $estado = 'OBSERVADO';
                $alerta = ['OCR_BAJA_CONFIANZA', "Confianza OCR baja ($conf) en el paso DEMO."];
            } elseif ($i % 12 === 7) {                         // placa no registrada
                $placaLeida = '7777DEM';
                $x = ['placa' => '7777DEM', 'cat' => 'automovil', 'ejes' => 2, 'saldo' => 0.0];
                $monto = $tarifa('automovil', 2) ?? 0.0;
                $estado = 'OBSERVADO';
                $alerta = ['PLACA_NO_REGISTRADA', 'La placa 7777DEM no esta registrada (dato DEMO).'];
            } elseif ($x['saldo'] <= 0.0 && $i % 3 === 0) {    // sin saldo
                $estado = 'OBSERVADO';
                $alerta = ['SIN_SALDO', "El vehiculo {$x['placa']} no tiene saldo (dato DEMO)."];
            }

            $p->execute([':pl' => $placaLeida, ':pn' => $placaLeida, ':co' => $conf, ':cat' => $x['cat'], ':ej' => $x['ejes'],
                         ':pas' => mt_rand(1, 4), ':m' => $monto, ':ca' => $casetas[$i % count($casetas)],
                         ':es' => $estado, ':t' => $ts($ago)]);
            $pasoId = (int)$pdo->lastInsertId();
            $pasos++;
            if ($alerta) {
                $a->execute([':p' => $pasoId, ':t' => $alerta[0], ':d' => $alerta[1], ':at' => $i % 2, ':c' => $ts($ago)]);
                $alertas++;
            }
        }
        // Alertas adicionales de ejemplo sobre pasos existentes
        $first = (int)$pdo->query("SELECT id FROM pasos WHERE origen='DEMO' ORDER BY id LIMIT 1")->fetchColumn();
        foreach ([
            ['ERROR_INFERENCIA', 'Tiempo de espera agotado al llamar al endpoint de inferencia (ejemplo DEMO).'],
            ['INCONSISTENCIA_CATEGORIA_EJES', 'Categoria "automovil" con 4 ejes detectados (ejemplo DEMO).'],
            ['DATOS_INCOMPLETOS', 'No se detecto la cantidad de ejes (ejemplo DEMO).'],
            ['VEHICULO_NO_IDENTIFICADO', 'No se pudo clasificar el vehiculo (ejemplo DEMO).'],
        ] as [$t, $d]) {
            $a->execute([':p' => $first, ':t' => $t, ':d' => $d, ':at' => 0, ':c' => $ts(600)]);
            $alertas++;
        }
        $log[] = "DEMO: $pasos pasos (origen DEMO, sin imagenes) y $alertas alertas.";
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $log;
};
