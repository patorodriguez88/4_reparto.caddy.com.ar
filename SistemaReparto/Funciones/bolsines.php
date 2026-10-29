<?php
// Bolsines de facturas de Dinter (tarea #13 de Micaela). El depósito asigna uno o más bolsines a
// un recorrido desde el sistema (Logistica/Bolsines.php); acá el chofer los escanea en Warehouse
// como un bulto más (código BOL-0001) y no puede confirmar la salida sin ellos. Tablas:
// Bolsines y Bolsines_movimientos (sistema: Conexion/migraciones/2026_10_28_bolsines.sql).

require_once __DIR__ . '/control_escaneo.php'; // tieneColumna()

function bolsinesHabilitado(mysqli $mysqli): bool
{
    static $ok = null;
    if ($ok === null) {
        $r = $mysqli->query("SHOW TABLES LIKE 'Bolsines'");
        $ok = $r && $r->num_rows > 0;
    }
    return $ok;
}

function esCodigoBolsin(string $codigo): bool
{
    return stripos($codigo, 'BOL-') === 0;
}

// Orden de salida actual del chofer (la misma fila que usa overrideEscaneo).
function ordenCargadaChofer(mysqli $mysqli, int $idUsuario): ?int
{
    if ($idUsuario <= 0) return null;
    $r = $mysqli->query("SELECT NumerodeOrden FROM Logistica WHERE idUsuarioChofer = {$idUsuario} AND Estado = 'Cargada' AND Eliminado = 0 ORDER BY id DESC LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? (int) $row['NumerodeOrden'] : null;
}

// Bolsines del recorrido para la lista de Warehouse: los asignados (falta escanear) y los que ya
// salieron con esta orden.
function bolsinesDelRecorrido(mysqli $mysqli, string $recorrido, ?int $orden): array
{
    if (!bolsinesHabilitado($mysqli)) return [];
    $st = $mysqli->prepare("SELECT Codigo, Estado FROM Bolsines
                            WHERE Eliminado = 0 AND Recorrido = ?
                              AND (Estado = 'Asignado' OR (Estado = 'En viaje' AND NumerodeOrden = ?))
                            ORDER BY Codigo");
    $o = (int) $orden;
    $st->bind_param('si', $recorrido, $o);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

// El chofer confirmó la carga con el bolsín escaneado: pasa a En viaje con su orden.
function cargarBolsin(mysqli $mysqli, string $codigo, string $recorrido, ?int $orden, int $idUsuario, string $usuario): bool
{
    if (!bolsinesHabilitado($mysqli)) return false;
    $codigo = strtoupper($codigo);
    $st = $mysqli->prepare("SELECT id FROM Bolsines WHERE Codigo = ? AND Recorrido = ? AND Estado = 'Asignado' AND Eliminado = 0 LIMIT 1");
    $st->bind_param('ss', $codigo, $recorrido);
    $st->execute();
    $b = $st->get_result()->fetch_assoc();
    if (!$b) return false;

    $id = (int) $b['id'];
    $fecha = date('Y-m-d H:i:s');
    $st = $mysqli->prepare("UPDATE Bolsines SET Estado = 'En viaje', NumerodeOrden = ?, idUsuarioChofer = ?, FechaEstado = ? WHERE id = ?");
    $st->bind_param('iisi', $orden, $idUsuario, $fecha, $id);
    $st->execute();
    // El precinto viene de la asignación (sistema: Logistica/Bolsines.php).
    $st = $mysqli->prepare("INSERT INTO Bolsines_movimientos (idBolsin, Accion, Recorrido, NumerodeOrden, Precinto, Usuario, Fecha)
                            SELECT ?, 'cargado', ?, ?, Precinto, ?, ? FROM Bolsines WHERE id = ?");
    $st->bind_param('isissi', $id, $recorrido, $orden, $usuario, $fecha, $id);
    $st->execute();
    return true;
}

// ¿El recorrido lleva pedidos de Dinter (importación) para entregar?
function recorridoTieneDinter(mysqli $mysqli, string $recorrido): bool
{
    if (!tieneColumna($mysqli, 'TransClientes', 'Origen')) return false;
    $st = $mysqli->prepare("SELECT 1 FROM HojaDeRuta h
                            JOIN TransClientes t ON t.id = h.idTransClientes AND t.Eliminado = 0
                            WHERE h.Recorrido = ? AND h.Estado = 'Abierto' AND h.Eliminado = 0 AND h.Devuelto = 0
                              AND t.Origen = 'IMPORTACION_DINTER' AND t.Retirado = 1 AND t.Entregado = 0
                            LIMIT 1");
    $st->bind_param('s', $recorrido);
    $st->execute();
    return (bool) $st->get_result()->fetch_row();
}

// Control de salida: códigos de bolsines asignados sin escanear, y si el recorrido lleva pedidos
// de Dinter sin ningún bolsín. NO depende de "Omitir control de escaneo": eso es para los bultos;
// las facturas tienen que salir igual.
function controlBolsines(mysqli $mysqli, string $recorrido, ?int $orden): array
{
    if (!bolsinesHabilitado($mysqli)) return ['pendientes' => [], 'sin_bolsin' => false];

    $bolsines = bolsinesDelRecorrido($mysqli, $recorrido, $orden);
    $pendientes = [];
    foreach ($bolsines as $b) {
        if ($b['Estado'] === 'Asignado') $pendientes[] = $b['Codigo'];
    }
    $sinBolsin = !$bolsines && recorridoTieneDinter($mysqli, $recorrido);
    return ['pendientes' => $pendientes, 'sin_bolsin' => $sinBolsin];
}

// Mensaje para el chofer si el bolsín le traba la salida (null si está todo bien).
function mensajeBolsin(array $control): ?string
{
    if ($control['sin_bolsin']) {
        return 'Este recorrido lleva pedidos de Dinter y no tiene bolsín interno asignado. Pedile al depósito que te lo asigne y escanealo en Warehouse.';
    }
    if ($control['pendientes']) {
        return 'Escaneá en Warehouse el bolsín interno: ' . implode(', ', $control['pendientes']) . '.';
    }
    return null;
}
