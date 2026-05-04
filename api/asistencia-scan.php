<?php
/**
 * ============================================================
 * api/asistencia-scan.php
 * Registra la asistencia de un contacto cuando un voluntario
 * de la feria escanea su QR. Marca asistio_feria = "Si" en HubSpot.
 *
 * Requiere autenticación con rol "Asistente".
 * Espera POST con JSON: { "email": "<contenido del QR>" }
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_hubspot.php';

requireMethod('POST');
requireAuth('Asistente');

// ============================================================
// LECTURA Y VALIDACIÓN DEL QR
// ============================================================
$input = readJsonInput();
$rawQr = (string) ($input['email'] ?? '');

// Extraer un email del contenido del QR
if (!preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $rawQr, $m)) {
    jsonError(400, 'Código QR inválido');
}
$email = strtolower($m[0]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError(400, 'Código QR inválido');
}

// ============================================================
// BUSCAR CONTACTO
// ============================================================
$contact = hubspotSearchContact('email', $email, [
    'registro_feria_laboral',
    'asistio_feria',
    'firstname',
    'lastname',
    'email',
    'phone',
    'numero_de_identificacion',
    'fl___nivel_de_estudios',
]);

if ($contact === null) {
    jsonError(404, 'Contacto no encontrado');
}

$contactId = (string) $contact['id'];
$p         = $contact['properties'] ?? [];

$registroFeria = strtolower((string) ($p['registro_feria_laboral'] ?? 'no'));
$asistioFeria  = strtolower((string) ($p['asistio_feria']          ?? 'no'));

// Datos a devolver (siempre con la misma estructura)
$contactPayload = [
    'firstname'                => (string) ($p['firstname']                ?? ''),
    'lastname'                 => (string) ($p['lastname']                 ?? ''),
    'email'                    => (string) ($p['email']                    ?? $email),
    'phone'                    => (string) ($p['phone']                    ?? ''),
    'numero_de_identificacion' => (string) ($p['numero_de_identificacion'] ?? ''),
    'fl___nivel_de_estudios'   => (string) ($p['fl___nivel_de_estudios']   ?? ''),
];

// ============================================================
// VALIDAR REGISTRO PREVIO
// ============================================================
if ($registroFeria !== 'si') {
    jsonError(403, 'La persona no está registrada en la feria');
}

// ============================================================
// SI YA ESCANEÓ ANTES, AVISAR
// ============================================================
if ($asistioFeria === 'si') {
    jsonResponse(200, [
        'success'        => false,
        'alreadyScanned' => true,
        'message'        => 'Este asistente ya ingresó a la feria',
        'contact'        => $contactPayload,
    ]);
}

// ============================================================
// MARCAR asistio_feria = "Si"
// ============================================================
[$updCode] = hubspotRequest('PATCH', "/crm/v3/objects/contacts/$contactId", [
    'properties' => ['asistio_feria' => 'Si'],
]);

if ($updCode !== 200) {
    jsonError(502, 'No se pudo registrar la asistencia');
}

jsonResponse(200, [
    'success' => true,
    'message' => 'Asistencia registrada correctamente',
    'contact' => $contactPayload,
]);
