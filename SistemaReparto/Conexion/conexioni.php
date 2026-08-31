<?php
// sistema.caddy.com.ar, plataforma.caddy.com.ar y reparto.caddy.com.ar viven en el
// mismo cPanel y usaban el nombre de cookie por default de PHP (PHPSESSID) sin fijar
// dominio/path - eso puede hacer que el navegador mande la misma cookie a los tres
// (o que compartan session.save_path) y que un login en un sistema termine pisando o
// mezclándose con la sesión de otro, ya que las tres usan claves de $_SESSION
// parecidas (Usuario, NCliente, etc.). Nombre de cookie propio + dominio explícito
// (host-only, nunca el padre) corta el cruce sin depender de la config del hosting.
if (session_status() === PHP_SESSION_NONE) {
    session_name('CADDY_REPARTO_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // host-only: nunca .caddy.com.ar
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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

function redirigirAlLogin($motivo = 'sesion')
{
    // Queda en el error_log del servidor para saber POR QUÉ se cortó
    // (config-missing / config-invalid / db-connect / sesion / sesion-expirada).
    error_log('conexioni.php redirigirAlLogin: ' . $motivo . ' - ' . ($_SERVER['REQUEST_URI'] ?? ''));

    // Si es AJAX → 401 con cuerpo JSON (antes iba vacío y el front lo veía
    // como "El servidor devolvió HTML/Warning y no JSON").
    if (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        if (!headers_sent()) {
            header('X-Session-Expired: 1');
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
        }
        echo json_encode([
            'success' => 0,
            'error'   => ($motivo === 'sesion' || $motivo === 'sesion-expirada')
                ? 'Sesión no válida. Volvé a ingresar.'
                : 'No se pudo conectar con la base (' . $motivo . '). Avisá a soporte.',
            'motivo'  => $motivo,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Navegación normal
    header("Location: /SistemaReparto/hdr.html");
    exit;
}

/**
 * ¿Estamos corriendo en un entorno de desarrollo local?
 * true para: localhost / 127.0.0.1 / ::1, hostnames *.local / *.test
 * (Bonjour), IPs de LAN privada (para abrir la PWA desde el celular en la
 * misma red) y ejecución por CLI. Producción y sandbox (*.caddy.com.ar)
 * siempre devuelven false.
 */
function esEntornoLocalCaddy(): bool
{
    $sn   = strtolower($_SERVER['SERVER_NAME'] ?? '');
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));

    // Producción / sandbox: nunca local.
    if (preg_match('/caddy\.com\.ar$/', $sn) || preg_match('/caddy\.com\.ar$/', $host)) {
        return false;
    }
    if (php_sapi_name() === 'cli') {
        return true;
    }
    foreach ([$sn, $host] as $h) {
        if ($h === '') continue;
        if (in_array($h, ['localhost', '127.0.0.1', '::1'], true)) return true;
        if (preg_match('/\.(local|test|lan)$/', $h)) return true;
        // Rangos IPv4 privados (RFC 1918) - probar desde el teléfono en la LAN.
        if (preg_match('/^127\./', $h)) return true;
        if (preg_match('/^10\./', $h)) return true;
        if (preg_match('/^192\.168\./', $h)) return true;
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $h)) return true;
    }
    return ($sn === '' && $host === '');
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
        $socket   = !empty($datos['socket']) ? $datos['socket'] : null;

        // El socket se usa SOLO en entorno local (dev por XAMPP) cuando el
        // config lo define. En producción el flujo es igual que siempre:
        // conexión TCP, sin socket.
        if (esEntornoLocalCaddy() && $socket !== null) {
            $this->conexion = new mysqli($server, $user, $password, $database, $port, $socket);
        } else {
            $this->conexion = new mysqli($server, $user, $password, $database, $port);
        }
        // ❌ Error de conexión
        if ($this->conexion->connect_error) {
            error_log('conexioni.php DB connect_error: ' . $this->conexion->connect_error . ' (server=' . $server . ' db=' . $database . ')');
            destruirSesionSegura();
            redirigirAlLogin('db-connect');
        }

        $this->conexion->set_charset("utf8");
        $_SESSION['server'] = $server;
    }

    private function cargarDatosConexion(): array
    {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host);
        $host = preg_replace('/^www\./', '', $host);

        if (esEntornoLocalCaddy()) {
            $archivo = "config_local";
            define('ENTORNO', 'local');
            // } elseif ($host === 'sandbox.reparto.caddy.com.ar') {
        } elseif (stripos($host, 'sandbox.') === 0) {
            $archivo = "config_sandbox";
            define('ENTORNO', 'sandbox');
        } else {
            $archivo = "config";
            define('ENTORNO', 'produccion');
        }

        $path = __DIR__ . "/" . $archivo;

        if (!file_exists($path)) {
            error_log('conexioni.php config FALTA: ' . $path);
            destruirSesionSegura();
            redirigirAlLogin('config-missing');
        }

        $json  = file_get_contents($path);
        $datos = json_decode($json, true);

        if (!$datos || !isset($datos[0])) {
            error_log('conexioni.php config INVÁLIDO (json_decode): ' . $path . ' - ' . json_last_error_msg());
            destruirSesionSegura();
            redirigirAlLogin('config-invalid');
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
            redirigirAlLogin('sesion-expirada');
        }

        // Sin sesión válida
        if (empty($_SESSION['Usuario'])) {
            destruirSesionSegura();
            redirigirAlLogin('sesion');
        }

        // Sesión OK → refresco tiempo
        $_SESSION['tiempo'] = time();
    }
}
