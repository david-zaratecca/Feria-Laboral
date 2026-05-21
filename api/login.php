<?php
/**
 * ============================================================
 * api/login.php
 * Autenticación de usuarios contra HubSpot.
 * Tras login exitoso, emite un JWT firmado que el cliente
 * deberá enviar en el header Authorization de cada llamada.
 *
 * Flujo:
 *   1. Validar método POST y rate-limit por IP.
 *   2. Validar formato de email y dominio permitido.
 *   3. Verificar reCAPTCHA v3.
 *   4. Buscar contacto en HubSpot.
 *   5. Comparar la "contraseña" (hoy = numero_de_identificacion)
 *      con hash_equals() para evitar timing attacks.
 *   6. Si rol=Empresa, obtener companyId asociado.
 *   7. Emitir JWT con claims firmados.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_hubspot.php';
require_once __DIR__ . '/_rate_limit.php';

requireMethod('POST');

// ============================================================
// 1. RATE LIMIT
// ============================================================
$ip = clientIp();
if (!checkLoginRateLimit($ip)) {
    jsonError(429, 'Demasiados intentos. Intenta nuevamente en un minuto.');
}

// ============================================================
// 2. LECTURA Y VALIDACIÓN DE INPUT
// ============================================================
$input = readJsonInput();

$email     = strtolower(trim((string) ($input['email']         ?? '')));
$password  =                    (string) ($input['password']       ?? '');
$captcha   =          trim((string) ($input['recaptchaToken'] ?? ''));

if ($email === '' || $password === '') {
    jsonError(400, 'Email y contraseña son requeridos');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError(401, 'Credenciales incorrectas');
}

// ============================================================
// 3. DOMINIOS PERMITIDOS
// ============================================================
$allowedDomains = [
    'colombobogota.edu.co',
    'adecco.com',
    'desarrolloeconomico.gov.co',
    'sena.edu.co',
    'americasbps.com',
    'ymcacolombia.org',
    'anyvan.com',
    'asurion.com',
    'atento.com',
    'atlanticqi.com',
    'bet365.com',
    'bpmconsulting.com.co',
    'capgemini.com',
    'comfandi.com.co',
    'concentrix.com',
    'dhl.com',
    'ea.com',
    'state.gov',
    'ensenaporcolombia.org',
    'weareeverise.com',
    'foundever.com',
    'ajg.com',
    'hirehoratio.co',
    'aloftbogotaairport.com',
    'igtsolutions.com',
    'innovaschools.edu.co',
    'unica.edu.co',
    'interactivo.com.co',
    'intouchcx.com',
    'konecta.com',
    'leangroup.com',
    'andi.com.co',
    'colombiapass.com',
    'omc.com',
    'sutherlandglobal.com',
    'tca-staffing.com',
    'tdcx.com',
    'teleperformance.com',
    'trustcore-services.com',
    'ttec.com',
    'valuanceglobal.com',
    'zemsania.com',
    'generation.org',
    'manpower.com.co',
    'vadel',
    'kpmg.com'
];

$domain = substr(strrchr($email, '@') ?: '', 1);
if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
    // Mensaje genérico — no revelar la razón real
    jsonError(401, 'Credenciales incorrectas');
}

// ============================================================
// 4. reCAPTCHA v3
// ============================================================
if ($captcha === '') {
    jsonError(400, 'Validación de seguridad fallida');
}

$ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $captcha,
        'remoteip' => $ip,
    ]),
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$verifyResponse = curl_exec($ch);
curl_close($ch);

$captchaData = json_decode((string) ($verifyResponse ?: '{}'), true) ?: [];
if (
    empty($captchaData['success']) ||
    ((float) ($captchaData['score'] ?? 0)) < 0.5 ||
    ($captchaData['action'] ?? '') !== 'login'
) {
    jsonError(403, 'Acceso bloqueado por seguridad');
}

// ============================================================
// 5. BUSCAR CONTACTO EN HUBSPOT
// ============================================================
$contact = hubspotSearchContact('email', $email, [
    'firstname',
    'numero_de_identificacion',
    'rol_feria',
    'registro_feria_laboral',
]);

if ($contact === null) {
    jsonError(401, 'Credenciales incorrectas');
}

$props = $contact['properties'] ?? [];
$idGuardado = (string) ($props['numero_de_identificacion'] ?? '');
$nombre     = (string) ($props['firstname']                ?? 'Usuario');
$rol        = (string) ($props['rol_feria']                ?? '');

// Mensaje único para todos los caminos de fallo
if ($idGuardado === '' || $rol === '') {
    jsonError(401, 'Credenciales incorrectas');
}

// ============================================================
// 6. VERIFICAR "CONTRASEÑA" — comparación timing-safe
//
// NOTA DE SEGURIDAD: hoy la "contraseña" es el numero_de_identificacion
// almacenado en HubSpot. Esto es DÉBIL (la cédula no es secreta).
// Recomendación: agregar a HubSpot una propiedad oculta `password_hash_feria`
// que contenga el resultado de password_hash() y validar con password_verify().
// Ver docs/DIAGNOSTICO_SEGURIDAD.md hallazgo #2.
// ============================================================
if (!hash_equals($idGuardado, $password)) {
    jsonError(401, 'Credenciales incorrectas');
}

// ============================================================
// 7. SI ES EMPRESA, OBTENER companyId ASOCIADO
// ============================================================
$companyId = null;
$companyName = null;
if ($rol === 'Empresa') {
    [$assocCode, $assocData] = hubspotRequest(
        'GET',
        "/crm/v4/objects/contacts/{$contact['id']}/associations/companies"
    );
    if ($assocCode === 200 && !empty($assocData['results'])) {
        foreach ($assocData['results'] as $company){
            if (!empty($company['associationTypes'])) {

                foreach ($company['associationTypes'] as $associationType) {

                    if (
                        isset($associationType['typeId']) &&
                        $associationType['typeId'] == 1
                    ) {
                        $companyId = (string) $company['toObjectId'];
                        break 2;
                    }
                }
            }
        }
    }

    if ($companyId === null) {
        jsonError(403, 'No se pudo identificar la empresa del usuario');
    }

    [$companyCode, $companyData] = hubspotRequest(
        'GET',
        "/crm/v3/objects/companies/{$companyId}?properties=name"
    );

    if ($companyCode === 200 && !empty($companyData['properties']['name'])) {
        $companyName = $companyData['properties']['name'];
    } else {
        jsonError(404, 'No se pudo obtener el nombre de la empresa');
    }
}

// ============================================================
// 8. EMITIR JWT
// ============================================================
$claims = [
    'sub'         => (string) $contact['id'],
    'rol'         => $rol,
    'nombre'      => $nombre,
    'companyId'   => $companyId,
    'companyName' => $companyName,
];
$token = jwtIssue($claims);

// Resetear rate limit tras login exitoso
clearLoginRateLimit($ip);

jsonResponse(200, [
    'success'     => true,
    'token'       => $token,
    'nombre'      => $nombre,
    'rol'         => $rol,
    'companyId'   => $companyId,
    'companyName' => $companyName,
]);
