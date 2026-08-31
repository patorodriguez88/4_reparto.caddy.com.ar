<?php

declare(strict_types=1);

// Este endpoint SIEMPRE tiene que responder JSON. Si PHP imprime un
// Warning/Notice/Deprecated en el body (o una página de error HTML), el
// front lo recibe como "El servidor devolvió HTML/Warning y no JSON".
// Por eso: nunca mostrar errores en pantalla, sí loguearlos al error_log,
// y bufferear toda la salida para poder tirarla si algo se ensucia.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('America/Argentina/Buenos_Aires');
header('Content-Type: application/json; charset=utf-8');

ob_start();

// Cualquier warning/notice se loguea pero NO se imprime (ya está en '0',
// esto es por si algún include lo vuelve a prender).
set_error_handler(function ($severity, $message, $file, $line) {
    error_log("admision.php warning: $message en $file:$line");
    return true; // no seguir con el handler interno (no imprime)
});

// Fatal que igual mate el script -> devolvemos JSON, no la pantalla blanca/HTML.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length() !== false) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success'  => 0,
            'error'    => 'Error interno en admisión',
            'error_id' => date('YmdHis') . '-fatal',
        ], JSON_UNESCAPED_UNICODE);
        error_log("admision.php FATAL: {$e['message']} en {$e['file']}:{$e['line']}");
    }
});

define('ALLOW_NO_SESSION', true);

// session_start() la hace conexioni.php, con el nombre de cookie propio del
// sistema (CADDY_REPARTO_SESSID) - esto es el login real de los choferes, así
// que es el archivo más importante para no arrancar la sesión con el PHPSESSID
// por default antes de que conexioni.php la nombre bien.
require_once __DIR__ . "/conexioni.php";

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    http_response_code(500);
    if (ob_get_length() !== false) {
        ob_clean();
    }
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

/**
 * Emite JSON y corta. Antes de imprimir tira cualquier basura que se haya
 * colado en el buffer (warnings, whitespace de algún include, etc.) para
 * que el body sea SOLO el JSON.
 */
function jsonSend(array $payload, int $http = 200): void
{
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (ob_get_length() !== false) {
        ob_clean();
    }

    // JSON_INVALID_UTF8_SUBSTITUTE: si algún campo de la DB viene en Latin-1
    // o con bytes rotos, json_encode NO devuelve false (devolvía body vacío).
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('admision.php json_encode fallo: ' . json_last_error_msg());
        $json = json_encode([
            'success'  => 0,
            'error'    => 'No se pudo serializar la respuesta',
            'error_id' => date('YmdHis') . '-jsonerr',
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    echo $json;
    exit;
}

function jsonOk(array $data): void
{
    jsonSend($data, 200);
}

function jsonFail(string $msg, array $extra = [], int $http = 200): void
{
    $payload = array_merge([
        'success'  => 0,
        'error'    => $msg,
        'error_id' => date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8),
    ], $extra);

    jsonSend($payload, $http);
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
          AND Estado='Activo'
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
            AND Inactivo=0            
            LIMIT 1
        ");
        $stmtEmp->bind_param("i", $idUsuario);
        $stmtEmp->execute();
        $resEmp = $stmtEmp->get_result();
        $emp = $resEmp ? $resEmp->fetch_assoc() : null;
        $stmtEmp->close();
        $nombreCompleto = $emp['NombreCompleto'] ?? 'Sin Nombre';
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
        $stmtLog = $mysqli->prepare("SELECT Recorrido, NumerodeOrden
            FROM Logistica
            WHERE idUsuarioChofer = ?
              AND Estado = 'Cargada'
              AND Eliminado = '0'
            LIMIT 1");

        $stmtLog->bind_param("i", $idUsuario);
        $stmtLog->execute();
        $resLog = $stmtLog->get_result();
        $dato = $resLog ? $resLog->fetch_assoc() : null;
        $stmtLog->close();

        $recorridoAsignado = $dato['Recorrido'] ?? '';
        $numeroOrden       = $dato['NumerodeOrden'] ?? '';
    } catch (Throwable $e) {
        error_log('admision.php Logistica: ' . $e->getMessage());
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
        'usuario'   => $nombreCompleto,
    ]);
} catch (Throwable $e) {
    // ✅ Si algo explota, devolvemos JSON y no HTML. El detalle va al
    // error_log del servidor, NO al front (no filtrar rutas/SQL al cliente).
    error_log(sprintf(
        'admision.php catch-all: %s en %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    jsonFail("Error en admisión", ['where' => 'catch-all'], 500);
}
