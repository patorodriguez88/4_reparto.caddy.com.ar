<?php
//colecta_scan.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "../../Conexion/conexioni.php";
require_once __DIR__ . '/../../Funciones/estados.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');
header('Content-Type: application/json; charset=utf-8');

function responder($arr)
{
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
/**
 * Helpers colecta JSON
 */
// --- helper: obtener resume aunque no insertemos Seguimiento ---
function leerResumeColecta($mysqli, $colectaId)
{
    if ($colectaId <= 0) return null;

    $st = $mysqli->prepare("SELECT ColectaScans FROM Colecta WHERE id=? LIMIT 1");
    $st->bind_param("i", $colectaId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $json = $row ? ($row['ColectaScans'] ?? '') : '';
    if (trim((string)$json) === '') return null;

    $payload = json_decode($json, true);
    if (!is_array($payload)) return null;

    // si no hay resume, lo calculamos
    if (empty($payload['resume']) && function_exists('calcularResume')) {
        $payload['resume'] = calcularResume($payload);
    }
    return $payload['resume'] ?? null;
}
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

function getExpectedServicio($payload, $base)
{
    if (!is_array($payload)) return null;
    $exp = $payload['expected'] ?? null;
    $det = $exp['servicios_detalle'] ?? null;
    if (!is_array($det)) return null;

    foreach ($det as $svc) {
        if (isset($svc['cs_base']) && trim((string)$svc['cs_base']) === trim((string)$base)) {
            return $svc;
        }
    }
    return null;
}

function calcularResume($payload)
{
    $exp = $payload['expected'] ?? [];
    $det = $exp['servicios_detalle'] ?? [];
    $scans = $payload['scans'] ?? [];

    // paquetes_ok: suma qty de scans
    $paquetes_ok = 0;
    foreach ($scans as $s) {
        $paquetes_ok += (int)($s['qty'] ?? 1);
    }

    // servicios_ok: por cada servicio, sumar qty escaneados por base y comparar contra expected paquetes
    $servicios_ok = 0;
    if (is_array($det)) {
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
    }

    return [
        'servicios_ok'   => $servicios_ok,
        'servicios_total' => (int)($exp['servicios'] ?? (is_array($det) ? count($det) : 0)),
        'paquetes_ok'    => $paquetes_ok,
        'paquetes_total' => (int)($exp['paquetes_total'] ?? 0),
    ];
}
function normalizarMeliJsonAId($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return '';

    if ($raw !== '' && $raw[0] === '{') {
        $j = json_decode($raw, true);
        if (is_array($j) && !empty($j['id'])) return trim((string)$j['id']);
    }
    return $raw;
}
if (isset($_POST['InitColecta'])) {

    $colectaId = (int)($_POST['colectaId'] ?? 0); // Colecta.id
    $padreId   = (int)($_POST['padreId'] ?? 0);   // TransClientes.id (padre)

    if ($colectaId <= 0 || $padreId <= 0) {
        responder(['success' => 0, 'error' => 'FALTA_COLECTAID_O_PADREID']);
    }

    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // 1) Traer colecta real
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

        // 2) Servicios asignados a esa colecta (excluyo padre)
        $stT = $mysqli->prepare("
          SELECT id, CodigoSeguimiento, Cantidad,shipments_id,CodigoProveedor
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
            $cs = trim((string)$row['CodigoSeguimiento']);
            if ($cs === '') continue;

            $base = trim(explode('_', $cs)[0]);
            $cant = (int)($row['Cantidad'] ?? 0);
            if ($cant <= 0) $cant = 1;

            $serviciosDetalle[] = [
                'idTransCliente' => (int)$row['id'],
                'codigoProveedor' => (string)($row['CodigoProveedor'] ?? ''),
                'cs_base'        => $base,
                'paquetes'       => $cant,
            ];
            $sumaTrans += $cant;
        }

        // ⚠️ Sugerencia práctica:
        // si la colecta dice 10 pero asignaste 12 envíos reales,
        // no te conviene bloquear al rider.
        if ($totalEsperado <= 0) $totalEsperado = $sumaTrans;
        if ($sumaTrans > 0 && $totalEsperado < $sumaTrans) $totalEsperado = $sumaTrans;

        $expected = [
            'servicios' => count($serviciosDetalle),
            'paquetes_total' => $totalEsperado,
            'servicios_detalle' => $serviciosDetalle,
            'colecta_id' => $colectaId
        ];

        $payload = [
            'colecta_id' => $colectaId,
            'padre_id'   => $padreId,
            'expected' => $expected,
            'scans' => [],
            'resume' => [
                'servicios_ok' => 0,
                'servicios_total' => count($serviciosDetalle),
                'paquetes_ok' => 0,
                'paquetes_total' => $totalEsperado,
            ],
        ];

        // 3) Guardar JSON en el padre (como ya hacías)
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
            'success' => 1,
            'expected' => $expected,
            'resume' => $payload['resume'],
            'colectaId' => $colectaId,
            'padreId' => $padreId,
            'cs_base' => $base
        ]);
    } catch (Throwable $e) {
        responder(['success' => 0, 'error' => 'INIT_COLECTA_ERROR', 'detail' => $e->getMessage()]);
    }
}

