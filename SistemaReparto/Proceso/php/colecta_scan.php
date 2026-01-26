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

$base  = trim($_POST['base'] ?? '');
$bulto = trim($_POST['bulto'] ?? '');

if ($base === '' || $bulto === '') {
    responder(['success' => 0, 'error' => 'Faltan base o bulto']);
}

// Normalizo base por si viene con _n (por error o futuro cambio)
$base = explode('_', $base)[0];

// Este es el “código escaneado” real (puede ser BASE_2, etc.)
// Lo vamos a usar en Observaciones
$codigo = $bulto;
$base = explode('_', $codigo)[0];

$usuario   = $_SESSION['Usuario']  ?? ($_POST['Usuario']  ?? '');
$sucursal  = $_SESSION['Sucursal'] ?? ($_POST['Sucursal'] ?? '');
$recorrido = $_SESSION['RecorridoAsignado'] ?? ($_POST['Recorrido'] ?? '');

if ($usuario === '') {
    http_response_code(401);
    echo json_encode([
        'success' => 0,
        'error' => 'Sin sesión (y sin Usuario por POST)',
        'debug' => [
            'has_session' => isset($_SESSION) ? 1 : 0,
            'cookie' => $_COOKIE['PHPSESSID'] ?? null
        ]
    ]);
    exit;
}
// $recorrido = $_SESSION['RecorridoAsignado'] ?? '';
// $usuario   = $_SESSION['Usuario'] ?? '';
// $sucursal  = $_SESSION['Sucursal'] ?? '';

// if ($usuario === '') {
//     http_response_code(401);
//     responder(['success' => 0, 'error' => 'Sin sesión']);
// }

// Si NO vas a hacer update acá, dejalo explícito:
$okUp = false;

// 1) Traer datos para Seguimiento
$sqlT = $mysqli->prepare("
    SELECT id, idClienteDestino, DomicilioDestino, NumerodeOrden
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

$idTransClientes = (int)($tr['id'] ?? 0);
$idCliente       = (int)($tr['idClienteDestino'] ?? 0);
$destino         = (string)($tr['DomicilioDestino'] ?? '');
$nroOrden        = (string)($tr['NumerodeOrden'] ?? '');

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
$obs = 'Bulto ' . $codigo . ' escaneado (colecta)';

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
$types = "ssssssssii sis"; // lo armamos prolijo abajo para evitar confusión

// OJO: el string exacto correcto es:
$types = "ssssssssii" . "s" . "i" . "ss"; // = "ssssssssii s i ss" => "ssssssssii sis s"? mejor explícito:
$types = "ssssssssii" . "s" . "i" . "ss"; // => "ssssssssii si ss" (sin espacios) = "ssssssssii s i ss"
$types = "ssssssssii" . "s" . "i" . "ss"; // final: "ssssssssii s i ss"

// Bind en el MISMO orden de los ? del INSERT:
if (!$sqlIns->bind_param(
    "ssssssssii" . "s" . "i" . "ss",
    $fecha,          // 1 s
    $hora,           // 2 s
    $usuario,        // 3 s
    $sucursal,       // 4 s
    $base,           // 5 s
    $obs,            // 6 s
    $Estado,         // 7 s
    $destino,        // 8 s
    $idCliente,      // 9 i
    $idTransClientes, //10 i
    $recorrido,      //11 s
    $Estado_id,      //12 i
    $nroOrden,       //13 s
    $status          //14 s
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
    'bulto'    => $codigo,
    'estado'   => $Estado,
    'status'   => $status
]);
