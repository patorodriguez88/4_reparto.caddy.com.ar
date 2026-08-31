<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once "../../Conexion/conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');

if (isset($_POST['GetLista'])) {

    $recorrido = $_SESSION['RecorridoAsignado'] ?? $_POST['recorrido'] ?? null;
    if (!$recorrido) {
        echo json_encode(['success' => 0, 'error' => 'Sin recorrido']);
        exit;
    }

    // Las COLECTAS no se cargan en el depósito: el chofer las retira en el
    // cliente durante el recorrido y las entrega en el warehouse como una
    // parada más. Por eso quedan fuera de esta lista de carga pre-salida.
    $st = $mysqli->prepare("
        SELECT t.Retirado, t.CodigoSeguimiento, t.Cantidad, t.shipments_id
        FROM HojaDeRuta h
        INNER JOIN TransClientes t ON t.id = h.idTransClientes
        WHERE h.Recorrido = ?
            AND h.Estado = 'Abierto'
            AND h.Eliminado = 0
            AND t.Eliminado = 0
            AND (t.idColecta IS NULL OR t.idColecta = 0)
        ORDER BY t.CodigoSeguimiento ASC
        ");

    $st->bind_param("s", $recorrido);
    $st->execute();
    $sql = $st->get_result();

    $items = [];
    while ($r = $sql->fetch_assoc()) {

        $meliId = (string)($r['shipments_id'] ?? '');
        if ($meliId === '0') $meliId = '';

        $items[] = [
            'base' => $r['CodigoSeguimiento'],
            'bultos' => (int)$r['Cantidad'],
            'retirado' => (int)$r['Retirado'],
            'meli_id' => $meliId,
        ];
    }
    $solo_hash = isset($_POST['solo_hash']) ? (int)$_POST['solo_hash'] : 0;
    $hash = md5($recorrido . json_encode($items));

    if ($solo_hash === 1) {
        echo json_encode([
            'success' => 1,
            'recorrido' => $recorrido,
            'hash' => $hash,
        ]);
        exit;
    }
    echo json_encode([
        'success' => 1,
        'recorrido' => $recorrido,
        'hash' => $hash,
        'items' => $items
    ]);
    exit;
}

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

// Trazabilidad de códigos escaneados que NO pertenecen al recorrido del
// repartidor - no va a Seguimiento (eso lo ve el cliente, un rechazo no le
// aporta nada), sino a una tabla interna. Reusa la última posición conocida
// del repartidor (tabla UbicacionRepartidor) en vez de pedir un GPS nuevo en
// el momento - si el paquete se pierde, esto ayuda a reconstruir "quién lo
// leyó y dónde" aunque no fuera suyo.
if (isset($_POST['RegistrarRechazo'])) {

    $userId  = (int)($_SESSION['idusuario'] ?? 0);
    $usuario = (string)($_SESSION['Usuario'] ?? '');
    $codigo  = trim((string)($_POST['codigo'] ?? ''));

    if ($userId <= 0 || $codigo === '') {
        echo json_encode(['success' => 0, 'error' => 'Faltan datos']);
        exit;
    }

    $stUbi = $mysqli->prepare("SELECT Latitud, Longitud FROM UbicacionRepartidor WHERE idUsuario = ?");
    $stUbi->bind_param("i", $userId);
    $stUbi->execute();
    $ubi = $stUbi->get_result()->fetch_assoc() ?: [];

    $stmt = $mysqli->prepare("
        INSERT INTO EscaneosRechazados (idUsuario, Usuario, CodigoEscaneado, Contexto, Latitud, Longitud, TimeStamp)
        VALUES (?, ?, ?, 'warehouse', ?, ?, NOW())
    ");
    $lat = $ubi['Latitud'] ?? null;
    $lng = $ubi['Longitud'] ?? null;
    $stmt->bind_param("issdd", $userId, $usuario, $codigo, $lat, $lng);
    $stmt->execute();

    echo json_encode(['success' => 1]);
    exit;
}