//HELPERS COLECTA CON DISTINTOS CODIGOS

function parseMeliJsonId($raw)
{
    $t = trim((string)$raw);
    if ($t === '' || $t[0] !== '{') return null;
    $j = json_decode($t, true);
    if (is_array($j) && isset($j['id']) && $j['id'] !== '') return trim((string)$j['id']);
    return null;
}

function parseCaddyBase($raw)
{
    $t = strtoupper(trim((string)$raw));
    if ($t === '') return '';
    $base = explode('_', $t)[0];
    return trim($base);
}

/**
 * Resolver servicio (TransClientes) a partir de lo escaneado.
 * - Busca primero dentro de la colecta (si hay colectaId).
 * - Orden de resolución:
 *   1) ML JSON -> shipments_id
 *   2) Caddy QR -> base por CodigoSeguimiento (SUBSTRING_INDEX)
 *   3) Proveedor -> CodigoProveedor (Ferniplast/otros) con filtro por idClienteOrigen si aplica
 */
function resolverServicioColecta($mysqli, $colectaId, $padreId, $raw, $ferniplastClienteId = 0)
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;

    // 1) Mercado Libre JSON
    $meliId = parseMeliJsonId($raw);
    if ($meliId !== null && ctype_digit($meliId)) {
        $ship = (int)$meliId;

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
            $st->bind_param("iiii", $colectaId, $ship, $padreId, $padreId);
        } else {
            $st = $mysqli->prepare("
                SELECT id, CodigoSeguimiento, Cantidad, idClienteOrigen, idClienteDestino, DomicilioDestino, NumerodeOrden
                FROM TransClientes
                WHERE Eliminado=0 AND Entregado=0 AND Devuelto=0
                  AND shipments_id=?
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->bind_param("i", $ship);
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
                'token_store' => $meliId, // guardás el shipment_id como token
            ];
        }

        // si es JSON pero no lo encontró, devolvemos null (no forzamos otra heurística)
        return null;
    }

    // 2) Caddy QR (BASE o BASE_n)
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
                'token_store' => $raw, // guardás BASE_n o BASE
            ];
        }
    }

    // 3) Proveedor (Ferniplast u otros): match por CodigoProveedor
    $prov = trim((string)$raw);
    if ($prov !== '') {

        // Si querés “modo Ferniplast” por idClienteOrigen, aplicá filtro.
        // Si $ferniplastClienteId == 0, no filtramos.
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
            }
        }

        $st->execute();
        $tr = $st->get_result()->fetch_assoc();
        if ($tr && !empty($tr['id'])) {
            $base2 = parseCaddyBase($tr['CodigoSeguimiento']);
            return [
                'tipo' => ($ferniplastClienteId > 0 ? 'FERNIPLAST_CODPROV' : 'PROV_CODPROV'),
                'idTransClientes' => (int)$tr['id'],
                'cs_base' => $base2,
                'cantidad' => (int)($tr['Cantidad'] ?? 1),
                'idClienteOrigen' => (int)($tr['idClienteOrigen'] ?? 0),
                'idClienteDestino' => (int)($tr['idClienteDestino'] ?? 0),
                'destino' => (string)($tr['DomicilioDestino'] ?? ''),
                'nroOrden' => (string)($tr['NumerodeOrden'] ?? ''),
                'token_store' => $prov, // guardás el CodigoProveedor
            ];
        }
    }

    return null;
}


/**
 * ============================================================
 * ROUTER 2: ColectaBulto
 * ============================================================
 */
