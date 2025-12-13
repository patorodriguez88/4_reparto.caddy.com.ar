<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


class Conexion
{
    private $server;
    private $user;
    private $password;
    private $database;
    private $port;
    private $socket;
    private $conexion;

    public function __construct()
    {
        $datos = $this->cargarDatosConexion();

        $this->server   = $datos['server'] ?? 'localhost';
        $this->user     = $datos['user'] ?? 'root';
        $this->password = $datos['password'] ?? '';
        $this->database = $datos['database'] ?? '';
        $this->port     = isset($datos['port']) ? intval($datos['port']) : 3306;
        $this->socket   = $datos['socket'] ?? null;

        if ($_SERVER['SERVER_NAME'] === 'localhost') {
            $this->conexion = new mysqli(
                $this->server,
                $this->user,
                $this->password,
                $this->database,
                $this->port,
                $this->socket
            );
        } else {
            $this->conexion = new mysqli(
                $this->server,
                $this->user,
                $this->password,
                $this->database,
                $this->port
            );
        }

        // 🔴 SI FALLA LA CONEXIÓN → REDIRIGE AL LOGIN
        if ($this->conexion->connect_error) {
            echo "❌ Error de conexión: " . $this->conexion->connect_error;
            session_destroy();
            header("Location: /SistemaReparto/hdr.php");
            exit;
        }

        $this->conexion->set_charset("utf8");

        $_SESSION['server'] = $this->server;
    }

    private function cargarDatosConexion(): array
    {
        // 🔄 NUEVO: detectamos host para decidir entorno
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        $httpHost   = $_SERVER['HTTP_HOST']   ?? '';
        $host       = strtolower($httpHost ?: $serverName);

        if ($serverName === 'localhost') {
            // 🖥️ Entorno local
            $archivo = "config_local";
            define('ENTORNO', 'local');
        } elseif (strpos($host, 'sandbox.reparto.caddy.com.ar') !== false) {
            // 🧪 Entorno sandbox
            $archivo = "config_sandbox";   // 👈 ESTE ARCHIVO NUEVO
            define('ENTORNO', 'sandbox');
        } else {
            // 🔵 Producción
            $archivo = "config";
            define('ENTORNO', 'produccion');
        }

        $path = dirname(__FILE__) . "/" . $archivo;

        if (!file_exists($path)) {
            session_destroy();
            header("Location: /SistemaReparto/hdr.php");
            exit;
        }

        $json  = file_get_contents($path);
        $datos = json_decode($json, true);

        if (!$datos || !is_array($datos) || !isset($datos[0])) {
            session_destroy();
            header("Location: /SistemaReparto/hdr.php");
            exit;
        }

        return $datos[0];
    }

    public function obtenerConexion(): mysqli
    {
        return $this->conexion;
    }
}


// 🧠 INSTANCIAR CONEXIÓN
$miConexion = new Conexion();
$mysqli = $miConexion->obtenerConexion();

// 🕒 Tiempo máximo de sesión (90 min)
$tiempoMaximo = 5400;

// Archivos que no requieren sesión activa
$archivoActual = basename($_SERVER['PHP_SELF']);
$excepciones = ['hdr.html'];

// 🔐 Validación completa de sesión (saltear si es API)
if (!defined('ALLOW_NO_SESSION') || ALLOW_NO_SESSION !== true) {

    // 🔐 Validación completa de sesión
    if (!in_array($archivoActual, $excepciones)) {
        // Sesión expirada por inactividad
        if (isset($_SESSION['tiempo']) && (time() - $_SESSION['tiempo']) > $tiempoMaximo) {
            session_destroy();
            $_SESSION = [];
        }

        // Sin sesión válida
        if (empty($_SESSION['Usuario'])) {
            session_destroy();

            // Si es llamada AJAX
            if (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            ) {
                header('X-Session-Expired: 1');
                http_response_code(401);
                exit;
            }

            // Carga normal
            // header("Location: /SistemaReparto/inicio.php");
            exit;
        }

        // Si sigue activo, actualizamos el tiempo
        $_SESSION['tiempo'] = time();
    }
}
