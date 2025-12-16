<?php
require_once "../../Conexion/conexioni.php";

if (isset($_POST['GetLista'])) {

    $recorrido = $_SESSION['RecorridoAsignado'] ?? $_POST['recorrido'] ?? null;
    if (!$recorrido) {
        echo json_encode(['success' => 0, 'error' => 'Sin recorrido']);
        exit;
    }

    $sql = $mysqli->query("SELECT TransClientes.CodigoSeguimiento, TransClientes.Cantidad
    FROM HojaDeRuta
    INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
    WHERE HojaDeRuta.Recorrido = '$recorrido'
    AND HojaDeRuta.Estado = 'Abierto'
    AND HojaDeRuta.Eliminado = 0
    AND TransClientes.Eliminado = 0");

    $items = [];
    while ($r = $sql->fetch_assoc()) {
        $items[] = [
            'base' => $r['CodigoSeguimiento'],
            'bultos' => (int)$r['Cantidad']
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


if (isset($_POST['RegistrarWarehouse'])) {

    // ⚠️ Si warehouse.php lo llamás desde el front, debería pasar por conexioni.php
    // y ahí ya tenés sesión/control.
    // Si te devuelve 401, es porque no hay sesión -> lo resolvemos desde JS redirigiendo.

    $codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : '';
    if ($codigo === '') {
        echo json_encode(['success' => 0, 'error' => 'Código vacío']);
        exit;
    }

    // Normalizamos por si llega con _n
    $base = explode('_', $codigo)[0];

    $recorrido = $_SESSION['RecorridoAsignado'] ?? null;
    $usuario   = $_SESSION['Usuario'] ?? null;     // ajustá si tu session usa otro key
    $sucursal  = $_SESSION['Sucursal'] ?? '';      // ajustá si tu session usa otro key

    if (!$recorrido || !$usuario) {
        http_response_code(401);
        echo json_encode(['success' => 0, 'error' => 'Sin sesión o recorrido']);
        exit;
    }

    // ✅ Anti-duplicado: si ya está marcado como warehouse_validated, no insertamos de nuevo
    $sqlChk = $mysqli->prepare("SELECT id FROM Seguimiento WHERE CodigoSeguimiento=? AND status='warehouse_validated' AND Eliminado=0 LIMIT 1");
    $sqlChk->bind_param("s", $base);
    $sqlChk->execute();
    $chk = $sqlChk->get_result();
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['success' => 1, 'inserted' => 0, 'msg' => 'Ya registrado']);
        exit;
    }

    // 🔎 Buscamos datos de TransClientes (mínimo: idTransClientes, idCliente, Destino)
    $sqlT = $mysqli->prepare("SELECT id, idClienteDestino, DomicilioDestino AS Destino, NumerodeOrden FROM TransClientes WHERE CodigoSeguimiento=? AND Eliminado=0 ORDER BY id DESC LIMIT 1");
    $sqlT->bind_param("s", $base);
    $sqlT->execute();
    $rt = $sqlT->get_result();
    $tr = $rt ? $rt->fetch_assoc() : null;

    $idTransClientes = $tr['id'] ?? 0;
    $idCliente       = $tr['idClienteDestino'] ?? 0;
    $destino         = $tr['Destino'] ?? '';
    $nroOrden        = $tr['NumerodeOrden'] ?? '';

    // 🧾 Insert Seguimiento (evento de validación en warehouse)
    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');

    // Elegí tu ID real en tabla Estados (ej: 13, 14, etc.)
    // Por ahora lo mandamos 0 y después lo seteamos cuando lo crees.
    $state_id = isset($_POST['state_id']) ? (int)$_POST['state_id'] : 0;

    $estadoTxt = "Validado en Warehouse";
    $obs       = "Validado en warehouse (escaneo QR)";

    $sqlIns = $mysqli->prepare("INSERT INTO Seguimiento
    (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones, Entregado, Estado,
     Destino, Avisado, idCliente, Retirado, Visitas, idTransClientes, TimeStamp, Recorrido,
     Devuelto, Webhook, state_id, NumerodeOrden, status, Eliminado)
    VALUES
    (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 0, 0, ?, NOW(), ?, 0, 0, ?, ?, 'warehouse_validated', 0)
");

    // 8 strings + 2 int + 1 string + 1 int + 1 string = 13
    $sqlIns->bind_param(
        "ssssssssiisis",
        $fecha,           // s
        $hora,            // s
        $usuario,         // s
        $sucursal,        // s
        $base,            // s
        $obs,             // s
        $estadoTxt,       // s
        $destino,         // s
        $idCliente,       // i
        $idTransClientes, // i
        $recorrido,       // s
        $state_id,        // i
        $nroOrden         // s
    );

    $ok = $sqlIns->execute();

    echo json_encode([
        'success' => $ok ? 1 : 0,
        'inserted' => $ok ? 1 : 0,
        'codigo' => $base,
        'recorrido' => $recorrido
    ]);
    exit;
}
