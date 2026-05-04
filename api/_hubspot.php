<?php
/**
 * ============================================================
 * api/_hubspot.php
 * Cliente mínimo para la API de HubSpot.
 * Centraliza todas las llamadas cURL y aplica la configuración
 * de seguridad (verificación SSL, timeouts, no follow redirects).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * Realiza una llamada HTTP a la API de HubSpot.
 *
 * @param string     $method  GET, POST, PATCH, DELETE...
 * @param string     $path    Ruta relativa, debe empezar con '/'
 * @param array|null $body    Cuerpo JSON; null para no enviar body
 *
 * @return array{0:int, 1:array|null}  [statusCode, parsedJson|null]
 */
function hubspotRequest(string $method, string $path, ?array $body = null): array
{
    $url = 'https://api.hubapi.com' . $path;
    $ch = curl_init($url);

    if ($ch === false) {
        error_log('hubspotRequest: curl_init falló para ' . $url);
        return [0, null];
    }

    $headers = [
        'Authorization: Bearer ' . HUBSPOT_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,

        // Seguridad TLS — explícito para no depender de defaults del servidor
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,

        // Anti-SSRF / anti-redirect-abuse
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        error_log("HubSpot cURL error ($errno) on $method $path: $error");
        return [0, null];
    }

    $decoded = json_decode((string) $response, true);
    return [$code, is_array($decoded) ? $decoded : null];
}

/**
 * Busca un contacto en HubSpot por una propiedad simple (ej. email).
 * Devuelve el primer resultado o null si no encuentra nada.
 */
function hubspotSearchContact(string $property, string $value, array $properties): ?array
{
    [$code, $data] = hubspotRequest('POST', '/crm/v3/objects/contacts/search', [
        'filterGroups' => [[
            'filters' => [[
                'propertyName' => $property,
                'operator'     => 'EQ',
                'value'        => $value,
            ]],
        ]],
        'properties' => $properties,
        'limit'      => 1,
    ]);

    if ($code !== 200) {
        return null;
    }
    return $data['results'][0] ?? null;
}
