<?php
/**
 * ============================================================
 * api/_auth.php
 * Autenticación basada en JWT (JSON Web Token) firmado con HS256.
 * Implementación propia y mínima, sin dependencias externas.
 *
 * - jwtIssue(claims, ttl)  -> string token
 * - jwtVerify(token)       -> array|null payload
 * - bearerToken()          -> string|null leído del header Authorization
 * - requireAuth(rol)       -> array payload (corta ejecución si falla)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// ============================================================
// HELPERS BASE64URL
// ============================================================
function b64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64UrlDecode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder !== 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

// ============================================================
// EMITIR Y VERIFICAR JWT
// ============================================================

/**
 * Emite un JWT firmado con HS256 a partir de los claims dados.
 * Agrega automáticamente iat, nbf, exp y jti.
 */
function jwtIssue(array $claims, int $ttl = JWT_TTL): string
{
    $now = time();
    $header  = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload = array_merge($claims, [
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $ttl,
        'jti' => bin2hex(random_bytes(8)),
    ]);

    $headerEnc  = b64UrlEncode(json_encode($header,  JSON_UNESCAPED_SLASHES));
    $payloadEnc = b64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

    $signature = hash_hmac('sha256', "$headerEnc.$payloadEnc", JWT_SECRET, true);
    $signatureEnc = b64UrlEncode($signature);

    return "$headerEnc.$payloadEnc.$signatureEnc";
}

/**
 * Verifica un JWT. Devuelve el payload si es válido, null en caso contrario.
 */
function jwtVerify(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$headerEnc, $payloadEnc, $signatureEnc] = $parts;

    // Verificar firma (timing-safe)
    $expected = hash_hmac('sha256', "$headerEnc.$payloadEnc", JWT_SECRET, true);
    $actual   = b64UrlDecode($signatureEnc);
    if (!hash_equals($expected, $actual)) {
        return null;
    }

    // Decodificar payload
    $payload = json_decode(b64UrlDecode($payloadEnc), true);
    if (!is_array($payload)) {
        return null;
    }

    // Validar tiempos
    $now = time();
    if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
        return null;
    }
    if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
        return null;
    }

    return $payload;
}

// ============================================================
// LECTURA DEL HEADER AUTHORIZATION
// ============================================================

/**
 * Devuelve el token Bearer del header Authorization, o null.
 */
function bearerToken(): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    $auth = $headers['Authorization']
        ?? $headers['authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? null;

    if (!$auth) {
        return null;
    }
    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        return null;
    }
    return trim($m[1]);
}

// ============================================================
// MIDDLEWARE
// ============================================================

/**
 * Exige una sesión válida. Si se especifica $rolRequerido, también valida el rol.
 * Si la autenticación falla, corta la ejecución con un 401/403.
 *
 * Devuelve el payload del JWT.
 */
function requireAuth(?string $rolRequerido = null): array
{
    $token = bearerToken();
    if ($token === null || $token === '') {
        jsonError(401, 'No autenticado');
    }

    $payload = jwtVerify($token);
    if ($payload === null) {
        jsonError(401, 'Sesión inválida o expirada');
    }

    if ($rolRequerido !== null && (string) ($payload['rol'] ?? '') !== $rolRequerido) {
        jsonError(403, 'No autorizado para esta acción');
    }

    return $payload;
}
