<?php
// 👇 Para que conexioni NO exija sesión web (evitar 401 para la app)
if (!defined('ALLOW_NO_SESSION')) {
  define('ALLOW_NO_SESSION', true);
}

error_reporting(E_ALL);
// IMPORTANT: evitar que warnings/notices rompan respuestas JSON (AJAX/Dropzone)
ini_set('display_errors', 0);

require_once "../../Conexion/conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Asegurar conexión en UTF-8 para evitar "Incorrect string value" (tildes, ñ, etc.)
if (isset($mysqli) && $mysqli instanceof mysqli) {
  @$mysqli->set_charset('utf8mb4');
}

// Helper para responder siempre JSON
function responder(array $data)
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

// Helper para consultas SQL con control de error
function consultaOError(mysqli $mysqli, string $query, string $label)
{
  try {
    $res = $mysqli->query($query);
  } catch (mysqli_sql_exception $e) {
    responder([
      'success' => 0,
      'error'   => "Error SQL {$label}: " . $e->getMessage(),
      'query'   => $label
    ]);
  }

  if (!$res) {
    responder([
      'success' => 0,
      'error'   => "Error SQL {$label}: " . $mysqli->error
    ]);
  }

  return $res;
}

// Variables base
$Fecha = date("Y-m-d");
$Hora  = date("H:i");

// Tomamos datos desde sesión o (si no hay) desde POST, con defaults
$Usuario       = $_SESSION['Usuario']       ?? ($_POST['Usuario']       ?? 'APP');
$idUsuario     = $_SESSION['idusuario']     ?? ($_POST['idUsuario']     ?? 0);
$Sucursal      = $_SESSION['Sucursal']      ?? ($_POST['Sucursal']      ?? '');
$Transportista = $_SESSION['Transportista'] ?? ($_POST['Transportista'] ?? '');
$NumeroOrden   = $_SESSION['hdr']           ?? ($_POST['NumeroOrden']   ?? '');
$Recorrido     = $_SESSION['RecorridoAsignado'] ?? ($_POST['Recorrido'] ?? '');

$infoABM = $Usuario . ' ' . $Fecha . ' ' . $Hora;


// ==================================================
// ================  BLOQUE DATOS  ===================
// ==================================================
if (isset($_POST['Datos'])) {
  $idUsuario = $_SESSION['idusuario'] ?? ($_POST['idUsuario'] ?? 0);

  if (empty($idUsuario)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['forceLogout' => true, 'reason' => 'NO_IDUSUARIO', 'success' => 0, 'usuario' => 0, 'error' => 'idUsuario no definido...']);
    exit;
  }

  // Busco la orden cargada para este chofer
  $sql = consultaOError(
    $mysqli,
    "SELECT NumerodeOrden,Recorrido 
         FROM Logistica 
         WHERE idUsuarioChofer = '{$idUsuario}' 
           AND Estado = 'Cargada' 
           AND Eliminado = 0 
         LIMIT 1",
    'Logistica NumerodeOrden'
  );

  $row = $sql->fetch_array(MYSQLI_ASSOC) ?: [];

  if (!empty($row['NumerodeOrden'])) {

    $nOrden = $row['NumerodeOrden'];
    $Recorrido = $row['Recorrido'];
    // CANTIDAD TOTAL
    $sqlCantidadTotal = consultaOError(
      $mysqli,
      "SELECT COUNT(id) AS Cantidad 
             FROM HojaDeRuta 
             WHERE Recorrido    = '{$Recorrido}'
               AND Eliminado    = 0 
               AND NumerodeOrden = '{$nOrden}' 
               AND Devuelto     = 0",
      'CantidadTotal HojaDeRuta'
    );
    $TotalCantidad = $sqlCantidadTotal->fetch_array(MYSQLI_ASSOC);

    // NO ENTREGADOS
    $sqlNoEntregados = consultaOError(
      $mysqli,
      "SELECT COUNT(HojaDeRuta.id) AS Cantidad 
             FROM HojaDeRuta 
             INNER JOIN TransClientes 
                 ON HojaDeRuta.Seguimiento = TransClientes.CodigoSeguimiento 
             WHERE HojaDeRuta.Recorrido   = '{$Recorrido}' 
               AND HojaDeRuta.Eliminado   = 0 
               AND HojaDeRuta.NumerodeOrden = '{$nOrden}' 
               AND HojaDeRuta.Devuelto    = 0
               AND TransClientes.Entregado = 0 
               AND TransClientes.Eliminado = 0",
      'NoEntregados HojaDeRuta'
    );
    $TotalNoEntregados = $sqlNoEntregados->fetch_array(MYSQLI_ASSOC);

    // ENTREGADOS
    $sqlEntregados = consultaOError(
      $mysqli,
      "SELECT COUNT(HojaDeRuta.id) AS Cantidad 
             FROM HojaDeRuta 
             INNER JOIN TransClientes 
                 ON HojaDeRuta.Seguimiento = TransClientes.CodigoSeguimiento 
             WHERE HojaDeRuta.Recorrido   = '{$Recorrido}' 
               AND HojaDeRuta.Eliminado   = 0 
               AND HojaDeRuta.NumerodeOrden = '{$nOrden}' 
               AND HojaDeRuta.Devuelto    = 0
               AND TransClientes.Entregado = 1",
      'Entregados HojaDeRuta'
    );
    $TotalEntregados = $sqlEntregados->fetch_array(MYSQLI_ASSOC);

    responder([
      'success'    => 1,
      'NOrden'       => $nOrden,
      'Recorrido'  => $Recorrido,
      'Total'      => (int) $TotalCantidad['Cantidad'],
      'Cerrados'   => (int) $TotalNoEntregados['Cantidad'],
      'Abiertos'   => (int) $TotalEntregados['Cantidad'],
      'Usuario'    => $Transportista,
      'idUsuario'  => $idUsuario
    ]);
  } else {
    responder([
      'success' => 2,
      'usuario' => $idUsuario,
      'norden'  => $row['NumerodeOrden'] ?? null
    ]);
  }
}


