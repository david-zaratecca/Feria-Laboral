<?php
/**
 * ============================================================
 * api/_bootstrap.php
 * Inicialización común para todos los endpoints de la API.
 * - Carga config y .env
 * - Configura cabeceras de respuesta y de seguridad
 * - Maneja CORS con allowlist
 * - Expone helpers: jsonResponse, jsonError, readJsonInput,
 *   requireMethod, clientIp
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// ============================================================
// MANEJO DE ERRORES (según APP_DEBUG)
// ============================================================
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

// ============================================================
// CABECERAS DE RESPUESTA Y SEGURIDAD
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header_remove('X-Powered-By');

// ============================================================
// CORS — sólo orígenes en lista blanca (ALLOWED_ORIGINS del .env)
// ============================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

// Preflight CORS
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// HELPERS
// ============================================================

/**
 * Responde con un JSON y termina la ejecución.
 */
function jsonResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Atajo para responder con un error genérico.
 */
function jsonError(int $code, string $message): never
{
    jsonResponse($code, [
        'success' => false,
        'message' => $message,
    ]);
}

/**
 * Lee el body como JSON y lo devuelve como array asociativo.
 * Si el JSON es inválido o el body está vacío y se exige body, devuelve error 400.
 */
function readJsonInput(bool $required = true): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        if ($required) {
            jsonError(400, 'Cuerpo de la solicitud vacío');
        }
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonError(400, 'Cuerpo de la solicitud inválido (JSON malformado)');
    }
    return $data;
}

/**
 * Exige que el método HTTP sea el indicado, o responde 405.
 */
function requireMethod(string $method): void
{
    $current = $_SERVER['REQUEST_METHOD'] ?? '';
    if (strtoupper($current) !== strtoupper($method)) {
        jsonError(405, 'Método no permitido');
    }
}

/**
 * Devuelve la IP del cliente. Considera que puede haber un proxy delante.
 */
function clientIp(): string
{
    // En SiteGround, REMOTE_ADDR es confiable. Si en el futuro se mete CDN
    // tipo Cloudflare, usar 'CF-Connecting-IP' tras validar el origen.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
