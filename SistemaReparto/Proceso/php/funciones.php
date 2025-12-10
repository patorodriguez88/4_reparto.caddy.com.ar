<?php
session_start();
require_once "../../Conexion/conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');

$Fecha = date("Y-m-d");
$Hora = date("H:i");
$Usuario = $_SESSION['Usuario'];
$idUsuario = $_SESSION['idusuario'];
$Sucursal = $_SESSION['Sucursal'];
$Transportista = $_SESSION['Transportista'];
$NumeroOrden = $_SESSION['hdr'];
$Recorrido = $_SESSION['RecorridoAsignado'];
$infoABM = $Usuario . ' ' . $Fecha . ' ' . $Hora;


if ($_POST['Datos'] == 1) {
  if ($_SESSION['idusuario']) {
    $sql = $mysqli->query("SELECT NumerodeOrden FROM Logistica WHERE idUsuarioChofer='$_SESSION[idusuario]' AND Estado='Cargada' AND Eliminado='0'");
    $row = $sql->fetch_array(MYSQLI_ASSOC);

    if ($row['NumerodeOrden']) {
      //CANTIDADES
      $sqlCantidadTotal = $mysqli->query("SELECT COUNT(id)as Cantidad FROM HojaDeRuta WHERE Recorrido='$_SESSION[RecorridoAsignado]' 
    AND Eliminado=0 AND NumerodeOrden='$row[NumerodeOrden]' AND Devuelto='0'");
      $TotalCantidad = $sqlCantidadTotal->fetch_array(MYSQLI_ASSOC);

      //NO ENTREGADOS
      $sqlNoEntregados = $mysqli->query("SELECT COUNT(HojaDeRuta.id)as Cantidad FROM HojaDeRuta INNER JOIN TransClientes ON HojaDeRuta.Seguimiento=TransClientes.CodigoSeguimiento 
    WHERE HojaDeRuta.Recorrido='$_SESSION[RecorridoAsignado]' AND HojaDeRuta.Eliminado=0 AND HojaDeRuta.NumerodeOrden='$row[NumerodeOrden]' AND HojaDeRuta.Devuelto='0'
    AND TransClientes.Entregado=0 AND TransClientes.Eliminado=0");
      $TotalNoEntregados = $sqlNoEntregados->fetch_array(MYSQLI_ASSOC);

      //ENTREGADOS
      $sqlEntregados = $mysqli->query("SELECT COUNT(HojaDeRuta.id)as Cantidad FROM HojaDeRuta INNER JOIN TransClientes ON HojaDeRuta.Seguimiento=TransClientes.CodigoSeguimiento 
    WHERE HojaDeRuta.Recorrido='$_SESSION[RecorridoAsignado]' AND HojaDeRuta.Eliminado=0 AND HojaDeRuta.NumerodeOrden='$row[NumerodeOrden]' AND HojaDeRuta.Devuelto='0'
    AND TransClientes.Entregado=1");
      $TotalEntregados = $sqlEntregados->fetch_array(MYSQLI_ASSOC);


      echo json_encode(array(
        'success' => 1,
        'data' => $row['NumerodeOrden'],
        'Recorrido' => $_SESSION['RecorridoAsignado'],
        'Total' => $TotalCantidad['Cantidad'],
        'Cerrados' => $TotalNoEntregados['Cantidad'],
        'Abiertos' => $TotalEntregados['Cantidad'],
        'Usuario' => $Transportista
      ));
    } else {
      echo json_encode(array('success' => 2, 'usuario' => $_SESSION['idusuario'], 'norden' => $row['NumerodeOrden']));
    }
  } else {
    echo json_encode(array('success' => 0, 'usuario' => $_SESSION['idusuario']));
  }
}

if ($_POST['ConfirmoEntrega'] == 1) {

  $CodigoSeguimiento = $_POST['Cs'];
  $sqlhdr = $mysqli->query("SELECT id FROM HojaDeRuta WHERE Seguimiento='$CodigoSeguimiento'");
  $id = $sqlhdr->fetch_array(MYSQLI_ASSOC);

  $dni = $_POST['Dni'];
  $nombre2 = $_POST['Name'];
  $Observaciones = $_POST['Obs'];
  $Retirado = $_POST['Retirado'];
  $Etiquetas = $_POST['Etiquetas'];

  for ($i = 0; $i < count($Etiquetas); $i++) {
    //ETIQUETAS
    $mysqli->query("INSERT INTO `Etiquetas`(`CodigoSeguimiento`,`Observaciones`) VALUES 
('{$CodigoSeguimiento}','{$Etiquetas[$i]}')");
  }

  $sqlLocalizacion = $mysqli->query("SELECT ClienteDestino,DomicilioDestino,LocalidadDestino,Redespacho,IngBrutosOrigen 
FROM TransClientes WHERE CodigoSeguimiento='$CodigoSeguimiento'");

  $sqlLocalizacionR = $sqlLocalizacion->fetch_array(MYSQLI_ASSOC);

  $Localizacion = utf8_decode($sqlLocalizacionR['DomicilioDestino']);
  //BUSCO LOS DATOS PARA EL ENVIO DEL MAIL
  // $sqlmail=$mysqli->query("SELECT nombrecliente,Mail FROM Clientes WHERE id='$sqlLocalizacionR[IngBrutosOrigen]'");
  // $datomail=mysql_fetch_array($sqlmail);
  // $NombreMail=$datomail[nombrecliente];
  // $EmailMail=$datomail[Mail];
  // $Fecha= date("Y-m-d");	
  // $Hora=date("H:i"); 
  // $Usuario=$_SESSION['Usuario'];
  // $Sucursal=utf8_decode($_SESSION['Sucursal']);
  // $Localizacion=$_SESSION[Localizacion];  

  //BUSCO QUE NUMERO DE VISITA ES
  $sqlvisita = $mysqli->query("SELECT MAX(Visitas)as Visita FROM Seguimiento WHERE CodigoSeguimiento='$CodigoSeguimiento'");
  $visita = $sqlvisita->fetch_array(MYSQLI_ASSOC);
  $Visita = $visita['Visita'] + 1;

  //BUSCO LOS DATOS DEL CLIENTE
  if ($_POST['Retirado'] == 1) {

    if ($sqlLocalizacionR['Redespacho'] == 0) {
      $Entregado = 1;
      $Estado = 'Entregado al Cliente';
      $Estado_id = 7;
      //CONTROLO PARA EVITAR DUPLICIDAD DE ESTADO ENTREGADO
      $resultado = $mysqli->query("SELECT 1 FROM Seguimiento WHERE CodigoSeguimiento = '$CodigoSeguimiento' AND Entregado = $Entregado AND Estado='$Estado' LIMIT 1");

      if ($resultado && $resultado->num_rows > 0) {
        echo json_encode(['success' => 0, 'error' => 'Este pedido ya fue marcado como entregado.']);
        exit;
      }
    } else {
      $Entregado = 0;
      $Estado = 'En Transito';
      $Estado_id = 5;
      $Fecha = date("Y-m-d");
      $Hora = date("H:i");
      $sqlTransClientes = $mysqli->query("SELECT id,RazonSocial,DomicilioOrigen,Recorrido FROM TransClientes WHERE CodigoSeguimiento='$CodigoSeguimiento' AND Eliminado=0 ");
      $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC);
      $NombreCompleto = utf8_decode($datossqlTransClientes['RazonSocial']);
      $Localizacion = utf8_decode($datossqlTransClientes['DomicilioOrigen']);
      $idTransClientes = $datossqlTransClientes['id'];
      $Recorrido = $datossqlTransClientes['Recorrido'];
    }

    $sqlTransClientes = $mysqli->query("SELECT id,ClienteDestino,DomicilioDestino,Recorrido FROM TransClientes WHERE CodigoSeguimiento='$CodigoSeguimiento' AND Eliminado=0");
    $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC);
    $NombreCompleto = utf8_decode($datossqlTransClientes['ClienteDestino']);
    $Localizacion = utf8_decode($datossqlTransClientes['DomicilioDestino']);
    $idTransClientes = $datossqlTransClientes['id'];
    $Recorrido = $datossqlTransClientes['Recorrido'];
  } else {

    $Entregado = 0;
    $Estado = 'Retirado del Cliente';
    $Estado_id = 3;
    $sqlTransClientes = $mysqli->query("SELECT id,RazonSocial,DomicilioOrigen,Recorrido FROM TransClientes WHERE CodigoSeguimiento='$CodigoSeguimiento' AND Eliminado=0 ");
    $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC);
    $NombreCompleto = utf8_decode($datossqlTransClientes['RazonSocial']);
    $Localizacion = utf8_decode($datossqlTransClientes['DomicilioOrigen']);
    $idTransClientes = $datossqlTransClientes['id'];
    $Recorrido = $datossqlTransClientes['Recorrido'];
  }

  $mysqli->query("INSERT INTO Seguimiento(Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden)
  VALUES('{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$CodigoSeguimiento}','{$Observaciones}','{$Entregado}','{$Estado}','{$nombre2}','{$dni}','{$Localizacion}','{$Visita}',
  '{$Retirado}','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}')");

  if (($_POST['Retirado'] == 1) || ($Entregado == 1)) {

    //CIERRO HOJA DE RUTA
    $mysqli->query("UPDATE HojaDeRuta SET Estado='Cerrado' WHERE Eliminado=0 AND Seguimiento='$CodigoSeguimiento' LIMIT 1");
    //CIERRO ROADMAP
    $mysqli->query("UPDATE Roadmap SET Estado='Cerrado' WHERE Eliminado=0 AND Seguimiento='$CodigoSeguimiento' LIMIT 1");
  }

  //ACTUALIZO TRANSCLIENTES
  $mysqli->query("UPDATE IGNORE TransClientes SET Estado='$Estado',Entregado='$Entregado',Retirado='1',Transportista='$Transportista', 
  NumerodeOrden='$NumeroOrden',Recorrido='$Recorrido',Estado='$Estado',idABM='$idUsuario',infoABM='$infoABM',FechaEntrega='$Fecha' 
  WHERE Eliminado=0 AND CodigoSeguimiento='$CodigoSeguimiento' LIMIT 1");

  echo json_encode(array('success' => 1, 'id' => $id['id'], 'estado' => $Estado));

  // $verifico=$mysqli->query("SELECT id FROM Seguimiento WHERE CodigoSeguimiento='$CodigoSeguimiento' AND Entregado=1");

  //ACTUALIZO TRANSCLIENTES
  // $FechaEntrega=date('Y-m-d');
  // $sqlT="UPDATE TransClientes SET Entregado='$Entregado',Retirado='$Retirado',FechaEntrega='$FechaEntrega',Estado='$Estado' WHERE CodigoSeguimiento='$CodigoSeguimiento' AND Eliminado=0";
  // $mysqli->query($sqlT);
  //ACTUALIZO HOJA DE RUTA SIEMPRE A CERRADO PARA QUE NO FIGURE MAS EN EL SISTEMA DE SMARTPHONE
  // $sqlhdr="UPDATE HojaDeRuta SET Estado='Cerrado' WHERE Seguimiento='$CodigoSeguimiento' AND Eliminado=0";
  // $mysqli->query($sqlhdr);	


  // $sql=$mysqli->query("UPDATE");


}
//NO ENTREGADO
if ($_POST['ConfirmoNoEntrega'] == 1) {

  $CodigoSeguimiento = $_POST['Cs'];
  $sqlhdr = $mysqli->query("SELECT id FROM HojaDeRuta WHERE Seguimiento='$CodigoSeguimiento'");
  $id = $sqlhdr->fetch_array(MYSQLI_ASSOC);

  $dni = $_POST['Dni'];
  $nombre2 = $_POST['Name'];
  $Observaciones = $_POST['Razones'] . ' ' . $_POST['Obs'];
  $Retirado = $_POST['Retirado'];
  $Estado = 'No se pudo entregar';
  $Estado_id = 8;

  $sqlLocalizacion = $mysqli->query("SELECT ClienteDestino,DomicilioDestino,LocalidadDestino,Redespacho,IngBrutosOrigen 
FROM TransClientes WHERE CodigoSeguimiento='$CodigoSeguimiento'");

  $sqlLocalizacionR = $sqlLocalizacion->fetch_array(MYSQLI_ASSOC);

  $Localizacion = utf8_decode($sqlLocalizacionR['DomicilioDestino']);
  //BUSCO LOS DATOS PARA EL ENVIO DEL MAIL
  // $sqlmail=$mysqli->query("SELECT nombrecliente,Mail FROM Clientes WHERE id='$sqlLocalizacionR[IngBrutosOrigen]'");
  // $datomail=mysql_fetch_array($sqlmail);
  // $NombreMail=$datomail[nombrecliente];
  // $EmailMail=$datomail[Mail];
  // $Fecha= date("Y-m-d");	
  // $Hora=date("H:i"); 
  // $Usuario=$_SESSION['Usuario'];
  // $Sucursal=utf8_decode($_SESSION['Sucursal']);
  // $Localizacion=$_SESSION[Localizacion];  

  //BUSCO QUE NUMERO DE VISITA ES
  $sqlvisita = $mysqli->query("SELECT MAX(Visitas)as Visita FROM Seguimiento WHERE CodigoSeguimiento='$CodigoSeguimiento'");
  $visita = $sqlvisita->fetch_array(MYSQLI_ASSOC);
  $Visita = $visita['Visita'] + 1;
  $Recorrido = '80';
  //BUSCO LOS DATOS DEL CLIENTE
  if ($_POST['Retirado'] == 1) {
    $Entregado = 0;
    $sqlTransClientes = $mysqli->query("SELECT id,ClienteDestino,DomicilioDestino,Recorrido FROM TransClientes WHERE CodigoSeguimiento='$CodigoSeguimiento'");
    $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC);
    $NombreCompleto = utf8_decode($datossqlTransClientes['ClienteDestino']);
    $Localizacion = utf8_decode($datossqlTransClientes['DomicilioDestino']);
    $idTransClientes = $datossqlTransClientes['id'];
    // $Recorrido=$datossqlTransClientes[Recorrido];
  } else {
    $Entregado = 0;
    $sqlTransClientes = $mysqli->query("SELECT id,RazonSocial,DomicilioOrigen,Recorrido FROM TransClientes WHERE CodigoSeguimiento='$CodigoSeguimiento'");
    $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC);
    $NombreCompleto = utf8_decode($datossqlTransClientes['RazonSocial']);
    $Localizacion = utf8_decode($datossqlTransClientes['DomicilioOrigen']);
    $idTransClientes = $datossqlTransClientes['id'];
    // $Recorrido=$datossqlTransClientes[Recorrido];  
  }

  $mysqli->query("INSERT IGNORE INTO Seguimiento(Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden)
  VALUES('{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$CodigoSeguimiento}','{$Observaciones}','{$Entregado}','{$Estado}','{$nombre2}','{$dni}','{$Localizacion}','{$Visita}',
  '{$Retirado}','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}')");

  //CIERRO EN HOJA DE RUTA
  if ($CodigoSeguimiento <> "") {
    $mysqli->query("UPDATE HojaDeRuta SET Estado='Cerrado' WHERE Seguimiento='$CodigoSeguimiento' LIMIT 1");
    //CIERRO EN ROADMAP
    $mysqli->query("UPDATE Roadmap SET Estado='Cerrado' WHERE Seguimiento='$CodigoSeguimiento' LIMIT 1");
    //ACTUALIZO TRANSCLIENTES
    $mysqli->query("UPDATE IGNORE TransClientes SET Estado='$Estado',Entregado='$Entregado',Transportista='$Transportista',
  NumerodeOrden='$NumeroOrden',Recorrido='$Recorrido',Estado='$Estado',idABM='$idUsuario',infoABM='$infoABM',FechaEntrega='$Fecha' 
  WHERE CodigoSeguimiento='$CodigoSeguimiento' LIMIT 1");
  }
  echo json_encode(array('success' => 1, 'id' => $id['id'], 'estado' => $Estado));

  // $verifico=$mysqli->query("SELECT id FROM Seguimiento WHERE CodigoSeguimiento='$CodigoSeguimiento' AND Entregado=1");

  //ACTUALIZO TRANSCLIENTES
  // $FechaEntrega=date('Y-m-d');
  // $sqlT="UPDATE TransClientes SET Entregado='$Entregado',Retirado='$Retirado',FechaEntrega='$FechaEntrega',Estado='$Estado' WHERE CodigoSeguimiento='$CodigoSeguimiento' AND Eliminado=0";
  // $mysqli->query($sqlT);
  //ACTUALIZO HOJA DE RUTA SIEMPRE A CERRADO PARA QUE NO FIGURE MAS EN EL SISTEMA DE SMARTPHONE
  // $sqlhdr="UPDATE HojaDeRuta SET Estado='Cerrado' WHERE Seguimiento='$CodigoSeguimiento' AND Eliminado=0";
  // $mysqli->query($sqlhdr);	
  // $sql=$mysqli->query("UPDATE");
}

if ($_POST['BuscoDatos'] == 1) {
  $sql = $mysqli->query("SELECT Seguimiento FROM HojaDeRuta WHERE id='$_POST[id]'");
  $row = $sql->fetch_array(MYSQLI_ASSOC);

  $Buscar = $mysqli->query("SELECT id,Fecha,if(Retirado=0,RazonSocial,ClienteDestino)as NombreCliente,
      if(Retirado=0,DomicilioOrigen,DomicilioDestino)as Domicilio,CodigoSeguimiento,Observaciones,Retirado 
      FROM TransClientes WHERE CodigoSeguimiento='$row[Seguimiento]'");
  $rows = array();
  while ($fila = $Buscar->fetch_array(MYSQLI_ASSOC)) {
    $rows[] = $fila;
  }
  echo json_encode(array('data' => $rows));
}

if ($_POST['SubirFotos'] == 1) {
  foreach ($_FILES["file"]["error"] as $key => $error) {
    if ($error == UPLOAD_ERR_OK) {
      $tmp_name = $_FILES["file"]["tmp_name"][$key];
      // basename() may prevent filesystem traversal attacks;
      // further validation/sanitation of the filename may be appropriate
      $name = basename($_FILES["file"]["name"][$key]);
      move_uploaded_file($tmp_name, "data/$name");
    }
  }
}
