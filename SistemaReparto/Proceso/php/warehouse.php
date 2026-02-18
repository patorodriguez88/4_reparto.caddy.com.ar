<?php
require_once "../../Conexion/conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');
if (isset($_POST['GetLista'])) {

    $recorrido = $_SESSION['RecorridoAsignado'] ?? $_POST['recorrido'] ?? null;
    if (!$recorrido) {
        echo json_encode(['success' => 0, 'error' => 'Sin recorrido']);
        exit;
    }

    $sql = $mysqli->query("SELECT TransClientes.Retirado,TransClientes.CodigoSeguimiento, TransClientes.Cantidad,TransClientes.shipments_id
    FROM HojaDeRuta
    INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
    WHERE HojaDeRuta.Recorrido = '$recorrido'
    AND HojaDeRuta.Estado = 'Abierto'
    AND HojaDeRuta.Eliminado = 0
    AND TransClientes.Eliminado = 0
    ORDER BY TransClientes.CodigoSeguimiento ASC");

    $items = [];
    while ($r = $sql->fetch_assoc()) {
        $items[] = [
            'base' => $r['CodigoSeguimiento'],
            'bultos' => (int)$r['Cantidad'],
            'retirado' => (int)$r['Retirado'],
            'meli_id' => trim((string)($r['shipments_id'] ?? '')),
        ];
    }

    echo json_encode([
        'success' => 1,
        'recorrido' => $recorrido,
        'hash' => md5($recorrido . json_encode($items)),
        'items' => $items
    ]);
    exit;
}


// if (isset($_POST['RegistrarWarehouse'])) {

//     // ⚠️ Si warehouse.php lo llamás desde el front, debería pasar por conexioni.php
//     // y ahí ya tenés sesión/control.
//     // Si te devuelve 401, es porque no hay sesión -> lo resolvemos desde JS redirigiendo.

//     $codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : '';
//     if ($codigo === '') {
//         echo json_encode(['success' => 0, 'error' => 'Código vacío']);
//         exit;
//     }

//     // Normalizamos por si llega con _n
//     $base = explode('_', $codigo)[0];

//     $recorrido = $_SESSION['RecorridoAsignado'] ?? null;
//     $usuario   = $_SESSION['Usuario'] ?? null;     // ajustá si tu session usa otro key
//     $sucursal  = $_SESSION['Sucursal'] ?? '';      // ajustá si tu session usa otro key

//     if (!$recorrido || !$usuario) {
//         http_response_code(401);
//         echo json_encode(['success' => 0, 'error' => 'Sin sesión o recorrido']);
//         exit;
//     }

//     // ✅ Anti-duplicado: si ya está marcado como warehouse_validated, no insertamos de nuevo
//     $sqlChk = $mysqli->prepare("SELECT id FROM Seguimiento WHERE CodigoSeguimiento=? AND status='warehouse_validated' AND Eliminado=0 LIMIT 1");
//     $sqlChk->bind_param("s", $base);
//     $sqlChk->execute();
//     $chk = $sqlChk->get_result();

//     if ($chk && $chk->num_rows > 0) {
//         echo json_encode(['success' => 1, 'inserted' => 0, 'msg' => 'Ya registrado']);
//         exit;
//     }

//     // 🔎 Buscamos datos de TransClientes (mínimo: idTransClientes, idCliente, Destino)
//     $sqlT = $mysqli->prepare("SELECT id, idClienteDestino, DomicilioDestino AS Destino, NumerodeOrden FROM TransClientes WHERE CodigoSeguimiento=? AND Eliminado=0 ORDER BY id DESC LIMIT 1");
//     $sqlT->bind_param("s", $base);
//     $sqlT->execute();
//     $rt = $sqlT->get_result();
//     $tr = $rt ? $rt->fetch_assoc() : null;

//     $idTransClientes = $tr['id'] ?? 0;
//     $idCliente       = $tr['idClienteDestino'] ?? 0;
//     $destino         = $tr['Destino'] ?? '';
//     $nroOrden        = $tr['NumerodeOrden'] ?? '';

//     // 🧾 Insert Seguimiento (evento de validación en warehouse)
//     $fecha = date('Y-m-d');
//     $hora  = date('H:i:s');

//     // Elegí tu ID real en tabla Estados (ej: 13, 14, etc.)
//     // Por ahora lo mandamos 0 y después lo seteamos cuando lo crees.
//     $state_id = isset($_POST['state_id']) ? (int)$_POST['state_id'] : 0;

//     $estadoTxt = "Validado en Warehouse";
//     $obs       = "Validado en warehouse (escaneo QR)";

//     $sqlIns = $mysqli->prepare("INSERT INTO Seguimiento
//     (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones, Entregado, Estado,
//      Destino, Avisado, idCliente, Retirado, Visitas, idTransClientes, TimeStamp, Recorrido,
//      Devuelto, Webhook, state_id, NumerodeOrden, status, Eliminado)
//     VALUES
//     (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 0, 0, ?, NOW(), ?, 0, 0, ?, ?, 'warehouse_validated', 0)
// ");

//     // 8 strings + 2 int + 1 string + 1 int + 1 string = 13
//     $sqlIns->bind_param(
//         "ssssssssiisis",
//         $fecha,           // s
//         $hora,            // s
//         $usuario,         // s
//         $sucursal,        // s
//         $base,            // s
//         $obs,             // s
//         $estadoTxt,       // s
//         $destino,         // s
//         $idCliente,       // i
//         $idTransClientes, // i
//         $recorrido,       // s
//         $state_id,        // i
//         $nroOrden         // s
//     );

//     $ok = $sqlIns->execute();

//     echo json_encode([
//         'success' => $ok ? 1 : 0,
//         'inserted' => $ok ? 1 : 0,
//         'codigo' => $base,
//         'recorrido' => $recorrido
//     ]);
//     exit;
// }
if (isset($_POST['RegistrarWarehouseBatch'])) {

    $recorrido = $_SESSION['RecorridoAsignado'] ?? null;
    $usuario   = $_SESSION['Usuario'] ?? null;
    $sucursal  = $_SESSION['Sucursal'] ?? '';

    if (!$recorrido || !$usuario) {
        http_response_code(401);
        echo json_encode(['success' => 0, 'error' => 'Sin sesión o recorrido']);
        exit;
    }

    $state_id = isset($_POST['state_id']) ? (int)$_POST['state_id'] : 0;

    $basesJson = $_POST['bases'] ?? '[]';
    $basesArr = json_decode($basesJson, true);

    if (!is_array($basesArr) || count($basesArr) === 0) {
        echo json_encode(['success' => 0, 'error' => 'Sin bases']);
        exit;
    }

    // normalizar y deduplicar
    $bases = [];
    foreach ($basesArr as $b) {
        $b = trim((string)$b);
        if ($b === '') continue;
        $b = explode('_', $b)[0];
        $bases[$b] = true;
    }
    $bases = array_keys($bases);

    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');
    $estadoTxt = "Validado en Warehouse";
    $obs = "Validado en warehouse (confirmación de carga)";

    // prepareds reusables
    $sqlChk = $mysqli->prepare("SELECT id FROM Seguimiento WHERE CodigoSeguimiento=? AND status='warehouse_validated' AND Eliminado=0 LIMIT 1");

    $sqlT = $mysqli->prepare("SELECT id, idClienteDestino, DomicilioDestino AS Destino, NumerodeOrden
                              FROM TransClientes
                              WHERE CodigoSeguimiento=? AND Eliminado=0
                              ORDER BY id DESC LIMIT 1");

    $sqlIns = $mysqli->prepare("INSERT INTO Seguimiento
      (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones, Entregado, Estado,
       Destino, Avisado, idCliente, Retirado, Visitas, idTransClientes, TimeStamp, Recorrido,
       Devuelto, Webhook, state_id, NumerodeOrden, status, Eliminado)
      VALUES
      (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 0, 0, ?, NOW(), ?, 0, 0, ?, ?, 'warehouse_validated', 0)
    ");

    $insertados = 0;
    $yaExistian = 0;
    $errores = 0;

    foreach ($bases as $base) {

        // anti duplicado
        $sqlChk->bind_param("s", $base);
        $sqlChk->execute();
        $chk = $sqlChk->get_result();
        if ($chk && $chk->num_rows > 0) {
            $yaExistian++;
            continue;
        }

        // buscar TransClientes
        $sqlT->bind_param("s", $base);
        $sqlT->execute();
        $rt = $sqlT->get_result();
        $tr = $rt ? $rt->fetch_assoc() : null;

        $idTransClientes = (int)($tr['id'] ?? 0);
        $idCliente       = (int)($tr['idClienteDestino'] ?? 0);
        $destino         = (string)($tr['Destino'] ?? '');
        $nroOrden        = (string)($tr['NumerodeOrden'] ?? '');

        $sqlIns->bind_param(
            "ssssssssiisis",
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
            $state_id,
            $nroOrden
        );

        if ($sqlIns->execute()) $insertados++;
        else $errores++;
    }

    echo json_encode([
        'success' => 1,
        'insertados' => $insertados,
        'ya_existian' => $yaExistian,
        'errores' => $errores
    ]);
    exit;
}
//MARCO LOS PEDIDOS EN TRANSITO DESDE WAREHOUSE

if (isset($_POST['PuedeSalir'])) {

    if (session_status() === PHP_SESSION_NONE) session_start();

    $usuario   = $_SESSION['Usuario'] ?? '';
    $sucursal  = $_SESSION['Sucursal'] ?? '';
    $recorrido = $_SESSION['RecorridoAsignado'] ?? '';

    if ($usuario === '' || $recorrido === '') {
        http_response_code(401);
        echo json_encode(['success' => 0, 'error' => 'Sin sesión o sin recorrido']);
        exit;
    }

    require_once __DIR__ . '/../../Funciones/estados.php';

    $status = 'last_mile';
    $st = estadoPorSlug($mysqli, $status);
    if (!$st || empty($st['id'])) {
        echo json_encode(['success' => 0, 'error' => 'Estado last_mile no existe en BD', 'slug' => $status]);
        exit;
    }

    $Estado_id = (int)$st['id'];
    $Estado    = (string)$st['Estado'];

    // Traigo bases (solo entregas) del recorrido abierto
    $q = $mysqli->prepare("
    SELECT DISTINCT SUBSTRING_INDEX(t.CodigoSeguimiento,'_',1) AS base,
           t.id AS idTransClientes,
           t.idClienteDestino AS idCliente,
           t.DomicilioDestino AS destino,
           t.NumerodeOrden AS nroOrden
    FROM HojaDeRuta h
    INNER JOIN TransClientes t ON t.id = h.idTransClientes
    WHERE h.Estado='Abierto'
      AND h.Eliminado=0
      AND h.Devuelto=0
      AND h.Recorrido=?
      AND t.Eliminado=0
      AND t.Retirado=1
  ");
    $q->bind_param("s", $recorrido);
    $q->execute();
    $rs = $q->get_result();

    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');

    $ins = $mysqli->prepare("
    INSERT INTO Seguimiento
    (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones,
     Entregado, Estado, Destino, Avisado, idCliente, Retirado, Visitas,
     idTransClientes, TimeStamp, Recorrido, Devuelto, Webhook, state_id,
     NumerodeOrden, status, Eliminado)
    VALUES
    (?, ?, ?, ?, ?, ?,
     0, ?, ?, 0, ?, 1, 0,
     ?, NOW(), ?, 0, 0, ?,
     ?, ?, 0)
  ");

    $insertados = 0;
    $yaExistian = 0;

    while ($r = $rs->fetch_assoc()) {
        $base = (string)$r['base'];

        // anti duplicado por base + status
        $chk = $mysqli->prepare("
      SELECT id FROM Seguimiento
      WHERE CodigoSeguimiento=? AND status=? AND Eliminado=0
      LIMIT 1
    ");
        $chk->bind_param("ss", $base, $status);
        $chk->execute();
        $cr = $chk->get_result();
        if ($cr && $cr->num_rows > 0) {
            $yaExistian++;
            continue;
        }

        $obs = "Salida de warehouse – En Tránsito";

        $destino = (string)($r['destino'] ?? '');
        $idCliente = (int)($r['idCliente'] ?? 0);
        $idTrans   = (int)($r['idTransClientes'] ?? 0);
        $nroOrden  = (string)($r['nroOrden'] ?? '');

        $ins->bind_param(
            "ssssssssii" . "s" . "i" . "ss",
            $fecha,
            $hora,
            $usuario,
            $sucursal,
            $base,
            $obs,
            $Estado,
            $destino,
            $idCliente,
            $idTrans,
            $recorrido,
            $Estado_id,
            $nroOrden,
            $status
        );

        if ($ins->execute()) $insertados++;
    }

    echo json_encode([
        'success' => 1,
        'insertados' => $insertados,
        'ya_existian' => $yaExistian,
        'status' => $status,
        'estado' => $Estado
    ]);
    exit;
}
