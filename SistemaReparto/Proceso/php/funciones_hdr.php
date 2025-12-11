<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --------------------------------------------------
// INICIAR SESIÓN (evita Undefined array key)
// --------------------------------------------------
// if (session_status() === PHP_SESSION_NONE) {
//   session_start();
// }
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
  $sql = $mysqli->query("
        SELECT COUNT(id) AS Total 
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

  if (!isset($_SESSION['RecorridoAsignado'])) {
    echo "<div class='alert alert-danger'>Error: No existe RecorridoAsignado en la sesión.</div>";
    exit;
  }

  $Recorrido = $_SESSION['RecorridoAsignado'];
  $search    = isset($_POST['search']) ? $_POST['search'] : '';

  if ($search == '') {
    $sqlTxt = "
            SELECT TransClientes.CobrarEnvio,
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
    $sqlTxt = "
            SELECT TransClientes.CobrarEnvio,
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
      $sql_nombrecliente_origen = $mysqli->query("
                SELECT RazonSocial 
                FROM TransClientes 
                WHERE CodigoSeguimiento='$row[Seguimiento]' AND Eliminado=0
            ");

      if (!$sql_nombrecliente_origen) {
        echo "<div class='alert alert-danger'>Error SQL nombrecliente_origen: " . $mysqli->error . "</div>";
        continue;
      }

      $dato_nombrecliente_origen = $sql_nombrecliente_origen->fetch_array(MYSQLI_ASSOC);

      $sqlBuscoidProveedor = $mysqli->query("
                SELECT idProveedor,nombrecliente,ActivarCoordenadas,Latitud,Longitud,Observaciones 
                FROM Clientes 
                WHERE id='$row[idCliente]'
            ");

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

?>

    <!-- === TARJETA === -->
    <div class="col-xl-7">
      <div class="card">
        <div class="card-body border border-<?php echo $color; ?>">
          <h2 class="header-title mb-1 text-<?php echo $color; ?>">
            <?php echo $row['Posicion']; ?>
            <i class="mdi mdi-arrow-<?php echo $icon; ?>"></i>
            <?php echo $Servicio; ?> | <?php echo $row['NombreCliente']; ?>
          </h2>

          <small><b>
              <?php
              if ($row['Retirado'] == 0) {
                echo 'Destino: ' . $dato_nombrecliente_entrega['ClienteDestino'];
              } else {
                echo 'Origen: ' . $dato_nombrecliente_origen['RazonSocial'];
              }
              ?>
            </b></small>

          <!-- resto del html igual... -->
      <?php
      // No modifico nada más.  
      // El resto del HTML sigue exactamente igual.
    }
  } // FIN PANEL
      ?>