// ==================================================
// ============  CONFIRMO ENTREGA  ===================
// ==================================================
if (isset($_POST['ConfirmoEntrega'])) {

  // 1) Primero lo traés del POST
  $CodigoRaw = $_POST['Cs'] ?? '';
  $CodigoRaw = trim($CodigoRaw);

  if ($CodigoRaw === '') {
    responder(['success' => 0, 'error' => 'Falta Cs (CodigoSeguimiento)']);
  }

  // 2) Después normalizás (por si un día Cs viniera con _n)
  $CodigoSeguimiento = explode('_', $CodigoRaw)[0]; // BASE sin _n

  $dni               = $_POST['Dni']  ?? '';
  $nombre2           = $_POST['Name'] ?? '';
  $Observaciones     = $_POST['Obs']  ?? '';
  // $Retirado          = isset($_POST['Retirado']) ? (int) $_POST['Retirado'] : 0;
  $Etiquetas         = isset($_POST['Etiquetas']) && is_array($_POST['Etiquetas'])
    ? $_POST['Etiquetas']
    : [];

  // ID de HojaDeRuta
  $sqlhdr = consultaOError(
    $mysqli,
    "SELECT id FROM HojaDeRuta WHERE Seguimiento = '{$CodigoSeguimiento}' LIMIT 1",
    'HojaDeRuta por Seguimiento'
  );
  $id = $sqlhdr->fetch_array(MYSQLI_ASSOC) ?: ['id' => null];

  // Etiquetas
  // foreach ($Etiquetas as $et) {
  //   $et = $mysqli->real_escape_string($et);
  //   $mysqli->query(
  //     "INSERT INTO Etiquetas (CodigoSeguimiento, Observaciones)
  //            VALUES ('{$CodigoSeguimiento}', '{$et}')"
  //   );
  //   // No corto si falla una etiqueta, pero se podría controlar también
  // }

  // Localización base
  $sqlLocalizacion = consultaOError(
    $mysqli,
    "SELECT ClienteDestino,DomicilioDestino,LocalidadDestino,Redespacho,IngBrutosOrigen,Retirado 
         FROM TransClientes 
         WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'",
    'Localizacion TransClientes'
  );
  $sqlLocalizacionR = $sqlLocalizacion->fetch_array(MYSQLI_ASSOC) ?: [];

  $Localizacion = ($sqlLocalizacionR['DomicilioDestino'] ?? '');
  $Retirado = (int)($sqlLocalizacionR['Retirado']);

  // Número de visita
  $sqlvisita = consultaOError(
    $mysqli,
    "SELECT MAX(Visitas) AS Visita 
         FROM Seguimiento 
         WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'",
    'MAX Visitas Seguimiento'
  );
  $visita  = $sqlvisita->fetch_array(MYSQLI_ASSOC) ?: ['Visita' => 0];
  $Visita  = (int) $visita['Visita'] + 1;

  // Lógica de Retirado / Entregado / Redespacho
  if ($Retirado == 1) {

    if (!empty($sqlLocalizacionR) && (int)$sqlLocalizacionR['Redespacho'] === 0) {
      $Entregado = 1;
      $Estado    = 'Entregado al Cliente';
      $Estado_id = 7;

      // Evitar duplicar registro "Entregado al Cliente"
      $resultado = $mysqli->query(
        "SELECT 1 
                 FROM Seguimiento 
                 WHERE CodigoSeguimiento = '{$CodigoSeguimiento}' 
                   AND Entregado = 1
                   AND Estado    = '{$Estado}'
                   AND Eliminado= 0
                 LIMIT 1"
      );

      if ($resultado && $resultado->num_rows > 0) {
        responder([
          'success' => 0,
          'error'   => 'Este pedido ya fue marcado como entregado.'
        ]);
      }
    } else {
      $Entregado = 0;
      $Estado    = 'En Transito';
      $Estado_id = 5;

      $sqlTransClientes = consultaOError(
        $mysqli,
        "SELECT id,RazonSocial,DomicilioOrigen,Recorrido 
                 FROM TransClientes 
                 WHERE CodigoSeguimiento = '{$CodigoSeguimiento}' 
                   AND Eliminado = 0",
        'TransClientes Redespacho Origen'
      );
      $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC) ?: [];

      $NombreCompleto = ($datossqlTransClientes['RazonSocial'] ?? '');
      $Localizacion   = ($datossqlTransClientes['DomicilioOrigen'] ?? '');
      $idTransClientes = $datossqlTransClientes['id'] ?? 0;
      $Recorrido       = $datossqlTransClientes['Recorrido'] ?? $Recorrido;
    }

    // Datos destino
    $sqlTransClientes = consultaOError(
      $mysqli,
      "SELECT id,ClienteDestino,DomicilioDestino,Recorrido 
             FROM TransClientes 
             WHERE CodigoSeguimiento = '{$CodigoSeguimiento}' 
               AND Eliminado = 0",
      'TransClientes Destino'
    );
    $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC) ?: [];

    $NombreCompleto  = ($datossqlTransClientes['ClienteDestino'] ?? '');
    $Localizacion    = ($datossqlTransClientes['DomicilioDestino'] ?? '');
    $idTransClientes = $datossqlTransClientes['id'] ?? 0;
    $Recorrido       = $datossqlTransClientes['Recorrido'] ?? $Recorrido;
  } else {

    // Caso Retiro
    $Entregado = 0;
    $Estado    = 'Retirado del Cliente';
    $Estado_id = 3;

    $sqlTransClientes = consultaOError(
      $mysqli,
      "SELECT id,RazonSocial,DomicilioOrigen,Recorrido 
             FROM TransClientes 
             WHERE CodigoSeguimiento = '{$CodigoSeguimiento}' 
               AND Eliminado = 0",
      'TransClientes Origen Retiro'
    );
    $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC) ?: [];

    $NombreCompleto = $datossqlTransClientes['RazonSocial'] ?? '';
    $Localizacion   = $datossqlTransClientes['DomicilioOrigen'] ?? '';
    $idTransClientes = $datossqlTransClientes['id'] ?? 0;
    $Recorrido       = $datossqlTransClientes['Recorrido'] ?? $Recorrido;
  }

  // Insert en Seguimiento
  consultaOError(
    $mysqli,
    "INSERT INTO Seguimiento
            (Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,
             NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden)
         VALUES
            ('{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$CodigoSeguimiento}','{$Observaciones}',
             '{$Entregado}','{$Estado}','{$nombre2}','{$dni}','{$Localizacion}','{$Visita}',
             '{$Retirado}','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}')",
    'INSERT Seguimiento ConfirmoEntrega'
  );

  // Cierro HojaDeRuta / Roadmap si corresponde
  if (($Retirado == 1) || ($Entregado == 1)) {
    consultaOError(
      $mysqli,
      "UPDATE HojaDeRuta 
             SET Estado = 'Cerrado' 
             WHERE Eliminado = 0 AND Seguimiento = '{$CodigoSeguimiento}' 
             LIMIT 1",
      'UPDATE HojaDeRuta Cerrado'
    );

    consultaOError(
      $mysqli,
      "UPDATE Roadmap 
             SET Estado = 'Cerrado' 
             WHERE Eliminado = 0 AND Seguimiento = '{$CodigoSeguimiento}' 
             LIMIT 1",
      'UPDATE Roadmap Cerrado'
    );
  }

  // Actualizo TransClientes

  consultaOError(
    $mysqli,
    "UPDATE TransClientes 
         SET Estado        = '{$Estado}',
             Entregado     = '{$Entregado}',
             Retirado      = '1',
             Transportista = '{$Transportista}', 
             NumerodeOrden = '{$NumeroOrden}',
             Recorrido     = '{$Recorrido}',
             idABM         = '{$idUsuario}',
             infoABM       = '{$infoABM}',
             FechaEntrega  = '{$Fecha}' 
         WHERE Eliminado = 0 
           AND CodigoSeguimiento = '{$CodigoSeguimiento}' 
         LIMIT 1",
    'UPDATE TransClientes ConfirmoEntrega'
  );

  responder([
    'success' => 1,
    'id'      => $id['id'] ?? null,
    'estado'  => $Estado
  ]);
}


