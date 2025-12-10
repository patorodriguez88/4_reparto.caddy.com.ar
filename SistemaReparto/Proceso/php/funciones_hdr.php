<?php
session_start();
require_once "../../Conexion/conexioni.php";

// if ($_POST['MisEnvios'] == 1) {
//   $Usuario = $_SESSION['idusuario'];
//   $sql = $mysqli->query("SELECT COUNT(id)AS Total FROM TransClientes WHERE Entregado=1 AND Eliminado=0 AND Devuelto=0 AND idABM='$Usuario' AND YEAR(FechaEntrega) =YEAR(CURRENT_DATE()) and MONTH(FechaEntrega)=MONTH(CURRENT_DATE())");

//   $MisEnvios = $sql->fetch_array(MYSQLI_ASSOC);
//   $TotalMisEnvios = $MisEnvios['Total'];
//   //ENVIOS NO ENTREGADOS
//   $sql = $mysqli->query("SELECT COUNT(id)AS Total FROM TransClientes WHERE Entregado=0 AND Eliminado=0 AND Devuelto=0 AND idABM='$Usuario' AND YEAR(FechaEntrega) =YEAR(CURRENT_DATE()) and MONTH(FechaEntrega)=MONTH(CURRENT_DATE())");
//   $MisNoEnvios = $sql->fetch_array(MYSQLI_ASSOC);
//   $TotalMisNoEnvios = $MisNoEnvios['Total'];

