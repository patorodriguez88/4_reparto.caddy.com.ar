<?php
// Marca el inicio real del recorrido (botón "Iniciar Recorrido") y dispara
// el primer recálculo de ETA desde la posición actual del repartidor.
declare(strict_types=1);

// NOW() de MySQL depende del reloj/timezone del servidor de base de datos,
// no de PHP - HoraSalidaReal se arma acá con PHP para que sea siempre hora
// Argentina real, sin importar cómo esté configurado el servidor de la BD.
date_default_timezone_set('America/Argentina/Buenos_Aires');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => 0, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
  exit;
}

// session_start() la hace conexioni.php, con el nombre de cookie propio del sistema.
require_once __DIR__ . "/../../Conexion/conexioni.php";
require_once __DIR__ . "/../../Funciones/control_escaneo.php";
require_once __DIR__ . "/../../Funciones/bolsines.php";
require_once __DIR__ . "/../../Funciones/salida_recorrido.php";
require_once __DIR__ . "/eta.php";

$googleConfigPath = __DIR__ . "/../../Conexion/google_config.php";
if (file_exists($googleConfigPath)) {
  require_once $googleConfigPath;
}

function responder(array $arr, int $code = 200): void
{
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

$userId = (int) ($_SESSION['idusuario'] ?? 0);
if ($userId <= 0) {
  responder(['success' => 0, 'error' => 'NO_SESSION'], 401);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
  $data = $_POST;
}

$lat = isset($data['lat']) ? (float) $data['lat'] : null;
$lng = isset($data['lng']) ? (float) $data['lng'] : null;
if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
  responder(['success' => 0, 'error' => 'MISSING_COORDS'], 400);
}

$recorrido = (string) ($_SESSION['RecorridoAsignado'] ?? '');
if ($recorrido === '') {
  responder(['success' => 0, 'error' => 'SIN_RECORRIDO']);
}

// Si el recorrido ya había arrancado (botón tocado de nuevo, app recargada) no se vuelve a controlar:
// lo que la oficina sume después se avisa en Warehouse, no traba al chofer en la calle.
$resYa = $mysqli->query("SELECT HoraSalidaReal FROM Logistica WHERE idUsuarioChofer = {$userId} AND Estado = 'Cargada' AND Eliminado = 0 LIMIT 1");
$yaArranco = $resYa && ($filaYa = $resYa->fetch_assoc()) && !empty($filaYa['HoraSalidaReal']);

// Bolsín interno (switch "Bolsín" en Logística > Recorridos): sin escanearlo no se arranca.
$msgBolsin = $yaArranco ? null : mensajeBolsin(controlBolsines($mysqli, $recorrido, ordenCargadaChofer($mysqli, $userId)));
if ($msgBolsin !== null) {
  registrarBloqueoReparto($mysqli, 'iniciar_recorrido', $recorrido, 'bolsin', [], $msgBolsin);
  responder(['success' => 0, 'error' => 'BOLSIN', 'msg' => $msgBolsin]);
}

// REGLA (23/11/2026, Patricio): no se arranca el recorrido con entregas sin escanear en Warehouse,
// en ningún recorrido. Las tarjetas son solo lo escaneado (y los retiros/colectas, que se escanean en
// el cliente). Si un paquete no está, la oficina lo saca del recorrido o prende "Omitir control de
// escaneo" desde Logística > Órdenes > Control. Cada intento trabado queda en RepartoBloqueos.
// (Del 14/9 al 23/11 esto no bloqueaba: el chofer salía igual y las entregas sin escanear quedaban
// ocultas todo el día; la oficina las terminaba cerrando a mano.)
if (!$yaArranco && !overrideEscaneo($mysqli, $userId)) {
  $faltan = candidatosSinEscaneoWarehouse($mysqli, $recorrido);
  if ($faltan) {
    $detalle = detalleSinEscaneo($mysqli, $faltan);
    $n = count($detalle);
    registrarBloqueoReparto($mysqli, 'iniciar_recorrido', $recorrido, 'sin_escanear', $detalle);
    responder([
      'success'    => 0,
      'error'      => 'WAREHOUSE_PENDIENTE',
      'msg'        => ($n === 1 ? 'Falta escanear 1 entrega' : "Faltan escanear {$n} entregas") . ' en Warehouse antes de arrancar.',
      'pendientes' => $detalle,
    ]);
  }
}

// Sin ninguna parada asignada no tiene sentido arrancar (va después del control de escaneo: si todo
// está sin escanear, el problema es ese y no que "no tenga paradas").
$tarjetas = tarjetasPendientes($mysqli, $recorrido);
if ($tarjetas === 0) {
  responder([
    'success' => 0,
    'error'   => 'SIN_TARJETAS_PENDIENTES',
    'msg'     => 'Este recorrido no tiene ninguna parada asignada todavía. Consultá con la oficina antes de arrancar.',
  ]);
}

try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  $res = $mysqli->query(
    "SELECT id, HoraSalidaReal FROM Logistica
     WHERE idUsuarioChofer = {$userId} AND Estado = 'Cargada' AND Eliminado = 0
     LIMIT 1"
  );
  $log = $res ? $res->fetch_assoc() : null;
  if (!$log) {
    responder(['success' => 0, 'error' => 'SIN_LOGISTICA_ACTIVA']);
  }

  $yaHabiaArrancado = !empty($log['HoraSalidaReal']);

  // Idempotente: si ya había arrancado (por botón o por auto-detección),
  // no pisa la hora real ya registrada.
  $ahora = new DateTime();
  $ahoraEsc = $mysqli->real_escape_string($ahora->format('Y-m-d H:i:s'));
  $latEsc = $mysqli->real_escape_string((string) $lat);
  $lngEsc = $mysqli->real_escape_string((string) $lng);
  $mysqli->query(
    "UPDATE Logistica
     SET HoraSalidaReal = '{$ahoraEsc}',
         LatInicio = COALESCE(LatInicio, {$latEsc}),
         LngInicio = COALESCE(LngInicio, {$lngEsc})
     WHERE id = {$log['id']} AND HoraSalidaReal IS NULL
     LIMIT 1"
  );

  // Todo escaneado (o con "Omitir control"): la salida queda registrada aunque el chofer no haya
  // apretado "Confirmar carga" en Warehouse (En Tránsito + egreso de Wepoint; no duplica).
  try {
    $salida = registrarSalidaEnTransito($mysqli, $recorrido, (string)($_SESSION['Usuario'] ?? ''), (string)($_SESSION['Sucursal'] ?? ''));
  } catch (Throwable $e) {
    error_log('iniciar_recorrido salida: ' . $e->getMessage()); // no frena el arranque
    $salida = [];
  }

  $recalculado = false;
  if (defined('GOOGLE_API_KEY_SERVER')) {
    $recalculado = recalcularEtas($mysqli, $recorrido, $lat, $lng, $ahora);
  }

  responder([
    'success' => 1,
    'yaHabiaArrancado' => $yaHabiaArrancado,
    'recalculado' => $recalculado,
    'salida' => $salida['insertados'] ?? 0,
  ]);
} catch (Throwable $e) {
  responder(['success' => 0, 'error' => 'INICIAR_RECORRIDO_ERROR', 'detail' => $e->getMessage()], 500);
}
