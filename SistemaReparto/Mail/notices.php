<?php
// SistemaReparto/Mail/notices.php
header('Content-Type: application/json; charset=utf-8');

require_once "../Conexion/Conexioni.php";
date_default_timezone_set('America/Argentina/Buenos_Aires');
function getClienteById(mysqli $mysqli, int $id): ?array
{
    $stmt = $mysqli->prepare("SELECT nombrecliente, Mail FROM Clientes WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}
function notificationExists(mysqli $mysqli, int $idCliente, string $cs, string $slug): ?array
{
    $stmt = $mysqli->prepare("SELECT id FROM Notifications WHERE idCliente=? AND CodigoSeguimiento=? AND State=? LIMIT 1");
    $stmt->bind_param("iss", $idCliente, $cs, $slug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}
function getEstadoFlagsBySlug(mysqli $mysqli, string $slug): array
{
    $stmt = $mysqli->prepare("SELECT Estado, Notificacion_origen, Notificacion_destino 
                              FROM Estados 
                              WHERE Slug=? 
                              LIMIT 1");
    $stmt->bind_param("s", $slug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return ['found' => false];

    return [
        'found'   => true,
        'label'   => $row['Estado'] ?? $slug, // texto humano
        'origen'  => (int)($row['Notificacion_origen'] ?? 0),
        'destino' => (int)($row['Notificacion_destino'] ?? 0),
    ];
}
function respond(int $success, string $code, string $message, array $context = [], int $http = 200): void
{
    http_response_code($http);
    echo json_encode([
        'success' => $success,
        'code'    => $code,
        'message' => $message,
        'context' => $context
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(0, 'METHOD_NOT_ALLOWED', 'Se requiere POST', [], 405);
}

$avisos = isset($_POST['Avisos']) ? (int)$_POST['Avisos'] : 0;
$cs     = trim($_POST['cs'] ?? '');
$slug   = trim($_POST['slug'] ?? '');

if (!in_array($avisos, [1, 2], true)) {
    respond(0, 'BAD_AVISOS', 'Avisos inválido', ['Avisos' => $_POST['Avisos'] ?? null], 400);
}
if ($cs === '' || $slug === '') {
    respond(0, 'MISSING_PARAMS', 'Faltan parámetros cs o slug', ['cs' => $cs, 'slug' => $slug], 400);
}

$flags = getEstadoFlagsBySlug($mysqli, $slug);
if (!$flags['found']) {
    respond(0, 'STATE_NOT_FOUND', 'No existe el estado (slug) en tabla Estados', ['slug' => $slug], 400);
}
$label = trim($flags['label'] ?? $slug);

// Si está deshabilitado, devolvemos SKIPPED (no es error)
if ($avisos === 1 && $flags['origen'] !== 1) {
    respond(1, 'SKIPPED', 'Notificación ORIGEN deshabilitada para este estado', ['slug' => $slug, 'label' => $label, 'avisos' => $avisos]);
}
if ($avisos === 2 && $flags['destino'] !== 1) {
    respond(1, 'SKIPPED', 'Notificación DESTINO deshabilitada para este estado', ['slug' => $slug, 'label' => $label, 'avisos' => $avisos]);
}

$Fecha   = date("Y-m-d");
$Hora    = date("H:i");
$Usuario = $_SESSION['Usuario'] ?? 'sistema';

// --------------------
// Avisos = 1 (ORIGEN)
// --------------------
if ($avisos === 1) {

    $stmt = $mysqli->prepare("SELECT ingBrutosOrigen, ClienteDestino 
                              FROM TransClientes 
                              WHERE CodigoSeguimiento=? AND Eliminado='0' 
                              LIMIT 1");
    $stmt->bind_param("s", $cs);
    $stmt->execute();
    $tc = $stmt->get_result()->fetch_assoc();

    if (!$tc) {
        respond(0, 'NOT_FOUND_TRANSCLIENTES', 'No se encontró TransClientes para ese Código', ['cs' => $cs]);
    }

    $idCliente = (int)$tc['ingBrutosOrigen'];
    if ($idCliente <= 0) {
        respond(0, 'INVALID_IDCLIENTE', 'ingBrutosOrigen inválido', ['cs' => $cs, 'ingBrutosOrigen' => $tc['ingBrutosOrigen']]);
    }

    $stmt2 = $mysqli->prepare("SELECT nombrecliente, Mail 
                               FROM Clientes 
                               WHERE id=? 
                               LIMIT 1");
    $stmt2->bind_param("i", $idCliente);
    $stmt2->execute();

    $cl = $stmt2->get_result()->fetch_assoc();

    if (!$cl) {
        respond(0, 'NOT_FOUND_CLIENTE', 'No se encontró Cliente origen', ['idCliente' => $idCliente]);
    }

    $mail = trim($cl['Mail'] ?? '');
    $name = trim($cl['nombrecliente'] ?? '');

    if ($mail === '') {
        respond(0, 'NO_EMAIL', 'El cliente origen no tiene mail cargado', ['idCliente' => $idCliente, 'name' => $name]);
    }

    $stmt3 = $mysqli->prepare("SELECT id 
                               FROM Notifications 
                               WHERE idCliente=? AND CodigoSeguimiento=? AND State=? 
                               LIMIT 1");
    $stmt3->bind_param("iss", $idCliente, $cs, $slug);
    $stmt3->execute();
    $exists = $stmt3->get_result()->fetch_assoc();

    if ($exists) {
        respond(0, 'ALREADY_EXISTS', 'La notificación ya existe', ['id' => $exists['id'], 'idCliente' => $idCliente, 'cs' => $cs, 'slug' => $slug, 'label' => $label]);
    }
    $token = bin2hex(random_bytes(16));
    $stmt4 = $mysqli->prepare("INSERT INTO Notifications (CodigoSeguimiento, idCliente, Name, Mail, State, Fecha, Hora, Token)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt4->bind_param("sissssss", $cs, $idCliente, $name, $mail, $slug, $Fecha, $Hora, $token);

    if (!$stmt4->execute()) {
        respond(0, 'DB_ERROR_INSERT', 'Error insertando Notifications', ['db_error' => $stmt4->error]);
    }

    respond(1, 'OK', 'Notificación creada', [
        'mail' => $mail,
        'name' => $name,
        'destination_name' => $tc['ClienteDestino'] ?? '',
        'state_slug' => $slug,
        'state_label' => $label
    ]);
}

// ------------------------
// Avisos = 2 (DESTINATARIO)
// ------------------------
if ($avisos === 2) {

    $stmt = $mysqli->prepare("SELECT idClienteDestino, RazonSocial 
                              FROM TransClientes 
                              WHERE CodigoSeguimiento=? AND Eliminado='0' 
                              LIMIT 1");
    $stmt->bind_param("s", $cs);
    $stmt->execute();
    $tc = $stmt->get_result()->fetch_assoc();

    if (!$tc) {
        respond(0, 'NOT_FOUND_TRANSCLIENTES', 'No se encontró TransClientes para ese Código', ['cs' => $cs]);
    }

    $idCliente = (int)$tc['idClienteDestino'];
    if ($idCliente <= 0) {
        respond(0, 'INVALID_IDCLIENTE', 'idClienteDestino inválido', ['cs' => $cs, 'idClienteDestino' => $tc['idClienteDestino']]);
    }

    $stmt2 = $mysqli->prepare("SELECT nombrecliente, Mail 
                               FROM Clientes 
                               WHERE id=? 
                               LIMIT 1");
    $stmt2->bind_param("i", $idCliente);
    $stmt2->execute();
    $cl = $stmt2->get_result()->fetch_assoc();

    if (!$cl) {
        respond(0, 'NOT_FOUND_CLIENTE', 'No se encontró Cliente destino', ['idCliente' => $idCliente]);
    }

    $mail = trim($cl['Mail'] ?? '');
    $name = trim($cl['nombrecliente'] ?? '');

    if ($mail === '') {
        respond(0, 'NO_EMAIL', 'El cliente destino no tiene mail cargado', ['idCliente' => $idCliente, 'name' => $name]);
    }

    $stmt3 = $mysqli->prepare("SELECT id 
                               FROM Notifications 
                               WHERE idCliente=? AND CodigoSeguimiento=? AND State=? 
                               LIMIT 1");
    $stmt3->bind_param("iss", $idCliente, $cs, $slug);
    $stmt3->execute();
    $exists = $stmt3->get_result()->fetch_assoc();

    if ($exists) {
        respond(0, 'ALREADY_EXISTS', 'La notificación ya existe', ['id' => $exists['id'], 'idCliente' => $idCliente, 'cs' => $cs, 'slug' => $slug, 'label' => $label]);
    }

    $token = bin2hex(random_bytes(16));
    // Insert notification
    $stmt4 = $mysqli->prepare("INSERT INTO Notifications (CodigoSeguimiento, idCliente, Name, Mail, State, Fecha, Hora, Token)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt4->bind_param("sissssss", $cs, $idCliente, $name, $mail, $slug, $Fecha, $Hora, $token);

    if (!$stmt4->execute()) {
        respond(0, 'DB_ERROR_INSERT', 'Error insertando Notifications', ['db_error' => $stmt4->error]);
    }

    // Update TransClientes if needed
    TransClientes($mysqli, $cs, $label);

    // Insert Seguimiento if needed
    Seguimiento($mysqli, $cs, $label);

    respond(1, 'OK', 'Notificación creada', [
        'mail' => $mail,
        'name' => $name,
        'id' => $idCliente,
        'origen_name' => $tc['RazonSocial'] ?? '',
        'state_slug' => $slug,
        'state_label' => $label
    ]);
}

// ---------------- Helpers ----------------

function TransClientes(mysqli $mysqli, string $codigo, string $estado): void
{
    $stmt = $mysqli->prepare("SELECT id FROM TransClientes WHERE Eliminado=0 AND CodigoSeguimiento=? AND Estado=? LIMIT 1");
    $stmt->bind_param("ss", $codigo, $estado);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_assoc();

    if (!$ok) {
        $stmt2 = $mysqli->prepare("UPDATE TransClientes SET Estado=? WHERE CodigoSeguimiento=? AND Eliminado=0 LIMIT 1");
        $stmt2->bind_param("ss", $estado, $codigo);
        $stmt2->execute();
    }
}

function Seguimiento(mysqli $mysqli, string $codigo, string $estado): void
{
    $Fecha    = date("Y-m-d");
    $Hora     = date("H:i");
    $Usuario  = $_SESSION['Usuario'] ?? 'sistema';
    $Sucursal = $_SESSION['Sucursal'] ?? '';

    // Evitar duplicado
    $stmt = $mysqli->prepare("SELECT id FROM Seguimiento WHERE CodigoSeguimiento=? AND Estado=? LIMIT 1");
    $stmt->bind_param("ss", $codigo, $estado);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) return;

    // Base data desde TransClientes (ajustá campos reales)
    $stmt2 = $mysqli->prepare("SELECT Destinatario AS NombreCompleto, Dni, Domicilio AS Destino, idCliente, id AS idTransClientes, Recorrido
                               FROM TransClientes
                               WHERE CodigoSeguimiento=? AND Eliminado=0 LIMIT 1");
    $stmt2->bind_param("s", $codigo);
    $stmt2->execute();
    $tc = $stmt2->get_result()->fetch_assoc();

    $NombreCompleto  = $tc['NombreCompleto'] ?? '';
    $Dni             = $tc['Dni'] ?? '';
    $Destino         = $tc['Destino'] ?? '';
    $idCliente       = (int)($tc['idCliente'] ?? 0);
    $idTransClientes = (int)($tc['idTransClientes'] ?? 0);
    $Recorrido       = $tc['Recorrido'] ?? '';

    $stmt3 = $mysqli->prepare("INSERT INTO Seguimiento
      (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones, Entregado, Estado, NombreCompleto, Dni, Destino, Avisado, idCliente, Retirado, Visitas, idTransClientes, Recorrido, Devuelto, Webhook, state_id, NumerodeOrden, status)
      VALUES (?, ?, ?, ?, ?, '', 0, ?, ?, ?, ?, 0, ?, 0, 0, ?, ?, 0, 0, 0, 0, '')");

    // OJO: tipos: Fecha/Hora/Usuario/Sucursal/Codigo/Estado/Nombre/Dni/Destino = string
    // idCliente/idTransClientes = int
    // Recorrido = string
    $stmt3->bind_param(
        "sssssssssiis",
        $Fecha,
        $Hora,
        $Usuario,
        $Sucursal,
        $codigo,
        $estado,
        $NombreCompleto,
        $Dni,
        $Destino,
        $idCliente,
        $idTransClientes,
        $Recorrido
    );
    $stmt3->execute();
}
