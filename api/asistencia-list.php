<?php
/**
 * ============================================================
 * api/asistencia-list.php
 * Devuelve la lista de contactos que ya marcaron asistencia
 * a la feria (asistio_feria = "Si").
 *
 * Requiere autenticación con rol "Asistente".
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_hubspot.php';

requireMethod('GET');
requireAuth('Asistente');

$allResults = [];
$after      = null;

do {
    $body = [
        'filterGroups' => [[
            'filters' => [[
                'propertyName' => 'asistio_feria',
                'operator'     => 'EQ',
                'value'        => 'Si',
            ]],
        ]],
        'properties' => [
            'firstname',
            'lastname',
            'email',
            'phone',
            'numero_de_identificacion',
            'fl___nivel_de_estudios',
        ],
        'limit' => 100,
    ];
    if ($after !== null) {
        $body['after'] = $after;
    }

    [$code, $data] = hubspotRequest('POST', '/crm/v3/objects/contacts/search', $body);
    if ($code !== 200 || $data === null) {
        jsonError(502, 'Error al consultar HubSpot');
    }

    foreach (($data['results'] ?? []) as $c) {
        $p = $c['properties'] ?? [];
        $allResults[] = [
            'firstname'                => (string) ($p['firstname']                ?? ''),
            'lastname'                 => (string) ($p['lastname']                 ?? ''),
            'email'                    => (string) ($p['email']                    ?? ''),
            'phone'                    => (string) ($p['phone']                    ?? ''),
            'numero_de_identificacion' => (string) ($p['numero_de_identificacion'] ?? ''),
            'fl___nivel_de_estudios'   => (string) ($p['fl___nivel_de_estudios']   ?? ''),
        ];
    }

    $after = $data['paging']['next']['after'] ?? null;
} while ($after !== null);

jsonResponse(200, [
    'success'  => true,
    'count'    => count($allResults),
    'contacts' => $allResults,
]);
