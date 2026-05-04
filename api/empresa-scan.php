<?php
/**
 * ============================================================
 * api/empresa-scan.php
 * Asocia un contacto (escaneado por su QR) a la empresa
 * del usuario autenticado.
 *
 * Requiere autenticación con rol "Empresa".
 * El companyId se toma EXCLUSIVAMENTE del JWT.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_hubspot.php';

requireMethod('POST');
$auth = requireAuth('Empresa');

$companyId = (string) ($auth['companyId'] ?? '');
if ($companyId === '' || !ctype_digit($companyId)) {
    jsonError(403, 'No autorizado');
}

// ============================================================
// LECTURA Y VALIDACIÓN DEL QR
// ============================================================
$input = readJsonInput();
$rawQr = (string) ($input['email'] ?? '');

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
// ASOCIAR CONTACTO ↔ EMPRESA (HUBSPOT_DEFINED, type 1)
// ============================================================
[$assocCode] = hubspotRequest(
    'POST',
    '/crm/v4/associations/contacts/companies/batch/create',
    [
        'inputs' => [[
            'from'  => ['id' => $contactId],
            'to'    => ['id' => $companyId],
            'types' => [[
                'associationCategory' => 'HUBSPOT_DEFINED',
                'associationTypeId'   => 1,
            ]],
        ]],
    ]
);

// 201 = creada (o ya existía y la API la considera creada)
if ($assocCode !== 201) {
    jsonError(502, 'No se pudo registrar el escaneo');
}

jsonResponse(200, [
    'success' => true,
    'message' => 'Asistente asociado correctamente a la empresa',
    'contact' => $contactPayload,
]);
