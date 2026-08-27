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
require_once __DIR__ . "/eta.php";

$googleConfigPath = __DIR__ . "/../../Conexion/google_config.php";
if (file_exists($googleConfigPath)) {
    require_once $googleConfigPath;
}

// Umbral de "se alejó lo suficiente como para considerar que arrancó el
// recorrido": por encima del ruido típico de GPS en celular (~10-50m), pero
// chico para detectar la salida real rápido.
const UMBRAL_INICIO_RECORRIDO_METROS = 300;

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

    // Auto-detección de inicio de recorrido: la ubicación ya se guardó
    // arriba (lo crítico), así que un problema acá nunca debe convertirse
    // en un error para el repartidor - sólo se loguea.
    try {
        detectarInicioRecorrido($mysqli, $userId, $recorrido, $lat, $lng);
    } catch (Throwable $e) {
        error_log('ubicacion.php: detectarInicioRecorrido falló: ' . $e->getMessage());
    }

    responder(['success' => 1]);
} catch (Throwable $e) {
    responder(['success' => 0, 'error' => 'UBICACION_ERROR', 'detail' => $e->getMessage()], 500);
}

function detectarInicioRecorrido(mysqli $mysqli, int $userId, string $recorrido, float $lat, float $lng): void
{
    if ($recorrido === '') {
        return;
    }

    $res = $mysqli->query(
        "SELECT id, LatInicio, LngInicio, HoraSalidaReal
         FROM Logistica
         WHERE idUsuarioChofer = {$userId} AND Estado = 'Cargada' AND Eliminado = 0
         LIMIT 1"
    );
    $log = $res ? $res->fetch_assoc() : null;
    if (!$log) {
        return;
    }

    if ($log['LatInicio'] === null) {
        // Primer ping del día: se toma como punto de referencia (se asume
        // que el repartidor todavía está en el depósito/base).
        $latEsc = $mysqli->real_escape_string((string) $lat);
        $lngEsc = $mysqli->real_escape_string((string) $lng);
        $mysqli->query(
            "UPDATE Logistica SET LatInicio = {$latEsc}, LngInicio = {$lngEsc}
             WHERE id = {$log['id']} LIMIT 1"
        );
        return;
    }

    if (!empty($log['HoraSalidaReal'])) {
        return; // ya arrancó (por botón o por una detección anterior)
    }

    $distanciaM = haversineMetros((float) $log['LatInicio'], (float) $log['LngInicio'], $lat, $lng);
    if ($distanciaM < UMBRAL_INICIO_RECORRIDO_METROS) {
        return;
    }

    $mysqli->query(
        "UPDATE Logistica SET HoraSalidaReal = NOW()
         WHERE id = {$log['id']} AND HoraSalidaReal IS NULL LIMIT 1"
    );

    if (defined('GOOGLE_API_KEY_SERVER')) {
        recalcularEtas($mysqli, $recorrido, $lat, $lng, new DateTime());
    }
}
