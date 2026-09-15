<?php
// control_escaneo.php
// -----------------------------------------------------------------------------
// Control "nadie entrega lo que no escaneó" para la app de reparto.
//
// Hay DOS gates distintos acá, con criterios de "escaneado" distintos a
// propósito (no son el mismo control, ver el docblock de cada función):
//
//   - escaneoOk() / CONTROL_ESCANEO_STATUSES: control al momento de ENTREGAR
//     al cliente final. Cualquier confirmación previa de la cadena de
//     custodia alcanza (retiro, colecta cerrada sin escanear, deposito).
//
//   - bultosSinEscaneoWarehouse(): control al momento de SALIR con el
//     recorrido (antes de arrancar / paneles). Acá el retiro en el cliente
//     (pickup_scanned) NO alcanza -es un evento distinto y anterior en el
//     tiempo, no prueba que el bulto esté en el depósito listo para ESTE
//     recorrido-. Solo cuenta el escaneo real de depósito (warehouse_validated)
//     o que MercadoLibre ya haya confirmado el envío por su propia API
//     (ml_confirmado, ver el docblock de la función).
//
// Override por recorrido: Logistica.OmitirControlEscaneo = 1. Se prende a mano
// (o desde el sistema viejo) cuando falla un escáner en la calle. Cada bypass
// se registra en logs/control_escaneo_bypass.log.
//
// Si la columna OmitirControlEscaneo todavía no existe (entornos sin migrar),
// overrideEscaneo() devuelve false sin reventar.
// -----------------------------------------------------------------------------

const CONTROL_ESCANEO_STATUSES = ['warehouse_validated', 'pickup_ready', 'pickup_scanned', 'pickup_not_scanned'];

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
 * escaneados antes de salir. Cuenta:
 *   - Entregas normales desde depósito (sin colecta).
 *   - HIJOS de colecta ya retirados: en las rutas Flex salen de Wepoint/depósito
 *     como una entrega más y también hay que escanearlos.
 * NO cuenta el PADRE de la colecta (idClienteDestino = 18587): ese se retira en
 * el cliente durante el recorrido, no antes de salir.
 *
 * Un bulto está "escaneado" si:
 *   - tiene warehouse_validated (escaneo real en el depósito, via warehouse.php), o
 *   - MercadoLibre YA confirmó el envío por su propia API/webhook
 *     (ColectaScans.expected.servicios_detalle[].ml_confirmado=1, ver
 *     mlConfirmacionServicio() en colecta_scan.php). Esa es una fuente externa
 *     e independiente de que el bulto salió de lo de MELI, asi que ahorra el
 *     reescaneo en deposito PARA ESE BULTO puntual.
 *
 * El escaneo de RETIRO EN EL CLIENTE hecho por nuestro propio chofer
 * (Seguimiento.status='pickup_scanned', sea colecta de MercadoLibre o de
 * proveedor/Ferniplast) NO alcanza: es un evento distinto y anterior en el
 * tiempo que solo prueba que el bulto salió del cliente, no que esté
 * físicamente en el depósito listo para salir en ESTE recorrido -entre el
 * retiro y la salida puede pasar horas y el bulto puede terminar en otro
 * recorrido, perderse, etc. Es la confirmación de MELI (verificación externa)
 * la que ahorra el reescaneo, no nuestro propio escaneo de retiro.
 *
 * FIX (2026-09-14): esto contaba 1 por cada FILA de TransClientes pendiente,
 * sin mirar Cantidad - un envío de 2 bultos sumaba "1 falta" acá pero "2
 * faltan" en la pantalla de Warehouse (Proceso/php/warehouse.php::GetLista,
 * que sí expande por Cantidad). El chofer veía "Faltan escanear 13 bultos"
 * en Recorrido y "FALTAN 14" en Warehouse para el mismo recorrido - mismo
 * gate, mismo texto ("bultos"), unidades distintas. Ahora suma Cantidad
 * igual que GetLista, para que los dos números siempre coincidan.
 */
function bultosSinEscaneoWarehouse(mysqli $mysqli, string $recorrido): int
{
    $faltan = 0;
    foreach (candidatosSinEscaneoWarehouse($mysqli, $recorrido) as $row) {
        $faltan += $row['bultos'];
    }
    return $faltan;
}

/**
 * Devuelve, en un array [idTransCliente => true], los bultos de
 * entrega-desde-depósito del recorrido que TODAVÍA no fueron escaneados
 * (mismo criterio que bultosSinEscaneoWarehouse() - ver su docblock para el
 * detalle de qué cuenta como "escaneado" y por qué el PADRE de colecta y
 * los confirmados por MELI no entran acá).
 *
 * Extraída de bultosSinEscaneoWarehouse() (2026-09-15, a pedido) para poder
 * usarse como FILTRO por fila en Paneles (funciones_hdr.php) en vez de
 * como gate de "todo o nada" para toda la pantalla: la colecta (Retirado=0,
 * el chofer todavía no la retiró) siempre tiene que verse - es el mecanismo
 * por el cual el chofer arranca a escanearla, no puede depender de un
 * escaneo de Warehouse previo. Solo las entregas normales desde depósito
 * (y los hijos de colecta ya retirados, no confirmados por MELI) tienen que
 * quedar ocultas hasta que Warehouse las valide.
 */
