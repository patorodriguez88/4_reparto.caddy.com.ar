<?php
session_start();
require_once "../../Conexion/conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');

$Fecha= date("Y-m-d");	
$Hora=date("H:i"); 
$newstatedate = "2021-11-13T18:15Z"; 
$state="Entregado al Cliente";
$codigo="LHUKT34572";
$Servidor='https://sandbox-api.clicoh.com/api/v1/caddy/webhook/';
$Token='KBr0GuDVAk4pdOQdrbgrPs6E3tZ141JK50OCs2lYxjw';
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

$sql=$mysqli->query("INSERT INTO `Webhook_notifications`(`idCliente`, `idCaddy`,`idProveedor`,`Servidor`, `State`, `User`, `Response`) VALUES 
('{$idClienteOrigen}','{$CodigoSeguimiento}','{$codigo}','{$Servidor}','{$postfields}','{$_SESSION['Usuario']}','{$Response}')");

echo json_encode(array('postfields'=>$postfields,'codigo'=>$codigo,'new'=>$state,'response'=>$Response));
echo $response;
echo $Fecha.'T'.$Hora.'Z';
?>