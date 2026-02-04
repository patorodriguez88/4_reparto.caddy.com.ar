<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "../../Conexion/conexioni.php";
require_once __DIR__ . '/../../Funciones/estados.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');
header('Content-Type: application/json; charset=utf-8');

function responder($arr)
{
    echo json_encode($arr);
    exit;
}

if (!isset($_POST['ColectaBulto'])) {
    responder(['success' => 0, 'error' => 'Acción inválida']);
}


$base  = trim($_POST['base'] ?? '');   // puede venir tu CodigoSeguimiento o shipments_id
$bulto = trim($_POST['bulto'] ?? '');  // token escaneado (auditoría)

if ($base === '' || $bulto === '') {
    responder(['success' => 0, 'error' => 'Faltan base o bulto']);
}
// Cantidad confirmada visualmente (solo aplica a QR tipo ML)
$cantidad = (int)($_POST['cantidad'] ?? 1);
if ($cantidad <= 0) {
    $cantidad = 1;
}
$tokenEscaneado = $bulto;

// Si bulto viene BASE_2 → lookup BASE, si viene 4225... → lookup 4225...
$lookup = trim(explode('_', $bulto)[0]);

// $baseCandidate: lo que intentamos primero como CodigoSeguimiento
$baseCandidate = trim(explode('_', $base)[0]);

$usuario   = $_SESSION['Usuario']  ?? ($_POST['Usuario']  ?? '');
$sucursal  = $_SESSION['Sucursal'] ?? ($_POST['Sucursal'] ?? '');
$recorrido = $_SESSION['RecorridoAsignado'] ?? ($_POST['Recorrido'] ?? '');
$okUp = false;
if ($usuario === '') {
    responder(['success' => 0, 'forceLogout' => 1, 'reason' => 'NO_IDUSUARIO', 'error' => 'Sin sesión']);
}

// ---------------------------------------
// 1) Resolver TransClientes
//    Primero por CodigoSeguimiento, luego por shipments_id
// ---------------------------------------
$idTransClientes = 0;
$idCliente       = 0;
$destino         = '';
$nroOrden        = '';