function candidatosSinEscaneoWarehouse(mysqli $mysqli, string $recorrido): array
{
    if (trim($recorrido) === '') return [];
    $recEsc = $mysqli->real_escape_string($recorrido);

    $sql = "SELECT TransClientes.id, TransClientes.idColecta, TransClientes.Cantidad
            FROM HojaDeRuta
            INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
            WHERE HojaDeRuta.Estado = 'Abierto'
              AND HojaDeRuta.Devuelto = 0
              AND HojaDeRuta.Eliminado = 0
              AND HojaDeRuta.Recorrido = '{$recEsc}'
              AND TransClientes.Eliminado = '0'
              AND TransClientes.Retirado = 1
              AND TransClientes.idClienteDestino <> 18587
              AND NOT EXISTS (
                  SELECT 1 FROM Seguimiento s
                  WHERE SUBSTRING_INDEX(s.CodigoSeguimiento, '_', 1) = SUBSTRING_INDEX(TransClientes.CodigoSeguimiento, '_', 1)
                    AND s.status = 'warehouse_validated'
                    AND (s.Eliminado IS NULL OR s.Eliminado = 0)
                  LIMIT 1
              )";

    $res = $mysqli->query($sql);
    if (!$res) return [];

    $candidatos = [];
    $idsColecta = [];
    while ($row = $res->fetch_assoc()) {
        $idTr = (int)$row['id'];
        $idCol = (int)($row['idColecta'] ?? 0);
        $bultos = (int)($row['Cantidad'] ?? 1);
        if ($bultos < 1) $bultos = 1;
        $candidatos[$idTr] = ['id' => $idTr, 'idColecta' => $idCol, 'bultos' => $bultos];
        if ($idCol > 0) $idsColecta[$idCol] = true;
    }
    if (!$candidatos) return [];

    // ml_confirmado por idTransCliente, leyendo el JSON de cada Colecta
    // involucrada una sola vez (no por bulto).
    $mlConfirmado = [];
    if ($idsColecta) {
        $in = implode(',', array_map('intval', array_keys($idsColecta)));
        $rc = $mysqli->query("SELECT ColectaScans FROM Colecta WHERE id IN ({$in})");
        while ($rc && $c = $rc->fetch_assoc()) {
            $payload = json_decode((string)($c['ColectaScans'] ?? ''), true);
            $det = $payload['expected']['servicios_detalle'] ?? null;
            if (!is_array($det)) continue;
            foreach ($det as $sd) {
                $idTr = (int)($sd['idTransCliente'] ?? 0);
                if ($idTr > 0 && !empty($sd['ml_confirmado'])) {
                    $mlConfirmado[$idTr] = true;
                }
            }
        }
    }

    foreach ($mlConfirmado as $idTr => $_) {
        unset($candidatos[$idTr]); // MELI ya lo confirmo -> no hace falta reescanear, no queda oculto
    }

    return $candidatos;
}

/**
 * Cuántas tarjetas/paradas pendientes tiene el recorrido (mismo criterio
 * que Proceso/php/funciones_hdr.php::Paneles usa para listarlas).
 *
 * GATE de arranque (2026-09-14, a pedido): antes "Iniciar Recorrido" y el
 * panel de tarjetas exigían bultosSinEscaneoWarehouse()===0 (escaneo real
 * de depósito). En la práctica eso trababa casi TODAS las rutas con
 * colecta (el depósito no escanea sistemáticamente en Warehouse) y la
 * oficina terminaba prendiendo OmitirControlEscaneo a mano todas las
 * mañanas, en casi el 100% de las órdenes - el gate dejó de ser una
 * excepción para convertirse en un trámite diario. Se reemplaza por un
 * chequeo mucho más simple y confiable: si el recorrido no tiene NINGUNA
 * tarjeta pendiente asignada, no tiene sentido arrancarlo (caso real que
 * originó esto: no era esto, era un falso "arrancó" por GPS, pero de paso
 * se saca el gate de escaneo que ya se sabía que rompía las colectas).
 * El control de escaneo real de depósito sigue existiendo y viéndose en
 * Warehouse - solo deja de BLOQUEAR la salida del recorrido.
 */
function tarjetasPendientes(mysqli $mysqli, string $recorrido): int
{
    if (trim($recorrido) === '') return 0;
    $recEsc = $mysqli->real_escape_string($recorrido);

    $res = $mysqli->query(
        "SELECT COUNT(*) AS n
         FROM HojaDeRuta
         INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
         WHERE HojaDeRuta.Estado = 'Abierto'
           AND HojaDeRuta.Devuelto = 0
           AND HojaDeRuta.Eliminado = 0
           AND HojaDeRuta.Recorrido = '{$recEsc}'
           AND TransClientes.Eliminado = '0'"
    );
    $row = $res ? $res->fetch_assoc() : null;
    return (int)($row['n'] ?? 0);
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
            ORDER BY id DESC
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
