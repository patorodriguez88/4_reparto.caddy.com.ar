<?php
//funciones.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 👇 Para que conexioni NO exija sesión web (evitar 401 para la app)
if (!defined('ALLOW_NO_SESSION')) {
  define('ALLOW_NO_SESSION', true);
}

require_once "../../Conexion/conexioni.php";
require_once __DIR__ . '/../../Funciones/estados.php';
require_once __DIR__ . '/eta.php';

$googleConfigPath = __DIR__ . '/../../Conexion/google_config.php';
if (file_exists($googleConfigPath)) {
  require_once $googleConfigPath;
}

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

// Recalcula la ETA de las paradas que quedan pendientes después de una
// entrega/no-entrega, partiendo de la última posición GPS conocida del
// repartidor. Nunca debe romper el flujo de confirmación - recalcularEtas()
// ya se traga sus propios errores, y acá además se verifica que haya key
// y posición antes de intentar.
function dispararRecalculoEta(mysqli $mysqli, string $recorrido, int $idUsuario): void
{
  if ($recorrido === '' || !defined('GOOGLE_API_KEY_SERVER')) {
    return;
  }
  $res = $mysqli->query(
    "SELECT Latitud, Longitud FROM UbicacionRepartidor WHERE idUsuario = {$idUsuario} LIMIT 1"
  );
  $pos = $res ? $res->fetch_assoc() : null;
  if (!$pos) {
    return; // todavía no mandó ningún ping GPS, no hay desde dónde recalcular
  }
  recalcularEtas($mysqli, $recorrido, (float) $pos['Latitud'], (float) $pos['Longitud'], new DateTime());
}

