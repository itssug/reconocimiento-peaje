<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\ApiException;
use App\Helpers\Config;

/**
 * Validacion, almacenamiento seguro (fuera de public/) y entrega controlada de imagenes de evidencia.
 * Nombres aleatorios criptograficos; verificacion de firma real (finfo + getimagesize).
 */
final class EvidenceService
{
    /**
     * Valida y guarda un archivo subido ($_FILES['imagen'] o bytes crudos).
     * @return array{filename:string,full_path:string,mime:string,url:string}
     */
    public function storeUploaded(?array $file, ?string $base64 = null): array
    {
        $maxBytes = (int)Config::get('app.uploads.max_bytes', 5 * 1024 * 1024);
        $allowed = (array)Config::get('app.uploads.mimes', [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        ]);

        $tmpPath = null;
        $cleanupTmp = false;

        if ($file !== null && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new ApiException(413, 'La imagen supera el tamano maximo permitido.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
                throw new ApiException(422, 'Error al subir la imagen.', ['imagen' => 'Subida fallida.']);
            }
            if ((int)($file['size'] ?? 0) > $maxBytes || filesize($file['tmp_name']) > $maxBytes) {
                throw new ApiException(413, 'La imagen supera el tamano maximo permitido (' . round($maxBytes / 1048576, 1) . ' MB).');
            }
            $tmpPath = $file['tmp_name'];
        } elseif ($base64 !== null && trim($base64) !== '') {
            $clean = preg_replace('#^data:image/\w+;base64,#i', '', trim($base64)) ?? '';
            $bytes = base64_decode($clean, true);
            if ($bytes === false || $bytes === '') {
                throw new ApiException(422, 'Imagen base64 invalida.', ['imagen' => 'Base64 invalido.']);
            }
            if (strlen($bytes) > $maxBytes) {
                throw new ApiException(413, 'La imagen supera el tamano maximo permitido.');
            }
            $tmpPath = tempnam(sys_get_temp_dir(), 'ev_');
            file_put_contents($tmpPath, $bytes);
            $cleanupTmp = true;
        } else {
            throw new ApiException(422, 'La imagen o frame es obligatoria.', ['imagen' => 'Campo obligatorio.']);
        }

        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($tmpPath);
            if (!isset($allowed[$mime])) {
                throw new ApiException(415, 'Formato de imagen no permitido. Solo JPG, PNG o WebP.', ['imagen' => 'Formato no permitido.']);
            }
            $dim = @getimagesize($tmpPath);
            if ($dim === false || $dim[0] < 8 || $dim[1] < 8 || ($dim[0] * $dim[1]) > 50_000_000) {
                throw new ApiException(422, 'Dimensiones de imagen invalidas.', ['imagen' => 'Imagen corrupta o dimensiones invalidas.']);
            }

            $dir = (string)Config::get('app.paths.evidencias');
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new ApiException(500, 'No se pudo preparar el directorio de evidencias.');
            }
            $this->ensureHtaccess($dir);

            $ext = $allowed[$mime];
            $filename = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $dir . '/' . $filename;
            if (!@copy($tmpPath, $dest)) {
                throw new ApiException(500, 'No se pudo guardar la imagen de evidencia.');
            }
            return [
                'filename' => $filename,
                'full_path' => $dest,
                'mime' => $mime,
                'url' => '/api/v1/evidencias/' . $filename,
            ];
        } finally {
            if ($cleanupTmp && $tmpPath && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /** Valida una imagen temporal para consulta sin guardarla como evidencia permanente. */
    public function validateTemp(?array $file, ?string $base64 = null): array
    {
        $maxBytes = (int)Config::get('app.uploads.max_bytes', 5 * 1024 * 1024);
        $allowed = (array)Config::get('app.uploads.mimes', [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        ]);
        $tmpPath = null;
        $cleanup = false;

        if ($file !== null && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new ApiException(413, 'La imagen supera el tamano maximo permitido.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
                throw new ApiException(422, 'Error al subir la imagen.', ['imagen' => 'Subida fallida.']);
            }
            if ((int)($file['size'] ?? 0) > $maxBytes || filesize($file['tmp_name']) > $maxBytes) {
                throw new ApiException(413, 'La imagen supera el tamano maximo permitido.');
            }
            $tmpPath = $file['tmp_name'];
        } elseif ($base64 !== null && trim($base64) !== '') {
            $clean = preg_replace('#^data:image/\w+;base64,#i', '', trim($base64)) ?? '';
            $bytes = base64_decode($clean, true);
            if ($bytes === false || $bytes === '') {
                throw new ApiException(422, 'Imagen base64 invalida.', ['imagen' => 'Base64 invalido.']);
            }
            if (strlen($bytes) > $maxBytes) {
                throw new ApiException(413, 'La imagen supera el tamano maximo permitido.');
            }
            $tmpPath = tempnam(sys_get_temp_dir(), 'q_');
            file_put_contents($tmpPath, $bytes);
            $cleanup = true;
        } else {
            throw new ApiException(422, 'La imagen es obligatoria.', ['imagen' => 'Campo obligatorio.']);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpPath);
        if (!isset($allowed[$mime])) {
            if ($cleanup) { @unlink($tmpPath); }
            throw new ApiException(415, 'Formato de imagen no permitido. Solo JPG, PNG o WebP.', ['imagen' => 'Formato no permitido.']);
        }
        $dim = @getimagesize($tmpPath);
        if ($dim === false || $dim[0] < 8 || $dim[1] < 8) {
            if ($cleanup) { @unlink($tmpPath); }
            throw new ApiException(422, 'Imagen corrupta o dimensiones invalidas.', ['imagen' => 'Imagen invalida.']);
        }
        return ['path' => $tmpPath, 'mime' => $mime, 'cleanup' => $cleanup];
    }

    public function resolvePath(string $filename): string
    {
        if (!preg_match('/^[0-9]{8}_[0-9]{6}_[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
            throw new ApiException(404, 'Evidencia no encontrada.');
        }
        $path = (string)Config::get('app.paths.evidencias') . '/' . $filename;
        if (!is_file($path)) {
            throw new ApiException(404, 'Evidencia no encontrada.');
        }
        return $path;
    }

    private function ensureHtaccess(string $dir): void
    {
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml .phar .cgi .pl .py\nphp_flag engine off\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
        }
    }
}
