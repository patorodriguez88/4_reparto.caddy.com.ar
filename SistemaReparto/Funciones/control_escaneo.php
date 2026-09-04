<?php
// control_escaneo.php
// -----------------------------------------------------------------------------
// Control "nadie entrega lo que no escaneó" para la app de reparto.
//
// Un envío está "escaneado" si en Seguimiento (no eliminado) hay una fila para
// su CodigoSeguimiento base con alguno de estos status:
//   - warehouse_validated : escaneado en el depósito antes de salir (warehouse.php)
//   - pickup_ready         : retiro confirmado en el cliente        (colecta_scan.php / ConfirmoEntrega)
//   - pickup_scanned       : bulto de colecta escaneado             (colecta_scan.php)
//
// Override por recorrido: Logistica.OmitirControlEscaneo = 1. Se prende a mano
// (o desde el sistema viejo) cuando falla un escáner en la calle. Cada bypass
// se registra en logs/control_escaneo_bypass.log.
//
// Si la columna OmitirControlEscaneo todavía no existe (entornos sin migrar),
// overrideEscaneo() devuelve false sin reventar.
// -----------------------------------------------------------------------------

const CONTROL_ESCANEO_STATUSES = ['warehouse_validated', 'pickup_ready', 'pickup_scanned'];

/** Base pura del CodigoSeguimiento (sin sufijo _n), en mayúsculas. */
function csBase(string $cs): string
{
    $cs = strtoupper(trim($cs));
    return trim(explode('_', $cs)[0]);
}

/** ¿La tabla tiene esa columna? (cacheado por request) */
function tieneColumna(mysqli $mysqli, string $tabla, string $col): bool
{
    static $cache = [];
    $key = $tabla . '.' . $col;
    if (isset($cache[$key])) return $cache[$key];

    $t = str_replace('`', '', $tabla);
    $c = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");

    return $cache[$key] = ($res && $res->num_rows > 0);
}

/**
 * ¿El envío (por CS base) tiene algún escaneo de retiro / warehouse?
 */
function escaneoOk(mysqli $mysqli, string $cs): bool
{
    $base = csBase($cs);
    if ($base === '') return false;

    $baseEsc = $mysqli->real_escape_string($base);
    $inList  = "'" . implode("','", CONTROL_ESCANEO_STATUSES) . "'";

    $sql = "SELECT 1
            FROM Seguimiento
            WHERE SUBSTRING_INDEX(CodigoSeguimiento, '_', 1) = '{$baseEsc}'
              AND status IN ({$inList})
              AND (Eliminado IS NULL OR Eliminado = 0)
            LIMIT 1";

    $res = $mysqli->query($sql);
    return $res && $res->num_rows > 0;
}

/**
 * Bultos de entrega-desde-depósito del recorrido que todavía NO fueron
 * validados en Warehouse. Las colectas y los retiros no cuentan: se escanean
 * durante el recorrido, no antes de salir.
 */
function bultosSinEscaneoWarehouse(mysqli $mysqli, string $recorrido): int
{
    if (trim($recorrido) === '') return 0;
    $recEsc = $mysqli->real_escape_string($recorrido);

    $sql = "SELECT COUNT(*) AS faltan
            FROM HojaDeRuta
            INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
            WHERE HojaDeRuta.Estado = 'Abierto'
              AND HojaDeRuta.Devuelto = 0
              AND HojaDeRuta.Eliminado = 0
              AND HojaDeRuta.Recorrido = '{$recEsc}'
              AND TransClientes.Eliminado = '0'
              AND TransClientes.Retirado = 1
              AND (TransClientes.idColecta IS NULL OR TransClientes.idColecta = 0)
              AND NOT EXISTS (
                  SELECT 1 FROM Seguimiento s
                  WHERE SUBSTRING_INDEX(s.CodigoSeguimiento, '_', 1) = SUBSTRING_INDEX(TransClientes.CodigoSeguimiento, '_', 1)
                    AND s.status = 'warehouse_validated'
                    AND (s.Eliminado IS NULL OR s.Eliminado = 0)
                  LIMIT 1
              )";

    $res = $mysqli->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    return (int)($row['faltan'] ?? 0);
}

/**
 * ¿El recorrido activo del chofer tiene prendido el override de control
 * de escaneo? Si falta la columna, devuelve false.
 */
function overrideEscaneo(mysqli $mysqli, int $userId): bool
{
    if ($userId <= 0) return false;
    if (!tieneColumna($mysqli, 'Logistica', 'OmitirControlEscaneo')) return false;

    $sql = "SELECT OmitirControlEscaneo
            FROM Logistica
            WHERE idUsuarioChofer = {$userId}
              AND Estado = 'Cargada'
              AND Eliminado = 0
            LIMIT 1";

    $res = $mysqli->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    return (int)($row['OmitirControlEscaneo'] ?? 0) === 1;
}

/**
 * Registra un bypass del control de escaneo (auditoría).
 * $contexto: 'panel' | 'iniciar_recorrido' | 'confirmo_entrega'
 */
function logBypassEscaneo(array $data): void
{
    $logFile = __DIR__ . '/../logs/control_escaneo_bypass.log';
    $line = [
        'fecha'     => date('Y-m-d H:i:s'),
        'usuario'   => $data['usuario']   ?? '',
        'recorrido' => $data['recorrido'] ?? '',
        'cs'        => $data['cs']        ?? '',
        'contexto'  => $data['contexto']  ?? '',
    ];
    @file_put_contents(
        $logFile,
        json_encode($line, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
