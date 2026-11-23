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
    $baseLike = addcslashes($baseEsc, '%_') . '\\_%'; // Código exacto o con sufijo de bulto, con el índice (SUBSTRING_INDEX recorría la tabla entera; 2026-11-18)
    $inList  = "'" . implode("','", CONTROL_ESCANEO_STATUSES) . "'";

    $sql = "SELECT 1
            FROM Seguimiento
            WHERE (CodigoSeguimiento = '{$baseEsc}' OR CodigoSeguimiento LIKE '{$baseLike}')
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
/**
 * Escaneos de Seguimiento de varios envíos en UNA consulta que usa el índice de CodigoSeguimiento:
 * busca cada código exacto (BASE) o con sufijo de bulto (BASE_n) escrito literal.
 *
 * FIX (2026-11-18, app lenta / trabada): antes cada parada hacía su propia subconsulta con
 * SUBSTRING_INDEX(s.CodigoSeguimiento, '_', 1) = ..., que no puede usar el índice y recorría
 * Seguimiento entero (~1 millón de filas) por parada: 31 s para un recorrido de 78 paradas, en
 * Paneles (tarjetas), GetLista y PuedeSalir de Warehouse. Con varios choferes a la vez se ocupaban
 * todos los procesos de PHP y hasta version.php tardaba un minuto. Mismo resultado, ~1 s.
 *
 * @param string[] $bases CodigoSeguimiento de TransClientes (sin sufijo)
 * @return array<string, array{wv: array<string,bool>, ml: bool, retiro: bool}> por base en mayúsculas:
 *         wv = NumerodeOrden con warehouse_validated; ml = pickup_scanned de MercadoLibre;
 *         retiro = algún pickup_scanned
 */
