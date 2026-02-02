<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once "../../Conexion/conexioni.php";


try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => 0, 'error' => 'JSON inválido']);
        exit;
    }

    $appName      = isset($data['app']) ? trim((string)$data['app']) : 'reparto';
    $deviceId     = isset($data['device_id']) ? trim((string)$data['device_id']) : '';
    $isStandalone = !empty($data['is_standalone']) ? 1 : 0;
    $platform     = isset($data['platform']) ? trim((string)$data['platform']) : null;
    $appVersion   = isset($data['version']) ? trim((string)$data['version']) : null;

    if ($deviceId === '' || strlen($deviceId) < 10) {
        http_response_code(422);
        echo json_encode(['success' => 0, 'error' => 'device_id requerido']);
        exit;
    }

    // Si tenés login, guardá el ID de usuario real desde sesión
    // Ajustá el nombre del índice según tu sistema:
    $userId = null;
    if (isset($_SESSION['idusuario'])) {
        $userId = (int)$_SESSION['idusuario'];
    } elseif (isset($_SESSION['id'])) {
        $userId = (int)$_SESSION['id'];
    }

    $now = date('Y-m-d H:i:s');
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // UPSERT: si existe DeviceId+AppName → update, si no → insert
    $sql = "
        INSERT INTO AppInstalls
            (UserId, DeviceId, AppName, Platform, IsStandalone, AppVersion, FirstSeen, LastSeen, LastIp, LastUserAgent)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            UserId = COALESCE(VALUES(UserId), UserId),
            Platform = VALUES(Platform),
            IsStandalone = VALUES(IsStandalone),
            AppVersion = VALUES(AppVersion),
            LastSeen = VALUES(LastSeen),
            LastIp = VALUES(LastIp),
            LastUserAgent = VALUES(LastUserAgent)
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param(
        "issssissss",
        $userId,
        $deviceId,
        $appName,
        $platform,
        $isStandalone,
        $appVersion,
        $now,
        $now,
        $ip,
        $ua
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    echo json_encode([
        'success' => 1,
        'installed' => (bool)$isStandalone,
        'userId' => $userId,
        'deviceId' => $deviceId
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => 0, 'error' => $e->getMessage()]);
}
