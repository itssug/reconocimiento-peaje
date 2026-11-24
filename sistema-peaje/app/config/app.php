<?php
declare(strict_types=1);

use App\Helpers\Env;

return [
    'name'      => Env::get('APP_NAME', 'Sistema de Control de Peaje'),
    'env'       => Env::get('APP_ENV', 'production'),
    'debug'     => Env::bool('APP_DEBUG', false),
    'url'       => Env::get('APP_URL', ''),
    'timezone'  => Env::get('APP_TIMEZONE', 'America/La_Paz'),
    'key'       => Env::get('APP_KEY', ''),

    'paths' => [
        'root'       => BASE_PATH,
        'storage'    => BASE_PATH . '/storage',
        'logs'       => BASE_PATH . '/storage/logs',
        'evidencias' => BASE_PATH . '/storage/evidencias',
        'lock'       => BASE_PATH . '/storage/installed.lock',
    ],

    'google' => [
        'client_id'     => Env::get('GOOGLE_CLIENT_ID', ''),
        'client_secret' => Env::get('GOOGLE_CLIENT_SECRET', ''),
        'issuers'       => ['https://accounts.google.com', 'accounts.google.com'],
    ],

    'session' => [
        'ttl_hours'      => Env::int('SESSION_TTL_HOURS', 8),
        'max_attempts'   => Env::int('LOGIN_MAX_ATTEMPTS', 5),
        'window_minutes' => Env::int('LOGIN_WINDOW_MINUTES', 15),
    ],

    'inference' => [
        'url'            => Env::get('INFERENCE_URL', ''),
        'api_key'        => Env::get('INFERENCE_API_KEY', ''),
        'timeout'        => Env::int('INFERENCE_TIMEOUT_SECONDS', 15),
        'ocr_min_conf'   => Env::float('OCR_MIN_CONFIDENCE', 0.80),
    ],

    'plates' => [
        'regex' => Env::get('PLATE_REGEX', '^(\d{3,4})([A-Z]{3})$'),
    ],

    'uploads' => [
        'max_bytes' => Env::int('MAX_IMAGE_MB', 5) * 1024 * 1024,
        'mimes'     => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
    ],

    'agent' => [
        'provider_url'   => Env::get('AGENT_PROVIDER_URL', ''),
        'provider_key'   => Env::get('AGENT_PROVIDER_KEY', ''),
        'provider_model' => Env::get('AGENT_PROVIDER_MODEL', ''),
    ],

    'install_token'   => Env::get('INSTALL_TOKEN', ''),
    'diag_enabled'    => Env::bool('DIAG_ENABLED', true),
];
