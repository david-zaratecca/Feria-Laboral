<?php
/**
 * ============================================================
 * CONFIGURACIÓN GLOBAL — Feria Laboral Centro Colombo Americano
 * ============================================================
 * Carga las variables sensibles desde un archivo .env
 * (que NO se versiona en Git, ver .gitignore).
 *
 * Si en SiteGround puedes ubicar el .env FUERA de public_html,
 * cambia la línea de loadEnv() para apuntar allí. Ver
 * docs/INSTRUCCIONES_DESPLIEGUE.md.
 * ============================================================
 */

declare(strict_types=1);

// ============================================================
// CARGADOR MÍNIMO DE .env (sin Composer)
// ============================================================
function feria_loadEnv(string $path): void
{
    if (!is_readable($path)) {
        http_response_code(500);
        error_log("FATAL: archivo .env no encontrado en: $path");
        exit(json_encode([
            'success' => false,
            'message' => 'Error de configuración del servidor'
        ]));
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));

        // Quitar comillas envolventes si las hay
        if (preg_match('/^"(.*)"$/', $value, $m)
            || preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }

        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}

function feria_env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? false;
    if ($value === false) {
        $value = getenv($key);
    }
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
}

// ============================================================
// CARGAR .env
// ============================================================
//
// OPCIÓN A (default): .env en el mismo directorio que config.php
//   protegido por .htaccess.
feria_loadEnv(__DIR__ . '/.env');
//
// OPCIÓN B (más segura, recomendada en producción):
//   .env fuera de public_html. Comenta la línea anterior y
//   descomenta la siguiente (ajustando la ruta absoluta):
//
// feria_loadEnv('/home/USUARIO_SITEGROUND/feria-config/.env');

// ============================================================
// VALIDAR VARIABLES OBLIGATORIAS
// ============================================================
$required = ['HUBSPOT_API_KEY', 'RECAPTCHA_SECRET_KEY', 'JWT_SECRET'];
foreach ($required as $var) {
    if (feria_env($var) === null) {
        http_response_code(500);
        error_log("FATAL: falta variable de entorno requerida: $var");
        exit(json_encode([
            'success' => false,
            'message' => 'Error de configuración del servidor'
        ]));
    }
}

if (strlen((string) feria_env('JWT_SECRET')) < 32) {
    http_response_code(500);
    error_log('FATAL: JWT_SECRET debe tener al menos 32 caracteres.');
    exit(json_encode([
        'success' => false,
        'message' => 'Error de configuración del servidor'
    ]));
}

// ============================================================
// CONSTANTES PÚBLICAS
// ============================================================
define('HUBSPOT_API_KEY',     (string) feria_env('HUBSPOT_API_KEY'));
define('RECAPTCHA_SITE_KEY',  (string) feria_env('RECAPTCHA_SITE_KEY', ''));
define('RECAPTCHA_SECRET_KEY',(string) feria_env('RECAPTCHA_SECRET_KEY'));
define('JWT_SECRET',          (string) feria_env('JWT_SECRET'));
define('JWT_TTL',             (int)    feria_env('JWT_TTL', '28800'));
define('APP_ENV',             (string) feria_env('APP_ENV', 'production'));
define('APP_DEBUG',           filter_var(feria_env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));
define('ALLOWED_ORIGINS',     array_filter(array_map('trim',
    explode(',', (string) feria_env('ALLOWED_ORIGINS', ''))
)));
