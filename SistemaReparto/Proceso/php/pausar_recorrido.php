<?php
// Registra una pausa del recorrido (botón "Parar Ruta") - bloquea la
// pantalla de confirmación del lado del repartidor hasta que reanude, y
// queda visible para operaciones en el mapa de RepartidoresEnVivo
// (sistema.caddy.com.ar) mientras la pausa siga abierta (Fin IS NULL).
declare(strict_types=1);

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

$motivosValidos = ['mecanico', 'descanso', 'transito', 'otro'];
$motivo = (string) ($data['motivo'] ?? '');
if (!in_array($motivo, $motivosValidos, true)) {
  responder(['success' => 0, 'error' => 'MOTIVO_INVALIDO'], 400);
}

$detalle = trim((string) ($data['detalle'] ?? ''));
$lat = isset($data['lat']) ? (float) $data['lat'] : null;
$lng = isset($data['lng']) ? (float) $data['lng'] : null;

try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  // Idempotente: si ya hay una pausa abierta, no crea otra - devuelve la
  // que ya existe (evita duplicados por doble-tap).
  $res = $mysqli->query(
    "SELECT id, Motivo, Detalle, Inicio FROM PausasRecorrido
     WHERE idUsuario = {$userId} AND Fin IS NULL
     LIMIT 1"
  );
  $existente = $res ? $res->fetch_assoc() : null;
  if ($existente) {
    responder(['success' => 1, 'pausa' => $existente]);
  }

  $usuario = (string) ($_SESSION['Usuario'] ?? '');
  $recorrido = (string) ($_SESSION['RecorridoAsignado'] ?? '');
  $ahora = (new DateTime())->format('Y-m-d H:i:s');

  $stmt = $mysqli->prepare(
    "INSERT INTO PausasRecorrido (idUsuario, Usuario, Recorrido, Motivo, Detalle, Latitud, Longitud, Inicio)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
  );
  $stmt->bind_param(
    "issssdds",
    $userId,
    $usuario,
    $recorrido,
    $motivo,
    $detalle,
    $lat,
    $lng,
    $ahora
  );
  // lat/lng pueden venir null - bind_param con tipo 'd' y valor null igual
  // funciona (mysqli lo manda como NULL), no hace falta un tipo especial.
  $stmt->execute();

  responder([
    'success' => 1,
    'pausa' => ['id' => $stmt->insert_id, 'Motivo' => $motivo, 'Detalle' => $detalle, 'Inicio' => $ahora],
  ]);
} catch (Throwable $e) {
  responder(['success' => 0, 'error' => 'PAUSAR_ERROR', 'detail' => $e->getMessage()], 500);
}
