<?php
session_start();
require_once "../../Conexion/conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');
//DATOS
$Fecha= date("Y-m-d");	
$Hora=date("H:i"); 

//BUSCO EL SLUG
$state=$_POST['state'];
$sql=$mysqli->query("SELECT Slug FROM Estados WHERE Estado='$state'");
$sql_newstate=$sql->fetch_array(MYSQLI_ASSOC);
$newstate=$sql_newstate['Slug'];

$CodigoSeguimiento=$_POST['cs'];
$sql=$mysqli->query("SELECT ingBrutosOrigen,idClienteDestino,CodigoProveedor FROM TransClientes WHERE CodigoSeguimiento='$CodigoSeguimiento'");
$idCliente=$sql->fetch_array(MYSQLI_ASSOC);
$idClienteOrigen=$idCliente['ingBrutosOrigen'];
$idClienteDestino=$idCliente['idClienteDestino'];


if($idCliente['CodigoProveedor']<>''){
    
$codigo=$idCliente['CodigoProveedor'];

//CLIENTE ORIGEN
$sql=$mysqli->query("SELECT Webhook FROM Clientes WHERE id='$idClienteOrigen'");
$Webhook=$sql->fetch_array(MYSQLI_ASSOC);


if($Webhook['Webhook']==1){

    //BUSCO EL LOS DATOS DE CONEXION AL WEBHOOK
    $sql=$mysqli->query("SELECT * FROM Webhook WHERE idCliente='$idClienteOrigen'");
    if($sql_webhook=$sql->fetch_array(MYSQLI_ASSOC)){
      $Servidor=$sql_webhook['Endpoint'];
      $Token=$sql_webhook['Token'];  

      $newstatedate = $Fecha.'T'.$Hora;

    $curl = curl_init();

    curl_setopt_array($curl, array(
    CURLOPT_URL => $Servidor,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS =>'{
    "new_state": "'.$state.'", 
    "new_state_date": "'.$newstatedate.'", 
    "package_code": "'.$codigo.'" 
    }',
    CURLOPT_HTTPHEADER => array(
    'x-clicoh-token: '.$Token.'',
    'Content-Type: application/json'
    ),
    ));

    $response = curl_exec($curl);

    // Comprueba el código de estado HTTP
    if (!curl_errno($curl)) {
    switch ($http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
    case 200: 
    $Response=200; # OK
    // break;
    default:
    $Response=$http_code;
    // echo 'Unexpected HTTP code: ', $http_code, "\n";
    }
    }

    curl_close($curl);

    $postfields=$state.' '.$newstatedate.' '.$codigo;

    $sql=$mysqli->query("INSERT INTO `Webhook_notifications`(`idCliente`, `idCaddy`, `idProveedor`, `Servidor`, `State`, `Estado`, `Fecha`, `Hora`, `User`, `Response`) VALUES 
    ('{$idClienteOrigen}','{$CodigoSeguimiento}','{$codigo}','{$Servidor}','{$postfields}','{$state}','{$Fecha}','{$Hora}','{$_SESSION['Usuario']}','{$Response}')");

    // $sql=$mysqli->query("INSERT INTO `Webhook_notifications`(`idCliente`, `idCaddy`,`idProveedor`,`Servidor`, `State`, `User`, `Response`) VALUES 
    // ('{$idClienteOrigen}','{$CodigoSeguimiento}','{$codigo}','{$Servidor}','{$postfields}','{$_SESSION['Usuario']}','{$Response}')");

    echo json_encode(array('idOrigen'=>$idClienteOrigen,'codigo'=>$codigo,'new'=>$newstate));
    echo $response;
    }else{
     echo json_encode(array('err'=>'No hay datos de webpoint en cliente origen'));
    }
}//end if Cliente Origen

//CLIENTE DESTINO
$sql=$mysqli->query("SELECT Webhook FROM Clientes WHERE id='$idClienteDestino'");
$Webhook=$sql->fetch_array(MYSQLI_ASSOC);

if($Webhook['Webhook']==1){
//BUSCO EL LOS DATOS DE CONEXION AL WEBHOOK

$sql=$mysqli->query("SELECT * FROM Webhook WHERE idCliente='$idClienteDestino'");
if($sql_webhook=$sql->fetch_array(MYSQLI_ASSOC)){
  $Servidor=$sql_webhook['Endpoint'];
  $Token=$sql_webhook['Token'];      
  $newstatedate = $Fecha.'T'.$Hora;

$curl = curl_init();
curl_setopt_array($curl, array(
  CURLOPT_URL => 'https://sandbox-api.clicoh.com/api/v1/caddy/webhook/',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS =>'{
    "new_state": "'.$newstate.'", 
    "new_state_date": "'.$newstatedate.'", 
    "package_code": "'.$codigo.'" 
}',
  CURLOPT_HTTPHEADER => array(
    'x-clicoh-token: '.$Token.'',
    'Content-Type: application/json'
  ),
));

$response = curl_exec($curl);
// Comprueba el código de estado HTTP
if (!curl_errno($curl)) {
    switch ($http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
      case 200: 
        $Response=200; # OK
        // break;
      default:
      $Response=$http_code;
        // echo 'Unexpected HTTP code: ', $http_code, "\n";
    }
  }

curl_close($curl);

$postfields=$state.' '.$newstatedate.' '.$codigo;

$sql=$mysqli->query("INSERT INTO `Webhook_notifications`(`idCliente`, `idCaddy`, `idProveedor`, `Servidor`, `State`, `Estado`, `Fecha`, `Hora`, `User`, `Response`) VALUES 
    ('{$idClienteOrigen}','{$CodigoSeguimiento}','{$codigo}','{$Servidor}','{$postfields}','{$state}','{$Fecha}','{$Hora}','{$_SESSION['Usuario']}','{$Response}')");

// $sql=$mysqli->query("INSERT INTO `Webhook_notifications`(`idCliente`, `idCaddy`,`idProveedor`,`Servidor`, `State`, `User`, `Response`) VALUES 
// ('{$idClienteOrigen}','{$CodigoSeguimiento}','{$codigo}','{$Servidor}','{$postfields}','{$_SESSION['Usuario']}','{$Response}')");

echo json_encode(array('idDestino'=>$idClienteDestino,'codigo'=>$codigo,'new'=>$newstate));
echo $response;
}else{
echo json_encode(array('err'=>'No hay datos de webpoint en cliente destino'));
}
}//end if Cliente Destino
}else{
echo json_encode(array('status'=>'faild','CodigoProveedor'=>$idCliente['CodigoProveedor'],'idOrigen'=>$idClienteOrigen,'idDestino'=>$idClienteDestino,'codigo'=>$codigo,'new'=>$newstate));
}
?>