// ==================================================
// ============  CONFIRMO NO ENTREGA  ================
// ==================================================
if (isset($_POST['ConfirmoNoEntrega'])) {

  $CodigoSeguimiento = $_POST['Cs']      ?? '';
  $dni               = $_POST['Dni']     ?? '';
  $nombre2           = $_POST['Name']    ?? '';
  $razones           = $_POST['Razones'] ?? '';
  $Obs               = $_POST['Obs']     ?? '';
  $Retirado          = isset($_POST['Retirado']) ? (int) $_POST['Retirado'] : 0;

  if ($CodigoSeguimiento === '') {
    responder(['success' => 0, 'error' => 'Falta Cs (CodigoSeguimiento)']);
  }

  $Observaciones = trim($razones . ' ' . $Obs);
  $Estado        = 'No se pudo entregar';
  $Estado_id     = 8;

  // ID HojaDeRuta
  $sqlhdr = consultaOError(
    $mysqli,
    "SELECT id FROM HojaDeRuta WHERE Seguimiento = '{$CodigoSeguimiento}' LIMIT 1",
    'HojaDeRuta por Seguimiento (NoEntrega)'
  );
  $id = $sqlhdr->fetch_array(MYSQLI_ASSOC) ?: ['id' => null];

  // Localización base
  $sqlLocalizacion = consultaOError(
    $mysqli,
    "SELECT ClienteDestino,DomicilioDestino,LocalidadDestino,Redespacho,IngBrutosOrigen 
         FROM TransClientes 
         WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'",
    'Localizacion NoEntrega'
  );
  $sqlLocalizacionR = $sqlLocalizacion->fetch_array(MYSQLI_ASSOC) ?: [];
  $Localizacion     = ($sqlLocalizacionR['DomicilioDestino'] ?? '');

  // Visitas
  $sqlvisita = consultaOError(
    $mysqli,
    "SELECT MAX(Visitas) AS Visita 
         FROM Seguimiento 
         WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'",
    'MAX Visitas NoEntrega'
  );
  $visita = $sqlvisita->fetch_array(MYSQLI_ASSOC) ?: ['Visita' => 0];
  $Visita = (int) $visita['Visita'] + 1;

  // Por lo que tenías, seteás Recorrido fijo 80
  $Recorrido = '80';
  $Entregado = 0;

  // Datos cliente según Retirado
  if ($Retirado == 1) {
    $sqlTransClientes = consultaOError(
      $mysqli,
      "SELECT id,ClienteDestino,DomicilioDestino,Recorrido 
             FROM TransClientes 
             WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'",
      'TransClientes Destino NoEntrega'
    );
    $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC) ?: [];

    $NombreCompleto  = ($datossqlTransClientes['ClienteDestino'] ?? '');
    $Localizacion    = ($datossqlTransClientes['DomicilioDestino'] ?? '');
    $idTransClientes = $datossqlTransClientes['id'] ?? 0;
  } else {
    $sqlTransClientes = consultaOError(
      $mysqli,
      "SELECT id,RazonSocial,DomicilioOrigen,Recorrido 
             FROM TransClientes 
             WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'",
      'TransClientes Origen NoEntrega'
    );
    $datossqlTransClientes = $sqlTransClientes->fetch_array(MYSQLI_ASSOC) ?: [];

    $NombreCompleto  = ($datossqlTransClientes['RazonSocial'] ?? '');
    $Localizacion    = ($datossqlTransClientes['DomicilioOrigen'] ?? '');
    $idTransClientes = $datossqlTransClientes['id'] ?? 0;
  }

  // Insert Seguimiento
  consultaOError(
    $mysqli,
    "INSERT IGNORE INTO Seguimiento
            (Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,
             NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden)
         VALUES
            ('{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$CodigoSeguimiento}','{$Observaciones}',
             '{$Entregado}','{$Estado}','{$nombre2}','{$dni}','{$Localizacion}','{$Visita}',
             '{$Retirado}','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}')",
    'INSERT Seguimiento NoEntrega'
  );

  if ($CodigoSeguimiento !== '') {
    // Cierro HojaDeRuta
    consultaOError(
      $mysqli,
      "UPDATE HojaDeRuta 
             SET Estado = 'Cerrado' 
             WHERE Seguimiento = '{$CodigoSeguimiento}' 
             LIMIT 1",
      'UPDATE HojaDeRuta NoEntrega'
    );
    // Cierro Roadmap
    consultaOError(
      $mysqli,
      "UPDATE Roadmap 
             SET Estado = 'Cerrado' 
             WHERE Seguimiento = '{$CodigoSeguimiento}' 
             LIMIT 1",
      'UPDATE Roadmap NoEntrega'
    );
    // Actualizo TransClientes
    consultaOError(
      $mysqli,
      "UPDATE IGNORE TransClientes 
             SET Estado        = '{$Estado}',
                 Entregado     = '{$Entregado}',
                 Transportista = '{$Transportista}',
                 NumerodeOrden = '{$NumeroOrden}',
                 Recorrido     = '{$Recorrido}',
                 idABM         = '{$idUsuario}',
                 infoABM       = '{$infoABM}',
                 FechaEntrega  = '{$Fecha}' 
             WHERE CodigoSeguimiento = '{$CodigoSeguimiento}' 
             LIMIT 1",
      'UPDATE TransClientes NoEntrega'
    );
  }

  responder([
    'success' => 1,
    'id'      => $id['id'] ?? null,
    'estado'  => $Estado
  ]);
}


