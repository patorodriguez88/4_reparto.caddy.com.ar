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

/**
 * ============================================================
 * ROUTER 1: InitColecta
 * ============================================================
 */
// if (isset($_POST['InitColecta']) && isset($_POST['idColecta'])) {

//     $idColecta = (int)$_POST['idColecta'];

//     try {
//         mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

//         // 1) Traer datos del padre
//         $stmt = $mysqli->prepare("SELECT id, IngBrutosOrigen, RazonSocial, CodigoSeguimiento
//                               FROM TransClientes
//                               WHERE id = ? LIMIT 1");
//         $stmt->bind_param("i", $idColecta);
//         $stmt->execute();
//         $padre = $stmt->get_result()->fetch_assoc();

//         if (!$padre) responder(['success' => 0, 'error' => 'COLECTA_PADRE_NO_ENCONTRADA']);

//         $origenKey = trim((string)$padre['IngBrutosOrigen']);
//         if ($origenKey === '') responder(['success' => 0, 'error' => 'COLECTA_SIN_ORIGENKEY']);

//         // 2) Buscar servicios pendientes del mismo origen (excluyendo el padre)
//         $sql = "SELECT id, CodigoSeguimiento, Cantidad
//             FROM TransClientes
//             WHERE IngBrutosOrigen = ?
//               AND Eliminado = 0
//               AND Entregado = 0
//               AND Devuelto = 0
//               AND id <> ?";

//         $stmt2 = $mysqli->prepare($sql);
//         $stmt2->bind_param("si", $origenKey, $idColecta);
//         $stmt2->execute();
//         $res = $stmt2->get_result();

//         $serviciosDetalle = [];
//         $totalPaquetes = 0;

//         while ($row = $res->fetch_assoc()) {
//             $cs = trim((string)$row['CodigoSeguimiento']);
//             if ($cs === '') continue;

//             $base  = trim(explode('_', $cs)[0]);
//             $cant  = (int)($row['Cantidad'] ?? 0);
//             if ($base === '' || $cant <= 0) continue;

//             $serviciosDetalle[] = [
//                 'idTransCliente' => (int)$row['id'],
//                 'cs_base'        => $base,
//                 'paquetes'       => $cant,
//             ];
//             $totalPaquetes += $cant;
//         }

//         $expected = [
//             'servicios' => count($serviciosDetalle),
//             'paquetes_total' => $totalPaquetes,
//             'servicios_detalle' => $serviciosDetalle,
//         ];

//         $payload = [
//             'colecta_padre_id' => $idColecta,
//             'origen_key' => $origenKey,
//             'expected' => $expected,
//             'scans' => [],
//             'resume' => [
//                 'servicios_ok' => 0,
//                 'servicios_total' => count($serviciosDetalle),
//                 'paquetes_ok' => 0,
//                 'paquetes_total' => $totalPaquetes,
//             ],
//         ];

//         $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
//         $now = date('Y-m-d H:i:s');

//         $stmt3 = $mysqli->prepare("UPDATE TransClientes
//                                SET ColectaScans = ?, ColectaScansUpdatedAt = ?
//                                WHERE id = ?");
//         $stmt3->bind_param("ssi", $json, $now, $idColecta);
//         $stmt3->execute();

//         responder([
//             'success' => 1,
//             'expected' => $expected,
//             'resume' => $payload['resume'],
//             'origen_key' => $origenKey,
//             'idColecta' => $idColecta,
//         ]);
//     } catch (Throwable $e) {
//         responder(['success' => 0, 'error' => 'INIT_COLECTA_ERROR', 'detail' => $e->getMessage()]);
//     }
// }
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
          SELECT id, CodigoSeguimiento, Cantidad
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
          UPDATE TransClientes
          SET ColectaScans=?, ColectaScansUpdatedAt=?
          WHERE id=?
        ");
        $up->bind_param("ssi", $json, $now, $padreId);
        $up->execute();

        responder([
            'success' => 1,
            'expected' => $expected,
            'resume' => $payload['resume'],
            'colectaId' => $colectaId,
            'padreId' => $padreId
        ]);
    } catch (Throwable $e) {
        responder(['success' => 0, 'error' => 'INIT_COLECTA_ERROR', 'detail' => $e->getMessage()]);
    }
}

/**
 * ============================================================
 * ROUTER 2: ColectaBulto
 * ============================================================
 */
