<?php
ob_start();
session_start();
include_once "../ConexionSmartphone.php";
$user= $_SESSION['NCliente'];
$password= $_POST['password'];
$color='#B8C6DE'; 
$font='white';
date_default_timezone_set('America/Argentina/Buenos_Aires');
?>
<!DOCTYPE HTML>
<html>
	<head>
		<title>Caddy. Yo lo llevo!</title>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8"/>
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<link rel="stylesheet" href="../assets/css/main.css" />
		<link rel="stylesheet" href="../css/smartphone.css" />
	</head>
	<body class="subpage">
		<div id="page-wrapper">
			<!-- Header -->
				<div id="header-wrapper">
					<header id="header" class="container">
						<div class="row">
							<div class="12u">
								<!-- Logo -->
									<h1><a href="#" id="logo">Caddy. Yo lo llevo!</a></h1>
					    	<?php
								include('../MenuSmartphone/MenuPrincipal.php');
								?>
							</div>
						</div>
					</header>
				</div>
			<!-- Content -->
				<div id="content-wrapper">
					<div id="content">
						<div class="container">
							<div class="row">
								<div class="12u">

									<!-- Main Content -->
										<section>
<?
if($_POST[Cerrar]=='Aceptar'){
    //PRIMERO MODIFICO LOS DATOS Y CIERRO LA ORDEN
    $Fecha=date('Y-m-d');
    $Hora=date("H:i"); 
    
    $sql=mysql_query("UPDATE Logistica SET KilometrosRegreso='$_POST[Km]',KilometrosRecorridos=('$_POST[Km]'-Kilometros),
  CombustibleRegreso='$_POST[combustibleregreso_t]',ObservacionesCierre='$_POST[observacionesregreso_t]',Estado='Cerrada',
  FechaRetorno='$Fecha',HoraRetorno='$Hora',UsuarioCierre='$_SESSION[Usuario]' WHERE id='$_POST[id]' LIMIT 1"); 
  
    //SEGUNDO DETECTO SI HAY MAS DE UNA ORDEN EN ESTA CAMIONETA LE CARGO LOS KM DE SALIDA Y EL TANQUE DE COMBUSTIBLE
    
    $sqlC = mysql_query("SELECT *,MIN(id)as id FROM Logistica WHERE Patente='$_POST[patente]' AND Estado='Pendiente' AND Eliminado='0'");
    $datoid=mysql_fetch_array($sqlC);
    
    if($datoid[id]<>''){
    $sql=mysql_query("UPDATE Logistica SET Kilometros='$_POST[Km]',CombustibleSalida='$_POST[combustibleregreso_t]',Estado='Cargada' WHERE id='$datoid[id]'"); 
    $sqlvehiculos=mysql_query("UPDATE Vehiculos SET Estado ='Alta en Recorrido $datoid[Recorrido]', Kilometros='$_POST[Km]', NivelCombustible='$_POST[combustibleregreso_t]' WHERE Dominio='$_POST[patente]'");
  //   AGREGO EL NOMBRE DEL TRANSPORTISTA Y LA FECHA DE ENTREGA EN TRANSCLIENTES
    $sql5="UPDATE TransClientes SET Transportista ='$datoid[NombreChofer]', FechaEntrega='$datoid[Fecha]' WHERE Recorrido='$datoid[Recorrido]' AND Entregado='0' and Eliminado='0' LIMIT 1";
    mysql_query($sql5);
    
    }else{
    $sql2="UPDATE Vehiculos SET Estado ='Disponible', Kilometros='$_POST[Km]', NivelCombustible='$_POST[combustibleregreso_t]' WHERE Dominio='$_POST[patente]' LIMIT 1";
    mysql_query($sql2);  
    } 
  
  }   
//DESDE ACA PARA CERRAR RECORRIDO
$sqlC = mysql_query("SELECT * FROM Logistica WHERE idUsuarioChofer='".$_SESSION['idusuario']."' AND Estado='Cargada' AND Eliminado='0'");
$Dato=mysql_fetch_array($sqlC);
if(mysql_num_rows($sqlC)==0){
echo "<h2>No hay Recorridos disponibles para cierre</h2>";
goto a;  
}
//COMPRUEBO QUE YA ESTEN ENTREGADOS O PICKEADOS TODOS LOS PAQUETES
$sqlC = mysql_query("SELECT * FROM Logistica WHERE idUsuarioChofer='".$_SESSION['idusuario']."' AND Estado='Cargada' AND Eliminado='0'");
$Dato=mysql_fetch_array($sqlC);
$sql=mysql_query("SELECT id FROM HojaDeRuta WHERE Recorrido='$Dato[Recorrido]' AND Estado='Abierto' AND Eliminado=0");                      
// $sql=mysql_query("SELECT * FROM `TransClientes`,`HojaDeRuta` WHERE TransClientes.CodigoSeguimiento=HojaDeRuta.Seguimiento 
// AND TransClientes.Entregado='0'
// AND TransClientes.Recorrido='".$Dato[Recorrido]."' 
// AND TransClientes.Eliminado='0' 
// AND HojaDeRuta.Eliminado='0' ORDER BY HojaDeRuta.Posicion ASC");                      
if(mysql_num_rows($sql)<>0){
echo "<h2>Tiene paquetes pendientes en la Hoja de Ruta del Recorrido $Dato[Recorrido]... imposible cerrar</h2>";
goto a;  
  
}
                      
                      
echo "<form class='login' style='width:100%' action='' method='POST'>";
echo "<h2>Cerrar Briefing Recorrido $Dato[Recorrido]</h2>";
echo "<input type='hidden' name='id' value='$Dato[id]'>";
echo "<input type='hidden' name='patente' value='$Dato[Patente]'>";                      
echo "<div><label>Vehiculo $Dato[Patente]</label></div>";
echo "<div><label>Km Salida $Dato[Kilometros] </label></div>";
echo "<div><label>Km Actual ($Dato[Patente])</label><input type='number' name='Km' value='' style='float:right' required></div>";
// echo"<div><label>Cargo combustible:</label><input type='text' value='' name='carga_t' style='width:40%;float:right' placeholder='litros' ></div>";
echo "<div><label>Nivel Tanque de Combustible:</label><select name='combustibleregreso_t' style='width:40%;float:right' />";
				echo "<option value='0'>Vacio</option>";
					echo "<option value='1'>1/8</option>";
					echo "<option value='2'>2/8</option>";
					echo "<option value='3'>3/8</option>";
					echo "<option value='4'>4/8</option>";
					echo "<option value='5'>5/8</option>";
					echo "<option value='6'>6/8</option>";
					echo "<option value='7'>7/8</option>";
					echo "<option value='8'>8/8</option></select></div>";
echo "<div><label>Observaciones:</label><textarea rows='4' cols='30' name='observacionesregreso_t'></textarea></div>";

echo "<div><input class='button-big' align='right' type='submit' name='Cerrar' style='float:right;background:red;font-size:20px;width:95%;height:60px;margin-top:15px;' value='Aceptar'></div>";                      
echo "</form>";
                      a:
                      ?>     
                  </section>  
								</div>
							</div>
						</div>
					</div>
				</div>
		<!-- Scripts -->
			<script src="../../../assets/js/jquery.min.js"></script>
			<script src="../../../assets/js/skel.min.js"></script>
			<script src="../../../assets/js/skel-viewport.min.js"></script>
			<script src="../../../assets/js/util.js"></script>
			<!--[if lte IE 8]><script src="assets/js/ie/respond.min.js"></script><![endif]-->
			<script src="../../../assets/js/main.js"></script>
      
<!--       Firma -->
     <script src="firma/firma.js"></script>
     <script src="http://ajax.aspnetcdn.com/ajax/jQuery/jquery-1.6.1.min.js"></script>
   
	</body>
</html>
