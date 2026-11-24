<?php
declare(strict_types=1);

namespace App\Helpers;

/** Lectura y validacion de datos de entrada; acumula errores y lanza 422. */
final class Input
{
    public array $errors = [];

    public function __construct(private array $d) {}

    public function has(string $f): bool
    {
        return array_key_exists($f, $this->d);
    }

    public function error(string $f, string $msg): void
    {
        $this->errors[$f] ??= $msg;
    }

    public function str(string $f, int $max, bool $required = false): ?string
    {
        $v = $this->d[$f] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) {
                $this->error($f, 'Campo obligatorio.');
            }
            return null;
        }
        if (!is_string($v) && !is_numeric($v)) {
            $this->error($f, 'Valor invalido.');
            return null;
        }
        $v = trim((string)$v);
        if (mb_strlen($v) > $max) {
            $this->error($f, "Maximo $max caracteres.");
            return null;
        }
        // elimina caracteres de control
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    }

    public function int(string $f, int $min, int $max, bool $required = false): ?int
    {
        $v = $this->d[$f] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->error($f, 'Campo obligatorio.');
            }
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            $this->error($f, 'Debe ser un numero entero.');
            return null;
        }
        $v = (int)$v;
        if ($v < $min || $v > $max) {
            $this->error($f, "Debe estar entre $min y $max.");
            return null;
        }
        return $v;
    }

    public function num(string $f, float $min, float $max, bool $required = false): ?float
    {
        $v = $this->d[$f] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->error($f, 'Campo obligatorio.');
            }
            return null;
        }
        if (!is_numeric($v)) {
            $this->error($f, 'Debe ser numerico.');
            return null;
        }
        $v = round((float)$v, 2);
        if ($v < $min || $v > $max) {
            $this->error($f, "Debe estar entre $min y $max.");
            return null;
        }
        return $v;
    }

    public function enum(string $f, array $allowed, bool $required = false): ?string
    {
        $v = $this->d[$f] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->error($f, 'Campo obligatorio.');
            }
            return null;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->error($f, 'Valor no permitido. Opciones: ' . implode(', ', $allowed) . '.');
            return null;
        }
        return $v;
    }

    public function bool(string $f): ?bool
    {
        if (!$this->has($f) || $this->d[$f] === null) {
            return null;
        }
        $b = filter_var($this->d[$f], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($b === null) {
            $this->error($f, 'Debe ser verdadero o falso.');
        }
        return $b;
    }

    public function email(string $f, bool $required = true): ?string
    {
        $v = $this->str($f, 190, $required);
        if ($v !== null && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->error($f, 'Correo invalido.');
            return null;
        }
        return $v === null ? null : strtolower($v);
    }

    public function validate(): void
    {
        if ($this->errors) {
            throw new ApiException(422, 'Datos invalidos.', $this->errors);
        }
    }
}
