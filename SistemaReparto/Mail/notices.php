<?php
//SistemaReparto/Mail/notices.php
require_once "../../../Conexion/Conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');
//DATOS
$Fecha = date("Y-m-d");
$Hora = date("H:i");
$Usuario = $_SESSION['Usuario'];

//NUEVO AVISOS
if ($_POST['Avisos'] == 1) {

    $cs = $_POST['cs'];
    $st = $_POST['st'];

    $sql = $mysqli->query("SELECT ingBrutosOrigen,ClienteDestino FROM TransClientes WHERE CodigoSeguimiento='$cs' AND Eliminado='0'");

    if ($sql_customers = $sql->fetch_array(MYSQLI_ASSOC)) {

        $idCliente = $sql_customers['ingBrutosOrigen'];

        //BUSCO SI EL CLIENTE ORIGEN TIENE MAIL
        $sql = $mysqli->query("SELECT nombrecliente,Mail FROM Clientes WHERE Clientes.id='$idCliente'"); //REEMPLAZAR POR $idCliente           

        if ($sql_notices = $sql->fetch_array(MYSQLI_ASSOC)) {

            $mail = $sql_notices['Mail'];

            $name = $sql_notices['nombrecliente'];

            //COMPRUEBO QUE NO EXISTA YA LA NOTIFICACION
            $sql_compruebo = $mysqli->query("SELECT id FROM Notifications WHERE idCliente='$idCliente' AND CodigoSeguimiento='$cs' AND State='$st'"); //REEMPLAZAR POR $idCliente                       
            $dato_compruebo = $sql_compruebo->fetch_array(MYSQLI_ASSOC);

            if ($dato_compruebo) {

                echo json_encode(array('success' => 0));
            } else {
                if ($mail) {
                    $sql = "INSERT INTO `Notifications`(`CodigoSeguimiento`, `idCliente`, `Name`, `Mail`, `State`, `Fecha`, `Hora`) VALUES 
                    ('{$cs}','{$idCliente}','{$name}','{$mail}','{$st}','{$Fecha}','{$Hora}')";
                }
                if ($mysqli->query($sql)) {

                    echo json_encode(array('success' => 1, 'mail' => $mail, 'name' => $name, 'destination_name' => $sql_customers['ClienteDestino']));
                } else {

                    echo json_encode(array('success' => 0));
                }
            }
        } else {

            echo json_encode(array('success' => 0));
        }
    } else {

        echo json_encode(array('success' => 0));
    }
}
//AVISO AL DESTINATARIO EN RECEPCION
if ($_POST['Avisos'] == 2) {

    $cs = $_POST['cs'];
    $st = $_POST['st'];

    $sql = $mysqli->query("SELECT idClienteDestino,RazonSocial FROM TransClientes WHERE CodigoSeguimiento='$cs' AND Eliminado='0'");

    if ($sql_customers = $sql->fetch_array(MYSQLI_ASSOC)) {

        $idCliente = $sql_customers['idClienteDestino'];

        //BUSCO SI EL CLIENTE ORIGEN TIENE MAIL
        $sql = $mysqli->query("SELECT nombrecliente,Mail FROM Clientes WHERE Clientes.id='$idCliente'"); //REEMPLAZAR POR $idCliente           

        if (($sql_notices = $sql->fetch_array(MYSQLI_ASSOC)) <> NULL) {
            //result
            $mail = $sql_notices['Mail'];

            $name = $sql_notices['nombrecliente'];

            //COMPRUEBO QUE NO EXISTA YA LA NOTIFICACION
            $sql_compruebo = $mysqli->query("SELECT id FROM Notifications WHERE idCliente='$idCliente' AND CodigoSeguimiento='$cs' AND State='$st'"); //REEMPLAZAR POR $idCliente                       
            $dato_compruebo = $sql_compruebo->fetch_array(MYSQLI_ASSOC);

            if ($dato_compruebo) {

                echo json_encode(array('success' => 0));
            } else {

                if ($mail) {
                    $sql = "INSERT INTO `Notifications`(`CodigoSeguimiento`, `idCliente`, `Name`, `Mail`, `State`, `Fecha`, `Hora`) VALUES 
                    ('{$cs}','{$idCliente}','{$name}','{$mail}','{$st}','{$Fecha}','{$Hora}')";

                    //UPDATE EN TRANSCLIENTES
                    TransClientes($mysqli, $cs, $st);

                    //INSERT EN SEGUIMIENTO
                    Seguimiento($mysqli, $cs, $st);
                }

                if ($mysqli->query($sql)) {

                    // TransClientes($cs,$st);

                    // Seguimiento($cs,$st);

                    echo json_encode(array('success' => 1, 'mail' => $mail, 'name' => $name, 'id' => $idCliente, 'origen_name' => $sql_customers['RazonSocial']));
                } else {

                    echo json_encode(array('success' => 0));
                }
            }
        } else {

            echo json_encode(array('success' => 0));
        }
    } else {

        echo json_encode(array('success' => 0));
    }
}

