<?php
session_start();
require_once "../../Conexion/conexioni.php";
$Rec = $_SESSION['RecorridoAsignado'];

$query = "SELECT IF(Retirado=1,idClienteDestino,IngBrutosOrigen)as idCliente FROM TransClientes WHERE Recorrido='$Rec' AND Entregado=0 AND Eliminado=0";
$resultado = $mysqli->query($query);
$rowsr = array();
while ($rowr = $resultado->fetch_array(MYSQLI_ASSOC)) {
    $rowsr[] = join($rowr);
}
$exito = json_encode($rowsr);
$exito = trim($exito, '[]');

$query = "SELECT nombrecliente,Direccion,CONCAT(Latitud, ',', Longitud)as coordenadas,HojaDeRuta.Recorrido,HojaDeRuta.Seguimiento,Clientes.Telefono,Clientes.Celular,Clientes.Celular2 FROM Clientes 
INNER JOIN HojaDeRuta ON Clientes.id = HojaDeRuta.idCliente WHERE Clientes.id IN ($exito) AND HojaDeRuta.Estado='Abierto' And HojaDeRuta.Eliminado='0' AND Clientes.Latitud<>''";

$result = $mysqli->query($query);
$i = 0;
$rows = $result->num_rows;
$rowss = array();
while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
    $rowss[] = $row;
}
$queryr = "SELECT Color FROM Recorridos WHERE Numero='$Rec'";
$resultR = $mysqli->query($queryr);
$rowR = $resultR->fetch_array(MYSQLI_ASSOC);
$color = $rowR['Color'];
echo json_encode(array('data' => $rowss, 'Recorrido' => $Rec, 'Color' => $color));
