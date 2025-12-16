<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "../../Conexion/conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');

header('Content-Type: application/json; charset=utf-8');

if (isset($_POST['RetirarBulto'])) {
    $codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : '';
    if ($codigo === '') {
        echo json_encode(['success' => 0, 'error' => 'Código vacío']);
        exit;
    }

    $base = explode('_', $codigo)[0];

    $recorrido = isset($_SESSION['RecorridoAsignado']) ? $_SESSION['RecorridoAsignado'] : '';
    $usuario   = isset($_SESSION['Usuario']) ? $_SESSION['Usuario'] : '';
    $sucursal  = isset($_SESSION['Sucursal']) ? $_SESSION['Sucursal'] : '';

    if ($usuario === '') {
        http_response_code(401);
        echo json_encode(['success' => 0, 'error' => 'Sin sesión']);
        exit;
    }

    // 1) UPDATE TransClientes.Retirado=1
    $sqlUp = $mysqli->prepare("UPDATE TransClientes SET Retirado=1 WHERE CodigoSeguimiento=? AND Eliminado=0 LIMIT 1");
    $sqlUp->bind_param("s", $base);
    $okUp = $sqlUp->execute();

    // 2) Traer datos para Seguimiento
    $sqlT = $mysqli->prepare("SELECT id, idClienteDestino, DomicilioDestino, NumerodeOrden
                              FROM TransClientes
                              WHERE CodigoSeguimiento=? AND Eliminado=0
                              ORDER BY id DESC LIMIT 1");
    $sqlT->bind_param("s", $base);
    $sqlT->execute();
    $rt = $sqlT->get_result();
    $tr = $rt ? $rt->fetch_assoc() : null;

    $idTransClientes = isset($tr['id']) ? (int)$tr['id'] : 0;
    $idCliente       = isset($tr['idClienteDestino']) ? (int)$tr['idClienteDestino'] : 0;
    $destino         = isset($tr['DomicilioDestino']) ? $tr['DomicilioDestino'] : '';
    $nroOrden        = isset($tr['NumerodeOrden']) ? $tr['NumerodeOrden'] : '';

    // Anti duplicado
    $sqlChk = $mysqli->prepare("SELECT id FROM Seguimiento WHERE CodigoSeguimiento=? AND status='pickup_scanned' AND Eliminado=0 LIMIT 1");
    $sqlChk->bind_param("s", $base);
    $sqlChk->execute();
    $chk = $sqlChk->get_result();
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['success' => 1, 'updated' => $okUp ? 1 : 0, 'inserted' => 0, 'codigo' => $base]);
        exit;
    }

    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');

    $estadoTxt = 'Colectado del Cliente';
    $obs       = 'Bulto escaneado y retirado del cliente (colecta)';

    $sqlIns = $mysqli->prepare("INSERT INTO Seguimiento
        (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones, Entregado, Estado,
         Destino, Avisado, idCliente, Retirado, Visitas, idTransClientes, TimeStamp, Recorrido,
         Devuelto, Webhook, state_id, NumerodeOrden, status, Eliminado)
        VALUES
        (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 1, 0, ?, NOW(), ?, 0, 0, 0, ?, 'pickup_scanned', 0)
    ");

    // 12 vars: ssssssss i i s s
    $sqlIns->bind_param(
        "ssssssssisss",
        $fecha,
        $hora,
        $usuario,
        $sucursal,
        $base,
        $obs,
        $estadoTxt,
        $destino,
        $idCliente,
        $idTransClientes,
        $recorrido,
        $nroOrden
    );

    $okIns = $sqlIns->execute();

    echo json_encode([
        'success' => 1,
        'updated' => $okUp ? 1 : 0,
        'inserted' => $okIns ? 1 : 0,
        'codigo' => $base
    ]);
    exit;
}

echo json_encode(['success' => 0, 'error' => 'Acción inválida']);
