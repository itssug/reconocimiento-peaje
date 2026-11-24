<?php
declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/** Error de API con codigo HTTP; el router lo convierte en JSON consistente. */
final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = []
    ) {
        parent::__construct($message, $status);
    }
}