if (!isset($_POST['ColectaBulto'])) {
    responder(['success' => 0, 'error' => 'Acción inválida']);
}
$colectaId = (int)($_POST['colectaId'] ?? 0);
$padreId   = (int)($_POST['padreId'] ?? 0);
$paquetesSvc = 1;

$raw = trim((string)($_POST['raw'] ?? ''));            // 👈 si podés, mandalo desde JS
$basePost  = trim((string)($_POST['base'] ?? ''));
$bultoPost = trim((string)($_POST['bulto'] ?? ''));

if ($raw === '')  $raw = $bultoPost;
if ($raw === '')  $raw = $basePost;

if ($raw === '') {
    responder(['success' => 0, 'error' => 'FALTA_RAW']);
}

$cantidad = (int)($_POST['cantidad'] ?? 1);
if ($cantidad <= 0) $cantidad = 1;

$usuario   = $_SESSION['Usuario']  ?? ($_POST['Usuario']  ?? '');
$sucursal  = $_SESSION['Sucursal'] ?? ($_POST['Sucursal'] ?? '');
$recorrido = $_SESSION['RecorridoAsignado'] ?? ($_POST['Recorrido'] ?? '');

if ($usuario === '') {
    responder(['success' => 0, 'forceLogout' => 1, 'reason' => 'NO_IDUSUARIO', 'error' => 'Sin sesión']);
}

// ===============================
// Detectar “modo Ferniplast”
// ===============================
// Opción 1: fijo (recomendado)
// $FERNIPLAST_CLIENTE_ID = 19396;

// Opción 2: inferido por el padre (si el padre es Ferniplast)
// Si el padreId existe, usamos su idClienteOrigen como filtro de proveedor
$FERNIPLAST_CLIENTE_ID = 0;
if ($padreId > 0) {
    $stFP = $mysqli->prepare("SELECT idClienteOrigen FROM TransClientes WHERE id=? LIMIT 1");
    $stFP->bind_param("i", $padreId);
    $stFP->execute();
    $rFP = $stFP->get_result()->fetch_assoc();
    $FERNIPLAST_CLIENTE_ID = (int)($rFP['idClienteOrigen'] ?? 0);
}

// ===============================
// Resolver servicio por RAW
// ===============================
$info = resolverServicioColecta($mysqli, $colectaId, $padreId, $raw, $FERNIPLAST_CLIENTE_ID);

if (!$info) {
    responder([
        'success' => 0,
        'error' => 'SERVICIO_NO_RESUELTO',
        'debug' => ['raw' => $raw, 'colectaId' => $colectaId, 'padreId' => $padreId]
    ]);
}

$tipoDetectado   = (string)$info['tipo'];
$tokenStore      = (string)$info['token_store'];   // shipments_id / CodigoProveedor / BASE_n
$idTransClientes = (int)$info['idTransClientes'];
$base            = (string)$info['cs_base'];       // base Caddy real
$idCliente       = (int)$info['idClienteDestino'];
$destino         = (string)$info['destino'];
$nroOrden        = (string)$info['nroOrden'];

// Este es el “escaneado” real para validar sufijo si aplica:
$codigoEscaneado = strtoupper(trim($raw));

if ($idTransClientes === 0 || !preg_match('/^[A-Za-z0-9\-]+$/', $base)) {
    responder([
        'success' => 0,
        'error' => 'SERVICIO_NO_RESUELTO',
        'debug' => ['basePost' => $basePost, 'bultoPost' => $bultoPost]
    ]);
}

// ---------------------------------------
// 2) Si viene idColecta > 0 => actualizar JSON de colecta padre
// ---------------------------------------
$scanSavedToColecta = 0;
$colectaResume = null;
$colectaId = (int)($_POST['colectaId'] ?? 0);
$padreId   = (int)($_POST['padreId'] ?? 0);