/** 1.a) Buscar por CodigoSeguimiento */
$sqlT = $mysqli->prepare("
    SELECT id, CodigoSeguimiento, idClienteDestino, DomicilioDestino, NumerodeOrden
    FROM TransClientes
    WHERE CodigoSeguimiento=? AND Eliminado=0
    ORDER BY id DESC
    LIMIT 1
");
if (!$sqlT) {
    responder(['success' => 0, 'error' => 'prepare TransClientes failed', 'detail' => $mysqli->error]);
}
$sqlT->bind_param("s", $base);
$sqlT->execute();
$rt = $sqlT->get_result();
$tr = $rt ? $rt->fetch_assoc() : null;

if ($tr && !empty($tr['id'])) {
    $idTransClientes = (int)$tr['id'];

    // base FINAL = tu CodigoSeguimiento interno, sin _n
    $base = explode('_', (string)($tr['CodigoSeguimiento'] ?? $base))[0];

    $idCliente = (int)($tr['idClienteDestino'] ?? 0);
    $destino   = (string)($tr['DomicilioDestino'] ?? '');
    $nroOrden  = (string)($tr['NumerodeOrden'] ?? '');
}

/** 1.b) Fallback por shipments_id */
if ($idTransClientes === 0) {
    $sqlS = $mysqli->prepare("
        SELECT id, CodigoSeguimiento, idClienteDestino, DomicilioDestino, NumerodeOrden
        FROM TransClientes
        WHERE shipments_id=? AND Eliminado=0
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$sqlS) {
        responder(['success' => 0, 'error' => 'prepare shipments failed', 'detail' => $mysqli->error]);
    }

    $sqlS->bind_param("s", $lookup);
    $sqlS->execute();
    $rsS = $sqlS->get_result();
    $trS = $rsS ? $rsS->fetch_assoc() : null;

    if ($trS && !empty($trS['CodigoSeguimiento'])) {
        $idTransClientes = (int)($trS['id'] ?? 0);

        // base FINAL = tu CodigoSeguimiento interno
        $base = explode('_', (string)$trS['CodigoSeguimiento'])[0];

        $idCliente = (int)($trS['idClienteDestino'] ?? 0);
        $destino   = (string)($trS['DomicilioDestino'] ?? '');
        $nroOrden  = (string)($trS['NumerodeOrden'] ?? '');
    }
}

// Si no resolvimos un TransClientes válido, abortar

if ($idTransClientes === 0 || strlen($base) > 10) {
    responder([
        'success' => 0,
        'error' => 'CodigoSeguimiento interno no resuelto',
        'debug' => [
            'base' => $base,
            'shipments_id' => $bulto
        ]
    ]);
}
// 2) Anti duplicado por base + status final (pickup_ready)
$status_control = 'pickup_ready';
$status = 'pickup_scanned';


$sqlChk = $mysqli->prepare("
    SELECT id
    FROM Seguimiento
    WHERE CodigoSeguimiento=? AND status=? AND Eliminado=0
    LIMIT 1
");
if (!$sqlChk) {
    responder(['success' => 0, 'error' => 'prepare chk failed', 'detail' => $mysqli->error]);
}
$sqlChk->bind_param("ss", $base, $status_control);
$sqlChk->execute();
$chk = $sqlChk->get_result();
if ($chk && $chk->num_rows > 0) {
    responder([
        'success'  => 1,
        'updated'  => $okUp ? 1 : 0,
        'inserted' => 0,
        'codigo'   => $base
    ]);
}

// 3) Resolver estado por slug
$st = estadoPorSlug($mysqli, $status);
if (!$st || empty($st['id'])) {
    responder(['success' => 0, 'error' => 'No se encontró estado por slug', 'slug' => $status]);
}
$Estado_id = (int)$st['id'];
$Estado    = (string)$st['Estado'];

// 4) Insert Seguimiento
$fecha = date('Y-m-d');
$hora  = date('H:i:s');

// Observación con el bulto exacto escaneado (BASE_1, BASE_2, etc.)
// $obs = 'Bulto ' . $codigo . ' escaneado (colecta)';
// $obs = 'Bulto ' . $tokenEscaneado . ' escaneado (colecta)';
$obs = "QR ML {$bulto} | Cantidad confirmada: {$cantidad}";
$sqlIns = $mysqli->prepare("
    INSERT INTO Seguimiento
    (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones,
     Entregado, Estado, Destino, Avisado, idCliente, Retirado, Visitas,
     idTransClientes, TimeStamp, Recorrido, Devuelto, Webhook, state_id,
     NumerodeOrden, status, Eliminado)
    VALUES
    (?, ?, ?, ?, ?, ?,
     0, ?, ?, 0, ?, 0, 0,
     ?, NOW(), ?, 0, 0, ?,
     ?, ?, 0)
");
if (!$sqlIns) {
    responder(['success' => 0, 'error' => 'prepare insert failed', 'detail' => $mysqli->error]);
}

/**
 * Tipos:
 * Fecha(s), Hora(s), Usuario(s), Sucursal(s), CodigoSeguimiento(s), Observaciones(s),
 * Estado(s), Destino(s),
 * idCliente(i), idTransClientes(i),
 * Recorrido(s),
 * state_id(i),
 * NumerodeOrden(s),
 * status(s)
 *
 * => "ssssssssii s i ss" sin espacios:
 * "ssssssssii s i ss" => "ssssssssii s i ss" (vamos exacto)
 */
// $types = "ssssssssii sis"; // lo armamos prolijo abajo para evitar confusión
// 14 parámetros:
// 8 strings, 2 ints, 1 string, 1 int, 2 strings
$types = "ssssssssii s i ss";
$types = str_replace(' ', '', $types); // "ssssssssii s i ss" → "ssssssssii s i ss"
// Bind en el MISMO orden de los ? del INSERT:
if (!$sqlIns->bind_param(
    $types,
    $fecha,
    $hora,
    $usuario,
    $sucursal,
    $base,
    $obs,
    $Estado,
    $destino,
    $idCliente,
    $idTransClientes,
    $recorrido,
    $Estado_id,
    $nroOrden,
    $status
)) {
    responder(['success' => 0, 'error' => 'bind_param failed', 'detail' => $sqlIns->error]);
}

$okIns = $sqlIns->execute();
if (!$okIns) {
    responder(['success' => 0, 'error' => 'execute failed', 'detail' => $sqlIns->error]);
}

if ($st['Webhook'] === 1) {

    $payload = [
        "status" => $status,
        "description" => $Estado,
        "city" => "Córdoba",
        "province" => "Córdoba",
        "country" => "AR",
        "happened_at" => date('c'),               // ISO8601
        "estimated_delivery_at" => date('c'),
    ];

    $r = tnCrearFulfillment($mysqli, $idTransClientes, $payload);

    if (!$r['success']) {
        responder(['success' => 0, 'error' => 'tnCrearFulfillment failed', 'detail' => $r['error']]);
    }
}

responder([
    'success'  => 1,
    'updated'  => $okUp ? 1 : 0,
    'inserted' => 1,
    'codigo'   => $base,
    'bulto'    => $bulto,
    'estado'   => $Estado,
    'status'   => $status
]);