function escaneosPorBase(mysqli $mysqli, array $bases): array
{
    $bases = array_values(array_unique(array_filter(array_map(fn($b) => strtoupper(trim((string)$b)), $bases), fn($b) => $b !== '')));
    $out = [];
    foreach (array_chunk($bases, 200) as $chunk) {
        $conds = [];
        foreach ($chunk as $b) {
            $e = $mysqli->real_escape_string($b);
            $conds[] = "s.CodigoSeguimiento = '{$e}'";
            $conds[] = "s.CodigoSeguimiento LIKE '" . addcslashes($e, '%_') . "\\_%'";
        }
        $res = $mysqli->query("SELECT s.CodigoSeguimiento, s.status, s.Usuario, s.NumerodeOrden FROM Seguimiento s
            WHERE (" . implode(' OR ', $conds) . ")
              AND s.status IN ('warehouse_validated', 'pickup_scanned', 'last_mile')
              AND (s.Eliminado IS NULL OR s.Eliminado = 0)");
        while ($res && $r = $res->fetch_assoc()) {
            $cs = strtoupper((string)$r['CodigoSeguimiento']);
            $base = strpos($cs, '_') !== false ? strstr($cs, '_', true) : $cs;
            $out[$base] = $out[$base] ?? ['wv' => [], 'lm' => [], 'ml' => false, 'retiro' => false];
            if ($r['status'] === 'warehouse_validated') {
                $out[$base]['wv'][(string)$r['NumerodeOrden']] = true;
            } elseif ($r['status'] === 'last_mile') {
                // En Tránsito = salida confirmada de esa orden (registrarSalidaEnTransito)
                $out[$base]['lm'][(string)$r['NumerodeOrden']] = true;
            } else {
                $out[$base]['retiro'] = true;
                if ((string)$r['Usuario'] === 'MercadoLibre') $out[$base]['ml'] = true;
            }
        }
    }
    return $out;
}

function candidatosSinEscaneoWarehouse(mysqli $mysqli, string $recorrido, bool $exigirSalida = false): array
{
    return estadoEscaneoRecorrido($mysqli, $recorrido, $exigirSalida)['candidatos'];
}

/**
 * ¿La carga del recorrido quedó confirmada? Sí cuando no falta escanear ni confirmar nada y la salida
 * está registrada (En Tránsito de esta orden) o el recorrido ya arrancó. Si después le suman un envío,
 * vuelve a false hasta que lo escaneen y confirmen. Lo usa Warehouse para apagar "Confirmar carga".
 */
function cargaConfirmada(mysqli $mysqli, string $recorrido): bool
{
    $e = estadoEscaneoRecorrido($mysqli, $recorrido, true);
    return !$e['candidatos'] && $e['salida'];
}

/**
 * Entregas del recorrido que todavía no pueden tener tarjeta.
 *
 * $exigirSalida (Paneles, 23/11/2026, Patricio: "para darle entidad al escaneo"): además de escaneada
 * en Warehouse, la entrega tiene que tener la carga confirmada (En Tránsito de esta orden) o el
 * recorrido ya arrancado (HoraSalidaReal: lo que ya está en la calle no se esconde). Esas quedan con
 * 'escaneado' => true para no contarlas como "sin escanear". Retiros, colectas, retiro+entrega del
 * mismo chofer y lo confirmado por MELI no entran nunca; con "Omitir control de escaneo" no se llama.
 *
 * @return array{candidatos: array<int,array>, salida: bool} salida = hay En Tránsito de la orden o arrancó
 */
function estadoEscaneoRecorrido(mysqli $mysqli, string $recorrido, bool $exigirSalida = false): array
{
    if (trim($recorrido) === '') return ['candidatos' => [], 'salida' => false];
    $recEsc = $mysqli->real_escape_string($recorrido);

    $sql = "SELECT TransClientes.id, TransClientes.idColecta, TransClientes.Cantidad,
                   TransClientes.CodigoSeguimiento, HojaDeRuta.NumerodeOrden
            FROM HojaDeRuta
            INNER JOIN TransClientes ON TransClientes.id = HojaDeRuta.idTransClientes
            WHERE HojaDeRuta.Estado = 'Abierto'
              AND HojaDeRuta.Devuelto = 0
              AND HojaDeRuta.Eliminado = 0
              AND HojaDeRuta.Recorrido = '{$recEsc}'
              AND TransClientes.Eliminado = '0'
              AND TransClientes.Retirado = 1
              AND TransClientes.idClienteDestino <> 18587
              -- El warehouse_validated de ESTA salida se mira abajo con escaneosPorBase() (con índice)
              -- Retiro y entrega: lo retiró este mismo chofer en ESTA salida, está arriba de la
              -- camioneta; no pasa por el depósito antes de entregarlo.
              AND NOT EXISTS (
                  SELECT 1 FROM Seguimiento r
                  WHERE r.CodigoSeguimiento = TransClientes.CodigoSeguimiento
                    AND r.status = 'pickup_ready'
                    AND r.NumerodeOrden = HojaDeRuta.NumerodeOrden
                    AND (r.Eliminado IS NULL OR r.Eliminado = 0)
                  LIMIT 1
              )";

    $res = $mysqli->query($sql);
    if (!$res) return ['candidatos' => [], 'salida' => false];

    $filas = $res->fetch_all(MYSQLI_ASSOC);
    // Ya escaneado en Warehouse en esta salida: el escaneo de una visita anterior no cuenta (ver GetLista)
    $escaneos = escaneosPorBase($mysqli, array_column($filas, 'CodigoSeguimiento'));

    // Órdenes que ya arrancaron (HoraSalidaReal): cuentan como salida confirmada.
    $arrancadas = [];
    $ordenes = array_unique(array_filter(array_map('intval', array_column($filas, 'NumerodeOrden'))));
    if ($ordenes) {
        $rA = $mysqli->query("SELECT NumerodeOrden FROM Logistica WHERE Eliminado = 0 AND HoraSalidaReal IS NOT NULL
                              AND NumerodeOrden IN (" . implode(',', $ordenes) . ")");
        while ($rA && $a = $rA->fetch_assoc()) $arrancadas[(string)$a['NumerodeOrden']] = true;
    }

    $salida = (bool)$arrancadas;
    $candidatos = [];
    $idsColecta = [];
    foreach ($filas as $row) {
        $base = strtoupper((string)$row['CodigoSeguimiento']);
        $ord = (string)$row['NumerodeOrden'];
        $confirmada = isset($escaneos[$base]['lm'][$ord]) || isset($arrancadas[$ord]);
        if ($confirmada) $salida = true;
        $escaneado = isset($escaneos[$base]['wv'][$ord]);
        if ($escaneado && (!$exigirSalida || $confirmada)) continue;
        $idTr = (int)$row['id'];
        $idCol = (int)($row['idColecta'] ?? 0);
        $bultos = (int)($row['Cantidad'] ?? 1);
        if ($bultos < 1) $bultos = 1;
        $candidatos[$idTr] = ['id' => $idTr, 'idColecta' => $idCol, 'bultos' => $bultos, 'escaneado' => $escaneado];
        if ($idCol > 0) $idsColecta[$idCol] = true;
    }
    if (!$candidatos) return ['candidatos' => [], 'salida' => $salida];

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

    return ['candidatos' => $candidatos, 'salida' => $salida];
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

/**
 * Datos para mostrar las entregas que faltan escanear (código, pedido del cliente, cliente, destino,
 * bultos), a partir de lo que devuelve candidatosSinEscaneoWarehouse().
 */
function detalleSinEscaneo(mysqli $mysqli, array $candidatos): array
{
    if (!$candidatos) return [];
    $ids = implode(',', array_map('intval', array_keys($candidatos)));
    $res = $mysqli->query("SELECT id, CodigoSeguimiento, CodigoProveedor, RazonSocial, ClienteDestino, Cantidad
                             FROM TransClientes WHERE id IN ({$ids}) ORDER BY CodigoSeguimiento");
    $out = [];
    while ($res && $r = $res->fetch_assoc()) {
        $prov = trim((string)$r['CodigoProveedor']);
        $out[] = [
            'id' => (int)$r['id'],
            'codigo' => (string)$r['CodigoSeguimiento'],
            'pedido' => ($prov !== '' && $prov !== '0') ? $prov : '',
            'cliente' => (string)$r['RazonSocial'],
            'destino' => (string)$r['ClienteDestino'],
            'bultos' => max(1, (int)$r['Cantidad']),
        ];
    }
    return $out;
}

/**
 * Deja asentado que el chofer quedó trabado (no pudo confirmar la carga o arrancar el recorrido) y por
 * qué, en la tabla RepartoBloqueos: la oficina lo ve en Logística > Órdenes > Control de la orden
 * (23/11/2026). Sin la tabla (migración sin correr) no hace nada; nunca corta el flujo del chofer.
 * $accion: 'confirmar_carga' | 'iniciar_recorrido'. $motivo: 'sin_escanear' | 'bolsin' | ...
 */
function registrarBloqueoReparto(mysqli $mysqli, string $accion, string $recorrido, string $motivo, array $detalle = [], string $mensaje = ''): void
{
    try {
        static $hayTabla = null;
        if ($hayTabla === null) {
            $r = $mysqli->query("SHOW TABLES LIKE 'RepartoBloqueos'");
            $hayTabla = $r && $r->num_rows > 0;
        }
        if (!$hayTabla) return;
        if (!function_exists('ordenCargadaChofer')) require_once __DIR__ . '/bolsines.php';

        $idUsuario = (int)($_SESSION['idusuario'] ?? 0);
        $usuario = (string)($_SESSION['Usuario'] ?? '');
        $orden = (int)(ordenCargadaChofer($mysqli, $idUsuario) ?? 0);
        $codigos = implode(', ', array_map(fn($d) => is_array($d) ? (string)($d['codigo'] ?? '') : (string)$d, $detalle));
        $cantidad = count($detalle);
        $fecha = date('Y-m-d');
        $hora = date('H:i:s');
        $st = $mysqli->prepare("INSERT INTO RepartoBloqueos (Fecha, Hora, idUsuario, Usuario, Recorrido, NumerodeOrden, Accion, Motivo, Cantidad, Codigos, Mensaje)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$st) return;
        $st->bind_param('ssissississ', $fecha, $hora, $idUsuario, $usuario, $recorrido, $orden, $accion, $motivo, $cantidad, $codigos, $mensaje);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
        error_log('registrarBloqueoReparto: ' . $e->getMessage());
    }
}
