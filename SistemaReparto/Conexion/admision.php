<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('America/Argentina/Buenos_Aires');
header('Content-Type: application/json; charset=utf-8');

define('ALLOW_NO_SESSION', true);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/conexioni.php";

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    http_response_code(500);
    echo json_encode([
        "success" => 0,
        "error"   => "No se pudo inicializar mysqli. Revisar conexioni.php"
    ]);
    exit;
}

/**
 * 👉 IMPORTANTE:
 * - Esto hace que los errores SQL tiren exception y podamos atraparlos.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function jsonOk(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonFail(string $msg, array $extra = [], int $http = 200): void
{
    http_response_code($http);
    $payload = array_merge([
        'success'  => 0,
        'error'    => $msg,
        'error_id' => date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8),
    ], $extra);

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {

    // -----------------------
    // CERRAR SESIÓN
    // -----------------------
    if (isset($_POST['Salir'])) {
        session_destroy();
        jsonOk(['success' => 1]);
    }

    // -----------------------
    // LOGIN
    // -----------------------
    $user     = trim((string)($_POST['user'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));

    if ($user === '') {
        jsonFail("No se recibió el nombre de usuario.", [
            'where' => 'input',
        ]);
    }

    // ✅ Login con prepared statement
    $stmt = $mysqli->prepare("
        SELECT *
        FROM usuarios
        WHERE Usuario = ?
          AND PASSWORD = ?
          AND ACTIVO = '1'
          AND NIVEL = '3'
        LIMIT 1
    ");
    $stmt->bind_param("ss", $user, $password);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || empty($row['id'])) {
        jsonFail("Usuario o contraseña inválidos.", [
            'where' => 'auth'
        ]);
    }

    $idUsuario  = (int)$row['id'];
    $Fecha      = date('Y-m-d');
    $Hora       = date('H:i:s');
    $ipCliente  = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // -----------------------
    // INSERT LOG DE INGRESO (NO debe romper login)
    // -----------------------
    try {
        // chequeo rápido: existe tabla Ingresos?
        $chk = $mysqli->query("SHOW TABLES LIKE 'Ingresos'");
        $existeIngresos = ($chk && $chk->num_rows > 0);

        if ($existeIngresos) {
            $stmtIng = $mysqli->prepare("
                INSERT INTO Ingresos (idUsuario, Nombre, Fecha, Hora, ip, UserAgent)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $u = (string)($row['Usuario'] ?? '');
            $stmtIng->bind_param("isssss", $idUsuario, $u, $Fecha, $Hora, $ipCliente, $userAgent);
            $stmtIng->execute();
            $stmtIng->close();
        }
    } catch (Throwable $e) {
        // ✅ No cortamos el login, solo informamos en response (debug)
        // Podés loguearlo a archivo si querés.
        // file_put_contents(__DIR__.'/admision_errors.log', date('c')." ".$e->getMessage()."\n", FILE_APPEND);
    }

    // -----------------------
    // TRANSPORTISTA (Empleados)
    // -----------------------
    $nombreCompleto = '';
    try {
        $stmtEmp = $mysqli->prepare("
            SELECT NombreCompleto
            FROM Empleados
            WHERE Usuario = ?
            LIMIT 1
        ");
        $stmtEmp->bind_param("i", $idUsuario);
        $stmtEmp->execute();
        $resEmp = $stmtEmp->get_result();
        $emp = $resEmp ? $resEmp->fetch_assoc() : null;
        $stmtEmp->close();
        $nombreCompleto = $emp['NombreCompleto'] ?? '';
    } catch (Throwable $e) {
        // no rompemos login
        $nombreCompleto = '';
    }

    // -----------------------
    // BUSCO RECORRIDO ASIGNADO (Logistica)
    // -----------------------
    $recorridoAsignado = '';
    $numeroOrden = '';
    try {
        $stmtLog = $mysqli->prepare("
            SELECT Recorrido, NumerodeOrden
            FROM Logistica
            WHERE idUsuarioChofer = ?
              AND Estado = 'Cargada'
              AND Eliminado = '0'
            LIMIT 1
        ");
        $stmtLog->bind_param("i", $idUsuario);
        $stmtLog->execute();
        $resLog = $stmtLog->get_result();
        $dato = $resLog ? $resLog->fetch_assoc() : null;
        $stmtLog->close();

        $recorridoAsignado = $dato['Recorrido'] ?? '';
        $numeroOrden       = $dato['NumerodeOrden'] ?? '';
    } catch (Throwable $e) {
        $recorridoAsignado = '';
        $numeroOrden = '';
    }

    // -----------------------
    // SESIÓN
    // -----------------------
    $_SESSION['Transportista']      = $nombreCompleto;
    $_SESSION['idusuario']          = $idUsuario;
    $_SESSION['ingreso']            = $row['Usuario'] ?? '';
    $_SESSION['NCliente']           = $row['NdeCliente'] ?? '';
    $_SESSION['Nivel']              = $row['NIVEL'] ?? '';
    $_SESSION['Direccion']          = $row['Direccion'] ?? '';
    $_SESSION['NombreUsuario']      = $row['Nombre'] ?? '';
    $_SESSION['Usuario']            = $row['Usuario'] ?? '';
    $_SESSION['Sucursal']           = $row['Sucursal'] ?? '';

    $_SESSION['RecorridoAsignado']  = $recorridoAsignado;
    $_SESSION['hdr']                = $numeroOrden;

    // -----------------------
    // CODIGOS PENDIENTES (solo si hay recorrido)
    // -----------------------
    $rows = [];
    if ($recorridoAsignado !== '') {
        $stmtHdr = $mysqli->prepare("
            SELECT HojaDeRuta.Seguimiento, TransClientes.Retirado
            FROM HojaDeRuta
            INNER JOIN TransClientes ON HojaDeRuta.idTransClientes = TransClientes.id
            WHERE HojaDeRuta.Eliminado = 0
              AND HojaDeRuta.Devuelto = 0
              AND HojaDeRuta.Estado = 'Abierto'
              AND HojaDeRuta.Recorrido = ?
        ");
        $stmtHdr->bind_param("s", $recorridoAsignado);
        $stmtHdr->execute();
        $resHdr = $stmtHdr->get_result();

        while ($r = $resHdr->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmtHdr->close();
    }

    // ✅ RESPUESTA OK (SIEMPRE JSON)
    jsonOk([
        'success'   => 1,
        'codigos'   => $rows,
        'recorrido' => $recorridoAsignado,
        'norden'    => $numeroOrden,
        'usuario'   => $nombreCompleto
    ]);
} catch (Throwable $e) {
    // ✅ Si algo explota, devolvemos JSON y no HTML
    jsonFail("Error en admisión", [
        'where'   => 'catch-all',
        'detail'  => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ], 500);
}