if (!isset($_POST['ColectaBulto'])) {
    responder(['success' => 0, 'error' => 'Acción inválida']);
}

$basePost  = trim($_POST['base'] ?? '');   // puede venir CodigoSeguimiento o shipments_id
$bultoPost = trim($_POST['bulto'] ?? '');  // token escaneado (auditoría)
// $idColecta = (int)($_POST['idColecta'] ?? 0);

if ($basePost === '' || $bultoPost === '') {
    responder(['success' => 0, 'error' => 'Faltan base o bulto']);
}

$cantidad = (int)($_POST['cantidad'] ?? 1);
if ($cantidad <= 0) $cantidad = 1;

$usuario   = $_SESSION['Usuario']  ?? ($_POST['Usuario']  ?? '');
$sucursal  = $_SESSION['Sucursal'] ?? ($_POST['Sucursal'] ?? '');
$recorrido = $_SESSION['RecorridoAsignado'] ?? ($_POST['Recorrido'] ?? '');

if ($usuario === '') {
    responder(['success' => 0, 'forceLogout' => 1, 'reason' => 'NO_IDUSUARIO', 'error' => 'Sin sesión']);
}

// ---------------------------------------
// 1) Resolver TransClientes del "servicio escaneado"
// ---------------------------------------
$idTransClientes = 0;
$idCliente       = 0;
$destino         = '';
$nroOrden        = '';

