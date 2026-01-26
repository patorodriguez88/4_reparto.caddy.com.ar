<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once "../../Conexion/conexioni.php";

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

    exit; // 👈 por prolijidad, cortamos también


}

if (isset($_POST['Paneles'])) {
    // $recorrido = $_SESSION['RecorridoAsignado'];
    // $usuario = $_SESSION['NombreUsuario'];
    $recorrido = $_SESSION['RecorridoAsignado'] ?? '';
    $usuario = $_SESSION['NombreUsuario'] ?? 'Repartidor';
    $fechaHoy = date('Y-m-d');

    // Pre-cargar asignaciones
    $asignaciones = [];
    $resAsignaciones = $mysqli->query("SELECT * FROM Asignaciones WHERE Fecha='$fechaHoy'");
    while ($asig = $resAsignaciones->fetch_assoc()) {
        $asignaciones[$asig['idProveedor']][$asig['Relacion']][] = $asig;
    }

    // Pre-cargar productos de asignaciones
    $asigProductos = [];
    $resProductos = $mysqli->query("SELECT * FROM AsignacionesProductos");
    while ($prod = $resProductos->fetch_assoc()) {
        $asigProductos[$prod['CodigoProducto']][$prod['Relacion']] = $prod;
    }

    // Consulta principal optimizada con JOINs
    // $filtro = ($_POST['search'] != '') ? "AND HojaDeRuta.Cliente LIKE '%" . $mysqli->real_escape_string($_POST['search']) . "%'" : "";
    $busqueda = isset($_POST['search']) ? $mysqli->real_escape_string($_POST['search']) : '';
    $filtro = ($busqueda != '') ? "AND HojaDeRuta.Cliente LIKE '%$busqueda%'" : '';

    $query = "SELECT TransClientes.*, HojaDeRuta.id as hdrid, HojaDeRuta.Cliente, HojaDeRuta.Posicion, HojaDeRuta.Posicion_retiro,
                   HojaDeRuta.Estado, HojaDeRuta.Devuelto, Clientes.idProveedor, Clientes.nombrecliente,
                   Clientes.ActivarCoordenadas, Clientes.Latitud, Clientes.Longitud, Clientes.Observaciones
            FROM HojaDeRuta
            INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
            LEFT JOIN Clientes ON Clientes.id = IF(TransClientes.Retirado=0, TransClientes.ingBrutosOrigen, TransClientes.idClienteDestino)
            WHERE HojaDeRuta.Estado='Abierto' AND HojaDeRuta.Devuelto=0 AND TransClientes.Eliminado=0 AND HojaDeRuta.Eliminado=0
              AND HojaDeRuta.Recorrido='$recorrido' $filtro
            ORDER BY IF(TransClientes.Retirado=1, HojaDeRuta.Posicion, HojaDeRuta.Posicion_retiro)";

    $result = $mysqli->query($query);

    while ($row = $result->fetch_assoc()) {
        $retirado = $row['Retirado'];
        $color = $retirado ? 'success' : 'warning';
        $icon = $retirado ? 'up-bold' : 'down-bold';
        $servicio = $retirado ? 'Entrega' : 'Retiro';
        $serviciowp = $retirado ? 'entregar' : 'retirar';
        $nombreCliente = $retirado ? $row['ClienteDestino'] : $row['RazonSocial'];
        $direccion = $retirado ? $row['DomicilioDestino'] : $row['DomicilioOrigen'];
        $direccionMapa = ($row['ActivarCoordenadas'] == 1) ? ($row['Latitud'] . ',' . $row['Longitud']) : $direccion;
        $telefono = $retirado ? $row['TelefonoDestino'] : $row['TelefonoOrigen'];
        $contacto = (strlen($telefono) >= 10) ? (substr($telefono, 0, 2) != '54' ? '54' . $telefono : $telefono) : '';
        $veocel = ($contacto != '') ? 1 : 0;

        $idProv = $row['idProveedor'];
        // $relacion = $row['IngBrutosOrigen'];
        $relacion = $row['IngBrutosOrigen'] ?? '';
        $codSeguimiento = $row['CodigoSeguimiento'];

        $listaAsignaciones = $asignaciones[$idProv][$relacion] ?? [];

?>
        <div class="col-xl-7">
            <div class="card">
                <div class="card-body border border-<?= $color ?>">
                    <h2 class="header-title mb-1 text-<?= $color ?>">
                        <?= $row['Posicion'] ?> <i class="mdi mdi-arrow-<?= $icon ?>"></i> <?= $servicio ?> | <?= $nombreCliente ?>
                    </h2>
                    <small class="mb-2"><b><?= $retirado ? 'Origen: ' . $row['RazonSocial'] : 'Destino: ' . $row['ClienteDestino'] ?></b></small>

                    <div class="row">
                        <div class="col-md-7">
                            <div data-provide="datepicker-inline" data-date-today-highlight="true" class="calendar-widget"></div>
                        </div>
                        <div class="col-md-5">
                            <ul class="list-unstyled">
                                <?php if ($idProv): ?>
                                    <li>
                                        <p class="text-muted mb-1 font-13"><i class="mdi mdi-account"></i> ID PROVEEDOR</p>
                                        <h5>[<?= $idProv ?>]</h5>
                                    </li>
                                <?php endif; ?>

                                <li>
                                    <p class="text-muted mb-1 font-13"><i class="mdi mdi-calendar"></i> 7:30 AM - 18:00 PM</p>
                                    <h5><i class="mdi mdi-map-marker"></i> <?= $direccion . ' ' . $row['PisoDeptoDestino'] ?></h5>
                                    <small>Observaciones: <?= $row['Observaciones'] ?></small>
                                </li>

                                <li>
                                    <p class="text-muted mb-1 font-13"><i class="mdi mdi-card-account-phone-outline"></i> CONTACTO</p>
                                    <?php if ($veocel): ?>
                                        <h5><?= $contacto ?>
                                            <a style="float:right;margin-right:14%;" href="https://api.whatsapp.com/send?phone=<?= $contacto ?>&text=Hola <?= $nombreCliente ?> !,%20soy <?= $usuario ?>%20de%20Caddy%20Logística%20!%20Estoy%20en%20camino%20para <?= $serviciowp ?>%20tu%20pedido...">
                                                <img src='images/wp.png' width='30' height='30' />
                                            </a>
                                        </h5>
                                    <?php endif; ?>
                                </li>

                                <li>
                                    <p class="text-muted mb-1 font-13"><i class="mdi mdi-card-search-outline"></i> SEGUIMIENTOs</p>
                                    <h5><?= $codSeguimiento ?></h5>
                                </li>

                                <?php if (!empty($listaAsignaciones)): ?>
                                    <li>
                                        <table class="table table-hover table-centered mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Nombre</th>
                                                    <th>Edicion</th>
                                                    <th>Cantidad</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($listaAsignaciones as $asig):
                                                    // $prod = $asigProductos[$asig['CodigoProducto']][$relacion] ?? [];
                                                    $codigo = $asig['CodigoProducto'] ?? '';
                                                    $prod = $asigProductos[$codigo][$relacion] ?? [];
                                                ?>
                                                    <tr>
                                                        <td><?= $prod['Nombre'] ?? 'Sin nombre' ?></td>
                                                        <td><?= $asig['Edicion'] ?></td>
                                                        <td><?= $asig['Cantidad'] ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </li>
                                <?php endif; ?>

                                <?php
                                if (isset($row['CobrarEnvio'])) {
                                    $sqlCobranza = $mysqli->query("SELECT SUM(CobrarEnvio) AS Cobrar FROM Ventas WHERE NumPedido='$codSeguimiento' AND Eliminado=0");
                                    $datos = $sqlCobranza->fetch_assoc();
                                    echo "<span class='badge badge-outline-warning'>Atención! Requiere Cobranza de $ " . number_format($datos['Cobrar'], 2) . "</span>";
                                }
                                ?>
                            </ul>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <a style='margin-left:15%;'><img src='images/wrong.png' width='60' height='60' onclick="verwrong(<?php echo $row['hdrid'] ?>)" /></a>
                            <a style='margin-left:3%;' href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($direccionMapa) ?>" target="_blank"><img src="images/goto.png" width="70" height="70" /></a>
                            <a style='margin-left:6%;'><img src='images/ok.png' width='60' height='60' onclick="verok(<?= $row['hdrid'] ?>)" /></a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
<?php
    }
    exit; // 👈 por prolijidad, cortamos también


}
?>