<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --------------------------------------------------
// INICIAR SESIÓN (evita Undefined array key)
// --------------------------------------------------
define('ALLOW_NO_SESSION', true);

require_once "../../Conexion/conexioni.php";

// --------------------------------------------------
// FUNCIÓN PARA RESPONDER SIEMPRE JSON Y CORTAR
// --------------------------------------------------
function responder($data)
{
  echo json_encode($data);
  exit;
}

// ==================================================
// ===============  BLOQUE MIS ENVIOS  ===============
// ==================================================
if (isset($_POST['MisEnviosHTML'])) {

  if (!isset($_SESSION['idusuario'])) {
    http_response_code(401);
    echo '<div class="alert alert-warning mb-0">Sesión perdida. Volvé a ingresar.</div>';
    exit;
  }

  $Usuario   = (int)$_SESSION['idusuario'];
  $inicioMes = date('Y-m-01');
  $finMes    = date('Y-m-t');

  // ENTREGADOS
  $sql1 = $mysqli->query("
    SELECT COUNT(id) AS Total
    FROM TransClientes
    WHERE Entregado = 1
      AND Eliminado = 0
      AND Devuelto = 0
      AND idABM = {$Usuario}
      AND FechaEntrega BETWEEN '{$inicioMes}' AND '{$finMes}'
  ");
  if (!$sql1) {
    echo '<div class="alert alert-danger mb-0">Error SQL ENTREGADOS: ' . htmlspecialchars($mysqli->error) . '</div>';
    exit;
  }
  $TotalMisEnvios = (int)($sql1->fetch_assoc()['Total'] ?? 0);

  // NO ENTREGADOS
  $sql2 = $mysqli->query("
    SELECT COUNT(id) AS Total
    FROM TransClientes
    WHERE Entregado = 0
      AND Eliminado = 0
      AND Devuelto = 0
      AND idABM = {$Usuario}
      AND FechaEntrega BETWEEN '{$inicioMes}' AND '{$finMes}'
  ");
  if (!$sql2) {
    echo '<div class="alert alert-danger mb-0">Error SQL NO ENTREGADOS: ' . htmlspecialchars($mysqli->error) . '</div>';
    exit;
  }
  $TotalMisNoEnvios = (int)($sql2->fetch_assoc()['Total'] ?? 0);

  // ✅ HTML (Cuenta)
?>
  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <h4 class="mb-3">Mi cuenta</h4>

        <div class="row g-2 text-center">
          <div class="col-6">
            <div class="badge bg-success w-100 py-3 fs-3">
              <div class="small">Entregados (mes)</div>
              <b id="mis_envios_total"><?= $TotalMisEnvios ?></b>
            </div>
          </div>

          <div class="col-6">
            <div class="badge bg-danger w-100 py-3 fs-3">
              <div class="small">No entregados (mes)</div>
              <b id="mis_noenvios_total"><?= $TotalMisNoEnvios ?></b>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
  <?php
  exit;
}

// ==================================================
// ========  BLOQUE MI CUENTA (resumen JSON)  =======
// ==================================================
// Alineado con el control del operador en sistema.caddy.com.ar
// (Externos > Envios): mismos montos (Logistica.Costo_rendicion),
// mismo desempeño (entregados / total) y los 3 estados por orden:
//   - revision   : Rendicion=0 y Costo_rendicion=0  (el operador no controló todavía)
//   - controlada : Rendicion=0 y Costo_rendicion>0  (tarifas fijadas, falta facturar)
//   - facturada  : Rendicion=1                      (comprobante emitido = lo que se paga)
if (isset($_POST['CuentaResumen'])) {
  header('Content-Type: application/json; charset=utf-8');

  if (empty($_SESSION['idusuario'])) {
    http_response_code(401);
    echo json_encode(['success' => 0, 'error' => 'Sesión perdida. Volvé a ingresar.']);
    exit;
  }

  $idUsuario = (int) $_SESSION['idusuario'];

  // Mes a mostrar: por default el actual. Acepta ?mes=YYYY-MM para pruebas.
  $mes = isset($_POST['mes']) && preg_match('/^\d{4}-\d{2}$/', $_POST['mes'])
    ? $_POST['mes']
    : date('Y-m');
  $iniMes = $mes . '-01';
  $finMes = date('Y-m-t', strtotime($iniMes));
  $iniMesPrev = date('Y-m-01', strtotime($iniMes . ' -1 month'));
  $finMesPrev = date('Y-m-t', strtotime($iniMesPrev));

  // Mapa de respaldo de tarifas por si falta la tabla Externos_tarifas.
  $tarifaFallback = [
    1 => 'Dentro anillo',
    2 => 'Fuera anillo',
    3 => '> 25 km',
    4 => '> 50 km',
    6 => 'Colecta',
  ];

  /**
   * Clasifica el tipo de liquidación de un envío para el badge del front.
   */
  $tipoEnvio = function (array $e): string {
    $ret = (int) ($e['Retirado'] ?? 0);
    $entregado = (int) ($e['Entregado'] ?? 0);
    $cobranza = (float) ($e['CobranzaIntegrada'] ?? 0);
    $idDest = (int) ($e['idClienteDestino'] ?? 0);

    if ($ret === 0 && $idDest === 18587) return 'COLECTA';
    if ($ret === 0) return 'RETIRO';
    if ($entregado === 1 && $cobranza > 0) return 'ENTREGA_CON_COBRANZA';
    if ($entregado === 1) return 'ENTREGA';
    return 'NO_ENTREGA';
  };

  /**
   * Entregados / no entregados de una orden. Prioriza Seguimiento (igual que
   * el operador); si no hay registros de seguimiento, cae a TransClientes.
   */
  $conteoOrden = function (int $nOrden) use ($mysqli): array {
    $e = 0;
    $n = 0;
    $stmt = $mysqli->prepare("
      SELECT SUM(Entregado = 1) AS e, SUM(Entregado = 0) AS n, COUNT(*) AS c
      FROM Seguimiento
      WHERE NumerodeOrden = ? AND Eliminado = 0
        AND Visitas <> 0 AND Estado <> 'Retirado del Cliente'
    ");
    $stmt->bind_param('i', $nOrden);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($r && (int) $r['c'] > 0) {
      return ['entregados' => (int) $r['e'], 'no_entregados' => (int) $r['n']];
    }

    $stmt = $mysqli->prepare("
      SELECT SUM(Entregado = 1) AS e, SUM(Entregado = 0) AS n
      FROM TransClientes
      WHERE NumerodeOrden = ? AND Eliminado = 0 AND Devuelto = 0
    ");
    $stmt->bind_param('i', $nOrden);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ['entregados' => (int) ($r['e'] ?? 0), 'no_entregados' => (int) ($r['n'] ?? 0)];
  };

  try {
    // --------- Órdenes del mes ---------
    $stmt = $mysqli->prepare("
      SELECT L.NumerodeOrden, L.Recorrido, L.Fecha, L.Estado,
             L.Rendicion, L.Costo_rendicion, L.Observaciones_rendicion,
             R.Nombre AS RecorridoNombre
      FROM Logistica L
      LEFT JOIN Recorridos R ON R.Numero = L.Recorrido
      WHERE L.idUsuarioChofer = ? AND L.Eliminado = 0
        AND L.Fecha BETWEEN ? AND ?
      GROUP BY L.NumerodeOrden
      ORDER BY L.Fecha DESC, L.NumerodeOrden DESC
    ");
    $stmt->bind_param('iss', $idUsuario, $iniMes, $finMes);
    $stmt->execute();
    $ordenesRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Algunos entornos (copias/sandbox) no tienen la tabla Externos_tarifas.
    // Si falta, resolvemos el nombre de la tarifa con $tarifaFallback y no
    // hacemos el JOIN (así el endpoint no revienta).
    $hayTarifas = false;
    try {
      $chkT = $mysqli->query("SHOW TABLES LIKE 'Externos_tarifas'");
      $hayTarifas = ($chkT && $chkT->num_rows > 0);
    } catch (Throwable $e) {
      $hayTarifas = false;
    }

    // Statement reutilizable para el detalle de envíos de cada orden.
    $sqlDet = "
      SELECT er.CodigoSeguimiento, er.PrecioPagado, er.CobranzaIntegrada,
             er.idExternos_tarifas, er.Rendido, er.PrecioAnterior,
             er.TarifaAnteriorId, er.Observaciones, er.Kilometros,
             " . ($hayTarifas ? "et.Nombre" : "NULL") . " AS NombreTarifa,
             tc.Entregado, tc.Retirado, tc.idClienteDestino,
             tc.ClienteDestino, tc.LocalidadDestino, tc.DomicilioDestino
      FROM Externos_rendicion er
      " . ($hayTarifas ? "LEFT JOIN Externos_tarifas et ON et.id = er.idExternos_tarifas" : "") . "
      LEFT JOIN TransClientes tc
        ON tc.CodigoSeguimiento = er.CodigoSeguimiento AND tc.Eliminado = 0
      WHERE er.idRendicion = ?
      ORDER BY er.id ASC
    ";
    $stmtDet = $mysqli->prepare($sqlDet);

    $stmtComp = $mysqli->prepare("
      SELECT MAX(NumeroComprobante) AS NumeroComprobante,
             MAX(TipoComprobante)   AS TipoComprobante,
             MAX(FechaComprobante)  AS FechaComprobante
      FROM Externos_rendicion
      WHERE idRendicion = ? AND NumeroComprobante IS NOT NULL AND NumeroComprobante <> ''
    ");

    $ordenes = [];
    $mesEntregados = 0;
    $mesNoEntregados = 0;
    $mesKm = 0.0;
    $totFacturado = 0.0;
    $totControlado = 0.0;
    $nFacturado = 0;
    $nControlado = 0;
    $nRevision = 0;

    foreach ($ordenesRaw as $o) {
      $nOrden = (int) $o['NumerodeOrden'];
      $rendicion = (int) $o['Rendicion'];
      $costo = (float) $o['Costo_rendicion'];

      if ($rendicion === 1) {
        $estado = 'facturada';
        $totFacturado += $costo;
        $nFacturado++;
      } elseif ($costo > 0) {
        $estado = 'controlada';
        $totControlado += $costo;
        $nControlado++;
      } else {
        $estado = 'revision';
        $nRevision++;
      }

      // Detalle de envíos (solo si ya hay rendición cargada)
      $envios = [];
      $ajustados = 0;
      $stmtDet->bind_param('i', $nOrden);
      $stmtDet->execute();
      $det = $stmtDet->get_result()->fetch_all(MYSQLI_ASSOC);

      foreach ($det as $d) {
        $precio = (float) $d['PrecioPagado'];
        $cobranza = (float) $d['CobranzaIntegrada'];
        $tarifaId = (int) $d['idExternos_tarifas'];
        $nombreTarifa = $d['NombreTarifa'] ?: ($tarifaFallback[$tarifaId] ?? ('Tarifa ' . $tarifaId));
        $ajustado = $d['PrecioAnterior'] !== null && (float) $d['PrecioAnterior'] !== $precio;
        if ($ajustado) $ajustados++;

        $destino = trim((string) ($d['ClienteDestino'] ?? ''));
        $loc = trim((string) ($d['LocalidadDestino'] ?? ''));
        $dom = trim((string) ($d['DomicilioDestino'] ?? ''));
        $destTxt = $destino;
        $subTxt = trim($dom . ($dom && $loc ? ', ' : '') . $loc, ', ');

        $envios[] = [
          'codigo'        => $d['CodigoSeguimiento'],
          'destino'       => $destTxt,
          'domicilio'     => $subTxt,
          'tipo'          => $tipoEnvio($d),
          'tarifa'        => $nombreTarifa,
          'precio'        => round($precio, 2),
          'cobranza'      => round($cobranza, 2),
          'total'         => round($precio + $cobranza, 2),
          'km'            => round((float) $d['Kilometros'], 1),
          'ajustado'      => $ajustado,
          'precio_anterior' => $ajustado ? round((float) $d['PrecioAnterior'], 2) : null,
          'obs'           => $d['Observaciones'] ?: null,
        ];
      }

      $cnt = $conteoOrden($nOrden);
      $mesEntregados   += $cnt['entregados'];
      $mesNoEntregados += $cnt['no_entregados'];
      foreach ($envios as $e) $mesKm += $e['km'];

      $totalOrden = $costo;
      if ($totalOrden <= 0 && $envios) {
        $totalOrden = array_sum(array_column($envios, 'total'));
      }

      // Comprobante (si está facturada)
      $comprobante = null;
      if ($estado === 'facturada') {
        $stmtComp->bind_param('i', $nOrden);
        $stmtComp->execute();
        $c = $stmtComp->get_result()->fetch_assoc();
        if ($c && $c['NumeroComprobante']) {
          $comprobante = [
            'numero' => $c['NumeroComprobante'],
            'fecha'  => $c['FechaComprobante'],
          ];
        }
      }

      $totOrdEnvios = $cnt['entregados'] + $cnt['no_entregados'];
      $ordenes[] = [
        'norden'        => $nOrden,
        'recorrido'     => $o['Recorrido'],
        'recorrido_nombre' => $o['RecorridoNombre'] ?: null,
        'fecha'         => $o['Fecha'],
        'estado'        => $estado,
        'entregados'    => $cnt['entregados'],
        'no_entregados' => $cnt['no_entregados'],
        'desempeno'     => $totOrdEnvios > 0 ? round($cnt['entregados'] / $totOrdEnvios * 100) : null,
        'total'         => round($totalOrden, 2),
        'total_confirmado' => $estado !== 'revision',
        'ajustados'     => $ajustados,
        'observacion'   => $o['Observaciones_rendicion'] ?: null,
        'comprobante'   => $comprobante,
        'envios'        => $envios,
      ];
    }
    $stmtDet->close();
    $stmtComp->close();

    // --------- Total mes anterior (para el delta) ---------
    $stmt = $mysqli->prepare("
      SELECT COALESCE(SUM(Costo_rendicion), 0) AS total
      FROM Logistica
      WHERE idUsuarioChofer = ? AND Eliminado = 0
        AND Fecha BETWEEN ? AND ? AND (Rendicion = 1 OR Costo_rendicion > 0)
    ");
    $stmt->bind_param('iss', $idUsuario, $iniMesPrev, $finMesPrev);
    $stmt->execute();
    $prev = (float) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $aCobrar = $totFacturado + $totControlado;
    $mesTotEnvios = $mesEntregados + $mesNoEntregados;

    echo json_encode([
      'success' => 1,
      'mes'     => $mes,
      'resumen' => [
        'a_cobrar'       => round($aCobrar, 2),
        'facturado'      => round($totFacturado, 2),
        'controlado'     => round($totControlado, 2),
        'n_facturado'    => $nFacturado,
        'n_controlado'   => $nControlado,
        'n_revision'     => $nRevision,
        'entregas'       => $mesEntregados,
        'no_entregas'    => $mesNoEntregados,
        'desempeno'      => $mesTotEnvios > 0 ? round($mesEntregados / $mesTotEnvios * 100) : null,
        'km'             => round($mesKm),
        'delta_vs_prev'  => round($aCobrar - $prev, 2),
        'total_prev'     => round($prev, 2),
      ],
      'ordenes' => $ordenes,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  } catch (Throwable $e) {
    error_log('CuentaResumen: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => 0, 'error' => 'No se pudo cargar tu cuenta. Reintentá en un rato.']);
    exit;
  }
}

// ==================================================
// ===================  PANEL DE RUTAS  ==============
// ==================================================
if (isset($_POST['Paneles'])) {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);


  if (empty($_SESSION['RecorridoAsignado'])) {
    session_destroy();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['forceLogout' => true, 'reason' => 'NO_RECORRIDO_ASIGNADO']);
    exit;
  }
  $Recorrido = $_SESSION['RecorridoAsignado'];

  // ==================================================
  // PRE-CHECK: si falta validar algún envío (Retirado=0) en warehouse,
  // NO mostramos ningún registro para evitar salir con carga parcial.
  // ==================================================
  // $sqlChkTxt = "SELECT COUNT(*) AS faltan
  //     FROM HojaDeRuta
  //     INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
  //     WHERE HojaDeRuta.Estado='Abierto'
  //       AND HojaDeRuta.Devuelto=0
  //       AND HojaDeRuta.Recorrido='$Recorrido'
  //       AND TransClientes.Eliminado='0'
  //       AND HojaDeRuta.Eliminado=0
  //       AND TransClientes.Retirado = 1
  //       AND NOT EXISTS (
  //           SELECT 1
  //           FROM Seguimiento s
  //           WHERE s.CodigoSeguimiento = TransClientes.CodigoSeguimiento
  //             AND s.status = 'warehouse_validated'
  //             AND s.Eliminado = 0
  //           LIMIT 1
  //       )
  // ";
  // Las COLECTAS no entran en el gate de validación pre-salida del warehouse:
  // se retiran en el cliente durante el recorrido y se entregan al warehouse
  // como una parada de entrega más.
  $sqlChkTxt = "SELECT COUNT(*) AS faltan
  FROM HojaDeRuta
  INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
  WHERE HojaDeRuta.Estado='Abierto'
    AND HojaDeRuta.Devuelto=0
    AND HojaDeRuta.Recorrido='$Recorrido'
    AND TransClientes.Eliminado='0'
    AND HojaDeRuta.Eliminado=0
    AND TransClientes.Retirado = 1
    AND (TransClientes.idColecta IS NULL OR TransClientes.idColecta = 0)
    AND NOT EXISTS (
        SELECT 1
        FROM Seguimiento s
        WHERE SUBSTRING_INDEX(s.CodigoSeguimiento,'_',1) = SUBSTRING_INDEX(TransClientes.CodigoSeguimiento,'_',1)
          AND s.status = 'warehouse_validated'
          AND s.Eliminado = 0
        LIMIT 1
    )";
  $sqlChk = $mysqli->query($sqlChkTxt);
  if (!$sqlChk) {
    echo "<div class='alert alert-danger'>Error SQL CHECK WAREHOUSE: " . $mysqli->error . "</div>";
    exit;
  }

  $chkRow = $sqlChk->fetch_assoc();
  $faltan = (int)($chkRow['faltan'] ?? 0);

  if ($faltan > 0) {
    // echo "<div class='alert alert-warning'>⚠️ Faltan validar <b>{$faltan}</b> bultos en warehouse. Volvé a <b>Warehouse</b>, escaneá todo y recién ahí iniciá el recorrido.</div>";
    //   exit;
    $Retirado_ = 'AND TransClientes.Retirado = 0';
  } else {
    $Retirado_ = '';
  }

  $search    = isset($_POST['search']) ? $_POST['search'] : '';

  if ($search == '') {
    $sqlTxt = "SELECT TransClientes.CobrarEnvio,
                   IF(TransClientes.Retirado=1,HojaDeRuta.Posicion,HojaDeRuta.Posicion_retiro) AS Posicion,
                   HojaDeRuta.Cliente,
                   Seguimiento,
                   HojaDeRuta.id AS hdrid,
                   TransClientes.*,
                   IF(Retirado=0,RazonSocial,ClienteDestino) AS NombreCliente,
                   IF(Retirado=0,TransClientes.ingBrutosOrigen,TransClientes.idClienteDestino) AS idCliente,
                   TransClientes.Cantidad,
                   HojaDeRuta.Hora AS ETAHora,
                   Clientes.HorarioEntregaDesde,
                   Clientes.HorarioEntregaHasta
            FROM HojaDeRuta
            INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
            LEFT JOIN Clientes ON Clientes.id = HojaDeRuta.idCliente
            WHERE HojaDeRuta.Estado='Abierto'
                  AND HojaDeRuta.Devuelto=0
                  AND HojaDeRuta.Recorrido='$Recorrido'
                  AND TransClientes.Eliminado='0'
                  AND HojaDeRuta.Eliminado=0
                  " . $Retirado_ . "
            ORDER BY IF(TransClientes.Retirado=1,HojaDeRuta.Posicion,HojaDeRuta.Posicion_retiro)
        ";
  } else {
    $sqlTxt = "SELECT TransClientes.CobrarEnvio,
                   IF(TransClientes.Retirado=1,HojaDeRuta.Posicion,HojaDeRuta.Posicion_retiro) AS Posicion,
                   HojaDeRuta.Cliente,
                   Seguimiento,
                   HojaDeRuta.id AS hdrid,
                   TransClientes.*,
                   IF(Retirado=0,RazonSocial,ClienteDestino) AS NombreCliente,
                   IF(Retirado=0,TransClientes.ingBrutosOrigen,TransClientes.idClienteDestino) AS idCliente,
                   TransClientes.Cantidad,
                   HojaDeRuta.Hora AS ETAHora,
                   Clientes.HorarioEntregaDesde,
                   Clientes.HorarioEntregaHasta
            FROM HojaDeRuta
            INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
            LEFT JOIN Clientes ON Clientes.id = HojaDeRuta.idCliente
            WHERE HojaDeRuta.Estado='Abierto'
                  AND HojaDeRuta.Devuelto=0
                  AND HojaDeRuta.Recorrido='$Recorrido'
                  AND TransClientes.Eliminado='0'
                  AND HojaDeRuta.Eliminado=0
                  " . $Retirado_ . "
                  AND HojaDeRuta.Cliente LIKE '%$search%'
            ORDER BY IF(TransClientes.Retirado=1,HojaDeRuta.Posicion,HojaDeRuta.Posicion_retiro)
        ";
  }

  $BuscarRecorridos = $mysqli->query($sqlTxt);

  if (!$BuscarRecorridos) {
    echo "<div class='alert alert-danger'>Error SQL PANEL: " . $mysqli->error . "</div>";
    exit;
  }

  // ==================================================
  // RENDER HTML COMPLETO (MISMO FORMATO QUE TENÍAS)
  // ==================================================

  while ($row = $BuscarRecorridos->fetch_array(MYSQLI_ASSOC)) {

    // lo dejo EXACTAMENTE igual que tu versión,
    // solo verificando que ninguna query reviente.
    // ----------------------------------------------
    $Cantidad = (int)($row['Cantidad'] ?? 1); // ✅ SIEMPRE, para retiro y entrega

    if ($row['Retirado'] == 0) {

      // NOMBRE CLIENTE DESTINO
      $sql_nombrecliente_destino = $mysqli->query("
                SELECT ClienteDestino,idClienteDestino 
                FROM TransClientes 
                WHERE CodigoSeguimiento='$row[Seguimiento]' AND Eliminado=0
            ");

      if (!$sql_nombrecliente_destino) {
        echo "<div class='alert alert-danger'>Error SQL nombrecliente_destino: " . $mysqli->error . "</div>";
        continue;
      }

      $dato_nombrecliente_entrega = $sql_nombrecliente_destino->fetch_array(MYSQLI_ASSOC);

      // BUSCO ID PROVEEDOR
      $sqlBuscoidProveedor = $mysqli->query("
                SELECT idProveedor,nombrecliente,ActivarCoordenadas,Latitud,Longitud 
                FROM Clientes WHERE id='$row[idCliente]'
            ");

      if (!$sqlBuscoidProveedor) {
        echo "<div class='alert alert-danger'>Error SQL idProveedor: " . $mysqli->error . "</div>";
        continue;
      }

      // fetch_array() devuelve null si el Cliente de idClienteOrigen no existe
      // (borrado, o un id inválido) - sin el ?: [] rompía con "Trying to access
      // array offset on null" en vez de simplemente no mostrar el proveedor.
      $idProveedor = $sqlBuscoidProveedor->fetch_array(MYSQLI_ASSOC) ?: [];

      // resto del código igual...
      // ---------------------------
      $idP = (!empty($idProveedor['idProveedor'])) ? '[' . $idProveedor['idProveedor'] . ']' : '';
      $Retirado = 0;
      //SI ES WEPOINT NO ES RETIRO ES COLECTA 
      //ATENCION A ESTO NO SE SI IMPACTA EN ALGUN OTRO LADO DEL SISTEMA
      if ($dato_nombrecliente_entrega['idClienteDestino'] == 18587) {
        $Servicio = 'Colecta';
        $color = 'dark';
        $icon = 'swap-vertical-bold';
        $Serviciowp = 'colectar';
      } else {
        $Servicio = 'Retiro';
        $color = 'warning';
        $icon = 'down-bold';
        $Serviciowp = 'retirar';
      }
      $Direccion = $row['DomicilioOrigen'];
      $lat = isset($row['Latitud']) ? (float)$row['Latitud'] : null;
      $lng = isset($row['Longitud']) ? (float)$row['Longitud'] : null;

      if (($idProveedor['ActivarCoordenadas'] ?? 0) == 1) {
        $Direccion_mapa = $lat . ',' . $lng;
      } else {
        $Direccion_mapa = $row['DomicilioOrigen'];
      }

      $NombreCliente = $row['RazonSocial'];

      if (strlen((string)($row['TelefonoOrigen'] ?? '')) >= 10) {
        $Contacto = (substr($row['TelefonoOrigen'], 0, 2) == '54')
          ? $row['TelefonoOrigen']
          : '54' . $row['TelefonoOrigen'];
        $veocel = 1;
      } else {
        $veocel = 0;
      }
    } else {
      // ENTREGA (igual que antes, con controles)
      $sql_nombrecliente_origen = $mysqli->query("SELECT RazonSocial FROM TransClientes 
      WHERE CodigoSeguimiento='$row[Seguimiento]' AND Eliminado=0");

      if (!$sql_nombrecliente_origen) {
        echo "<div class='alert alert-danger'>Error SQL nombrecliente_origen: " . $mysqli->error . "</div>";
        continue;
      }

      $dato_nombrecliente_origen = $sql_nombrecliente_origen->fetch_array(MYSQLI_ASSOC);

      $sqlBuscoidProveedor = $mysqli->query("SELECT idProveedor,nombrecliente,ActivarCoordenadas,Latitud,Longitud,Observaciones 
      FROM Clientes WHERE id='$row[idCliente]'");

      if (!$sqlBuscoidProveedor) {
        echo "<div class='alert alert-danger'>Error SQL idProveedor: " . $mysqli->error . "</div>";
        continue;
      }

      // fetch_array() devuelve null si el Cliente de idClienteDestino no existe
      // (borrado, o un id inválido) - sin el ?: [] rompía con "Trying to access
      // array offset on null" en vez de simplemente no mostrar el proveedor.
      $idProveedor = $sqlBuscoidProveedor->fetch_array(MYSQLI_ASSOC) ?: [];

      $idP = (!empty($idProveedor['idProveedor'])) ? '[' . $idProveedor['idProveedor'] . ']' : '';
      $Retirado = 1;
      $Servicio = 'Entrega';
      $color = 'success';
      $icon = 'up-bold';
      $Serviciowp = "entregar";
      $Direccion = $row['DomicilioDestino'];
      $Direccion_mapa = (($idProveedor['ActivarCoordenadas'] ?? 0) == 1)
        ? ($idProveedor['Latitud'] ?? '') . ',' . ($idProveedor['Longitud'] ?? '')
        : $row['DomicilioDestino'];

      $NombreCliente = $row['ClienteDestino'];

      if (strlen((string)($row['TelefonoDestino'] ?? '')) >= 10) {
        $Contacto = (substr($row['TelefonoDestino'], 0, 2) == '54')
          ? $row['TelefonoDestino']
          : '54' . $row['TelefonoDestino'];
        $veocel = 1;
      } else {
        $veocel = 0;
      }
    }

    // ==================================================
    // AHORA TU TARJETA HTML (LA DEJÉ TAL CUAL)
    // ==================================================
    // Normalización de variables para el template (evita Undefined variable)
    $servicio      = $Servicio ?? '';
    $nombreCliente = $NombreCliente ?? '';
    $retirado      = $Retirado ?? 0;
    $direccion     = $Direccion ?? '';
    $contacto      = $Contacto ?? '';
    $direccionMapa = $Direccion_mapa ?? '';
    $codSeguimiento = $row['CodigoSeguimiento'] ?? $row['Seguimiento'] ?? '';
    $idProv        = $idP ?? '';
    $usuario       = $_SESSION['Transportista'] ?? '';
  ?>

    <!-- === TARJETA === -->
    <div class="col-xl-7">
      <div class="card">
        <div class="card-body border border-<?= $color ?>">
          <h2 class="header-title mb-1 text-<?= $color ?>">
            <?= $row['Posicion'] ?> <i class="mdi mdi-arrow-<?= $icon ?>"></i> <?= $servicio ?> | <?= $nombreCliente ?>
          </h2>
          <small class="mb-2"><b><?php echo $retirado ? 'Origen: ' . $row['RazonSocial'] : 'Destino: ' . $row['ClienteDestino'] ?></b></small>

          <div class="row">
            <div class="col-md-7">
              <div data-provide="datepicker-inline" data-date-today-highlight="true" class="calendar-widget"></div>
            </div>
            <div class="col-md-5">
              <ul class="list-unstyled">
                <?php if ($idProv): ?>
                  <li>
                    <p class="text-muted mb-1 font-13"><i class="mdi mdi-account"></i> ID PROVEEDOR</p>
                    <h5>[<?php echo $idProv; ?>]</h5>
                  </li>
                <?php endif; ?>

                <?php
                  // Horario real del cliente si está configurado; si no,
                  // el estimado calculado por recalcularEtas() (eta.php) a
                  // partir del arranque real del recorrido. Si no hay
                  // ninguno de los dos todavía, no se muestra nada - antes
                  // acá había un "14:00 AM - 21:00 PM" fijo, siempre igual
                  // para cualquier cliente.
                  $horarioDesde = trim((string) ($row['HorarioEntregaDesde'] ?? ''));
                  $horarioHasta = trim((string) ($row['HorarioEntregaHasta'] ?? ''));
                  $etaHora      = trim((string) ($row['ETAHora'] ?? ''));

                  $horarioMuted = false;
                  if ($horarioDesde !== '' && $horarioDesde !== '00:00:00' && $horarioHasta !== '' && $horarioHasta !== '00:00:00') {
                    $horarioIcon  = 'mdi-calendar';
                    $horarioTexto = substr($horarioDesde, 0, 5) . ' - ' . substr($horarioHasta, 0, 5);
                  } elseif ($etaHora !== '' && $etaHora !== '00:00:00') {
                    $horarioIcon  = 'mdi-clock-outline';
                    $horarioTexto = 'Estimado: ' . substr($etaHora, 0, 5);
                  } else {
                    // Todavía no hay horario real ni estimado calculado
                    // (recorrido sin arrancar, o esperando el próximo
                    // recálculo) - avisamos que está en camino en vez de
                    // dejar el espacio vacío.
                    $horarioIcon  = 'mdi-clock-outline';
                    $horarioTexto = 'Estimando horario...';
                    $horarioMuted = true;
                  }
                ?>
                <li>
                  <p class="text-muted mb-1 font-13<?= $horarioMuted ? ' opacity-50' : '' ?>"><i class="mdi <?= $horarioIcon ?>"></i> <?= $horarioTexto ?></p>
                </li>

                <li>
                  <h5><i class="mdi mdi-map-marker"></i> <?php echo $direccion . ' ' . $row['PisoDeptoDestino'] ?></h5>
                  <small>Observaciones: <?php echo $row['Observaciones']; ?></small>
                </li>
                <?php if ($veocel): ?>
                  <li>
                    <p class="text-muted mb-1 font-13"><i class="mdi mdi-card-account-phone-outline"></i> CONTACTO</p>

                    <h5><?php echo $contacto; ?>
                      <a style="float:right;margin-right:14%;" href="https://api.whatsapp.com/send?phone=<?= $contacto ?>&text=Hola <?= $nombreCliente ?> !,%20soy <?= $usuario ?>%20de%20Caddy%20Logística%20!%20Estoy%20en%20camino%20para <?= $serviciowp ?>%20tu%20pedido...">
                        <img src='images/wp.png' width='30' height='30' />
                      </a>
                    </h5>

                  </li>
                <?php endif; ?>
                <li>
                  <p class="text-muted mb-1 font-13"><i class="mdi mdi-card-search-outline"></i> SEGUIMIENTO</p>
                  <h5><?php echo $codSeguimiento; ?></i>
                  </h5>
                </li>

                <li>
                  <p class="text-muted mb-1 font-13"><i class="mdi mdi-package-variant"></i> CANTIDAD DE BULTOS</p>
                  <h5><?php echo $Cantidad; ?></h5>
                </li>


                <?php if (!empty($listaAsignaciones)): ?>
                  <li>
                    <table class="table table-hover table-centered mb-0">
                      <thead>
                        <tr>
                          <th>Nombre</th>
                          <th>Edicion</th>
                          <th>Cantidad</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($listaAsignaciones as $asig):
                          // $prod = $asigProductos[$asig['CodigoProducto']][$relacion] ?? [];
                          $codigo = $asig['CodigoProducto'] ?? '';
                          $prod = $asigProductos[$codigo][$relacion] ?? [];
                        ?>
                          <tr>
                            <td><?= $prod['Nombre'] ?? 'Sin nombre' ?></td>
                            <td><?= $asig['Edicion'] ?></td>
                            <td><?= $asig['Cantidad'] ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </li>
                <?php endif; ?>

                <?php
                if (isset($row['CobrarEnvio'])) {
                  $sqlCobranza = $mysqli->query("SELECT SUM(CobrarEnvio) AS Cobrar FROM Ventas WHERE NumPedido='$codSeguimiento' AND Eliminado=0");
                  $datos = $sqlCobranza->fetch_assoc();
                  $cobrar = number_format((float)($row['Importe'] ?? 0), 2);
                  if ($row['CobrarEnvio'] > 0) {
                    echo "<span class='badge badge-outline-warning'>Atención! Requiere Cobranza de $ " . $cobrar . "</span>";
                  }
                }
                ?>
              </ul>
            </div>
          </div>

          <div class="row">
            <div class="col-md-12">
              <a style='margin-left:15%;'><img src='images/wrong.png' width='60' height='60' onclick="verwrong(<?php echo $row['hdrid'] ?>)" /></a>
              <a style='margin-left:3%;' href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($direccionMapa) ?>" target="_blank"><img src="images/goto.png" width="70" height="70" /></a>
              <a style='margin-left:6%;'><img src='images/ok.png' width='60' height='60' onclick="verok(<?= $row['hdrid'] ?>)" /></a>
            </div>
          </div>

        </div>
      </div>
    </div>
<?php
  }
  exit; // 👈 por prolijidad, cortamos también

}
?>