if ($colectaId > 0 && $padreId > 0) {

    // 2.a) traer JSON del padre
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

    // 2.b) validar que base pertenezca a expected

    $svc = getExpectedServicio($payload, $base);
    if (!$svc) {
        responder(['success' => 0, 'error' => 'SERVICIO_FUERA_DE_COLECTA', 'base' => $base]);
    }

    $paquetesSvc = (int)($svc['paquetes'] ?? 1);
    if ($paquetesSvc <= 0) $paquetesSvc = 1;


    // --- 2.c) Tipos
    $esML    = ($tipoDetectado === 'ML_JSON');
    $esFerni = ($tipoDetectado === 'FERNIPLAST_CODPROV');
    $esProv  = ($tipoDetectado === 'PROV_CODPROV');

    // QR Caddy: acepto BASE o BASE_n (siempre que empiece con la base)
    $esQR = (bool)preg_match('/^' . preg_quote(strtoupper($base), '/') . '(?:_(\d+))?$/', $codigoEscaneado);

    // codeStore (una sola definición en todo el flujo)
    if ($esQR) {
        // Canonización: para paquetesSvc==1 guardo SIEMPRE BASE_1
        [$bScan, $suf] = parseBaseAndSuffix($codigoEscaneado);

        if (strtoupper($bScan) !== strtoupper($base)) {
            responder(['success' => 0, 'error' => 'BASE_NO_COINCIDE', 'esperado' => $base, 'escaneado' => $bScan]);
        }

        if ($paquetesSvc > 1) {
            // exige sufijo
            if ($suf === null) {
                responder(['success' => 0, 'error' => 'FALTA_SUFIJO', 'detail' => "Se requiere {$base}_1..{$base}_{$paquetesSvc}"]);
            }
            if ($suf < 1 || $suf > $paquetesSvc) {
                responder(['success' => 0, 'error' => 'SUFIJO_FUERA_DE_RANGO', 'detail' => "Permitido {$base}_1..{$base}_{$paquetesSvc}"]);
            }
            $codeStore = strtoupper($base) . "_" . (int)$suf; // normalizado
        } else {
            // paquetesSvc == 1: acepto BASE o BASE_1, pero NO BASE_2...
            if ($suf !== null && $suf !== 1) {
                responder(['success' => 0, 'error' => 'SUFIJO_FUERA_DE_RANGO', 'detail' => "Para {$base} sólo se permite {$base} o {$base}_1"]);
            }
            $codeStore = strtoupper($base) . "_1"; // ✅ canonizo
        }
    } else if ($esML || $esFerni || $esProv) {
        $codeStore = $tokenStore; // shipment_id / CodigoProveedor
    } else {
        $codeStore = $tokenStore ?: $codigoEscaneado;
    }
    // 2.d) validar que no supere cantidad total del servicio
    $scans = $payload['scans'] ?? [];
    if (!is_array($scans)) $scans = [];

    $yaQty = 0;
    foreach ($scans as $s) {
        if (trim((string)($s['base'] ?? '')) === $base) {
            $yaQty += (int)($s['qty'] ?? 1);
        }
    }

    // cantidad del scan (ML puede venir >1)
    $newQty = $cantidad;
    if ($esQR) $newQty = 1; // QR siempre representa 1 bulto por scan

    if (($yaQty + $newQty) > $paquetesSvc) {
        responder([
            'success' => 0,
            'error' => 'EXCEDE_PAQUETES_SERVICIO',
            'detail' => "{$base}: {$yaQty}/{$paquetesSvc} ya escaneados",
            'base' => $base,
            'ya' => $yaQty,
            'max' => $paquetesSvc
        ]);
    }
    // 2.e) anti-duplicado / merge
    $nowTs = date('Y-m-d H:i:s');

    // buscar si ya existe ese codeStore en scans
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
                'success' => 1,
                'duplicate' => 1,
                'resume' => $payload['resume'],
                'scan_saved' => 0,
                'cs_base' => $base,
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
                'success' => 0,
                'error' => 'EXCEDE_PAQUETES_SERVICIO',
                'detail' => "{$base}: {$currentQty}/{$paquetesSvc} ya escaneados",
                'base' => $base,
                'resume' => $payload['resume'],
                'scan_saved' => 0,
                'cs_base' => $base,
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

        responder([
            'success' => 1,
            'inserted' => 0,
            'merged' => 1,
            'added_qty' => $addQty,
            'resume' => $colectaResume,
            'scan_saved' => 1,
            'cs_base' => $base,
            'paquetes_servicio' => $paquetesSvc
        ]);
    }

    // 2.f) NUEVO scan (primera vez que vemos este codeStore)
    $scans[] = [
        'code' => $codeStore,
        'base' => $base,
        'qty'  => 1, // 1 por escaneo
        'ts'   => $nowTs,
        'kind' => $esQR ? 'QR' : ($esML ? 'ML' : ($esFerni ? 'FERNI' : ($esProv ? 'PROV' : 'OTHER'))),
    ];

    $payload['scans'] = $scans;
    $payload['resume'] = calcularResume($payload);

    $jsonNew = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $up = $mysqli->prepare("UPDATE Colecta SET ColectaScans=?, ColectaScansUpdatedAt=? WHERE id=?");
    $up->bind_param("ssi", $jsonNew, $nowTs, $colectaId);
    $up->execute();

    $scanSavedToColecta = 1;
    $colectaResume = $payload['resume'];

    responder([
        'success' => 1,
        'inserted' => 1,
        'codigo' => $base,
        'resume' => $colectaResume,
        'scan_saved' => 1,
        'cs_base' => $base,
        'paquetes_servicio' => $paquetesSvc
    ]);
}


