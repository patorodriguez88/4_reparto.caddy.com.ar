<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================
// Helpers internos
// =============================
function destruirSesionSegura()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
}

function redirigirAlLogin()
{
    // Si es AJAX → 401
    if (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        header('X-Session-Expired: 1');
        http_response_code(401);
        exit;
    }

    // Navegación normal
    header("Location: /SistemaReparto/hdr.html");
    exit;
}

class Conexion
{
    private $conexion;

    public function __construct()
    {
        $datos = $this->cargarDatosConexion();

        $server   = $datos['server']   ?? 'localhost';
        $user     = $datos['user']     ?? 'root';
        $password = $datos['password'] ?? '';
        $database = $datos['database'] ?? '';
        $port     = isset($datos['port']) ? intval($datos['port']) : 3306;
        $socket   = $datos['socket'] ?? null;

        if ($_SERVER['SERVER_NAME'] === 'localhost') {
            $this->conexion = new mysqli(
                $server,
                $user,
                $password,
                $database,
                $port,
                $socket
            );
        } else {
            $this->conexion = new mysqli(
                $server,
                $user,
                $password,
                $database,
                $port
            );
        }

        // ❌ Error de conexión
        if ($this->conexion->connect_error) {
            destruirSesionSegura();
            redirigirAlLogin();
        }

        $this->conexion->set_charset("utf8");
        $_SESSION['server'] = $server;
    }

    private function cargarDatosConexion(): array
    {
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        $host       = strtolower($_SERVER['HTTP_HOST'] ?? '');

        if ($serverName === 'localhost') {
            $archivo = "config_local";
            define('ENTORNO', 'local');
        } elseif (strpos($host, 'sandbox.reparto.caddy.com.ar') !== false) {
            $archivo = "config_sandbox";
            define('ENTORNO', 'sandbox');
        } else {
            $archivo = "config";
            define('ENTORNO', 'produccion');
        }

        $path = __DIR__ . "/" . $archivo;

        if (!file_exists($path)) {
            destruirSesionSegura();
            redirigirAlLogin();
        }

        $json  = file_get_contents($path);
        $datos = json_decode($json, true);

        if (!$datos || !isset($datos[0])) {
            destruirSesionSegura();
            redirigirAlLogin();
        }

        return $datos[0];
    }

    public function obtenerConexion(): mysqli
    {
        return $this->conexion;
    }
}

// =============================
// Instanciar conexión
// =============================
$miConexion = new Conexion();
$mysqli = $miConexion->obtenerConexion();

// =============================
// Validación de sesión
// =============================
$tiempoMaximo = 5400;
$archivoActual = basename($_SERVER['PHP_SELF']);
$excepciones = ['hdr.html'];

if (!defined('ALLOW_NO_SESSION') || ALLOW_NO_SESSION !== true) {

    if (!in_array($archivoActual, $excepciones)) {

        // Expiración por inactividad
        if (isset($_SESSION['tiempo']) && (time() - $_SESSION['tiempo']) > $tiempoMaximo) {
            destruirSesionSegura();
            redirigirAlLogin();
        }

        // Sin sesión válida
        if (empty($_SESSION['Usuario'])) {
            destruirSesionSegura();
            redirigirAlLogin();
        }

        // Sesión OK → refresco tiempo
        $_SESSION['tiempo'] = time();
    }
}
