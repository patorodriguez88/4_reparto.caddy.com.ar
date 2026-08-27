<?php
// Cierra la pausa abierta del recorrido (botón "Reanudar").
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

try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  $ahora = (new DateTime())->format('Y-m-d H:i:s');
  $ahoraEsc = $mysqli->real_escape_string($ahora);

  $mysqli->query(
    "UPDATE PausasRecorrido SET Fin = '{$ahoraEsc}'
     WHERE idUsuario = {$userId} AND Fin IS NULL
     LIMIT 1"
  );

  responder(['success' => 1]);
} catch (Throwable $e) {
  responder(['success' => 0, 'error' => 'REANUDAR_ERROR', 'detail' => $e->getMessage()], 500);
}
