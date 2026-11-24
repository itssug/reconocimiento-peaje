<?php
declare(strict_types=1);

namespace App\Helpers;

/** Normalizacion y validacion de placas bolivianas (formato configurable via PLATE_REGEX). */
final class Plate
{
    /** Pares de confusion tipicos de OCR (bidireccionales). */
    private const CONFUSION = ['O' => '0', '0' => 'O', 'I' => '1', '1' => 'I', 'B' => '8', '8' => 'B', 'S' => '5', '5' => 'S'];

    public static function normalize(string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($s))) ?? '';
    }

    public static function pattern(): string
    {
        return '~' . str_replace('~', '\~', (string)Config::get('app.plates.regex')) . '~';
    }

    public static function isValid(string $normalized): bool
    {
        return $normalized !== '' && preg_match(self::pattern(), $normalized) === 1;
    }

    /**
     * Corrige confusiones O/0, I/1, B/8, S/5 segun la posicion esperada
     * (digitos al inicio, 3 letras al final). Devuelve null si no cambia nada util.
     */
    public static function autocorrect(string $n): ?string
    {
        $len = strlen($n);
        if ($len < 6 || $len > 7) {
            return null;
        }
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $c = $n[$i];
            $isLetterPos = $i >= $len - 3;
            if ($isLetterPos && ctype_digit($c) && isset(self::CONFUSION[$c])) {
                $c = self::CONFUSION[$c];
            } elseif (!$isLetterPos && ctype_alpha($c) && isset(self::CONFUSION[$c])) {
                $c = self::CONFUSION[$c];
            }
            $out .= $c;
        }
        return $out !== $n && self::isValid($out) ? $out : null;
    }

    /**
     * Variantes con hasta $max sustituciones por confusion OCR (para sugerir "Quisiste decir...").
     * @return string[]
     */
    public static function variants(string $n, int $max = 2): array
    {
        $pos = [];
        for ($i = 0, $l = strlen($n); $i < $l; $i++) {
            if (isset(self::CONFUSION[$n[$i]])) {
                $pos[] = $i;
            }
        }
        $res = [];
        $rec = function (int $k, string $cur, int $changes) use (&$rec, &$res, $pos, $max, $n): void {
            if ($k === count($pos)) {
                if ($changes > 0) {
                    $res[$cur] = true;
                }
                return;
            }
            $rec($k + 1, $cur, $changes);
            if ($changes < $max) {
                $c = $cur;
                $c[$pos[$k]] = self::CONFUSION[$n[$pos[$k]]];
                $rec($k + 1, $c, $changes + 1);
            }
        };
        $rec(0, $n, 0);
        return array_keys($res);
    }
}
