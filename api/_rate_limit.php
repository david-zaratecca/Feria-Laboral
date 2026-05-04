<?php
/**
 * ============================================================
 * api/_rate_limit.php
 * Rate limiting basado en archivos. Suficiente para tráfico
 * bajo/moderado de un evento puntual. En cargas mayores,
 * considerar Redis u otro store en memoria.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

const RL_DIR          = __DIR__ . '/../tmp/login_rate_limit';
const RL_MAX_ATTEMPTS = 5;       // intentos
const RL_WINDOW       = 60;      // segundos

/**
 * Devuelve la ruta del archivo de rate limit para una IP.
 * La IP se hashea con SHA-256 para no escribir IPs en disco.
 */
function rlFile(string $ip): string
{
    if (!is_dir(RL_DIR)) {
        @mkdir(RL_DIR, 0700, true);
    }
    return RL_DIR . '/' . hash('sha256', $ip);
}

/**
 * Registra un intento de login y devuelve true si está dentro del límite,
 * false si se excedió.
 */
function checkLoginRateLimit(string $ip): bool
{
    $file = rlFile($ip);
    $now  = time();

    $attempts = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $attempts = $decoded;
            }
        }
    }

    // Eliminar intentos fuera de la ventana
    $attempts = array_values(array_filter(
        $attempts,
        static fn($t) => is_int($t) && $t > ($now - RL_WINDOW)
    ));

    if (count($attempts) >= RL_MAX_ATTEMPTS) {
        return false;
    }

    $attempts[] = $now;
    @file_put_contents($file, json_encode($attempts), LOCK_EX);
    return true;
}

/**
 * Limpia el contador para una IP (ej. tras login exitoso).
 */
function clearLoginRateLimit(string $ip): void
{
    $file = rlFile($ip);
    if (file_exists($file)) {
        @unlink($file);
    }
}
