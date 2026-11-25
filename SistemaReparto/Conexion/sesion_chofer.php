<?php
// Sesión del chofer que no se corta mientras reparte (25/11/2026).
//
// Antes la sesión vivía solo en la cookie de sesión de PHP (sin vencimiento) + el archivo de
// sesión del servidor. Se perdía cuando Android cerraba la app (por ejemplo al abrir la cámara
// para la foto de la entrega), cuando el hosting limpiaba sesiones con el teléfono bloqueado, o
// a los 90 minutos sin uso. Medido en Ingresos (14 días hasta el 25/11): ~10 logins por chofer
// por día, 543 de ellos desde el mismo teléfono.
//
// Ahora, al ingresar, además de la sesión se deja una cookie "recordar" de 30 días. Si la sesión
// se pierde, el próximo pedido la rearma sola desde esa cookie, sin pasar por el login. La cookie
// va firmada (HMAC) con la contraseña del chofer y el device_id, así que deja de valer sola si:
//   - le cambian la contraseña o lo desactivan,
//   - el chofer ingresa desde otro teléfono (regla "gana el último login" de DispositivoChofer),
//   - toca Salir (se borra la cookie).
// No usa tablas nuevas: la clave sale de la configuración de la conexión (que no está en el repo).

const RECORDAR_COOKIE = 'CADDY_REPARTO_RECORDAR';
const RECORDAR_DIAS   = 30;

function recordarClave(): string
{
    $base = $GLOBALS['CADDY_REPARTO_CLAVE_BASE'] ?? '';
    return hash('sha256', 'caddy-reparto-recordar|' . $base);
}

function recordarFirma(int $uid, int $vence, string $deviceId, string $password): string
{
    return hash_hmac('sha256', $uid . '|' . $vence . '|' . $deviceId . '|' . $password, recordarClave());
}

function recordarCookieParams(int $expira): array
{
    return [
        'expires'  => $expira,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function recordarEmitir(int $uid, string $deviceId, string $password): void
{
    if ($uid <= 0 || headers_sent()) return;
    $vence = time() + RECORDAR_DIAS * 86400;
    $valor = $uid . '.' . $vence . '.' . $deviceId . '.' . recordarFirma($uid, $vence, $deviceId, $password);
    setcookie(RECORDAR_COOKIE, $valor, recordarCookieParams($vence));
}

function recordarBorrar(): void
{
    if (!headers_sent()) {
        setcookie(RECORDAR_COOKIE, '', recordarCookieParams(time() - 3600));
    }
    unset($_COOKIE[RECORDAR_COOKIE]);
}

/**
 * Carga en $_SESSION los datos del chofer (lo mismo que hacía admision.php al ingresar).
 * $row = fila de `usuarios`. La orden la completa sincronizarOrdenSesion() en cada pedido.
 */
function armarSesionChofer(mysqli $mysqli, array $row, string $deviceId): void
{
    $idUsuario = (int) $row['id'];

    // Empleado de planta de Caddy = fila activa en Empleados con Aliados=0 (cobra sueldo: en
    // Mi Cuenta no ve importes). Aliados y externos sin fila en Empleados quedan en 0.
    $nombreCompleto  = '';
    $esEmpleadoCaddy = 0;
    try {
        $st = $mysqli->prepare("SELECT NombreCompleto, Aliados FROM Empleados WHERE Usuario = ? AND Inactivo = 0 LIMIT 1");
        $st->bind_param("i", $idUsuario);
        $st->execute();
        $emp = $st->get_result()->fetch_assoc();
        $st->close();
        $nombreCompleto  = $emp['NombreCompleto'] ?? 'Sin Nombre';
        $esEmpleadoCaddy = ($emp && (int) ($emp['Aliados'] ?? 1) === 0) ? 1 : 0;
    } catch (Throwable $e) {
        error_log('armarSesionChofer Empleados: ' . $e->getMessage());
    }

    $_SESSION['Transportista']   = $nombreCompleto;
    $_SESSION['EsEmpleadoCaddy'] = $esEmpleadoCaddy;
    $_SESSION['idusuario']       = $idUsuario;
    $_SESSION['ingreso']         = $row['Usuario'] ?? '';
    $_SESSION['NCliente']        = $row['NdeCliente'] ?? '';
    $_SESSION['Nivel']           = $row['NIVEL'] ?? '';
    $_SESSION['Direccion']       = $row['Direccion'] ?? '';
    $_SESSION['NombreUsuario']   = $row['Nombre'] ?? '';
    $_SESSION['Usuario']         = $row['Usuario'] ?? '';
    $_SESSION['Sucursal']        = $row['Sucursal'] ?? '';
    $_SESSION['device_id']       = $deviceId;
    $_SESSION['tiempo']          = time();
    $_SESSION['dev_chk']         = 0;
}

/**
 * Si no hay sesión y el teléfono tiene una cookie "recordar" válida, rearma la sesión.
 * Devuelve true si la restauró. Nunca corta el pedido: ante cualquier duda devuelve false
 * y sigue el camino de siempre (login).
 */
function restaurarSesionChofer(mysqli $mysqli): bool
{
    $valor = (string) ($_COOKIE[RECORDAR_COOKIE] ?? '');
    if ($valor === '') return false;

    $partes = explode('.', $valor);
    if (count($partes) !== 4) { recordarBorrar(); return false; }
    [$uid, $vence, $deviceId, $firma] = $partes;
    $uid   = (int) $uid;
    $vence = (int) $vence;
    if ($uid <= 0 || $vence < time() || !preg_match('/^[A-Za-z0-9_-]{0,64}$/', $deviceId)) {
        recordarBorrar();
        return false;
    }

    try {
        $st = $mysqli->prepare("SELECT * FROM usuarios
                                 WHERE id = ? AND ACTIVO = '1' AND NIVEL = '3' AND Estado = 'Activo' LIMIT 1");
        $st->bind_param("i", $uid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
    } catch (Throwable $e) {
        error_log('restaurarSesionChofer usuarios: ' . $e->getMessage());
        return false; // error de base: no se borra la cookie, se reintenta en el próximo pedido
    }

    if (!$row || !hash_equals(recordarFirma($uid, $vence, $deviceId, (string) $row['PASSWORD']), $firma)) {
        recordarBorrar();
        return false;
    }

    // Mono-teléfono: si después ingresó desde otro teléfono, esta cookie ya no sirve.
    if ($deviceId !== '') {
        try {
            $rDev = $mysqli->query("SELECT device_id FROM DispositivoChofer WHERE idUsuario = {$uid} LIMIT 1");
            $devActivo = ($rDev && $rDev->num_rows) ? (string) $rDev->fetch_assoc()['device_id'] : '';
            if ($devActivo !== '' && $devActivo !== $deviceId) {
                recordarBorrar();
                return false;
            }
        } catch (Throwable $e) {
            // Falta la tabla DispositivoChofer: igual que en conexioni.php, no bloquea.
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    armarSesionChofer($mysqli, $row, $deviceId);
    // Renueva los 30 días: mientras el chofer use la app, no vence nunca.
    recordarEmitir($uid, $deviceId, (string) $row['PASSWORD']);
    error_log('reparto: sesion restaurada desde cookie recordar - usuario ' . $uid . ' - ' . ($_SERVER['REQUEST_URI'] ?? ''));
    return true;
}
