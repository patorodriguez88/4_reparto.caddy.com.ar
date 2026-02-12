<?php
// SistemaReparto/Proceso/php/app_status.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../Conexion/conexioni.php";

function responder(array $arr, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    responder(['success' => 0, 'error' => 'INVALID_JSON'], 400);
}

// ✅ Si querés permitir app_status SIN sesión, comentá este bloque.
// Yo lo dejaría con sesión, así te sirve para auditar y detectar caídas.
$usuario = $_SESSION['Usuario'] ?? '';
$userId  = (int)($_SESSION['idusuario'] ?? 0);

if ($usuario === '' && $userId <= 0) {
    responder(['success' => 0, 'forceLogout' => 1, 'reason' => 'NO_SESSION'], 401);
}

// payload
$app         = trim((string)($data['app'] ?? ''));
$version     = trim((string)($data['version'] ?? ''));
$deviceId    = trim((string)($data['device_id'] ?? ''));
$isStandalone = !empty($data['is_standalone']) ? 1 : 0;
$platform    = trim((string)($data['platform'] ?? ''));
$ua          = trim((string)($data['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
$ip          = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$now         = date('Y-m-d H:i:s');

if ($app === '' || $deviceId === '') {
    responder(['success' => 0, 'error' => 'MISSING_APP_OR_DEVICE'], 400);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // ✅ Tabla sugerida: AppDevices (si no existe, podés crearla)
    // Si NO querés DB ahora, comentá el bloque INSERT/UPDATE y devolvé success=1.

    // 1) Crear tabla (una vez) - te dejo el SQL aparte abajo
    // 2) Upsert por deviceId+app

    $stmt = $mysqli->prepare("
        INSERT INTO AppDevices
        (app, device_id, user_id, usuario, version, is_standalone, platform, ua, ip, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          user_id=VALUES(user_id),
          usuario=VALUES(usuario),
          version=VALUES(version),
          is_standalone=VALUES(is_standalone),
          platform=VALUES(platform),
          ua=VALUES(ua),
          ip=VALUES(ip),
          last_seen=VALUES(last_seen)
    ");

    $stmt->bind_param(
        "ssississss",
        $app,
        $deviceId,
        $userId,
        $usuario,
        $version,
        $isStandalone,
        $platform,
        $ua,
        $ip,
        $now
    );
    $stmt->execute();

    responder([
        'success' => 1,
        'installed' => ($isStandalone === 1),
        'userId' => $userId,
        'deviceId' => $deviceId
    ]);
} catch (Throwable $e) {
    responder(['success' => 0, 'error' => 'APP_STATUS_ERROR', 'detail' => $e->getMessage()], 500);
}
