<?php
// Proceso/php/colecta_scan.php
// - InitColecta: arma y guarda ColectaScans (expected + scans + resume) en tabla Colecta
// - ColectaBulto: registra scan en ColectaScans + genera Seguimiento:
//      * COLECTA: PADRE => pickup_ready (id=3) 1 vez, HIJOS => pickup_scanned (id=14) por bulto
//      * RETIRO (sin colectaId): pickup_ready (id=3) sobre BASE_1 (canon)
//
// Requisitos:
// - Tabla Colecta: campos ColectaScans, ColectaScansUpdatedAt
// - Tabla Seguimiento: columnas usadas en insert
// - Funciones/estados.php => estadoPorSlug($mysqli, $slug) => ['id'=>..., 'Estado'=>...]
//
// Nota: Código sin "??" para máxima compatibilidad.

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "../../Conexion/conexioni.php";
require_once __DIR__ . '/../../Funciones/estados.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');
header('Content-Type: application/json; charset=utf-8');

function responder(array $arr): void
{
    // Auto-log de errores (success=0) si viene contexto mínimo
    if (($arr['success'] ?? null) === 0 || isset($arr['error'])) {
        // intentamos capturar contexto sin romper si no existe
        $usuario   = $_SESSION['Usuario'] ?? ($_POST['Usuario'] ?? '');
        $recorrido = $_SESSION['RecorridoAsignado'] ?? ($_POST['Recorrido'] ?? '');
        $colectaId = (int)($_POST['colectaId'] ?? 0);
        $padreId   = (int)($_POST['padreId'] ?? 0);
        $raw       = (string)($_POST['raw'] ?? ($_POST['bulto'] ?? ($_POST['base'] ?? '')));

        // armamos detalle "explicativo"
        $detalle = $arr['detail'] ?? ($arr['detalle'] ?? null) ?? ($arr['debug'] ?? null);

        logScanError([
            'usuario'   => $usuario,
            'recorrido' => $recorrido,
            'colectaId' => $colectaId,
            'padreId'   => $padreId,
            'raw'       => $raw,
            'error'     => (string)($arr['error'] ?? 'ERROR'),
            'detalle'   => $detalle,
        ]);
    }

    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function logScanError($data)
{
    $logFile = __DIR__ . "/../../logs/colecta_scan_errors.log";

    $line = [
        'fecha'      => date('Y-m-d H:i:s'),
        'usuario'    => $data['usuario'] ?? '',
        'recorrido'  => $data['recorrido'] ?? '',
        'colectaId'  => $data['colectaId'] ?? '',
        'padreId'    => $data['padreId'] ?? '',
        'raw'        => $data['raw'] ?? '',
        'error'      => $data['error'] ?? '',
        'detalle'    => $data['detalle'] ?? ''
    ];

    file_put_contents(
        $logFile,
        json_encode($line, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
/* ============================================================
   Helpers generales
   ============================================================ */

function parseBaseAndSuffix($code)
{
    $code = trim((string)$code);
    $parts = explode('_', $code);
    $base = trim($parts[0] ?? '');
    $suf  = null;
    if (count($parts) >= 2) {
        $n = (int)$parts[1];
        $suf = $n > 0 ? $n : null;
    }
    return [$base, $suf];
}

function parseMeliJsonId($raw)
{
    $t = trim((string)$raw);
    if ($t === '' || $t[0] !== '{') return null;

    $j = json_decode($t, true);
    if (is_array($j) && isset($j['id']) && $j['id'] !== '') {
        return trim((string)$j['id']);
    }
    return null;
}

function parseCaddyBase($raw)
{
    $t = strtoupper(trim((string)$raw));
    if ($t === '') return '';
    $base = explode('_', $t)[0];
    return trim($base);
}

function getExpectedServicio($payload, $base)
{
    if (!is_array($payload)) return null;
    $exp = $payload['expected'] ?? null;
    $det = is_array($exp) ? ($exp['servicios_detalle'] ?? null) : null;
    if (!is_array($det)) return null;

    foreach ($det as $svc) {
        $svcBase = isset($svc['cs_base']) ? trim((string)$svc['cs_base']) : '';
        if ($svcBase !== '' && $svcBase === trim((string)$base)) return $svc;
    }
    return null;
}

function calcularResume($payload)
{
    $exp   = is_array($payload) ? ($payload['expected'] ?? []) : [];
    $det   = is_array($exp) ? ($exp['servicios_detalle'] ?? []) : [];
    $scans = is_array($payload) ? ($payload['scans'] ?? []) : [];

    if (!is_array($scans)) $scans = [];
    if (!is_array($det))   $det = [];

    $paquetes_ok = 0;
    foreach ($scans as $s) {
        $paquetes_ok += (int)($s['qty'] ?? 1);
    }

    $servicios_ok = 0;
    foreach ($det as $svc) {
        $base = trim((string)($svc['cs_base'] ?? ''));
        $need = (int)($svc['paquetes'] ?? 0);
        if ($base === '' || $need <= 0) continue;

        $have = 0;
        foreach ($scans as $s) {
            if (trim((string)($s['base'] ?? '')) === $base) {
                $have += (int)($s['qty'] ?? 1);
            }
        }
        if ($have >= $need) $servicios_ok++;
    }

    $servicios_total = (int)($exp['servicios'] ?? (is_array($det) ? count($det) : 0));
    $paquetes_total  = (int)($exp['paquetes_total'] ?? 0);

    return [
        'servicios_ok'    => $servicios_ok,
        'servicios_total' => $servicios_total,
        'paquetes_ok'     => $paquetes_ok,
        'paquetes_total'  => $paquetes_total,
    ];
}

function leerResumeColecta($mysqli, $colectaId)
{
    $colectaId = (int)$colectaId;
    if ($colectaId <= 0) return null;

    $st = $mysqli->prepare("SELECT ColectaScans FROM Colecta WHERE id=? LIMIT 1");
    $st->bind_param("i", $colectaId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $json = $row ? ($row['ColectaScans'] ?? '') : '';
    if (trim((string)$json) === '') return null;

    $payload = json_decode($json, true);
    if (!is_array($payload)) return null;

    if (empty($payload['resume'])) {
        $payload['resume'] = calcularResume($payload);
    }
    return $payload['resume'] ?? null;
}

/**
 * Inserta Seguimiento si no existe (por CodigoSeguimiento exacto + status).
 * Devuelve:
 *  - ['inserted'=>1] si insertó
 *  - ['inserted'=>0] si ya existía
 */
function upsertSeguimiento($mysqli, $data)
{
    $codigo   = (string)($data['codigo'] ?? '');
    $status   = (string)($data['status'] ?? '');
    $estadoId = (int)($data['estado_id'] ?? 0);
    $estadoTx = (string)($data['estado_txt'] ?? '');
    $destino  = (string)($data['destino'] ?? '');
    $idCliente = (int)($data['idCliente'] ?? 0);
    $idTrans   = (int)($data['idTransClientes'] ?? 0);
    $usuario   = (string)($data['usuario'] ?? '');
    $sucursal  = (string)($data['sucursal'] ?? '');
    $recorrido = (string)($data['recorrido'] ?? '');
    $nroOrden  = (string)($data['nroOrden'] ?? '');
    $obs       = (string)($data['obs'] ?? '');
    $retirado  = (int)($data['retirado'] ?? 0);
    $codigo = strtoupper(trim((string)$codigo));
    $codigo = explode('_', $codigo)[0]; // BASE pura siempre
    $codigo = trim($codigo);

    if ($codigo === '' || $status === '' || $estadoId <= 0) {
        return ['inserted' => 0, 'error' => 'DATOS_INSUFICIENTES_SEGUIMIENTO'];
    }

    $chkSql = "SELECT 1 FROM Seguimiento WHERE CodigoSeguimiento=? AND status=? AND Eliminado=0";
    if ($status === 'pickup_scanned') {
        $chkSql .= " AND Observaciones=?";
    }
    $chkSql .= " LIMIT 1";

    $chk = $mysqli->prepare($chkSql);

    if ($status === 'pickup_scanned') {
        $chk->bind_param("sss", $codigo, $status, $obs);
    } else {
        $chk->bind_param("ss", $codigo, $status);
    }
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        return ['inserted' => 0];
    }



    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');

    $sql = $mysqli->prepare("
        INSERT INTO Seguimiento
        (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones,
         Entregado, Estado, Destino, Avisado, idCliente, Retirado, Visitas,
         idTransClientes, TimeStamp, Recorrido, Devuelto, Webhook, state_id,
         NumerodeOrden, status, Eliminado, Estado_id)
        VALUES
        (?, ?, ?, ?, ?, ?,
         0, ?, ?, 0, ?, ?, 0,
         ?, NOW(), ?, 0, 0, ?,
         ?, ?, 0, ?)
    ");
    if (!$sql) {
        return ['inserted' => 0, 'error' => 'PREPARE_FAILED', 'detail' => $mysqli->error];
    }

    // Params (16):
    // fecha(s), hora(s), usuario(s), sucursal(s), codigo(s), obs(s),
    // estadoTx(s), destino(s),
    // idCliente(i), retirado(i), idTrans(i),
    // recorrido(s), state_id(i),
    // nroOrden(s), status(s),
    // Estado_id(i)
    $types = "ssssssssiiisissi";

    if (!$sql->bind_param(
        $types,
        $fecha,
        $hora,
        $usuario,
        $sucursal,
        $codigo,
        $obs,
        $estadoTx,
        $destino,
        $idCliente,
        $retirado,
        $idTrans,
        $recorrido,
        $estadoId,
        $nroOrden,
        $status,
        $estadoId
    )) {
        return ['inserted' => 0, 'error' => 'BIND_FAILED', 'detail' => $sql->error];
    }

    if (!$sql->execute()) {
        return ['inserted' => 0, 'error' => 'EXECUTE_FAILED', 'detail' => $sql->error];
    }

    return ['inserted' => 1];
}

/**
 * Para COLECTA: decide qué CodigoSeguimiento "hijo" marcar como pickup_scanned.
 * - Si escaneo QR con sufijo => usa ese exacto (canoniza a _1 si paquetesSvc==1)
 * - Si es token (ML/PROV/FERNI) => asigna el primer hijo sin pickup_scanned
 */
function resolverCodigoHijoParaSeguimiento($mysqli, $colectaId, $base, $raw, $tipoDetectado, $paquetesSvc)
{
    $colectaId = (int)$colectaId;
    $base = strtoupper(trim((string)$base));
    $rawUp = strtoupper(trim((string)$raw));
    $paquetesSvc = (int)$paquetesSvc;
    if ($paquetesSvc <= 0) $paquetesSvc = 1;

    // QR con sufijo: BASE_2, BASE_3...
    if ($tipoDetectado === 'CADDY_QR') {
        // Si trae sufijo
        if (preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $rawUp, $m)) {
            $suf = (int)$m[1];
            if ($paquetesSvc <= 1) return $base . "_1";
            if ($suf >= 1 && $suf <= $paquetesSvc) return $base . "_" . $suf;
            return null;
        }
        // Si no trae sufijo
        if ($paquetesSvc <= 1) return $base . "_1";
        return null; // para paquetes>1 QR sin sufijo no define hijo único
    }

    // Tokens (ML/PROV/FERNI): asignar siguiente hijo libre
    if ($colectaId <= 0) return null;

    $st = $mysqli->prepare("
        SELECT t.CodigoSeguimiento
        FROM TransClientes t
        WHERE t.idColecta=?
          AND t.Eliminado=0 AND t.Entregado=0 AND t.Devuelto=0
          AND SUBSTRING_INDEX(t.CodigoSeguimiento,'_',1)=?
        ORDER BY CAST(SUBSTRING_INDEX(t.CodigoSeguimiento,'_',-1) AS UNSIGNED) ASC
    ");
    $st->bind_param("is", $colectaId, $base);
    $st->execute();
    $rs = $st->get_result();

    while ($r = $rs->fetch_assoc()) {
        $cs = strtoupper(trim((string)($r['CodigoSeguimiento'] ?? '')));
        if ($cs === '') continue;

        // ¿ya tiene pickup_scanned?
        $chk = $mysqli->prepare("
            SELECT 1 FROM Seguimiento
            WHERE CodigoSeguimiento=? AND status='pickup_scanned' AND Eliminado=0
            LIMIT 1
        ");
        $chk->bind_param("s", $cs);
        $chk->execute();
        $has = $chk->get_result()->num_rows > 0;

        if (!$has) return $cs;
    }

    return null;
}

/**
 * Resolver TransClientes "servicio" a partir de raw, acotando por colecta si aplica.
 * Devuelve:
 *  [
 *    'tipo' => 'ML_JSON'|'CADDY_QR'|'FERNIPLAST_CODPROV'|'PROV_CODPROV',
 *    'idTransClientes' => int,
 *    'cs_base' => string,
 *    'cantidad' => int,            // Cantidad esperada del servicio
 *    'idClienteOrigen' => int,
 *    'idClienteDestino' => int,
 *    'destino' => string,
 *    'nroOrden' => string,
 *    'token_store' => string,      // shipment_id / CodigoProveedor / raw
 *    'codigoSeguimiento' => string // CodigoSeguimiento encontrado
 *  ]
 */
function resolverServicioColecta($mysqli, $colectaId, $padreId, $raw, $ferniplastClienteId = 0)
{
    $raw = trim((string)$raw);
    $colectaId = (int)$colectaId;
    $padreId   = (int)$padreId;

    if ($raw === '') return null;

    // 1) Mercado Libre JSON => shipments_id
    $meliId = parseMeliJsonId($raw);
    if ($meliId !== null && ctype_digit($meliId)) {
        $ship = (string)$meliId; // NO casteo a int


        if ($colectaId > 0) {
            $st = $mysqli->prepare("
                SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                FROM TransClientes
                WHERE idColecta=? AND Eliminado=0 AND Entregado=0 AND Devuelto=0
                  AND shipments_id=?
                  AND (?=0 OR id<>?)
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->bind_param("isii", $colectaId, $ship, $padreId, $padreId);
        } else {
            $st = $mysqli->prepare("
                SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                FROM TransClientes
                WHERE Eliminado=0 AND Entregado=0 AND Devuelto=0
                  AND shipments_id=?
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->bind_param("s", $ship);
        }

        $st->execute();
        $tr = $st->get_result()->fetch_assoc();
        if ($tr && !empty($tr['id'])) {
            $base = parseCaddyBase($tr['CodigoSeguimiento']);
            return [
                'tipo' => 'ML_JSON',
                'idTransClientes' => (int)$tr['id'],
                'cs_base' => $base,
                'cantidad' => (int)($tr['Cantidad'] ?? 1),
                'idClienteOrigen' => (int)($tr['idClienteOrigen'] ?? 0),
                'idClienteDestino' => (int)($tr['idClienteDestino'] ?? 0),
                'destino' => (string)($tr['DomicilioDestino'] ?? ''),
                'nroOrden' => (string)($tr['NumerodeOrden'] ?? ''),
                'token_store' => $meliId,
                'codigoSeguimiento' => (string)($tr['CodigoSeguimiento'] ?? ''),
            ];
        }
        return null; // si es JSON y no matchea, no forzamos otras heurísticas
    }

    // 2) Caddy QR BASE o BASE_n (match por SUBSTRING_INDEX)
    $base = parseCaddyBase($raw);
    if ($base !== '' && preg_match('/^[A-Z0-9\-]+$/', $base)) {

        if ($colectaId > 0) {
            $st = $mysqli->prepare("
                SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                FROM TransClientes
                WHERE idColecta=? AND Eliminado=0 AND Entregado=0 AND Devuelto=0
                  AND SUBSTRING_INDEX(CodigoSeguimiento,'_',1)=?
                  AND (?=0 OR id<>?)
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->bind_param("isii", $colectaId, $base, $padreId, $padreId);
        } else {
            $st = $mysqli->prepare("
                SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                FROM TransClientes
                WHERE Eliminado=0 AND Entregado=0 AND Devuelto=0
                  AND SUBSTRING_INDEX(CodigoSeguimiento,'_',1)=?
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->bind_param("s", $base);
        }

        $st->execute();
        $tr = $st->get_result()->fetch_assoc();
        if ($tr && !empty($tr['id'])) {
            return [
                'tipo' => 'CADDY_QR',
                'idTransClientes' => (int)$tr['id'],
                'cs_base' => $base,
                'cantidad' => (int)($tr['Cantidad'] ?? 1),
                'idClienteOrigen' => (int)($tr['idClienteOrigen'] ?? 0),
                'idClienteDestino' => (int)($tr['idClienteDestino'] ?? 0),
                'destino' => (string)($tr['DomicilioDestino'] ?? ''),
                'nroOrden' => (string)($tr['NumerodeOrden'] ?? ''),
                'token_store' => $raw,
                'codigoSeguimiento' => (string)($tr['CodigoSeguimiento'] ?? ''),
            ];
        }
    }

    // 3) Proveedor => CodigoProveedor (con filtro Ferniplast si aplica)
    $prov = trim((string)$raw);
    if ($prov !== '') {

        if ($colectaId > 0) {
            if ($ferniplastClienteId > 0) {
                $st = $mysqli->prepare("
                    SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                    FROM TransClientes
                    WHERE idColecta=? AND Eliminado=0 AND Entregado=0 AND Devuelto=0
                      AND CodigoProveedor=?
                      AND idClienteOrigen=?
                      AND (?=0 OR id<>?)
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $st->bind_param("isiii", $colectaId, $prov, $ferniplastClienteId, $padreId, $padreId);
                $tipo = 'FERNIPLAST_CODPROV';
            } else {
                $st = $mysqli->prepare("
                    SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                    FROM TransClientes
                    WHERE idColecta=? AND Eliminado=0 AND Entregado=0 AND Devuelto=0
                      AND CodigoProveedor=?
                      AND (?=0 OR id<>?)
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $st->bind_param("isii", $colectaId, $prov, $padreId, $padreId);
                $tipo = 'PROV_CODPROV';
            }
        } else {
            if ($ferniplastClienteId > 0) {
                $st = $mysqli->prepare("
                    SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                    FROM TransClientes
                    WHERE Eliminado=0 AND Entregado=0 AND Devuelto=0
                      AND CodigoProveedor=?
                      AND idClienteOrigen=?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $st->bind_param("si", $prov, $ferniplastClienteId);
                $tipo = 'FERNIPLAST_CODPROV';
            } else {
                $st = $mysqli->prepare("
                    SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                    FROM TransClientes
                    WHERE Eliminado=0 AND Entregado=0 AND Devuelto=0
                      AND CodigoProveedor=?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $st->bind_param("s", $prov);
                $tipo = 'PROV_CODPROV';
            }
        }

        $st->execute();
        $tr = $st->get_result()->fetch_assoc();
        if ($tr && !empty($tr['id'])) {
            $base2 = parseCaddyBase($tr['CodigoSeguimiento']);
            return [
                'tipo' => $tipo,
                'idTransClientes' => (int)$tr['id'],
                'cs_base' => $base2,
                'cantidad' => (int)($tr['Cantidad'] ?? 1),
                'idClienteOrigen' => (int)($tr['idClienteOrigen'] ?? 0),
                'idClienteDestino' => (int)($tr['idClienteDestino'] ?? 0),
                'destino' => (string)($tr['DomicilioDestino'] ?? ''),
                'nroOrden' => (string)($tr['NumerodeOrden'] ?? ''),
                'token_store' => $prov,
                'codigoSeguimiento' => (string)($tr['CodigoSeguimiento'] ?? ''),
            ];
        }
    }

    return null;
}

/* ============================================================
   ROUTER 1: InitColecta
   ============================================================ */

if (isset($_POST['InitColecta'])) {

    $colectaId = (int)($_POST['colectaId'] ?? 0); // Colecta.id
    $padreId   = (int)($_POST['padreId'] ?? 0);   // TransClientes.id (padre)

    if ($colectaId <= 0 || $padreId <= 0) {
        responder(['success' => 0, 'error' => 'FALTA_COLECTAID_O_PADREID']);
    }

    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // Traer colecta real
        $stC = $mysqli->prepare("
          SELECT id, Cantidad, Cantidad_m
          FROM Colecta
          WHERE id=? AND Eliminado=0
          LIMIT 1
        ");
        $stC->bind_param("i", $colectaId);
        $stC->execute();
        $c = $stC->get_result()->fetch_assoc();

        if (!$c) responder(['success' => 0, 'error' => 'COLECTA_NO_ENCONTRADA']);

        $totalEsperado = (int)($c['Cantidad_m'] ?? 0);
        if ($totalEsperado <= 0) $totalEsperado = (int)($c['Cantidad'] ?? 0);

        // Servicios asignados a esa colecta (excluyo padre)
        $stT = $mysqli->prepare("
          SELECT id, CodigoSeguimiento, Cantidad, shipments_id, CodigoProveedor
          FROM TransClientes
          WHERE idColecta = ?
            AND Eliminado=0 AND Entregado=0 AND Devuelto=0
            AND id <> ?
        ");
        $stT->bind_param("ii", $colectaId, $padreId);
        $stT->execute();
        $res = $stT->get_result();

        $serviciosDetalle = [];
        $sumaTrans = 0;

        while ($row = $res->fetch_assoc()) {
            $cs = trim((string)($row['CodigoSeguimiento'] ?? ''));
            if ($cs === '') continue;

            $base = trim(explode('_', $cs)[0]);
            $cant = (int)($row['Cantidad'] ?? 0);
            if ($cant <= 0) $cant = 1;

            $serviciosDetalle[] = [
                'idTransCliente'  => (int)$row['id'],
                'codigoProveedor' => (string)($row['CodigoProveedor'] ?? ''),
                'cs_base'         => $base,
                'paquetes'        => $cant,
            ];
            $sumaTrans += $cant;
        }

        // Ajuste no bloqueante
        if ($totalEsperado <= 0) $totalEsperado = $sumaTrans;
        if ($sumaTrans > 0 && $totalEsperado < $sumaTrans) $totalEsperado = $sumaTrans;

        $expected = [
            'servicios'         => count($serviciosDetalle),
            'paquetes_total'    => $totalEsperado,
            'servicios_detalle' => $serviciosDetalle,
            'colecta_id'        => $colectaId
        ];

        $payload = [
            'colecta_id' => $colectaId,
            'padre_id'   => $padreId,
            'expected'   => $expected,
            'scans'      => [],
            'resume'     => [
                'servicios_ok'    => 0,
                'servicios_total' => count($serviciosDetalle),
                'paquetes_ok'     => 0,
                'paquetes_total'  => $totalEsperado,
            ],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $now = date('Y-m-d H:i:s');

        $up = $mysqli->prepare("
          UPDATE Colecta
          SET ColectaScans=?, ColectaScansUpdatedAt=?
          WHERE id=?
        ");
        $up->bind_param("ssi", $json, $now, $colectaId);
        $up->execute();

        responder([
            'success'   => 1,
            'expected'  => $expected,
            'resume'    => $payload['resume'],
            'colectaId' => $colectaId,
            'padreId'   => $padreId,
        ]);
    } catch (Throwable $e) {
        responder(['success' => 0, 'error' => 'INIT_COLECTA_ERROR', 'detail' => $e->getMessage()]);
    }
}

/* ============================================================
   ROUTER 2: ColectaBulto
   ============================================================ */

if (!isset($_POST['ColectaBulto'])) {
    responder(['success' => 0, 'error' => 'Acción inválida']);
}

$colectaId = (int)($_POST['colectaId'] ?? 0);
$padreId   = (int)($_POST['padreId'] ?? 0);

$raw = trim((string)($_POST['raw'] ?? ''));
$basePost  = trim((string)($_POST['base'] ?? ''));
$bultoPost = trim((string)($_POST['bulto'] ?? ''));

if ($raw === '')  $raw = $bultoPost;
if ($raw === '')  $raw = $basePost;

$cantidad = (int)($_POST['cantidad'] ?? 1);

if ($cantidad <= 0) $cantidad = 1;

$usuario   = $_SESSION['Usuario']  ?? ($_POST['Usuario']  ?? '');
$sucursal  = $_SESSION['Sucursal'] ?? ($_POST['Sucursal'] ?? '');
$recorrido = $_SESSION['RecorridoAsignado'] ?? ($_POST['Recorrido'] ?? '');

if ($raw === '') {

    logScanError([
        'usuario'   => $usuario ?? '',
        'recorrido' => $recorrido ?? '',
        'colectaId' => $colectaId,
        'padreId'   => $padreId,
        'raw'       => '',
        'error'     => 'FALTA_RAW'
    ]);

    responder(['success' => 0, 'error' => 'FALTA_RAW']);
}

if ($usuario === '') {
    responder(['success' => 0, 'forceLogout' => 1, 'reason' => 'NO_IDUSUARIO', 'error' => 'Sin sesión']);
}

// Inferir “modo Ferniplast” por idClienteOrigen del padre (si hay padre)
$FERNIPLAST_CLIENTE_ID = 19396;
if ($padreId > 0) {
    $stFP = $mysqli->prepare("SELECT idClienteOrigen FROM TransClientes WHERE id=? LIMIT 1");
    $stFP->bind_param("i", $padreId);
    $stFP->execute();
    $rFP = $stFP->get_result()->fetch_assoc();
    $FERNIPLAST_CLIENTE_ID = (int)($rFP['idClienteOrigen'] ?? 0);
}

// Resolver servicio
$info = resolverServicioColecta($mysqli, $colectaId, $padreId, $raw, $FERNIPLAST_CLIENTE_ID);
if (!$info) {

    logScanError([
        'usuario'   => $usuario ?? '',
        'recorrido' => $recorrido ?? '',
        'colectaId' => $colectaId,
        'padreId'   => $padreId,
        'raw'       => $raw,
        'error'     => 'SERVICIO_NO_RESUELTO'
    ]);

    responder([
        'success' => 0,
        'error'   => 'SERVICIO_NO_RESUELTO',
        'debug'   => [
            'raw' => $raw,
            'colectaId' => $colectaId,
            'padreId' => $padreId
        ]
    ]);
}

$tipoDetectado   = (string)$info['tipo'];
$tokenStore      = (string)$info['token_store'];
$idTransClientes = (int)$info['idTransClientes'];
$base            = (string)$info['cs_base'];
$idCliente       = (int)$info['idClienteDestino'];
$destino         = (string)$info['destino'];
$nroOrden        = (string)$info['nroOrden'];
$codigoServicio  = (string)($info['codigoSeguimiento'] ?? ''); // puede ser BASE_2 etc
$codigoEscaneado = strtoupper(trim($raw));

if ($idTransClientes === 0 || !preg_match('/^[A-Za-z0-9\-]+$/', $base)) {
    responder(['success' => 0, 'error' => 'SERVICIO_INVALIDO', 'base' => $base, 'idTrans' => $idTransClientes]);
}

$isColecta = ($colectaId > 0 && $padreId > 0);

// Variables de salida/estado
$scanSavedToColecta = 0;
$colectaResume = null;
$paquetesSvc = 1;
$isDuplicate = 0;
$isMerged = 0;
$addedQty = 0;
$codeStore = "";

/* ============================================================
   1) Si es COLECTA: actualizar JSON ColectaScans (autoridad)
   ============================================================ */
if ($isColecta) {

    // Traer JSON
    $stp = $mysqli->prepare("SELECT ColectaScans FROM Colecta WHERE id=? LIMIT 1");
    $stp->bind_param("i", $colectaId);
    $stp->execute();
    $rowP = $stp->get_result()->fetch_assoc();
    $json = $rowP ? ($rowP['ColectaScans'] ?? '') : '';

    if (trim((string)$json) === '') {
        responder(['success' => 0, 'error' => 'COLECTA_NOT_INITIALIZED', 'detail' => 'Falta InitColecta']);
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        responder(['success' => 0, 'error' => 'COLECTA_JSON_INVALIDO']);
    }

    // Validar pertenencia
    $svc = getExpectedServicio($payload, $base);
    if (!$svc) {

        logScanError([
            'usuario'   => $usuario ?? '',
            'recorrido' => $recorrido ?? '',
            'colectaId' => $colectaId,
            'padreId'   => $padreId,
            'raw'       => $raw,
            'error'     => 'SERVICIO_FUERA_DE_COLECTA',
            'detalle'   => $base
        ]);
        responder(['success' => 0, 'error' => 'SERVICIO_FUERA_DE_COLECTA', 'base' => $base]);
    }

    $paquetesSvc = (int)($svc['paquetes'] ?? 1);
    if ($paquetesSvc <= 0) $paquetesSvc = 1;

    $esML    = ($tipoDetectado === 'ML_JSON');
    $esFerni = ($tipoDetectado === 'FERNIPLAST_CODPROV');
    $esProv  = ($tipoDetectado === 'PROV_CODPROV');
    $esQR    = (bool)preg_match('/^' . preg_quote(strtoupper($base), '/') . '(?:_(\d+))?$/', $codigoEscaneado);

    // Definir codeStore (persistido en JSON)
    if ($esQR) {
        list($bScan, $suf) = parseBaseAndSuffix($codigoEscaneado);

        if (strtoupper($bScan) !== strtoupper($base)) {
            responder(['success' => 0, 'error' => 'BASE_NO_COINCIDE', 'esperado' => $base, 'escaneado' => $bScan]);
        }

        if ($paquetesSvc > 1) {
            if ($suf === null) {
                responder(['success' => 0, 'error' => 'FALTA_SUFIJO', 'detail' => "Se requiere {$base}_1..{$base}_{$paquetesSvc}"]);
            }
            if ($suf < 1 || $suf > $paquetesSvc) {
                responder(['success' => 0, 'error' => 'SUFIJO_FUERA_DE_RANGO', 'detail' => "Permitido {$base}_1..{$base}_{$paquetesSvc}"]);
            }
            $codeStore = strtoupper($base) . "_" . (int)$suf;
        } else {
            // paquetesSvc==1 => canon BASE_1
            if ($suf !== null && $suf !== 1) {
                responder(['success' => 0, 'error' => 'SUFIJO_FUERA_DE_RANGO', 'detail' => "Para {$base} sólo se permite {$base} o {$base}_1"]);
            }
            $codeStore = strtoupper($base) . "_1";
        }
    } else if ($esML || $esFerni || $esProv) {
        $codeStore = (string)$tokenStore; // shipment_id / CodigoProveedor
    } else {
        $codeStore = $tokenStore !== '' ? $tokenStore : $codigoEscaneado;
    }

    $scans = $payload['scans'] ?? [];
    if (!is_array($scans)) $scans = [];

    // Suma qty actual por base
    $yaQty = 0;
    foreach ($scans as $s) {
        if (trim((string)($s['base'] ?? '')) === $base) {
            $yaQty += (int)($s['qty'] ?? 1);
        }
    }

    $newQty = $cantidad;
    if ($esQR) $newQty = 1;

    if (($yaQty + $newQty) > $paquetesSvc) {
        responder([
            'success' => 0,
            'error'   => 'EXCEDE_PAQUETES_SERVICIO',
            'detail'  => "{$base}: {$yaQty}/{$paquetesSvc} ya escaneados",
            'base'    => $base,
            'ya'      => $yaQty,
            'max'     => $paquetesSvc
        ]);
    }

    $nowTs = date('Y-m-d H:i:s');

    // Buscar codeStore existente
    $foundIdx = -1;
    for ($i = 0; $i < count($scans); $i++) {
        if (trim((string)($scans[$i]['code'] ?? '')) === trim((string)$codeStore)) {
            $foundIdx = $i;
            break;
        }
    }

    if ($foundIdx >= 0) {

        // QR: duplicado real (BASE_n)
        if ($esQR) {
            $payload['resume'] = calcularResume($payload);

            responder([
                'success'     => 1,
                'duplicate'   => 1,
                'inserted'    => 0,
                'merged'      => 0,
                'scan_saved'  => 0,
                'codigo'      => $base,
                'cs_base'     => $base,
                'codeStore'   => $codeStore,
                'resume'      => $payload['resume'],
                'paquetes_servicio' => $paquetesSvc
            ]);
        }

        // ML / Ferni / Prov: sumar 1 por escaneo hasta tope
        $currentQty = (int)($scans[$foundIdx]['qty'] ?? 1);
        $addQty = 1;

        if (($currentQty + $addQty) > $paquetesSvc) {
            $addQty = max($paquetesSvc - $currentQty, 0);
        }

        if ($addQty <= 0) {
            $payload['resume'] = calcularResume($payload);

            responder([
                'success'    => 0,
                'error'      => 'EXCEDE_PAQUETES_SERVICIO',
                'detail'     => "{$base}: {$currentQty}/{$paquetesSvc} ya escaneados",
                'codigo'     => $base,
                'resume'     => $payload['resume'],
                'scan_saved' => 0,
                'paquetes_servicio' => $paquetesSvc
            ]);
        }

        $scans[$foundIdx]['qty'] = $currentQty + $addQty;
        $scans[$foundIdx]['ts']  = $nowTs;
        $payload['scans'] = $scans;

        $payload['resume'] = calcularResume($payload);

        $jsonNew = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $up = $mysqli->prepare("UPDATE Colecta SET ColectaScans=?, ColectaScansUpdatedAt=? WHERE id=?");
        $up->bind_param("ssi", $jsonNew, $nowTs, $colectaId);
        $up->execute();

        $scanSavedToColecta = 1;
        $colectaResume = $payload['resume'];
        $isMerged = 1;
        $addedQty = $addQty;

        // seguimos a Seguimiento (NO responder acá)
    } else {

        // NUEVO scan (primera vez que vemos este codeStore)
        $scans[] = [
            'code' => $codeStore,
            'base' => $base,
            'qty'  => ($esQR ? 1 : 1),
            'ts'   => $nowTs,
            'kind' => ($esQR ? 'QR' : ($esML ? 'ML' : ($esFerni ? 'FERNI' : ($esProv ? 'PROV' : 'OTHER')))),
        ];

        $payload['scans'] = $scans;
        $payload['resume'] = calcularResume($payload);

        $jsonNew = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $up = $mysqli->prepare("UPDATE Colecta SET ColectaScans=?, ColectaScansUpdatedAt=? WHERE id=?");
        $up->bind_param("ssi", $jsonNew, $nowTs, $colectaId);
        $up->execute();

        $scanSavedToColecta = 1;
        $colectaResume = $payload['resume'];
        $isMerged = 0;
        $addedQty = 0;

        // seguimos a Seguimiento (NO responder acá)
    }
}

/* ============================================================
   2) Seguimiento (COLECTA vs RETIRO)
   ============================================================ */

// estados (slugs)
$padreStatus = 'pickup_ready';   // id=3
$hijoStatus  = 'pickup_scanned'; // id=14

// === Estado padre ===
$padreEstado = estadoPorSlug($mysqli, $padreStatus);
if (!$padreEstado || empty($padreEstado['id'])) {
    responder(['success' => 0, 'error' => 'ESTADO_PADRE_NO_ENCONTRADO', 'slug' => $padreStatus]);
}
$padreEstadoId  = (int)$padreEstado['id'];
$padreEstadoTxt = (string)$padreEstado['Estado'];

// === Estado hijo ===
$hijoEstado = estadoPorSlug($mysqli, $hijoStatus);
if (!$hijoEstado || empty($hijoEstado['id'])) {
    responder(['success' => 0, 'error' => 'ESTADO_HIJO_NO_ENCONTRADO', 'slug' => $hijoStatus]);
}
$hijoEstadoId  = (int)$hijoEstado['id'];
$hijoEstadoTxt = (string)$hijoEstado['Estado'];

if ($isColecta) {

    // =====================================
    // COLECTA: PADRE (pickup_ready) 1 vez
    // =====================================
    $codigoPadre = $base;

    // El padre real es TransClientes.id = $padreId (puede tener codigo distinto)
    $stPad = $mysqli->prepare("SELECT CodigoSeguimiento FROM TransClientes WHERE id=? LIMIT 1");
    $stPad->bind_param("i", $padreId);
    $stPad->execute();
    $rPad = $stPad->get_result()->fetch_assoc();
    if ($rPad && !empty($rPad['CodigoSeguimiento'])) {
        // $codigoPadre = trim((string)$rPad['CodigoSeguimiento']);
        $codigoPadre = strtoupper(trim((string)$rPad['CodigoSeguimiento']));
        $codigoPadre = explode('_', $codigoPadre)[0]; // base pura
    }

    // Insert padre si no existe
    upsertSeguimiento($mysqli, [
        'codigo'         => $codigoPadre,
        'status'         => $padreStatus,
        'estado_id'      => $padreEstadoId,
        'estado_txt'     => $padreEstadoTxt,
        'destino'        => $destino,
        'idCliente'      => $idCliente,
        'idTransClientes' => $padreId, // padre
        'usuario'        => $usuario,
        'sucursal'       => $sucursal,
        'recorrido'      => $recorrido,
        'nroOrden'       => $nroOrden,
        'obs'            => 'Retirado del cliente (Colecta)',
        'retirado'       => 1
    ]);

    // =====================================
    // COLECTA: HIJO (pickup_scanned) por bulto
    // =====================================
    $codigoHijo = resolverCodigoHijoParaSeguimiento($mysqli, $colectaId, $base, $raw, $tipoDetectado, $paquetesSvc);
    if (!$codigoHijo) {
        responder(['success' => 0, 'error' => 'HIJOS_COMPLETOS', 'detail' => "Ya están completos los bultos de $base"]);
    }

    // $obsH = "BULTO {$raw} | HIJO {$codigoHijo}";
    // --- Armar observación legible para Seguimiento ---
    $rawTrim = trim((string)$raw);
    $mlIdObs = parseMeliJsonId($rawTrim); // si raw es JSON
    $isMlObs = ($mlIdObs !== null && ctype_digit($mlIdObs));

    // "BULTO xxx (ML)" o "BULTO XXX" (QR/CodProv)
    $bultoLabel = '';
    if ($isMlObs) {
        $bultoLabel = $mlIdObs . ' (ML)';
    } else {
        // si raw es QR BASE_1 o BASE_2, lo dejamos como vino (pero sin espacios raros)
        $bultoLabel = $rawTrim;
    }

    // PADRE: usamos el código padre real que ya resolviste arriba ($codigoPadre)
    $padreLabel = strtoupper(trim((string)$codigoPadre));
    if ($padreLabel !== '') $padreLabel = explode('_', $padreLabel)[0];

    $obsH = "BASE {$bultoLabel}";
    if ($padreLabel !== '') $obsH .= " | Colecta {$padreLabel}";
    $obsH .= " | CODIGO {$codigoHijo}";

    if ($cantidad > 1) $obsH .= " | Cantidad confirmada: {$cantidad}";


    $insH = upsertSeguimiento($mysqli, [
        'codigo'         => $base,
        'status'         => $hijoStatus,
        'estado_id'      => $hijoEstadoId,
        'estado_txt'     => $hijoEstadoTxt,
        'destino'        => $destino,
        'idCliente'      => $idCliente,
        'idTransClientes' => $idTransClientes, // servicio detectado
        'usuario'        => $usuario,
        'sucursal'       => $sucursal,
        'recorrido'      => $recorrido,
        'nroOrden'       => $nroOrden,
        'obs'            => $obsH,
        'retirado'       => 1
    ]);

    if ($colectaResume === null) {
        $colectaResume = leerResumeColecta($mysqli, $colectaId);
    }

    responder([
        'success'    => 1,
        'inserted'   => (int)($insH['inserted'] ?? 0),
        'codigo'     => $base,
        'codigoHijo' => $base,
        'status'     => $hijoStatus,
        'estado'     => $hijoEstadoTxt,
        'scan_saved' => $scanSavedToColecta,
        'merged'     => $isMerged,
        'added_qty'  => $addedQty,
        'resume'     => $colectaResume,
        'paquetes_servicio' => $paquetesSvc
    ]);
} else {

    // =====================================
    // RETIRO (sin colecta): pickup_ready sobre BASE_1 (canon)
    // =====================================
    $baseRetiro = parseCaddyBase($raw);
    if ($baseRetiro === '') $baseRetiro = $base;

    // canon: BASE_1

    $codigoRetiro = strtoupper($baseRetiro); // BASE pura, sin _n
    $obsR = "Retiro confirmado ({$raw})";
    if ($cantidad > 1) $obsR .= " | Cantidad confirmada: {$cantidad}";

    $insR = upsertSeguimiento($mysqli, [
        'codigo'         => $codigoRetiro,
        'status'         => $padreStatus,
        'estado_id'      => $padreEstadoId,
        'estado_txt'     => $padreEstadoTxt,
        'destino'        => $destino,
        'idCliente'      => $idCliente,
        'idTransClientes' => $idTransClientes,
        'usuario'        => $usuario,
        'sucursal'       => $sucursal,
        'recorrido'      => $recorrido,
        'nroOrden'       => $nroOrden,
        'obs'            => $obsR,
        'retirado'       => 1
    ]);

    responder([
        'success'    => 1,
        'inserted'   => (int)($insR['inserted'] ?? 0),
        'codigo'     => $codigoRetiro,
        'status'     => $padreStatus,
        'estado'     => $padreEstadoTxt,
        'scan_saved' => 0,
        'resume'     => null
    ]);
}