function TransClientes(mysqli $mysqli, $codigo, $estado)
{

    //COMPRUEBO SI COINCIDEN LOS ESTADOS
    $sql = $mysqli->query("SELECT id FROM TransClientes WHERE Eliminado=0 AND CodigoSeguimiento='$codigo' AND Estado='$estado'");
    $dato = $sql->fetch_array(MYSQLI_ASSOC);

    if ($dato['id']) {
    } else {

        $mysqli->query("UPDATE TransClientes SET Estado='$estado' WHERE CodigoSeguimiento='$codigo' AND Eliminado='0' LIMIT 1");
    }
}

function Seguimiento(mysqli $mysqli, string $codigo, string $estado): void
{
    $Fecha   = date("Y-m-d");
    $Hora    = date("H:i");
    $Usuario = $_SESSION['Usuario'] ?? 'sistema';
    $Sucursal = $_SESSION['Sucursal'] ?? '';

    // Evitar duplicado por mismo estado
    $stmt = $mysqli->prepare("SELECT id FROM Seguimiento WHERE CodigoSeguimiento=? AND Estado=? LIMIT 1");
    $stmt->bind_param("ss", $codigo, $estado);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->fetch_assoc()) return;

    // Tomo datos base desde TransClientes (ajustá campos si hiciera falta)
    $stmt2 = $mysqli->prepare("SELECT Destinatario AS NombreCompleto, Dni, Domicilio AS Destino, idCliente, id AS idTransClientes, Recorrido 
                               FROM TransClientes 
                               WHERE CodigoSeguimiento=? AND Eliminado=0 LIMIT 1");
    $stmt2->bind_param("s", $codigo);
    $stmt2->execute();
    $tc = $stmt2->get_result()->fetch_assoc();

    $NombreCompleto = $tc['NombreCompleto'] ?? '';
    $Dni            = $tc['Dni'] ?? '';
    $Destino        = $tc['Destino'] ?? '';
    $idCliente      = (int)($tc['idCliente'] ?? 0);
    $idTransClientes = (int)($tc['idTransClientes'] ?? 0);
    $Recorrido      = $tc['Recorrido'] ?? '';

    $stmt3 = $mysqli->prepare("INSERT INTO Seguimiento 
      (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones, Entregado, Estado, NombreCompleto, Dni, Destino, Avisado, idCliente, Retirado, Visitas, idTransClientes, Recorrido, Devuelto, Webhook, state_id, NumerodeOrden, status)
      VALUES (?, ?, ?, ?, ?, '', 0, ?, ?, ?, ?, 0, ?, 0, 0, ?, ?, 0, 0, 0, 0, '')");

    $stmt3->bind_param(
        "ssssssssssisis",
        $Fecha,
        $Hora,
        $Usuario,
        $Sucursal,
        $codigo,
        $estado,
        $NombreCompleto,
        $Dni,
        $Destino,
        $idCliente,
        $idTransClientes,
        $Recorrido
    );
    $stmt3->execute();
}
