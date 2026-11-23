<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once "../../Conexion/conexioni.php";
require_once __DIR__ . '/../../Funciones/bolsines.php';
require_once __DIR__ . '/../../Funciones/wepoint.php';
require_once __DIR__ . '/../../Funciones/control_escaneo.php';
require_once __DIR__ . '/../../Funciones/salida_recorrido.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

if (isset($_POST['GetLista'])) {

    $recorrido = $_SESSION['RecorridoAsignado'] ?? $_POST['recorrido'] ?? null;
    if (!$recorrido) {
        echo json_encode(['success' => 0, 'error' => 'Sin recorrido']);
        exit;
    }

    // Entregas del recorrido para escanear antes de salir / en el punto de
    // entrega (WePoint). Incluye:
    //   - Entregas normales desde depósito (sin colecta), como siempre.
    //   - Bultos de COLECTA ya retirados y sin entregar: en las rutas Flex el
    //     chofer los deja en WePoint y ahí se escanean. Antes se excluían con
    //     (idColecta = 0) y en esas rutas la lista salía casi vacía (p.ej. el
    //     recorrido 1464: 2 de 19).
    // Por cada bulto se calcula 'escaneo_via' mirando Seguimiento:
    //   'MANUAL' -> ya tiene warehouse_validated (escaneo real en depósito)
    //   'ML'     -> pickup_scanned confirmado por MercadoLibre (handshake)
    //   'RETIRO' -> pickup_scanned de NUESTRO chofer en el cliente, pero
    //               SIN warehouse_validated todavía - NO alcanza para el
    //               gate de salida (ver bultosSinEscaneoWarehouse() en
    //               Funciones/control_escaneo.php: entre el retiro y la
    //               salida el bulto puede terminar en otro recorrido,
    //               perderse, etc. - solo informativo, no cuenta como
    //               escaneado).
    //   ''       -> sin ningún escaneo/confirmación todavía.
    // pickup_ready y pickup_not_scanned NO cuentan como escaneado (el segundo
    // es justamente "colecta cerrada sin escanear").
    //
    // FIX (2026-11-04): el warehouse_validated tiene que ser de ESTA orden
    // (Seguimiento.NumerodeOrden = la de la hoja de ruta abierta). Antes
    // valía uno de cualquier fecha, y en una segunda visita el bulto aparecía
    // "YA ESCANEADO" por el escaneo de la primera salida aunque hoy no lo
    // hubiera escaneado nadie (caso: TCFDTIPCE y VIHSI0VKX de IGALFER,
    // escaneados el 29/10 con la orden 17589, no entregados el 30/10, y el
    // 4/11 salían con la 17637 marcados como ya escaneados).
    //
    // FIX (2026-09-14): antes 'RETIRO' entraba en el mismo bucket que
    // 'MANUAL' -> la lista mostraba el bulto en verde con el tag "YA
    // ESCANEADO" (ya_escaneado=1), pero el gate de salida (que exige
    // warehouse_validated de verdad) lo seguía contando como pendiente. El
    // chofer veía "27/27 completo" acá y "Faltan escanear N bultos" en la
    // otra pantalla para el mismo recorrido - caso real: funes_624, rec.
    // 1464, bulto 09ZL60WHC con pickup_scanned de OTRO chofer (retiro en
    // el cliente) pero sin escaneo de depósito.
    $st = $mysqli->prepare("
        SELECT t.Retirado, t.CodigoSeguimiento, t.Cantidad, t.shipments_id,
               t.CodigoProveedor, h.NumerodeOrden,
               CASE WHEN (t.idColecta IS NULL OR t.idColecta = 0) THEN 0 ELSE 1 END AS es_colecta
        FROM HojaDeRuta h
        INNER JOIN TransClientes t ON t.id = h.idTransClientes
        WHERE h.Recorrido = ?
            AND h.Estado = 'Abierto'
            AND h.Eliminado = 0
            -- Devueltos fuera (como en Paneles, candidatosSinEscaneoWarehouse y
            -- el resto de la app). Hay HdR viejas que quedaron 'Abierto' con
            -- Devuelto=1 y al cargar la orden les ponen el nº del día: el chofer
            -- las veía para escanear (tarea 79, rec. 3, 12 envíos de 2025).
            AND h.Devuelto = 0
            AND t.Eliminado = 0
            AND (
                (t.idColecta IS NULL OR t.idColecta = 0)
                OR (t.idColecta > 0 AND t.Retirado = 1 AND t.Entregado = 0 AND t.Devuelto = 0)
            )
        ORDER BY t.CodigoSeguimiento ASC
        ");

    $st->bind_param("s", $recorrido);
    $st->execute();
    $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    // escaneo_via con UNA consulta con índice para todo el recorrido (escaneosPorBase, en
    // Funciones/control_escaneo.php). Antes era una subconsulta por parada que recorría Seguimiento
    // entero: 31 s en un recorrido de 78 paradas (2026-11-18). Mismo criterio:
    // MANUAL = warehouse_validated de ESTA salida; ML = pickup_scanned de MercadoLibre; RETIRO = otro pickup_scanned.
    $escaneos = escaneosPorBase($mysqli, array_column($filas, 'CodigoSeguimiento'));

    $items = [];
    foreach ($filas as $r) {
        $e = $escaneos[strtoupper((string)$r['CodigoSeguimiento'])] ?? null;
        $r['escaneo_via'] = !$e ? ''
            : (isset($e['wv'][(string)$r['NumerodeOrden']]) ? 'MANUAL' : ($e['ml'] ? 'ML' : ($e['retiro'] ? 'RETIRO' : '')));

        $meliId = (string)($r['shipments_id'] ?? '');
        if ($meliId === '0') $meliId = '';
        // En Flex el nº de envío de ML suele venir en CodigoProveedor
        // (shipments_id = 0). Sin esto, el bulto de colecta no matchea cuando
        // el operador escanea el QR de MercadoLibre en WePoint.
        if ($meliId === '' && (int)$r['es_colecta'] === 1) {
            $cp = trim((string)($r['CodigoProveedor'] ?? ''));
            if ($cp !== '' && $cp !== '0') $meliId = $cp;
        }

        $via = (string)($r['escaneo_via'] ?? '');
        // Solo MANUAL (warehouse_validated real) y ML (confirmación externa
        // de MercadoLibre) satisfacen el gate de salida - RETIRO (nuestro
        // propio chofer lo retiró del cliente, pero todavía no hay escaneo
        // de depósito) es a propósito NO suficiente, ver comentario arriba.
        $yaEscaneado = ($via === 'MANUAL' || $via === 'ML') ? 1 : 0;

        // Número de pedido del cliente (CodigoProveedor): IGALFER, Ferniplast, etc. imprimen su
        // propia etiqueta con ese número (y _N por bulto) en vez del código de Caddy.
        $proveedor = trim((string)($r['CodigoProveedor'] ?? ''));
        if ($proveedor === '0') $proveedor = '';

        $items[] = [
            'base' => $r['CodigoSeguimiento'],
            'proveedor' => $proveedor,
            'bultos' => (int)$r['Cantidad'],
            'retirado' => (int)$r['Retirado'],
            'meli_id' => $meliId,
            'es_colecta' => (int)$r['es_colecta'],
            'ya_escaneado' => $yaEscaneado,
            'escaneo_via' => $via,
        ];
    }

    // Bolsines de facturas asignados al recorrido: se escanean como un bulto más (BOL-0001).
    $orden = ordenCargadaChofer($mysqli, (int)($_SESSION['idusuario'] ?? 0));
    foreach (bolsinesDelRecorrido($mysqli, (string)$recorrido, $orden) as $b) {
        $cargado = $b['Estado'] === 'En viaje';
        $items[] = [
            'base' => $b['Codigo'],
            'bultos' => 1,
            'retirado' => 1,
            'meli_id' => '',
            'es_colecta' => 0,
            'es_bolsin' => 1,
            'ya_escaneado' => $cargado ? 1 : 0,
            'escaneo_via' => $cargado ? 'BOLSIN' : '',
        ];
    }
    $solo_hash = isset($_POST['solo_hash']) ? (int)$_POST['solo_hash'] : 0;
    // La orden entra en el hash: una segunda salida del mismo recorrido invalida la lista guardada.
    $hash = md5($recorrido . '|' . (int) $orden . json_encode($items));
    // Carga ya confirmada (En Tránsito de la orden o recorrido arrancado, sin nada pendiente): la app
    // apaga "Confirmar carga". Va aparte del hash: se lee en cada apertura sin recargar la lista.
    $confirmada = cargaConfirmada($mysqli, (string)$recorrido) ? 1 : 0;

    if ($solo_hash === 1) {
        echo json_encode([
            'success' => 1,
            'recorrido' => $recorrido,
            'hash' => $hash,
            'carga_confirmada' => $confirmada,
        ]);
        exit;
    }
    echo json_encode([
        'success' => 1,
        'recorrido' => $recorrido,
        'orden' => $orden, // para que la app detecte una orden nueva del mismo recorrido
        'hash' => $hash,
        'carga_confirmada' => $confirmada,
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

    // Sin bases nuevas no hay nada que registrar, pero no es un error: todo lo del recorrido ya estaba
    // validado (p.ej. el chofer vuelve a apretar "Confirmar carga" después de haberla confirmado, o
    // todo venía confirmado por MELI). Sigue el paso de En Tránsito, que no duplica (Franco 4/11,
    // recorrido 1050: "Error sin bases" con la carga ya confirmada).
    if (!is_array($basesArr) || count($basesArr) === 0) {
        echo json_encode(['success' => 1, 'insertados' => 0, 'yaExistian' => 0, 'errores' => 0]);
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

    // Los bolsines no son envíos: no van a Seguimiento, pasan a "En viaje" con la orden del chofer.
    $bolsinesCargados = 0;
    $ordenChofer = ordenCargadaChofer($mysqli, (int)($_SESSION['idusuario'] ?? 0));
    foreach ($bases as $i => $b) {
        if (!esCodigoBolsin($b)) continue;
        unset($bases[$i]);
        if (cargarBolsin($mysqli, $b, (string)$recorrido, $ordenChofer, (int)($_SESSION['idusuario'] ?? 0), (string)$usuario)) $bolsinesCargados++;
    }

    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');
    $estadoTxt = "Validado en Warehouse";
    $obs = "Validado en warehouse (confirmación de carga)";

    // prepareds reusables
    // Anti duplicado por orden: el escaneo de una visita anterior no cuenta (ver GetLista).
    $sqlChk = $mysqli->prepare("SELECT id FROM Seguimiento WHERE CodigoSeguimiento=? AND status='warehouse_validated' AND NumerodeOrden=? AND Eliminado=0 LIMIT 1");

    // FIX (21/11/2026, rec 1314, orden 17813): la orden sale de la HOJA DE RUTA abierta de este
    // recorrido, igual que en GetLista y en el control de salida (candidatosSinEscaneoWarehouse).
    // Un envío que la oficina suma al recorrido con la orden ya abierta queda con
    // TransClientes.NumerodeOrden = 0: se grababa el warehouse_validated con orden 0, la salida lo
    // buscaba con la de la hoja de ruta, no lo encontraba y pedía escanearlo de nuevo, en loop.
    $sqlT = $mysqli->prepare("SELECT t.id, t.idClienteDestino, t.DomicilioDestino AS Destino,
                                     COALESCE(NULLIF((SELECT h.NumerodeOrden FROM HojaDeRuta h
                                                       WHERE h.idTransClientes = t.id AND h.Recorrido = ?
                                                         AND h.Estado = 'Abierto' AND h.Eliminado = 0 AND h.Devuelto = 0
                                                       ORDER BY h.id DESC LIMIT 1), 0), t.NumerodeOrden) AS NumerodeOrden
                              FROM TransClientes t
                              WHERE t.CodigoSeguimiento=? AND t.Eliminado=0
                              ORDER BY t.id DESC LIMIT 1");

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

        // buscar TransClientes
        $recorridoStr = (string)$recorrido;
        $sqlT->bind_param("ss", $recorridoStr, $base);
        $sqlT->execute();
        $rt = $sqlT->get_result();
        $tr = $rt ? $rt->fetch_assoc() : null;

        $idTransClientes = (int)($tr['id'] ?? 0);
        $idCliente       = (int)($tr['idClienteDestino'] ?? 0);
        $destino         = (string)($tr['Destino'] ?? '');
        $nroOrden        = (string)($tr['NumerodeOrden'] ?? '');

        // anti duplicado
        $sqlChk->bind_param("ss", $base, $nroOrden);
        $sqlChk->execute();
        $chk = $sqlChk->get_result();
        if ($chk && $chk->num_rows > 0) {
            $yaExistian++;
            continue;
        }

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

    // Lo que carga el chofer sale del depósito de Wepoint (se le avisa por la cola, sin esperar)
    $wepoint = encolarSalidaWepoint($mysqli, $bases, (string)$recorrido, (string)$usuario);

    echo json_encode([
        'success' => 1,
        'insertados' => $insertados,
        'ya_existian' => $yaExistian,
        'errores' => $errores,
        'bolsines' => $bolsinesCargados,
        'wepoint' => $wepoint
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
    require_once __DIR__ . '/../../Funciones/control_escaneo.php';

    // FIX (2026-10-28, caso 4BJB7KAZT, rec 1478, franco_618): la lista de Warehouse vive en el
    // teléfono (IndexedDB) y solo se revalida al abrir la pantalla. Si la oficina le suma un envío
    // al recorrido con la pantalla abierta, puedeSalir() no lo ve, la salida lo marcaba igual
    // "En Tránsito" y después su tarjeta quedaba oculta (sin warehouse_validated) -> el chofer lo
    // tenía arriba y no lo podía entregar desde la app. Ahora se controla acá con los datos
    // actuales, con la misma regla que oculta las tarjetas (candidatosSinEscaneoWarehouse), y si
    // falta algo no se marca la salida: la app recarga la lista para que lo escanee.
    // Bolsines de facturas de Dinter (2026-10-28): se exigen siempre, también con "Omitir control
    // de escaneo" (eso es para los bultos). Sin bolsín asignado no hay nada que recargar: se avisa.
    $bolsines = controlBolsines($mysqli, (string)$recorrido, ordenCargadaChofer($mysqli, (int)($_SESSION['idusuario'] ?? 0)));
    if ($bolsines['sin_bolsin']) {
        registrarBloqueoReparto($mysqli, 'confirmar_carga', (string)$recorrido, 'sin_bolsin', [], (string)mensajeBolsin($bolsines));
        echo json_encode(['success' => 0, 'reason' => 'SIN_BOLSIN', 'error' => mensajeBolsin($bolsines)]);
        exit;
    }
    $faltan = overrideEscaneo($mysqli, (int)($_SESSION['idusuario'] ?? 0)) ? [] : candidatosSinEscaneoWarehouse($mysqli, (string)$recorrido);
    if ($faltan || $bolsines['pendientes']) {
        $codigos = $bolsines['pendientes'];
        $detalle = detalleSinEscaneo($mysqli, $faltan);
        foreach ($detalle as $d) $codigos[] = $d['codigo'];
        // Queda asentado para la oficina (Logística > Órdenes > Control de la orden).
        registrarBloqueoReparto($mysqli, 'confirmar_carga', (string)$recorrido, $faltan ? 'sin_escanear' : 'bolsin', array_merge($bolsines['pendientes'], $detalle));
        echo json_encode([
            'success' => 0,
            'reason' => 'LISTA_DESACTUALIZADA',
            'pendientes' => $codigos,
            'detalle' => $detalle,
            'error' => 'Faltan escanear bultos del recorrido.',
        ]);
        exit;
    }

    $salida = registrarSalidaEnTransito($mysqli, (string)$recorrido, (string)$usuario, (string)$sucursal);

    echo json_encode($salida);
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