$baseCandidate = trim(explode('_', $basePost)[0]);  // siempre primero
$lookupShip    = $baseCandidate;                    // si basePost es shipments_id, esto sirve
$base          = $baseCandidate;                    // base default SIEMPRE
// 1.a) Buscar por CodigoSeguimiento
$sqlT = $mysqli->prepare("
  SELECT id, CodigoSeguimiento, idClienteDestino, DomicilioDestino, NumerodeOrden
  FROM TransClientes
  WHERE SUBSTRING_INDEX(CodigoSeguimiento,'_',1)=? AND Eliminado=0
    ORDER BY id DESC
    LIMIT 1
");

$sqlT->bind_param("s", $baseCandidate);
$sqlT->execute();
$tr = $sqlT->get_result()->fetch_assoc();

if ($tr && !empty($tr['id'])) {
    $idTransClientes = (int)$tr['id'];
    $base = explode('_', (string)($tr['CodigoSeguimiento'] ?? $baseCandidate))[0];
    $idCliente = (int)($tr['idClienteDestino'] ?? 0);
    $destino   = (string)($tr['DomicilioDestino'] ?? '');
    $nroOrden  = (string)($tr['NumerodeOrden'] ?? '');
} else {
    // 1.b) Fallback por shipments_id
    $sqlS = $mysqli->prepare("
    SELECT id, CodigoSeguimiento, idClienteDestino, DomicilioDestino, NumerodeOrden
    FROM TransClientes
    WHERE shipments_id=? AND Eliminado=0
    ORDER BY id DESC
    LIMIT 1
  ");
    $sqlS->bind_param("s", $lookupShip);
    $sqlS->execute();
    $trS = $sqlS->get_result()->fetch_assoc();

    if ($trS && !empty($trS['CodigoSeguimiento'])) {
        $idTransClientes = (int)($trS['id'] ?? 0);
        $base = explode('_', (string)$trS['CodigoSeguimiento'])[0];
        $idCliente = (int)($trS['idClienteDestino'] ?? 0);
        $destino   = (string)($trS['DomicilioDestino'] ?? '');
        $nroOrden  = (string)($trS['NumerodeOrden'] ?? '');
    }
}

// if ($idTransClientes === 0 || strlen($base ?? '') > 30) {
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
    $stp = $mysqli->prepare("SELECT ColectaScans FROM TransClientes WHERE id=? LIMIT 1");
    $stp->bind_param("i", $padreId);
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

    // 2.c) validar sufijo _n cuando es QR (si viene con _)
    // Para QR normal: el "código" real viene en bultoPost o basePost? (en tu JS se manda token=bulto, base=base)
    // Para validar límites, miramos el código que el usuario escaneó: bultoPost (si es "BASE_2") o basePost si ahí viene.
    $codigoEscaneado = $bultoPost;

    // Si el token es numérico (ML) no aplica sufijo. Usamos heurística: si contiene "_" y empieza por la base, tratamos QR
    // $esQR = (strpos($codigoEscaneado, $base . '_') === 0) || ($codigoEscaneado === $base);
    $esQR = (bool)preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $codigoEscaneado);
    if ($esQR) {
        [$bScan, $suf] = parseBaseAndSuffix($codigoEscaneado);

        // base debe coincidir
        if ($bScan !== $base) {
            responder(['success' => 0, 'error' => 'BASE_NO_COINCIDE', 'esperado' => $base, 'escaneado' => $bScan]);
        }

        if ($paquetesSvc > 1) {
            if ($suf === null) {
                responder(['success' => 0, 'error' => 'FALTA_SUFIJO', 'detail' => "Se requiere {$base}_1..{$base}_{$paquetesSvc}"]);
            }
            if ($suf < 1 || $suf > $paquetesSvc) {
                responder(['success' => 0, 'error' => 'SUFIJO_FUERA_DE_RANGO', 'detail' => "Permitido {$base}_1..{$base}_{$paquetesSvc}"]);
            }
        } else {
            // paquetesSvc == 1 => NO permitir _n
            if ($suf !== null) {
                responder(['success' => 0, 'error' => 'NO_PERMITE_SUFIJO', 'detail' => "Para {$base} debe ser sin sufijo"]);
            }
        }
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

    // 2.e) anti-duplicado por code exacto si es QR con sufijo, o por token si es ML
    $codeStore = $esQR ? $codigoEscaneado : $lookupShip;

    foreach ($scans as $s) {
        if (trim((string)($s['code'] ?? '')) === trim((string)$codeStore)) {
            // ya estaba
            $colectaResume = $payload['resume'] ?? calcularResume($payload);
            responder(['success' => 1, 'duplicate' => 1, 'resume' => $colectaResume, 'scan_saved' => 0]);
        }
    }

    // 2.f) append
    $scans[] = [
        'base' => $base,
        'code' => $codeStore,
        'tipo' => $esQR ? 'QR' : 'ML',
        'qty'  => $newQty,
        'ts'   => date('Y-m-d H:i:s'),
    ];
    $payload['scans'] = $scans;

    // 2.g) resume
    $payload['resume'] = calcularResume($payload);
    $colectaResume = $payload['resume'];

    // 2.h) guardar
    $jsonNew = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $now = date('Y-m-d H:i:s');

    // $up = $mysqli->prepare("UPDATE TransClientes SET ColectaScans=?, ColectaScansUpdatedAt=? WHERE id=?");
    // $up->bind_param("ssi", $jsonNew, $now, $idColecta);
    $up = $mysqli->prepare("UPDATE TransClientes SET ColectaScans=?, ColectaScansUpdatedAt=? WHERE id=?");
    $up->bind_param("ssi", $jsonNew, $now, $padreId);
    $up->execute();

    $scanSavedToColecta = 1;
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

$obs = "BULTO {$bultoPost}";
if ($cantidad > 1) $obs .= " | Cantidad confirmada: {$cantidad}";

$sqlIns = $mysqli->prepare("
  INSERT INTO Seguimiento
  (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones,
   Entregado, Estado, Destino, Avisado, idCliente, Retirado, Visitas,
   idTransClientes, TimeStamp, Recorrido, Devuelto, Webhook, state_id,
   NumerodeOrden, status, Eliminado)
  VALUES
  (?, ?, ?, ?, ?, ?,
   0, ?, ?, 0, ?, 0, 0,
   ?, NOW(), ?, 0, 0, ?,
   ?, ?, 0)
");
if (!$sqlIns) responder(['success' => 0, 'error' => 'prepare insert failed', 'detail' => $mysqli->error]);

// $types = "ssssssssii" . "s" . "i" . "ss"; // 14 params
$types = "ssssssssii" . "s" . "i" . "ss";
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
    $status
)) {
    responder(['success' => 0, 'error' => 'bind_param failed', 'detail' => $sqlIns->error]);
}

if (!$sqlIns->execute()) {
    responder(['success' => 0, 'error' => 'execute failed', 'detail' => $sqlIns->error]);
}

responder([
    'success'    => 1,
    'inserted'   => 1,
    'codigo'     => $base,
    'bulto'      => $bultoPost,
    'status'     => $status,
    'estado'     => $Estado,
    'scan_saved' => $scanSavedToColecta,
    'resume'     => $colectaResume
]);
