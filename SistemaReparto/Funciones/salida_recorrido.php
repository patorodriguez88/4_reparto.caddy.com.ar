<?php
// salida_recorrido.php
// -----------------------------------------------------------------------------
// Registra la SALIDA del recorrido: cada entrega de la hoja de ruta abierta pasa a "En Tránsito"
// (last_mile, una vez por orden) y se le pide a Wepoint el egreso de lo que estaba en su depósito.
// Antes vivía solo en warehouse.php (PuedeSalir, botón "Confirmar carga"); desde el 23/11/2026
// también lo llama iniciar_recorrido.php, así un recorrido que arranca siempre deja su salida
// registrada (antes, si la confirmación fallaba, el chofer arrancaba igual y no quedaba nada).
// Idempotente: no duplica el En Tránsito de la misma orden.
// -----------------------------------------------------------------------------

require_once __DIR__ . '/estados.php';
require_once __DIR__ . '/wepoint.php';

function registrarSalidaEnTransito(mysqli $mysqli, string $recorrido, string $usuario, string $sucursal): array
{
    $status = 'last_mile';
    $st = estadoPorSlug($mysqli, $status);
    if (!$st || empty($st['id'])) {
        return ['success' => 0, 'error' => 'Estado last_mile no existe en BD', 'slug' => $status];
    }

    $Estado_id = (int)$st['id'];
    $Estado    = (string)$st['Estado'];

    // Traigo bases (solo entregas) del recorrido abierto
    $q = $mysqli->prepare("
    SELECT DISTINCT SUBSTRING_INDEX(t.CodigoSeguimiento,'_',1) AS base,
           t.id AS idTransClientes,
           t.idClienteDestino AS idCliente,
           t.DomicilioDestino AS destino,
           COALESCE(NULLIF(h.NumerodeOrden, 0), t.NumerodeOrden) AS nroOrden -- la de la hoja de ruta (ver RegistrarWarehouseBatch)
    FROM HojaDeRuta h
    INNER JOIN TransClientes t ON t.id = h.idTransClientes
    WHERE h.Estado='Abierto'
      AND h.Eliminado=0
      AND h.Devuelto=0
      AND h.Recorrido=?
      AND t.Eliminado=0
      AND t.Retirado=1
  ");
    $q->bind_param("s", $recorrido);
    $q->execute();
    $rs = $q->get_result();

    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');

    $ins = $mysqli->prepare("
    INSERT INTO Seguimiento
    (Fecha, Hora, Usuario, Sucursal, CodigoSeguimiento, Observaciones,
     Entregado, Estado, Destino, Avisado, idCliente, Retirado, Visitas,
     idTransClientes, TimeStamp, Recorrido, Devuelto, Webhook, state_id,
     NumerodeOrden, status, Eliminado)
    VALUES
    (?, ?, ?, ?, ?, ?,
     0, ?, ?, 0, ?, 1, 0,
     ?, NOW(), ?, 0, 0, ?,
     ?, ?, 0)
  ");

    $insertados = 0;
    $yaExistian = 0;
    $basesRecorrido = [];

    while ($r = $rs->fetch_assoc()) {
        $base = (string)$r['base'];
        $basesRecorrido[] = $base;

        // anti duplicado por base + status + orden: en una segunda visita el En Tránsito de la salida
        // anterior no cuenta (mismo criterio que warehouse_validated en GetLista).
        $nroOrdenChk = (string)($r['nroOrden'] ?? '');
        $chk = $mysqli->prepare("
      SELECT id FROM Seguimiento
      WHERE CodigoSeguimiento=? AND status=? AND NumerodeOrden=? AND Eliminado=0
      LIMIT 1
    ");
        $chk->bind_param("sss", $base, $status, $nroOrdenChk);
        $chk->execute();
        $cr = $chk->get_result();
        if ($cr && $cr->num_rows > 0) {
            $yaExistian++;
            continue;
        }

        $obs = "Salida de warehouse – En Tránsito";

        $destino = (string)($r['destino'] ?? '');
        $idCliente = (int)($r['idCliente'] ?? 0);
        $idTrans   = (int)($r['idTransClientes'] ?? 0);
        $nroOrden  = (string)($r['nroOrden'] ?? '');

        $ins->bind_param(
            "ssssssssii" . "s" . "i" . "ss",
            $fecha,
            $hora,
            $usuario,
            $sucursal,
            $base,
            $obs,
            $Estado,
            $destino,
            $idCliente,
            $idTrans,
            $recorrido,
            $Estado_id,
            $nroOrden,
            $status
        );

        if ($ins->execute()) $insertados++;
    }

    // Sale todo el recorrido, también lo que no se escaneó en Warehouse (Flex leído con la app de
    // Meli, "omitir control de escaneo"): lo que estaba en Wepoint sale de su depósito.
    $wepoint = encolarSalidaWepoint($mysqli, $basesRecorrido, (string)$recorrido, (string)$usuario);

    return [
        'success' => 1,
        'insertados' => $insertados,
        'ya_existian' => $yaExistian,
        'wepoint' => $wepoint,
        'status' => $status,
        'estado' => $Estado,
    ];
}
