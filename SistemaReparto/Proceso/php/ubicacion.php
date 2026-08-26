<?php
// SistemaReparto/Proceso/php/ubicacion.php
// Guarda la ÚLTIMA posición conocida del repartidor (no un historial - alcanza
// para saber "dónde anda ahora" y evita que la tabla crezca sin límite).
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

function responder(array $arr, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$userId = (int)($_SESSION['idusuario'] ?? 0);
if ($userId <= 0) {
    responder(['success' => 0, 'error' => 'NO_SESSION'], 401);
}

$lat = isset($data['lat']) ? (float)$data['lat'] : null;
$lng = isset($data['lng']) ? (float)$data['lng'] : null;
if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
    responder(['success' => 0, 'error' => 'MISSING_COORDS'], 400);
}

$accuracy  = isset($data['accuracy']) ? (int)round((float)$data['accuracy']) : null;
$usuario   = (string)($_SESSION['Usuario'] ?? '');
$recorrido = (string)($_SESSION['RecorridoAsignado'] ?? '');
$now       = date('Y-m-d H:i:s');

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $stmt = $mysqli->prepare("
        INSERT INTO UbicacionRepartidor
          (idUsuario, Usuario, Recorrido, Latitud, Longitud, Precision_, TimeStamp)
        VALUES
          (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          Usuario=VALUES(Usuario),
          Recorrido=VALUES(Recorrido),
          Latitud=VALUES(Latitud),
          Longitud=VALUES(Longitud),
          Precision_=VALUES(Precision_),
          TimeStamp=VALUES(TimeStamp)
    ");
    $stmt->bind_param(
        "issddis",
        $userId,
        $usuario,
        $recorrido,
        $lat,
        $lng,
        $accuracy,
        $now
    );
    $stmt->execute();

    responder(['success' => 1]);
} catch (Throwable $e) {
    responder(['success' => 0, 'error' => 'UBICACION_ERROR', 'detail' => $e->getMessage()], 500);
}
