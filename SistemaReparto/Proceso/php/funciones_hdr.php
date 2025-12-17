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
if (isset($_POST['MisEnvios'])) {

  if (!isset($_SESSION['idusuario'])) {
    responder([
      'success' => 0,
      'error'   => 'No existe idusuario en la sesión'
    ]);
  }

  $Usuario   = $_SESSION['idusuario'];
  $inicioMes = date('Y-m-01');
  $finMes    = date('Y-m-t');

  // ==================================================
  // ENTREGADOS
  // ==================================================
  $sql = $mysqli->query("
        SELECT COUNT(id) AS Total 
        FROM TransClientes 
        WHERE Entregado = 1 
          AND Eliminado = 0 
          AND Devuelto = 0 
          AND idABM = '$Usuario' 
          AND FechaEntrega BETWEEN '$inicioMes' AND '$finMes'
    ");

  if (!$sql) {
    responder([
      'success' => 0,
      'error'   => 'Error SQL ENVIOS ENTREGADOS: ' . $mysqli->error
    ]);
  }

  $MisEnvios      = $sql->fetch_array(MYSQLI_ASSOC);
  $TotalMisEnvios = (int)$MisEnvios['Total'];

  // ==================================================
  // NO ENTREGADOS
  // ==================================================
  $sql = $mysqli->query("SELECT COUNT(id) AS Total 
        FROM TransClientes 
        WHERE Entregado = 0 
          AND Eliminado = 0 
          AND Devuelto = 0 
          AND idABM = '$Usuario' 
          AND FechaEntrega BETWEEN '$inicioMes' AND '$finMes'
    ");

  if (!$sql) {
    responder([
      'success' => 0,
      'error'   => 'Error SQL ENVIOS NO ENTREGADOS: ' . $mysqli->error
    ]);
  }

  $MisNoEnvios      = $sql->fetch_array(MYSQLI_ASSOC);
  $TotalMisNoEnvios = (int)$MisNoEnvios['Total'];

  responder([
    'success' => 1,
    'Total'   => $TotalMisEnvios,
    'Totalno' => $TotalMisNoEnvios,
    'Usuario' => $Usuario
  ]);
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
    http_response_code(409);
    echo json_encode([
      'forceLogout' => true,
      'reason' => 'NO_RECORRIDO_ASIGNADO'
    ]);
    exit;
  }
  $Recorrido = $_SESSION['RecorridoAsignado'];

  // ==================================================
  // PRE-CHECK: si falta validar algún envío (Retirado=0) en warehouse,
  // NO mostramos ningún registro para evitar salir con carga parcial.
  // ==================================================
  $sqlChkTxt = "
      SELECT COUNT(*) AS faltan
      FROM HojaDeRuta
      INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
      WHERE HojaDeRuta.Estado='Abierto'
        AND HojaDeRuta.Devuelto=0
        AND HojaDeRuta.Recorrido='$Recorrido'
        AND TransClientes.Eliminado='0'
        AND HojaDeRuta.Eliminado=0
        AND TransClientes.Retirado = 1
        AND NOT EXISTS (
            SELECT 1
            FROM Seguimiento s
            WHERE s.CodigoSeguimiento = TransClientes.CodigoSeguimiento
              AND s.status = 'warehouse_validated'
              AND s.Eliminado = 0
            LIMIT 1
        )
  ";

  $sqlChk = $mysqli->query($sqlChkTxt);
  if (!$sqlChk) {
    echo "<div class='alert alert-danger'>Error SQL CHECK WAREHOUSE: " . $mysqli->error . "</div>";
    exit;
  }

  $chkRow = $sqlChk->fetch_assoc();
  $faltan = (int)($chkRow['faltan'] ?? 0);

  if ($faltan > 0) {
    echo "<div class='alert alert-warning'>⚠️ Faltan validar <b>{$faltan}</b> bultos en warehouse. Volvé a <b>Warehouse</b>, escaneá todo y recién ahí iniciá el recorrido.</div>";
    exit;
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
                   TransClientes.Cantidad 
            FROM HojaDeRuta 
            INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
            WHERE HojaDeRuta.Estado='Abierto' 
                  AND HojaDeRuta.Devuelto=0 
                  AND HojaDeRuta.Recorrido='$Recorrido'
                  AND TransClientes.Eliminado='0' 
                  AND HojaDeRuta.Eliminado=0  
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
                   TransClientes.Cantidad 
            FROM HojaDeRuta 
            INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
            WHERE HojaDeRuta.Estado='Abierto' 
                  AND HojaDeRuta.Devuelto=0 
                  AND HojaDeRuta.Recorrido='$Recorrido'
                  AND TransClientes.Eliminado='0' 
                  AND HojaDeRuta.Eliminado=0
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

    if ($row['Retirado'] == 0) {

      // NOMBRE CLIENTE DESTINO
      $sql_nombrecliente_destino = $mysqli->query("
                SELECT ClienteDestino 
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

      $idProveedor = $sqlBuscoidProveedor->fetch_array(MYSQLI_ASSOC);

      // resto del código igual...
      // ---------------------------
      $idP = ($idProveedor['idProveedor'] != 0) ? '[' . $idProveedor['idProveedor'] . ']' : '';
      $Retirado = 0;
      $Servicio = 'Retiro';
      $color = 'warning';
      $icon = 'down-bold';
      $Serviciowp = 'retirar';
      $Direccion = $row['DomicilioOrigen'];

      if ($idProveedor['ActivarCoordenadas'] == 1) {
        $Direccion_mapa = $row['Latitud'] . ',' . $row['Longitud'];
      } else {
        $Direccion_mapa = $row['DomicilioOrigen'];
      }

      $NombreCliente = $row['RazonSocial'];

      if (strlen($row['TelefonoOrigen']) >= 10) {
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

      $idProveedor = $sqlBuscoidProveedor->fetch_array(MYSQLI_ASSOC);

      $idP = ($idProveedor['idProveedor'] != 0) ? '[' . $idProveedor['idProveedor'] . ']' : '';
      $Retirado = 1;
      $Servicio = 'Entrega';
      $color = 'success';
      $icon = 'up-bold';
      $Serviciowp = "entregar";
      $Direccion = $row['DomicilioDestino'];
      $Direccion_mapa = ($idProveedor['ActivarCoordenadas'] == 1)
        ? $idProveedor['Latitud'] . ',' . $idProveedor['Longitud']
        : $row['DomicilioDestino'];

      $NombreCliente = $row['ClienteDestino'];

      if (strlen($row['TelefonoDestino']) >= 10) {
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
          <small class="mb-2"><b><?= $retirado ? 'Origen: ' . $row['RazonSocial'] : 'Destino: ' . $row['ClienteDestino'] ?></b></small>

          <div class="row">
            <div class="col-md-7">
              <div data-provide="datepicker-inline" data-date-today-highlight="true" class="calendar-widget"></div>
            </div>
            <div class="col-md-5">
              <ul class="list-unstyled">
                <?php if ($idProv): ?>
                  <li>
                    <p class="text-muted mb-1 font-13"><i class="mdi mdi-account"></i> ID PROVEEDOR</p>
                    <h5>[<?= $idProv ?>]</h5>
                  </li>
                <?php endif; ?>

                <li>
                  <p class="text-muted mb-1 font-13"><i class="mdi mdi-calendar"></i> 7:30 AM - 18:00 PM</p>
                  <h5><i class="mdi mdi-map-marker"></i> <?= $direccion . ' ' . $row['PisoDeptoDestino'] ?></h5>
                  <small>Observaciones: <?= $row['Observaciones'] ?></small>
                </li>

                <li>
                  <p class="text-muted mb-1 font-13"><i class="mdi mdi-card-account-phone-outline"></i> CONTACTO</p>
                  <?php if ($veocel): ?>
                    <h5><?= $contacto ?>
                      <a style="float:right;margin-right:14%;" href="https://api.whatsapp.com/send?phone=<?= $contacto ?>&text=Hola <?= $nombreCliente ?> !,%20soy <?= $usuario ?>%20de%20Caddy%20Logística%20!%20Estoy%20en%20camino%20para <?= $serviciowp ?>%20tu%20pedido...">
                        <img src='images/wp.png' width='30' height='30' />
                      </a>
                    </h5>
                  <?php endif; ?>
                </li>

                <li>
                  <p class="text-muted mb-1 font-13"><i class="mdi mdi-card-search-outline"></i> SEGUIMIENTOs</p>
                  <h5><?= $codSeguimiento ?></h5>
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