// ==================================================
// ================  BUSCO DATOS  ====================
// ==================================================
if (isset($_POST['BuscoDatos'])) {

  $idHdr = $_POST['id'] ?? null;
  if (!$idHdr) {
    responder(['success' => 0, 'error' => 'Falta id en BuscoDatos']);
  }

  $sql = consultaOError(
    $mysqli,
    "SELECT Seguimiento FROM HojaDeRuta WHERE id = '{$idHdr}' LIMIT 1",
    'HojaDeRuta por id (BuscoDatos)'
  );
  $row = $sql->fetch_array(MYSQLI_ASSOC) ?: [];

  if (empty($row['Seguimiento'])) {
    responder(['success' => 0, 'error' => 'No se encontró Seguimiento para esa HojaDeRuta']);
  }

  $seguimiento = $row['Seguimiento'];

  $Buscar = consultaOError(
    $mysqli,
    "SELECT id,
                Fecha,
                IF(Retirado = 0, RazonSocial, ClienteDestino) AS NombreCliente,
                IF(Retirado = 0, DomicilioOrigen, DomicilioDestino) AS Domicilio,
                CodigoSeguimiento,
                Observaciones,
                Retirado,
                Cantidad 
         FROM TransClientes 
         WHERE CodigoSeguimiento = '{$seguimiento}'",
    'TransClientes BuscoDatos'
  );

  $rows = [];
  while ($fila = $Buscar->fetch_array(MYSQLI_ASSOC)) {
    $rows[] = $fila;
  }

  responder(['data' => $rows]);
}


// ==================================================
// ================  SUBIR FOTOS  ====================
// ==================================================
if (isset($_POST['SubirFotos'])) {

  if (!isset($_FILES["file"])) {
    responder(['success' => 0, 'error' => 'No se recibieron archivos']);
  }

  foreach ($_FILES["file"]["error"] as $key => $error) {
    if ($error === UPLOAD_ERR_OK) {
      $tmp_name = $_FILES["file"]["tmp_name"][$key];
      $name     = basename($_FILES["file"]["name"][$key]);

      // Podrías agregar una carpeta por seguimiento, etc.
      @move_uploaded_file($tmp_name, "data/{$name}");
    }
  }

  responder(['success' => 1]);
}