// Insert atómico "si no existe" (INSERT ... SELECT ... WHERE NOT EXISTS).
// Bajo doble-tap real, InnoDB puede resolver la contienda entre dos INSERTs
// casi simultáneos con un deadlock en el que "pierde" (en vez de simplemente
// devolver 0 filas afectadas) - probado con requests concurrentes reales.
// Si eso pasa, en vez de dejar que el deadlock reviente como fatal (pantalla
// de warning para el repartidor), lo tratamos como corresponde: si para
// cuando reconsultamos ya existe la fila (el otro request ganó la carrera),
// es un duplicado bloqueado legítimo; si no existe, reintentamos una vez.
function insertarSiNoExiste(mysqli $mysqli, string $sqlInsert, string $sqlExiste, string $label): bool
{
  for ($intento = 1; $intento <= 2; $intento++) {
    try {
      $mysqli->query($sqlInsert);
      return $mysqli->affected_rows > 0;
    } catch (mysqli_sql_exception $e) {
      $res = $mysqli->query($sqlExiste);
      if ($res && $res->num_rows > 0) {
        // El otro request de la carrera ya lo insertó: duplicado bloqueado.
        return false;
      }
      if ($intento === 2) {
        responder([
          'success' => 0,
          'error'   => "Error SQL {$label}: " . $e->getMessage(),
        ]);
      }
      // intento === 1 y no existe todavía -> no fue una carrera real, reintentar
    }
  }
  return false;
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
    http_response_code(200);
    // echo json_encode(['forceLogout' => true, 'reason' => 'NO_IDUSUARIO', 'success' => 0, 'usuario' => 0, 'error' => 'idUsuario no definido...']);
    responder([
      'success' => 0,
      'logged'  => 0,
      'reason'  => 'NO_IDUSUARIO'
    ]);
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
               AND TransClientes.Entregado = 1
               AND TransClientes.Eliminado = 0",
      'Entregados HojaDeRuta'
    );
    $TotalEntregados = $sqlEntregados->fetch_array(MYSQLI_ASSOC);

    // Km y minutos restantes (para el card resumen) - suma de lo que ya
    // calculó recalcularEtas() sobre las paradas pendientes. Si todavía no
    // se disparó ningún recálculo (recorrido recién armado, no arrancado),
    // KmO/Tiempo están en 0 y se muestra vacío del lado del front.
    $sqlPendiente = consultaOError(
      $mysqli,
      "SELECT SUM(HojaDeRuta.KmO) AS KmPendientes, SUM(HojaDeRuta.Tiempo) AS SegPendientes
             FROM HojaDeRuta
             INNER JOIN TransClientes
                 ON HojaDeRuta.Seguimiento = TransClientes.CodigoSeguimiento
             WHERE HojaDeRuta.Recorrido   = '{$Recorrido}'
               AND HojaDeRuta.Eliminado   = 0
               AND HojaDeRuta.NumerodeOrden = '{$nOrden}'
               AND HojaDeRuta.Devuelto    = 0
               AND TransClientes.Entregado = 0
               AND TransClientes.Eliminado = 0",
      'Pendiente Km/Tiempo HojaDeRuta'
    );
    $Pendiente = $sqlPendiente->fetch_array(MYSQLI_ASSOC);

    $paradasPendientes = (int) $TotalNoEntregados['Cantidad'];
    $timeDelivered = 5.0;
    $sqlVar = $mysqli->query("SELECT Valor FROM Variables WHERE Nombre = 'TiempoPorParada' LIMIT 1");
    $rowVar = $sqlVar ? $sqlVar->fetch_assoc() : null;
    if ($rowVar && is_numeric($rowVar['Valor'])) {
      $timeDelivered = (float) $rowVar['Valor'];
    }
    $minutosTravel = round(((float) ($Pendiente['SegPendientes'] ?? 0)) / 60);
    $minutosDwell  = $paradasPendientes * $timeDelivered;
    $tiempoPendienteMin = (int) ($minutosTravel + $minutosDwell);

    // Hora real de inicio del recorrido (botón "Iniciar Recorrido" o
    // auto-detección por GPS) - NULL si todavía no arrancó.
    $sqlLog = $mysqli->query(
      "SELECT HoraSalidaReal FROM Logistica
       WHERE idUsuarioChofer = '{$idUsuario}' AND Estado = 'Cargada' AND Eliminado = 0
       LIMIT 1"
    );
    $rowLog = $sqlLog ? $sqlLog->fetch_assoc() : null;

    // Ojo: 'Cerrados' alimenta el badge #badge-entregados (rojo) y 'Abiertos'
    // alimenta #badge-sinentregar (verde) en el frontend - antes estaban
    // invertidos y los dos badges mostraban el número del otro.
    responder([
      'success'    => 1,
      'NOrden'       => $nOrden,
      'Recorrido'  => $Recorrido,
      'Total'      => (int) $TotalCantidad['Cantidad'],
      'Cerrados'   => (int) $TotalEntregados['Cantidad'],
      'Abiertos'   => (int) $TotalNoEntregados['Cantidad'],
      'Usuario'    => $Transportista,
      'idUsuario'  => $idUsuario,
      'HoraSalidaReal'     => $rowLog['HoraSalidaReal'] ?? null,
      'KmPendientes'       => round((float) ($Pendiente['KmPendientes'] ?? 0), 1),
      'TiempoPendienteMin' => $tiempoPendienteMin > 0 ? $tiempoPendienteMin : null,
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

// if (isset($_POST['ConfirmoEntrega'])) {
//   ini_set('display_errors', 1);
//   ini_set('display_startup_errors', 1);
//   error_reporting(E_ALL);
//   // 1) Primero lo traés del POST
//   $CodigoRaw = $_POST['Cs'] ?? '';
//   $CodigoRaw = trim($CodigoRaw);

//   if ($CodigoRaw === '') {
//     responder(['success' => 0, 'error' => 'Falta Cs (CodigoSeguimiento)']);
//   }

//   // 2) Después normalizás (por si un día Cs viniera con _n)
//   $CodigoSeguimiento = explode('_', $CodigoRaw)[0]; // BASE sin _n

//   $dni               = $_POST['Dni']  ?? '';
//   $nombre2           = $_POST['Name'] ?? '';
//   $Observaciones     = $_POST['Obs']  ?? '';
//   // $Retirado          = isset($_POST['Retirado']) ? (int) $_POST['Retirado'] : 0;
//   $Etiquetas         = isset($_POST['Etiquetas']) && is_array($_POST['Etiquetas'])
//     ? $_POST['Etiquetas']
//     : [];

//   // ID de HojaDeRuta
//   $sqlhdr = consultaOError(
//     $mysqli,
//     "SELECT id FROM HojaDeRuta WHERE Seguimiento = '{$CodigoSeguimiento}' LIMIT 1",
//     'HojaDeRuta por Seguimiento'
//   );
//   $id = $sqlhdr->fetch_array(MYSQLI_ASSOC) ?: ['id' => null];


//   // Localización base
//   $sqlLocalizacion = consultaOError(
//     $mysqli,
//     "SELECT ClienteDestino,DomicilioDestino,LocalidadDestino,Redespacho,IngBrutosOrigen,Retirado 
//          FROM TransClientes 
//          WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'",
//     'Localizacion TransClientes'
//   );
//   $sqlLocalizacionR = $sqlLocalizacion->fetch_array(MYSQLI_ASSOC) ?: [];


//   // ✅ Datos base del envío (SIEMPRE) para evitar variables indefinidas
//   $sqlBase = consultaOError(
//     $mysqli,
//     "SELECT id, idClienteDestino, ClienteDestino, DomicilioDestino, NumerodeOrden, RazonSocial, DomicilioOrigen, Recorrido
//    FROM TransClientes
//    WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'
//      AND Eliminado = 0
//    ORDER BY id DESC
//    LIMIT 1",
//     'TransClientes Base ConfirmoEntrega'
//   );
//   $rowBase = $sqlBase->fetch_array(MYSQLI_ASSOC) ?: [];

//   $idTransClientes   = (int)($rowBase['id'] ?? 0);
//   $idClienteDestino  = (int)($rowBase['idClienteDestino'] ?? 0);
//   $NumerodeOrdenTC   = (string)($rowBase['NumerodeOrden'] ?? '');
//   $RecorridoTC       = (string)($rowBase['Recorrido'] ?? $Recorrido);

//   // fallback del NumeroOrden si en sesión no viene
//   if (empty($NumeroOrden) && $NumerodeOrdenTC !== '') {
//     $NumeroOrden = $NumerodeOrdenTC;
//   }

//   // Si no encontramos el envío, abortamos
//   if ($idTransClientes === 0) {
//     responder(['success' => 0, 'error' => 'No se encontró TransClientes para confirmar', 'cs' => $CodigoSeguimiento]);
//   }

//   $Localizacion = ($sqlLocalizacionR['DomicilioDestino'] ?? '');
//   $Retirado = (int)($sqlLocalizacionR['Retirado']);

//   // Número de visita
//   $sqlvisita = consultaOError(
//     $mysqli,
//     "SELECT MAX(Visitas) AS Visita 
//          FROM Seguimiento 
//          WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'",
//     'MAX Visitas Seguimiento'
//   );
//   $visita  = $sqlvisita->fetch_array(MYSQLI_ASSOC) ?: ['Visita' => 0];
//   $Visita  = (int) $visita['Visita'] + 1;

//   // ----------------------------------------
//   // Resolver flujo y ubicación sin re-consultar
//   // ----------------------------------------
//   $Recorrido = $RecorridoTC;
//   $Redespacho = (int)($sqlLocalizacionR['Redespacho'] ?? 0);

//   if ($Retirado == 1) {

//     // ENTREGA
//     if ($Redespacho === 0) {
//       $Entregado = 1;
//       $status = 'delivered';
//     } else {
//       // ENTREGA pero redespacho => vuelve al origen / last_mile
//       $Entregado = 0;
//       $status = 'last_mile';
//       $Localizacion = (string)($rowBase['DomicilioOrigen'] ?? '');
//     }

//     $st = estadoPorSlug($mysqli, $status);
//     $Estado_id = (int)($st['id'] ?? 0);
//     $Estado    = (string)($st['Estado'] ?? '');

//     // Anti duplicado entregado (solo si delivered)
//     if ($Entregado === 1) {
//       $resultado = $mysqli->query(
//         "SELECT 1
//        FROM Seguimiento
//        WHERE CodigoSeguimiento = '{$CodigoSeguimiento}'
//          AND Entregado = 1
//          AND Estado = '{$Estado}'
//          AND (Eliminado IS NULL OR Eliminado = 0)
//        LIMIT 1"
//       );
//       if ($resultado && $resultado->num_rows > 0) {
//         responder(['success' => 0, 'error' => 'Este pedido ya fue marcado como entregado.']);
//       }
//     }
//   } else {

//     // RETIRO (colecta)
//     $Entregado = 0;
//     $Retirado  = 1;

//     $status = 'pickup_ready';
//     $st = estadoPorSlug($mysqli, $status);
//     $Estado_id = (int)($st['id'] ?? 0);
//     $Estado    = (string)($st['Estado'] ?? '');

//     $Localizacion = (string)($rowBase['DomicilioOrigen'] ?? '');
//   }

//   // Insert en Seguimiento
//   consultaOError(
//     $mysqli,
//     "INSERT INTO Seguimiento
//             (Eliminado,idCliente,Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,
//              NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden,state_id,status)
//          VALUES
//             ('0','{$idClienteDestino}','{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$CodigoSeguimiento}','{$Observaciones}',
//              '{$Entregado}','{$Estado}','{$nombre2}','{$dni}','{$Localizacion}','{$Visita}',
//              '{$Retirado}','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}','{$Estado_id}','{$status}')",
//     'INSERT Seguimiento ConfirmoEntrega'
//   );

//   // Cierro HojaDeRuta / Roadmap si corresponde
//   if (($Retirado == 1) || ($Entregado == 1)) {
//     consultaOError(
//       $mysqli,
//       "UPDATE HojaDeRuta 
//              SET Estado = 'Cerrado' 
//              WHERE Eliminado = 0 AND Seguimiento = '{$CodigoSeguimiento}' 
//              LIMIT 1",
//       'UPDATE HojaDeRuta Cerrado'
//     );

//     consultaOError(
//       $mysqli,
//       "UPDATE Roadmap 
//              SET Estado = 'Cerrado' 
//              WHERE Eliminado = 0 AND Seguimiento = '{$CodigoSeguimiento}' 
//              LIMIT 1",
//       'UPDATE Roadmap Cerrado'
//     );
//   }

//   // Actualizo TransClientes

//   consultaOError(
//     $mysqli,
//     "UPDATE TransClientes 
//          SET Estado        = '{$Estado}',
//              Entregado     = '{$Entregado}',
//              Retirado      = '1',
//              Transportista = '{$Transportista}', 
//              NumerodeOrden = '{$NumeroOrden}',
//              Recorrido     = '{$Recorrido}',
//              idABM         = '{$idUsuario}',
//              infoABM       = '{$infoABM}',
//              FechaEntrega  = '{$Fecha}' 
//          WHERE Eliminado = 0 
//            AND CodigoSeguimiento = '{$CodigoSeguimiento}' 
//          LIMIT 1",
//     'UPDATE TransClientes ConfirmoEntrega'
//   );

//   responder([
//     'success' => 1,
//     'id'      => $id['id'] ?? null,
//     'estado'  => $Estado,
//     'slug'    => $status
//   ]);
// }

if (isset($_POST['ConfirmoEntrega'])) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);

  $CodigoRaw = isset($_POST['Cs']) ? trim((string)$_POST['Cs']) : '';
  if ($CodigoRaw === '') {
    responder(['success' => 0, 'error' => 'Falta Cs (CodigoSeguimiento)']);
  }

  // BASE sin _n
  $CodigoSeguimiento = explode('_', $CodigoRaw)[0];
  $CodigoSeguimiento = trim($CodigoSeguimiento);

  $dni           = isset($_POST['Dni']) ? (string)$_POST['Dni'] : '';
  $nombre2       = isset($_POST['Name']) ? (string)$_POST['Name'] : '';
  $Observaciones = isset($_POST['Obs']) ? (string)$_POST['Obs'] : '';

  // Sanitizado básico para evitar romper SQL (seguís con queries “a mano”)
  $dniEsc    = $mysqli->real_escape_string($dni);
  $nomEsc    = $mysqli->real_escape_string($nombre2);
  $obsEsc    = $mysqli->real_escape_string($Observaciones);
  $csEsc     = $mysqli->real_escape_string($CodigoSeguimiento);

  $Etiquetas = (isset($_POST['Etiquetas']) && is_array($_POST['Etiquetas'])) ? $_POST['Etiquetas'] : [];

  // ID de HojaDeRuta del CS
  $sqlhdr = consultaOError(
    $mysqli,
    "SELECT id FROM HojaDeRuta WHERE Seguimiento = '{$csEsc}' LIMIT 1",
    'HojaDeRuta por Seguimiento'
  );
  $id = $sqlhdr->fetch_array(MYSQLI_ASSOC) ?: ['id' => null];

  // Localización + Retirado real del CS
  $sqlLocalizacion = consultaOError(
    $mysqli,
    "SELECT ClienteDestino,DomicilioDestino,LocalidadDestino,Redespacho,IngBrutosOrigen,Retirado 
     FROM TransClientes 
     WHERE CodigoSeguimiento = '{$csEsc}'
     ORDER BY id DESC
     LIMIT 1",
    'Localizacion TransClientes'
  );
  $sqlLocalizacionR = $sqlLocalizacion->fetch_array(MYSQLI_ASSOC) ?: [];

  // Datos base del CS
  $sqlBase = consultaOError(
    $mysqli,
    "SELECT id, idClienteDestino, ClienteDestino, DomicilioDestino, NumerodeOrden, RazonSocial, DomicilioOrigen, Recorrido
     FROM TransClientes
     WHERE CodigoSeguimiento = '{$csEsc}'
       AND Eliminado = 0
     ORDER BY id DESC
     LIMIT 1",
    'TransClientes Base ConfirmoEntrega'
  );
  $rowBase = $sqlBase->fetch_array(MYSQLI_ASSOC) ?: [];

  $idTransClientes   = (int)($rowBase['id'] ?? 0);
  $idClienteDestino  = (int)($rowBase['idClienteDestino'] ?? 0);
  $NumerodeOrdenTC   = (string)($rowBase['NumerodeOrden'] ?? '');
  $RecorridoTC       = (string)($rowBase['Recorrido'] ?? $Recorrido);

  if (empty($NumeroOrden) && $NumerodeOrdenTC !== '') {
    $NumeroOrden = $NumerodeOrdenTC;
  }

  if ($idTransClientes === 0) {
    responder(['success' => 0, 'error' => 'No se encontró TransClientes para confirmar', 'cs' => $CodigoSeguimiento]);
  }

  $Localizacion = (string)($sqlLocalizacionR['DomicilioDestino'] ?? '');
  $RetiradoDB   = (int)($sqlLocalizacionR['Retirado'] ?? 0); // 👈 este manda el flujo
  $Redespacho   = (int)($sqlLocalizacionR['Redespacho'] ?? 0);

  // Visitas del CS
  $sqlvisita = consultaOError(
    $mysqli,
    "SELECT MAX(Visitas) AS Visita 
     FROM Seguimiento 
     WHERE CodigoSeguimiento = '{$csEsc}'",
    'MAX Visitas Seguimiento'
  );
  $visita  = $sqlvisita->fetch_array(MYSQLI_ASSOC) ?: ['Visita' => 0];
  $Visita  = (int)$visita['Visita'] + 1;

  // Resolver recorrido final
  $Recorrido = $RecorridoTC;

  // ==========================================================
  // ✅ MODO MULTI: si ES RETIRO (RetiradoDB==0) y vienen Etiquetas[]
  // ==========================================================
  $basesEtiquetas = [];
  if (!empty($Etiquetas)) {
    foreach ($Etiquetas as $et) {
      $et = trim((string)$et);
      if ($et === '') continue;
      $b = explode('_', $et)[0];
      $b = trim($b);
      if ($b !== '') $basesEtiquetas[] = $b;
    }
    $basesEtiquetas = array_values(array_unique($basesEtiquetas));
  }

  $isRetiro = ($RetiradoDB == 0);
  $isMulti  = ($isRetiro && count($basesEtiquetas) > 0);

  if ($isMulti) {
    // 1) Padre: pickup_ready (una vez)
    $Entregado = 0;
    $Retirado  = 1; // lo marcamos “retirado realizado”
    $status = 'pickup_ready';

    $st = estadoPorSlug($mysqli, $status);
    $Estado_id = (int)($st['id'] ?? 0);
    $Estado    = (string)($st['Estado'] ?? '');

    $Localizacion = (string)($rowBase['DomicilioOrigen'] ?? '');

    // evitar duplicado exacto (cs + status)
    $chkPadre = consultaOError(
      $mysqli,
      "SELECT 1 FROM Seguimiento 
       WHERE CodigoSeguimiento='{$csEsc}' 
         AND status='{$status}' 
         AND (Eliminado IS NULL OR Eliminado=0)
       LIMIT 1",
      'CHK padre pickup_ready'
    );

    if ($chkPadre->num_rows == 0) {
      consultaOError(
        $mysqli,
        "INSERT INTO Seguimiento
          (Eliminado,idCliente,Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,
           NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden,state_id,status)
         VALUES
          ('0','{$idClienteDestino}','{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$csEsc}','{$obsEsc}',
           '0','{$Estado}','{$nomEsc}','{$dniEsc}','{$Localizacion}','{$Visita}',
           '1','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}','{$Estado_id}','{$status}')",
        'INSERT Seguimiento Padre pickup_ready'
      );
    }
    consultaOError(
      $mysqli,
      "UPDATE TransClientes
   SET Estado        = '{$Estado}',
       Entregado     = 0,
       Retirado      = 1,
       Transportista = '{$Transportista}',
       NumerodeOrden = '{$NumeroOrden}',
       Recorrido     = '{$Recorrido}',
       idABM         = '{$idUsuario}',
       infoABM       = '{$infoABM}',
       FechaEntrega  = '{$Fecha}'
   WHERE Eliminado = 0
     AND CodigoSeguimiento = '{$csEsc}'
   LIMIT 1",
      'UPDATE TransClientes Padre retiro_multi'
    );

    // 2) Hijos: pickup_scanned por cada base
    $statusItem = 'pickup_scanned';
    $stItem = estadoPorSlug($mysqli, $statusItem);
    $EstadoItem_id = (int)($stItem['id'] ?? 0);
    $EstadoItem    = (string)($stItem['Estado'] ?? '');

    $insertados = 0;
    $omitidos   = 0;
    $noEncontrados = [];

    foreach ($basesEtiquetas as $baseCS) {
      $baseCS = strtoupper(trim($baseCS));
      if ($baseCS === '') continue;

      $baseEsc = $mysqli->real_escape_string($baseCS);

      // buscar TransClientes por base
      $sqlTC = consultaOError(
        $mysqli,
        "SELECT id, idClienteDestino, DomicilioOrigen, Recorrido
         FROM TransClientes
         WHERE Eliminado=0
           AND SUBSTRING_INDEX(CodigoSeguimiento,'_',1) = '{$baseEsc}'
         ORDER BY id DESC
         LIMIT 1",
        'TC por base etiqueta'
      );
      $tc = $sqlTC->fetch_array(MYSQLI_ASSOC) ?: [];
      $idTC  = (int)($tc['id'] ?? 0);

      if ($idTC <= 0) {
        $noEncontrados[] = $baseCS;
        continue;
      }

      $idCli = (int)($tc['idClienteDestino'] ?? 0);
      $loc   = (string)($tc['DomicilioOrigen'] ?? '');
      $rec   = (string)($tc['Recorrido'] ?? $Recorrido);

      $locEsc = $mysqli->real_escape_string($loc);
      $recEsc = $mysqli->real_escape_string($rec);

      // anti duplicado por base + status
      $chkItem = consultaOError(
        $mysqli,
        "SELECT 1 FROM Seguimiento
         WHERE CodigoSeguimiento='{$baseEsc}'
           AND status='{$statusItem}'
           AND (Eliminado IS NULL OR Eliminado=0)
         LIMIT 1",
        'CHK item pickup_scanned'
      );
      if ($chkItem->num_rows > 0) {
        $omitidos++;
        continue;
      }

      consultaOError(
        $mysqli,
        "INSERT INTO Seguimiento
          (Eliminado,idCliente,Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,
           NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden,state_id,status)
         VALUES
          ('0','{$idCli}','{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$baseEsc}','{$obsEsc}',
           '0','{$EstadoItem}','{$nomEsc}','{$dniEsc}','{$locEsc}','1',
           '1','{$idTC}','{$recEsc}','{$EstadoItem_id}','{$NumeroOrden}','{$EstadoItem_id}','{$statusItem}')",
        'INSERT Seguimiento Item pickup_scanned'
      );

      // ✅ actualizar TransClientes de ese item (marca retiro)
      consultaOError(
        $mysqli,
        "UPDATE TransClientes
         SET Estado        = '{$EstadoItem}',
             Entregado     = 0,
             Retirado      = 1,
             Transportista = '{$Transportista}', 
             NumerodeOrden = '{$NumeroOrden}',
             Recorrido     = '{$recEsc}',
             idABM         = '{$idUsuario}',
             infoABM       = '{$infoABM}',
             FechaEntrega  = '{$Fecha}'
         WHERE Eliminado=0
           AND SUBSTRING_INDEX(CodigoSeguimiento,'_',1) = '{$baseEsc}'",
        'UPDATE TransClientes Item retiro'
      );

      // ✅ cerrar HojaDeRuta / Roadmap del item (si existen)
      consultaOError(
        $mysqli,
        "UPDATE HojaDeRuta
         SET Estado='Cerrado'
         WHERE Eliminado=0 AND Seguimiento='{$baseEsc}'
         LIMIT 1",
        'UPDATE HojaDeRuta Item Cerrado'
      );
      consultaOError(
        $mysqli,
        "UPDATE Roadmap
         SET Estado='Cerrado'
         WHERE Eliminado=0 AND Seguimiento='{$baseEsc}'
         LIMIT 1",
        'UPDATE Roadmap Item Cerrado'
      );

      $insertados++;
    }

    responder([
      'success' => 1,
      'modo' => 'RETIRO_MULTI',
      'padre' => $CodigoSeguimiento,
      'items' => $basesEtiquetas,
      'insertados' => $insertados,
      'omitidos' => $omitidos,
      'noEncontrados' => $noEncontrados,
      'estado' => $EstadoItem,
      'slug' => $statusItem
    ]);
  }

  // ==========================================================
  // ✅ MODO NORMAL (tu lógica original)
  // ==========================================================
  if ($RetiradoDB == 1) {
    // ENTREGA
    if ($Redespacho === 0) {
      $Entregado = 1;
      $status = 'delivered';
    } else {
      $Entregado = 0;
      $status = 'last_mile';
      $Localizacion = (string)($rowBase['DomicilioOrigen'] ?? '');
    }

    $st = estadoPorSlug($mysqli, $status);
    $Estado_id = (int)($st['id'] ?? 0);
    $Estado    = (string)($st['Estado'] ?? '');

    $Retirado = 1; // ya estaba entregable
  } else {
    // RETIRO normal (sin etiquetas)
    $Entregado = 0;
    $Retirado  = 1;
    $status = 'pickup_ready';

    $st = estadoPorSlug($mysqli, $status);
    $Estado_id = (int)($st['id'] ?? 0);
    $Estado    = (string)($st['Estado'] ?? '');

    $Localizacion = (string)($rowBase['DomicilioOrigen'] ?? '');
  }

  // Insert en Seguimiento (solo CS)
  if ($Entregado === 1) {
    // INSERT atómico: el propio INSERT es la verificación (WHERE NOT EXISTS),
    // así dos requests casi simultáneos (doble-tap) no pueden pasar los dos
    // la comprobación antes de que cualquiera inserte, como sí podía pasar
    // con el SELECT-y-después-INSERT en dos pasos de antes.
    $sqlExisteEntregado = "SELECT 1 FROM Seguimiento
         WHERE CodigoSeguimiento = '{$csEsc}'
           AND Entregado = 1
           AND (Eliminado IS NULL OR Eliminado = 0)";

    $insertoNuevo = insertarSiNoExiste(
      $mysqli,
      "INSERT INTO Seguimiento
        (Eliminado,idCliente,Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,
         NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden,state_id,status)
       SELECT
        '0','{$idClienteDestino}','{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$csEsc}','{$obsEsc}',
        '{$Entregado}','{$Estado}','{$nomEsc}','{$dniEsc}','{$Localizacion}','{$Visita}',
        '{$Retirado}','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}','{$Estado_id}','{$status}'
       FROM DUAL
       WHERE NOT EXISTS ({$sqlExisteEntregado})",
      $sqlExisteEntregado,
      'INSERT Seguimiento ConfirmoEntrega'
    );

    if (!$insertoNuevo) {
      responder(['success' => 0, 'error' => 'Este pedido ya fue marcado como entregado.']);
    }
  } else {
    consultaOError(
      $mysqli,
      "INSERT INTO Seguimiento
        (Eliminado,idCliente,Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,
         NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden,state_id,status)
       VALUES
        ('0','{$idClienteDestino}','{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$csEsc}','{$obsEsc}',
         '{$Entregado}','{$Estado}','{$nomEsc}','{$dniEsc}','{$Localizacion}','{$Visita}',
         '{$Retirado}','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}','{$Estado_id}','{$status}')",
      'INSERT Seguimiento ConfirmoEntrega'
    );
  }

  if (($Retirado == 1) || ($Entregado == 1)) {
    consultaOError(
      $mysqli,
      "UPDATE HojaDeRuta 
       SET Estado = 'Cerrado' 
       WHERE Eliminado = 0 AND Seguimiento = '{$csEsc}' 
       LIMIT 1",
      'UPDATE HojaDeRuta Cerrado'
    );

    consultaOError(
      $mysqli,
      "UPDATE Roadmap 
       SET Estado = 'Cerrado' 
       WHERE Eliminado = 0 AND Seguimiento = '{$csEsc}' 
       LIMIT 1",
      'UPDATE Roadmap Cerrado'
    );
  }

  // ⚠️ OJO: antes vos siempre seteabas Retirado='1'. Acá lo dejo igual que tu lógica:
  consultaOError(
    $mysqli,
    "UPDATE TransClientes 
     SET Estado        = '{$Estado}',
         Entregado     = '{$Entregado}',
         Retirado      = '{$Retirado}',
         Transportista = '{$Transportista}', 
         NumerodeOrden = '{$NumeroOrden}',
         Recorrido     = '{$Recorrido}',
         idABM         = '{$idUsuario}',
         infoABM       = '{$infoABM}',
         FechaEntrega  = '{$Fecha}' 
     WHERE Eliminado = 0 
       AND CodigoSeguimiento = '{$csEsc}' 
     LIMIT 1",
    'UPDATE TransClientes ConfirmoEntrega'
  );

  dispararRecalculoEta($mysqli, (string) $Recorrido, (int) $idUsuario);

  responder([
    'success' => 1,
    'id'      => $id['id'] ?? null,
    'estado'  => $Estado,
    'slug'    => $status
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
  $status = '1st_visit_fail';
  $st = estadoPorSlug($mysqli, $status); // o 'entregado_cliente'
  $Estado_id = (int)$st['id'];
  $Estado    = $st['Estado'];

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

  // Recorrido REAL del repartidor (antes de que $Recorrido se pise con '80'
  // /Depósito unas líneas arriba) - hace falta para recalcular la ETA de
  // las paradas que le quedan a ÉL, no al depósito.
  $recorridoActivo = (string) ($datossqlTransClientes['Recorrido'] ?? '');

  // Insert Seguimiento (atómico: WHERE NOT EXISTS evita el duplicado del
  // doble-tap dentro de una ventana de 30s, pero sí permite una nueva
  // "No Entrega" legítima del mismo código en un intento posterior)
  $csEscNoEntrega = $mysqli->real_escape_string($CodigoSeguimiento);
  $sqlExisteNoEntrega = "SELECT 1 FROM Seguimiento
           WHERE CodigoSeguimiento = '{$csEscNoEntrega}'
             AND status = '1st_visit_fail'
             AND (Eliminado IS NULL OR Eliminado = 0)
             AND TimeStamp > (NOW() - INTERVAL 30 SECOND)";

  $insertoNuevo = insertarSiNoExiste(
    $mysqli,
    "INSERT INTO Seguimiento
            (Fecha,Hora,Usuario,Sucursal,CodigoSeguimiento,Observaciones,Entregado,Estado,
             NombreCompleto,Dni,Destino,Visitas,Retirado,idTransClientes,Recorrido,Estado_id,NumerodeOrden,state_id,status)
         SELECT
            '{$Fecha}','{$Hora}','{$Usuario}','{$Sucursal}','{$CodigoSeguimiento}','{$Observaciones}',
             '{$Entregado}','{$Estado}','{$nombre2}','{$dni}','{$Localizacion}','{$Visita}',
             '{$Retirado}','{$idTransClientes}','{$Recorrido}','{$Estado_id}','{$NumeroOrden}','{$Estado_id}','{$status}'
         FROM DUAL
         WHERE NOT EXISTS ({$sqlExisteNoEntrega})",
    $sqlExisteNoEntrega,
    'INSERT Seguimiento NoEntrega'
  );

  if (!$insertoNuevo) {
    responder(['success' => 0, 'error' => 'Esta no entrega ya fue registrada.']);
  }

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

  dispararRecalculoEta($mysqli, $recorridoActivo, (int) $idUsuario);

  responder([
    'success' => 1,
    'id'      => $id['id'] ?? null,
    'estado'  => $Estado,
    'slug'    => $status
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
                idClienteDestino,
                CodigoSeguimiento,
                Observaciones,
                Retirado,
                Cantidad,
                idColecta 
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
