<?php
/**
 * ============================================================
 * api/empresa-list.php
 * Devuelve los contactos asociados a la empresa del usuario
 * que vinieron a la feria (filtrados por asistio_feria).
 *
 * Requiere autenticación con rol "Empresa".
 * El companyId se toma EXCLUSIVAMENTE del JWT — el cliente
 * no puede pedir datos de otra empresa.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_hubspot.php';

requireMethod('GET');
$auth = requireAuth('Empresa');

$companyId = (string) ($auth['companyId'] ?? '');
if ($companyId === '' || !ctype_digit($companyId)) {
    jsonError(403, 'No autorizado');
}

// ============================================================
// 1. OBTENER IDS DE CONTACTOS ASOCIADOS A LA EMPRESA
// ============================================================
$contactIds = [];
$after      = null;

do {
    $path = "/crm/v4/objects/companies/{$companyId}/associations/contacts";
    if ($after !== null) {
        $path .= '?after=' . urlencode($after);
    }

    [$code, $data] = hubspotRequest('GET', $path);
    if ($code !== 200 || $data === null) {
        break;
    }

    foreach (($data['results'] ?? []) as $r) {
        if (!empty($r['toObjectId'])) {
            $contactIds[] = (string) $r['toObjectId'];
        }
    }

    $after = $data['paging']['next']['after'] ?? null;
} while ($after !== null);

if (empty($contactIds)) {
    jsonResponse(200, [
        'success'  => true,
        'count'    => 0,
        'contacts' => [],
    ]);
}

// ============================================================
// 2. LEER CONTACTOS EN BLOQUES DE 100
// ============================================================
$allContacts = [];
foreach (array_chunk($contactIds, 100) as $chunk) {
    [$code, $decoded] = hubspotRequest('POST', '/crm/v3/objects/contacts/batch/read', [
        'properties' => [
            'firstname',
            'lastname',
            'email',
            'phone',
            'asistio_feria',
            'numero_de_identificacion',
            'fl___nivel_de_estudios',
        ],
        'inputs' => array_map(
            static fn(string $id): array => ['id' => $id],
            $chunk
        ),
    ]);

    if ($code !== 200 || $decoded === null) {
        continue;
    }
    if (!empty($decoded['results'])) {
        $allContacts = array_merge($allContacts, $decoded['results']);
    }
}

// ============================================================
// 3. FILTRAR SÓLO QUIENES ASISTIERON A LA FERIA
// ============================================================
$results = [];
foreach ($allContacts as $c) {
    $p = $c['properties'] ?? [];
    $val = strtolower((string) ($p['asistio_feria'] ?? ''));
    if (!in_array($val, ['si', 'sí', 'true', '1'], true)) {
        continue;
    }

    $results[] = [
        'firstname'                => (string) ($p['firstname']                ?? ''),
        'lastname'                 => (string) ($p['lastname']                 ?? ''),
        'email'                    => (string) ($p['email']                    ?? ''),
        'phone'                    => (string) ($p['phone']                    ?? ''),
        'numero_de_identificacion' => (string) ($p['numero_de_identificacion'] ?? ''),
        'fl___nivel_de_estudios'   => (string) ($p['fl___nivel_de_estudios']   ?? ''),
    ];
}

jsonResponse(200, [
    'success'  => true,
    'count'    => count($results),
    'contacts' => $results,
]);
