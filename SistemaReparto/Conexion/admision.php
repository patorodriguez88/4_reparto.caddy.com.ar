<?php
session_start();
include_once "conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');

if ($_POST['Salir']==1){
session_destroy();
echo json_encode(array('success'=>1));      
}; 

if ($_POST['user']<>''){

  $user= $_POST['user'];
  $password= $_POST['password'];
  $sql = "SELECT * FROM usuarios WHERE Usuario = '$user' and PASSWORD = '$password' AND ACTIVO='1' AND Nivel='3'";
  $rec = $mysqli->query($sql);  
  $row = $rec->fetch_array(MYSQLI_ASSOC);

        if($row['id']){
            $Fecha=date('Y-m-d');
            $Hora=date('H:i');
            $ipCliente = $_SERVER['REMOTE_ADDR'];
            $userAgent = $_SERVER['HTTP_USER_AGENT'];

            $mysqli->query("INSERT INTO `Ingresos`(`idUsuario`, `Nombre`, `Fecha`, `Hora`, `ip`, `UserAgent`) VALUES ('{$row['id']}','{$row['Usuario']}','{$Fecha}','{$Hora}','{$ipCliente}','{$userAgent}')");
            //TRANSPORTISTA
            $idUsuario=$row['id'];
            $sql_usuario=$mysqli->query("SELECT NombreCompleto FROM Empleados WHERE Usuario ='$idUsuario'");
            $Usuario=$sql_usuario->fetch_array(MYSQLI_ASSOC);
            
            $_SESSION['Transportista']=$Usuario['NombreCompleto'];   
            $_SESSION['idusuario']=$row[id];
            $_SESSION['ingreso']=$_POST['user'];
            $_SESSION['NCliente']= $row[NdeCliente];
            $_SESSION['Nivel']=$row[NIVEL];
            $_SESSION['Direccion']=$row[Direccion];
            $_SESSION['NombreUsuario']=$row[Nombre];
            $_SESSION['Usuario']=$row[Usuario];
            $_SESSION['Sucursal']=$row[Sucursal]; 

            $sqlC = $mysqli->query("SELECT * FROM Logistica WHERE idUsuarioChofer=".$row[id]." AND Estado='Cargada' AND Eliminado='0'");
            $Dato=$sqlC->fetch_array(MYSQLI_ASSOC);
                        
            //BUSCO LOS ENVIOS PENDIENTES EN HOJA DE RUTA PARA EL RECORRIDO
            $sql_hdr=$mysqli->query("SELECT Seguimiento,TransClientes.Retirado FROM `HojaDeRuta` INNER JOIN TransClientes ON HojaDeRuta.idTransClientes=TransClientes.id where HojaDeRuta.Eliminado=0 AND HojaDeRuta.Devuelto=0 AND HojaDeRuta.Estado='Abierto' AND HojaDeRuta.Recorrido='$Dato[Recorrido]'");
            
            $rows=array();            
            
            while($dato_hdr=$sql_hdr->fetch_array(MYSQL_ASSOC)){
            
                $rows[]=$dato_hdr;
            
            }
            
            $_SESSION['RecorridoAsignado']=$Dato[Recorrido];
            $_SESSION['hdr']=$Dato[NumerodeOrden]; 
            
            
                echo json_encode(array('success'=>1,'codigos'=>$rows)); 
            
            
        }else{

            echo json_encode(array('success'=>0,'user'=>$row[id]));      

        }
   
    }else{

        echo json_encode(array('success'=>0,'user'=>$row[id]));      
}
?>