<?php

//TIENDA NUBE - FUNCIONES WEBHOOKS Y API

function traerDatosTransCliente(mysqli $mysqli, int $idTransClientes)
{
    $sql = "SELECT IngBrutosOrigen as idOrigen, CodigoSeguimiento, CodigoProveedor
            FROM TransClientes
            WHERE id = ?
            AND Eliminado = 0
            LIMIT 1";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param("i", $idTransClientes);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        throw new RuntimeException("TransCliente no encontrado para idTransClientes={$idTransClientes}");
    }

    return [
        'idOrigen' => (int)$row['idOrigen'],
        'CodigoSeguimiento' => (string)$row['CodigoSeguimiento'],
        'CodigoProveedor' => (string)$row['CodigoProveedor']
    ];
}

function tnCredencialesPorCliente(mysqli $mysqli, int $ncliente): array
{
    static $cache = [];

    if (isset($cache[$ncliente])) {
        return $cache[$ncliente];
    }

    $sql = "SELECT user_id_tn, token_tiendanube, carrier_id_tn
            FROM Clientes
            WHERE NdeCliente = ?
            LIMIT 1";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param("i", $ncliente);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        throw new RuntimeException("Cliente no encontrado para ncliente={$ncliente}");
    }

    // Normalizo tipos mínimos
    $row['user_id_tn'] = (string)$row['user_id_tn'];
    $row['token_tiendanube'] = (string)$row['token_tiendanube'];
    $row['carrier_id_tn'] = (string)$row['carrier_id_tn'];

    return $cache[$ncliente] = $row;
}

function tnCrearFulfillment(mysqli $mysqli, int $idTransClientes, array $payload): array
{
    $datos = traerDatosTransCliente($mysqli, $idTransClientes);
    $ncliente = $datos['idOrigen'];
    $order_id = $datos['CodigoProveedor'];
    $codigoseguimiento = $datos['CodigoSeguimiento'];

    $cred = tnCredencialesPorCliente($mysqli, $ncliente);

    $userId = $cred['user_id_tn'];
    $token  = $cred['token_tiendanube'];

    // OJO: URL correctamente interpolada
    $url = "https://api.nuvemshop.com.br/v1/{$userId}/orders/{$order_id}/fulfillments";

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return [
            'success' => 0,
            'error' => 'JSON payload inválido',
            'json_error' => json_last_error_msg(),
        ];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            // OJO: muchos APIs usan Authorization: Bearer ...
            // Si tu doc de Tienda Nube dice "Authentication: bearer", dejalo así.
            // Yo te lo dejo en Authorization (más estándar). Si te da 401, lo cambiamos a Authentication.
            "Authorization: Bearer " . $token,
            "User-Agent: Caddy (1579)",
            "Content-Type: application/json",
            "Accept: application/json",
        ],
    ]);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return [
            'success' => 0,
            'error' => 'cURL error',
            'errno' => $errno,
            'detail' => $err,
        ];
    }

    $decoded = null;
    if ($response !== '' && $response !== false) {
        $decoded = json_decode($response, true);
    }

    return [
        'success' => ($http >= 200 && $http < 300) ? 1 : 0,
        'http_code' => $http,
        'url' => $url,
        'response_raw' => $response,
        'response_json' => is_array($decoded) ? $decoded : null,
    ];
}


//IMPLEMENTACION 
// $payload = [
//     "status" => "delivered",
//     "description" => "Objeto entregue ao destinatário",
//     "city" => "Córdoba",
//     "province" => "Córdoba",
//     "country" => "AR",
//     "happened_at" => date('c'),               // ISO8601
//     "estimated_delivery_at" => date('c'),
// ];

// $r = tnCrearFulfillment($mysqli, $ncliente, $order_id, $payload);
// responder($r) o log