// ---------------------------------------
// 3) Seguimiento: mantenemos tu comportamiento
// ---------------------------------------
$status_control = 'pickup_ready';
$status = 'pickup_scanned';

$sqlChk = $mysqli->prepare("
  SELECT id FROM Seguimiento
  WHERE SUBSTRING_INDEX(CodigoSeguimiento,'_',1)=?
  AND status=?
  AND Eliminado=0
  LIMIT 1
");
$sqlChk->bind_param("ss", $base, $status);
$sqlChk->execute();
$chk = $sqlChk->get_result();
if ($chk && $chk->num_rows > 0) {
    // 👇 si no se guardó a colecta, igual devolvemos resume leyendo JSON
    if ($colectaResume === null) {
        $colectaResume = leerResumeColecta($mysqli, $colectaId);
    }
    responder([
        'success'  => 1,
        'inserted' => 0,
        'codigo'   => $base,
        'resume'   => $colectaResume,
        'scan_saved' => $scanSavedToColecta
    ]);
}

$st = estadoPorSlug($mysqli, $status);
if (!$st || empty($st['id'])) {
    responder(['success' => 0, 'error' => 'No se encontró estado por slug', 'slug' => $status]);
}
$Estado_id = (int)$st['id'];
$Estado    = (string)$st['Estado'];

$fecha = date('Y-m-d');
$hora  = date('H:i:s');

$obs = "BULTO {$raw}";
if ($cantidad > 1) $obs .= " | Cantidad confirmada: {$cantidad}";

$sqlIns = $mysqli->prepare("
  INSERT INTO Seguimiento
  (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones,
   Entregado, Estado, Destino, Avisado, idCliente, Retirado, Visitas,
   idTransClientes, TimeStamp, Recorrido, Devuelto, Webhook, state_id,
   NumerodeOrden, status, Eliminado,Estado_id)
  VALUES
  (?, ?, ?, ?, ?, ?,
   0, ?, ?, 0, ?, 0, 0,
   ?, NOW(), ?, 0, 0, ?,
   ?, ?, 0,?)
");
if (!$sqlIns) responder(['success' => 0, 'error' => 'prepare insert failed', 'detail' => $mysqli->error]);

// $types = "ssssssssii" . "s" . "i" . "ss"; // 14 params
$types = "ssssssssii" . "s" . "i" . "ssi";
// y verificá con strlen($types) == 14 durante debug
if (!$sqlIns->bind_param(
    $types,
    $fecha,
    $hora,
    $usuario,
    $sucursal,
    $base,
    $obs,
    $Estado,
    $destino,
    $idCliente,
    $idTransClientes,
    $recorrido,
    $Estado_id,
    $nroOrden,
    $status,
    $Estado_id
)) {
    responder(['success' => 0, 'error' => 'bind_param failed', 'detail' => $sqlIns->error]);
}

if (!$sqlIns->execute()) {
    responder(['success' => 0, 'error' => 'execute failed', 'detail' => $sqlIns->error]);
}
if ($colectaResume === null && $colectaId > 0) {
    $colectaResume = leerResumeColecta($mysqli, $colectaId);
}

responder([
    'success'    => 1,
    'inserted'   => 1,
    'codigo'     => $base,
    'bulto'      => $bultoPost,
    'status'     => $status,
    'estado'     => $Estado,
    'scan_saved' => $scanSavedToColecta,
    'resume'     => $colectaResume,
    'paquetes_servicio' => $paquetesSvc
]);
