<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);


include_once "conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');

header('Content-Type: application/json; charset=utf-8');

// CERRAR SESIÓN
if (isset($_POST['Salir'])) {
    session_destroy();
    echo json_encode(array('success' => 1));
    exit;
}

// LOGIN
if (!empty($_POST['user'])) {

    // OJO: en serio deberías usar consultas preparadas,
    // pero te lo dejo similar a lo tuyo por compatibilidad.
    $user     = $mysqli->real_escape_string($_POST['user']);
    $password = $mysqli->real_escape_string($_POST['password']);

    $sql = "
        SELECT *
        FROM usuarios
        WHERE Usuario = '$user'
          AND PASSWORD = '$password'
          AND ACTIVO = '1'
          AND Nivel = '3'
        LIMIT 1
    ";

    $rec = $mysqli->query($sql);
    $row = $rec ? $rec->fetch_array(MYSQLI_ASSOC) : null;

    if (!empty($row) && !empty($row['id'])) {

        $Fecha     = date('Y-m-d');
        $Hora      = date('H:i');
        $ipCliente = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

        $mysqli->query("
            INSERT INTO Ingresos (idUsuario, Nombre, Fecha, Hora, ip, UserAgent)
            VALUES (
                '{$row['id']}',
                '{$row['Usuario']}',
                '{$Fecha}',
                '{$Hora}',
                '{$ipCliente}',
                '{$userAgent}'
            )
        ");

        // TRANSPORTISTA
        $idUsuario = (int)$row['id'];

        $sql_usuario = $mysqli->query("
            SELECT NombreCompleto
            FROM Empleados
            WHERE Usuario = '$idUsuario'
            LIMIT 1
        ");
        $Usuario = $sql_usuario ? $sql_usuario->fetch_array(MYSQLI_ASSOC) : array('NombreCompleto' => '');

        $_SESSION['Transportista']  = $Usuario['NombreCompleto'];
        $_SESSION['idusuario']      = $row['id'];
        $_SESSION['ingreso']        = $row['Usuario'];
        $_SESSION['NCliente']       = $row['NdeCliente'];
        $_SESSION['Nivel']          = $row['NIVEL'];
        $_SESSION['Direccion']      = $row['Direccion'];
        $_SESSION['NombreUsuario']  = $row['Nombre'];
        $_SESSION['Usuario']        = $row['Usuario'];
        $_SESSION['Sucursal']       = $row['Sucursal'];

        // BUSCO RECORRIDO ASIGNADO
        $sqlC = $mysqli->query("
            SELECT *
            FROM Logistica
            WHERE idUsuarioChofer = {$idUsuario}
              AND Estado = 'Cargada'
              AND Eliminado = '0'
            LIMIT 1
        ");
        $Dato = $sqlC ? $sqlC->fetch_array(MYSQLI_ASSOC) : null;

        $recorridoAsignado = $Dato ? $Dato['Recorrido'] : '';
        $_SESSION['RecorridoAsignado'] = $recorridoAsignado;
        $_SESSION['hdr']               = $Dato ? $Dato['NumerodeOrden'] : '';

        // BUSCO LOS ENVIOS PENDIENTES EN HOJA DE RUTA PARA EL RECORRIDO
        $rows = array();

        if ($recorridoAsignado !== '') {
            $sql_hdr = $mysqli->query("
                SELECT Seguimiento, TransClientes.Retirado
                FROM HojaDeRuta
                INNER JOIN TransClientes
                    ON HojaDeRuta.idTransClientes = TransClientes.id
                WHERE HojaDeRuta.Eliminado = 0
                  AND HojaDeRuta.Devuelto = 0
                  AND HojaDeRuta.Estado = 'Abierto'
                  AND HojaDeRuta.Recorrido = '$recorridoAsignado'
            ");

            if ($sql_hdr) {
                while ($dato_hdr = $sql_hdr->fetch_array(MYSQLI_ASSOC)) {
                    $rows[] = $dato_hdr;
                }
            }
        }

        echo json_encode(array(
            'success' => 1,
            'codigos' => $rows
        ));
        exit;
    } else {
        // Usuario o password inválidos
        echo json_encode(array(
            'success' => 0,
            'user'    => null
        ));
        exit;
    }
} else {

    // No llegó user por POST
    echo json_encode(array(
        'success' => 0,
        'user'    => null
    ));
    exit;
}