//   echo json_encode(array('success' => 1, 'Total' => $TotalMisEnvios, 'Totalno' => $TotalMisNoEnvios, 'Usuario' => $Usuario));
// }
if (isset($_POST['MisEnvios'])) {
  $Usuario = $_SESSION['idusuario'];
  $inicioMes = date('Y-m-01');
  $finMes = date('Y-m-t');

  // ENVIOS ENTREGADOS
  $sql = $mysqli->query("
  SELECT COUNT(id) AS Total 
  FROM TransClientes 
  WHERE Entregado = 1 
    AND Eliminado = 0 
    AND Devuelto = 0 
    AND idABM = '$Usuario' 
    AND FechaEntrega BETWEEN '$inicioMes' AND '$finMes'
");
  $MisEnvios = $sql->fetch_array(MYSQLI_ASSOC);
  $TotalMisEnvios = $MisEnvios['Total'];

  // ENVIOS NO ENTREGADOS
  $sql = $mysqli->query("
  SELECT COUNT(id) AS Total 
  FROM TransClientes 
  WHERE Entregado = 0 
    AND Eliminado = 0 
    AND Devuelto = 0 
    AND idABM = '$Usuario' 
    AND FechaEntrega BETWEEN '$inicioMes' AND '$finMes'
");
  $MisNoEnvios = $sql->fetch_array(MYSQLI_ASSOC);
  $TotalMisNoEnvios = $MisNoEnvios['Total'];

  echo json_encode([
    'success' => 1,
    'Total' => $TotalMisEnvios,
    'Totalno' => $TotalMisNoEnvios,
    'Usuario' => $Usuario
  ]);
}

if ($_POST['Paneles'] == 1) {

  if ($_POST['search'] == '') {
    $BuscarRecorridos = $mysqli->query("SELECT TransClientes.CobrarEnvio,if(TransClientes.Retirado=1,HojaDeRuta.Posicion,HojaDeRuta.Posicion_retiro)as Posicion,HojaDeRuta.Cliente,Seguimiento,HojaDeRuta.id as hdrid,TransClientes.*,
     IF(Retirado=0,RazonSocial,ClienteDestino)as NombreCliente, 
     IF(Retirado=0,TransClientes.ingBrutosOrigen,TransClientes.idClienteDestino)as idCliente,
     TransClientes.Cantidad 
     FROM HojaDeRuta 
     INNER JOIN TransClientes ON TransClientes.id=HojaDeRuta.idTransClientes
     WHERE HojaDeRuta.Estado='Abierto' AND HojaDeRuta.Devuelto=0 AND HojaDeRuta.Recorrido='$_SESSION[RecorridoAsignado]'AND TransClientes.Eliminado='0' AND HojaDeRuta.Eliminado=0 ORDER BY if(TransClientes.Retirado=1,HojaDeRuta.Posicion,HojaDeRuta.Posicion_retiro)");
  } else {
    $BuscarRecorridos = $mysqli->query("SELECT TransClientes.CobrarEnvio,if(TransClientes.Retirado=1,HojaDeRuta.Posicion,HojaDeRuta.Posicion_retiro)as Posicion,HojaDeRuta.Cliente,Seguimiento,HojaDeRuta.id as hdrid,TransClientes.*,
     IF(Retirado=0,RazonSocial,ClienteDestino)as NombreCliente,
     IF(Retirado=0,TransClientes.ingBrutosOrigen,TransClientes.idClienteDestino)as idCliente,
     TransClientes.Cantidad
     FROM HojaDeRuta 
     INNER JOIN TransClientes ON TransClientes.id=HojaDeRuta.idTransClientes
     WHERE HojaDeRuta.Estado='Abierto' AND HojaDeRuta.Devuelto=0 
     AND HojaDeRuta.Recorrido='$_SESSION[RecorridoAsignado]' 
     AND TransClientes.Eliminado='0' AND HojaDeRuta.Eliminado=0 AND HojaDeRuta.Cliente LIKE '%$_POST[search]%' 
     ORDER BY  if(TransClientes.Retirado=1,HojaDeRuta.Posicion,HojaDeRuta.Posicion_retiro)");
  }

  while (($row = $BuscarRecorridos->fetch_array(MYSQLI_ASSOC)) != NULL) {

    if ($row['Retirado'] == 0) {
      $sql_nombrecliente_destino = $mysqli->query("SELECT ClienteDestino FROM TransClientes WHERE CodigoSeguimiento='$row[Seguimiento]' AND Eliminado=0");

      $dato_nombrecliente_entrega = $sql_nombrecliente_destino->fetch_array(MYSQLI_ASSOC);
      $dato_nombrecliente_entrega['ClienteDestino'];


      //ACA REEMPLAZAR ingBrutosOrigen por el ID DEL CLIENTE EMISOR
      $sqlBuscoidProveedor = $mysqli->query("SELECT idProveedor,nombrecliente,ActivarCoordenadas,Latitud,Longitud FROM Clientes WHERE id='$row[idCliente]'");
      $idProveedor = $sqlBuscoidProveedor->fetch_array(MYSQLI_ASSOC);
      if ($idProveedor['idProveedor'] <> 0) {
        $idP = '[' . $idProveedor['idProveedor'] . ']';
      } else {
        $idP = '';
      }
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
      if (strlen($row['TelefonoOrigen']) >= '10') {
        if (substr($row['TelefonoOrigen'], 0, 2) <> '54') {
          $Contacto = '54' . $row['TelefonoOrigen'];
        } else {
          $Contacto = $row['TelefonoOrigen'];
        }
        $veocel = 1;
      } else {
        $veocel = 0;
      }
    } else {

      //ACA REEMPLAZAR ingBrutosOrigen por el ID DEL CLIENTE EMISOR
      $sql_nombrecliente_origen = $mysqli->query("SELECT RazonSocial FROM TransClientes WHERE CodigoSeguimiento='$row[Seguimiento]' AND Eliminado=0");
      // $dato_nombrecliente=$sql_nombrecliente->fetch_array(MYSQLI_ASSOC);  
      $dato_nombrecliente_origen = $sql_nombrecliente_origen->fetch_array(MYSQLI_ASSOC);
      $dato_nombrecliente_origen['RazonSocial'];


      $sqlBuscoidProveedor = $mysqli->query("SELECT idProveedor,nombrecliente,ActivarCoordenadas,Latitud,Longitud,Observaciones FROM Clientes WHERE id='$row[idCliente]'");
      $idProveedor = $sqlBuscoidProveedor->fetch_array(MYSQLI_ASSOC);
      if ($idProveedor['idProveedor'] <> 0) {
        $idP = '[' . $idProveedor['idProveedor'] . ']';
      } else {
        $idP = '';
      }
      $Retirado = 1;
      $Servicio = 'Entrega';
      $color = 'success';
      $icon = 'up-bold';
      $Serviciowp = "entregar";

      //   if($row[PisoDeptoDestino]<>''){  
      //   $Direccion=$row[DomicilioDestino].'|'.$row[PisoDeptoDestino];
      //   }else{
      //   $Direccion=$row[DomicilioDestino];  
      //   }  

      $Direccion = $row['DomicilioDestino'];

      if ($idProveedor['ActivarCoordenadas'] == 1) {
        $Direccion_mapa = $idProveedor['Latitud'] . ',' . $idProveedor['Longitud'];
      } else {
        $Direccion_mapa = $row['DomicilioDestino'];
      }


      $NombreCliente = $row['ClienteDestino'];
      if (strlen($row['TelefonoDestino']) >= '10') {
        if (substr($row['TelefonoDestino'], 0, 2) <> '54') {
          $Contacto = '54' . $row['TelefonoDestino'];
        } else {
          $Contacto = $row['TelefonoDestino'];
        }
        $veocel = 1;
      } else {
        $veocel = 0;
      }
    }
?>
    <div class="col-xl-7">
      <div class="card">
        <div class="card-body border border-<? echo $color; ?>">

          <h2 class="header-title mb-1 text-<? echo $color; ?>"><? echo $row['Posicion']; ?> <i class="mdi mdi-arrow-<? echo $icon; ?>"> </i><? echo $Servicio; ?> | <? echo $row['NombreCliente']; ?></h2>
          <small class="mb-2"><b><?
                                  if ($row['Retirado'] == 0) {
                                    echo 'Destino: ' . $dato_nombrecliente_entrega['ClienteDestino'];
                                  } else {
                                    echo 'Origen: ' . $dato_nombrecliente_origen['RazonSocial'];
                                  }
                                  ?>
            </b></small>
          <div class="row">
            <div class="col-md-7">
              <div data-provide="datepicker-inline" data-date-today-highlight="true" class="calendar-widget"></div>
            </div> <!-- end col-->
            <div class="col-md-5">
              <ul class="list-unstyled">
                <?php
                if ($idP <> '') {
                ?>
                  <li class="mb-2">
                    <p class="text-muted mb-1 font-13">
                      <i class="mdi mdi-account"></i> ID PROVEEDOR
                    </p>
                    <h5><i class="mdi mdi-account"></i><? echo $idP; ?></h5>
                  </li>
                <?php
                }
                ?>
                <li class="mb-2">
                  <p class="text-muted mb-1 font-13">
                    <i class="mdi mdi-calendar"></i> 7:30 AM - 18:00 PM
                  </p>
                  <h5><i class="mdi mdi-map-marker"></i><? echo $Direccion . ' ' . $row['PisoDeptoDestino']; ?></h5>
                  <small><?php echo 'Observaciones: ' . $idProveedor['Observaciones']; ?></small>
                </li>
                <li class="mb-2">
                  <p class="text-muted mb-1 font-13">
                    <i class="mdi mdi-card-account-phone-outline"></i> CONTACTO
                  </p>
                  <? if ($veocel == 1) { ?>
                    <h5><? echo $Contacto; ?><a style='float:right;margin-right:14%;' href='https://api.whatsapp.com/send?phone=<? echo $Contacto; ?>&text=Hola <? echo $NombreCliente; ?> !,%20soy <? echo $_SESSION['NombreUsuario']; ?>%20de%20Caddy%20Logística%20!%20Estoy%20en%20camino%20para <? echo $Serviciowp; ?>%20tu%20pedido...'>
                        <img id='1' src='images/wp.png' width='30' height='30' /><? echo $Celular['Celular']; ?></a></h1>
                    <? } ?>
                </li>
                <li class="mb-2">
                  <p class="text-muted mb-1 font-13">
                    <i class="mdi mdi-card-search-outline"></i> SEGUIMIENTO
                  </p>
                  <h5><? echo $row['Seguimiento']; ?></h5>
                </li>
                <? if ($row['Observaciones'] <> '') { ?>
                  <li class="mb-2">
                    <p class="text-muted mb-1 font-13">
                      <i class="mdi mdi-card-account-details-outline"></i> REFERENCIA DE OPERACIONES
                    </p>
                    <h5><? echo $row['Observaciones']; ?></h5>
                  </li>
                <? } ?>
                <li class="mb-2">
                  <p class="text-muted mb-1 font-13">
                    <i class="mdi mdi-cube-outline"></i> CANTIDAD DE PAQUETES
                  </p>
                  <h5><? echo $row['Cantidad']; ?></h5>
                </li>

                <li>

                  <?
                  // $_SESSION[RecorridoAsignado]

                  $sql_muestraasignaciones = $mysqli->query("SELECT MuestraAsignaciones FROM `Logistica` where Estado ='Cargada' AND Eliminado=0 AND Recorrido='$_SESSION[RecorridoAsignado]'");
                  $dato_muestraasignaciones = $sql_muestraasignaciones->fetch_array(MYSQLI_ASSOC);

                  if ($dato_muestraasignaciones['MuestraAsignaciones'] == 1) {
                    //-----START ASIGNACIONES-----

                    $FechaAsignacion = date('Y-m-d');
                    $sqlasignaciones = $mysqli->query("SELECT * FROM Asignaciones WHERE idProveedor='$idProveedor[idProveedor]' and Relacion='$row[IngBrutosOrigen]' AND Fecha='$FechaAsignacion'");

                    $registros = $sqlasignaciones->num_rows;



                    if ($registros <> 0) {
                  ?>
                      <table class="table table-hover table-centered mb-0">
                        <thead>
                          <tr>
                            <th>Nombre</th>
                            <th>Edicion</th>
                            <th>Cantidad</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php
                          while ($datosasignaciones = $sqlasignaciones->fetch_array(MYSQLI_ASSOC)) {
                            $sqlasigproductos = $mysqli->query("SELECT * FROM AsignacionesProductos WHERE CodigoProducto='$datosasignaciones[CodigoProducto]' AND Relacion='$row[IngBrutosOrigen]'");
                            $datosasigproducto = $sqlasigproductos->fetch_array(MYSQLI_ASSOC);
                          ?>
                            <tr>
                              <td><? echo $datosasigproducto['Nombre']; ?></td>
                              <td><? echo $datosasignaciones['Edicion']; ?></td>
                              <td><? echo $datosasignaciones['Cantidad']; ?></td>
                            </tr>
                          <? } ?>
                        </tbody>
                      </table>
                  <? }
                  } else {
                  }
                  ?>
                  <!-- //-----END ASIGNACIONES------ -->
                </li>
                <?php
                if ($row['CobrarEnvio'] == 1) {
                  $numPedido = $row['Seguimiento'];
                  $sql = $mysqli->query("SELECT SUM(CobrarEnvio) AS Cobrar FROM Ventas WHERE NumPedido = '$numPedido' AND Eliminado = 0");

                  if ($sql) {
                    $rowCobranza = $sql->fetch_array();
                    $monto = $rowCobranza['Cobrar'];

                    if ($monto > 0) {
                      echo "<span class='badge badge-outline-danger fw-bold fs-6'>¡Atención! Requiere Cobranza de $ " . number_format($monto, 2, ',', '.') . "</span>";
                    }
                  }
                }
                ?>

              </ul>
            </div> <!-- end col -->
          </div>
          <!-- end row -->
          <div class="row">
            <div class="col-md-12">

              <a style='position:relative;bottom:0px;right:10px;float:left;margin-left:15%;'><img src='images/wrong.png' width='60' height='60' Onclick='verwrong(<? echo $row['hdrid']; ?>)' /></a>
              <a style='position:relative;bottom:7px;float:left;margin-left:3%;' href='https://maps.google.com/?q=<? echo urlencode($Direccion_mapa); ?>' target='_blank'><img src='images/goto.png' width='70' height='70' /></a>
              <a style='float:left;margin-left:6%;'><img src='images/ok.png' width='60' height='60' Onclick='verok(<? echo $row['hdrid']; ?>)' /></a>

            </div>
          </div>
        </div> <!-- end card body-->
      </div> <!-- end card -->
    </div><!-- end col-->
<?
  }
  // $sqlasignaciones->free();
  mysql_free_result();
}
?>