<?php
// Marca el inicio real del recorrido (botón "Iniciar Recorrido") y dispara
// el primer recálculo de ETA desde la posición actual del repartidor.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => 0, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
  exit;
}

// session_start() la hace conexioni.php, con el nombre de cookie propio del sistema.
require_once __DIR__ . "/../../Conexion/conexioni.php";
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
  $latEsc = $mysqli->real_escape_string((string) $lat);
  $lngEsc = $mysqli->real_escape_string((string) $lng);
  $mysqli->query(
    "UPDATE Logistica
     SET HoraSalidaReal = NOW(),
         LatInicio = COALESCE(LatInicio, {$latEsc}),
         LngInicio = COALESCE(LngInicio, {$lngEsc})
     WHERE id = {$log['id']} AND HoraSalidaReal IS NULL
     LIMIT 1"
  );

  $recalculado = false;
  if (defined('GOOGLE_API_KEY_SERVER')) {
    $recalculado = recalcularEtas($mysqli, $recorrido, $lat, $lng, new DateTime());
  }

  responder([
    'success' => 1,
    'yaHabiaArrancado' => $yaHabiaArrancado,
    'recalculado' => $recalculado,
  ]);
} catch (Throwable $e) {
  responder(['success' => 0, 'error' => 'INICIAR_RECORRIDO_ERROR', 'detail' => $e->getMessage()], 500);
}
