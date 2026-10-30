<?php
// Cargo del recorrido en la cuenta corriente del cliente al cerrar la orden (30/10/2026).
// La pantalla vieja de Logística (Logistica.php, apagada el 22/9) lo cargaba al armar la orden; la
// actual nunca lo hizo y quedaba a mano ("Ingresar Recorridos" en la ficha del cliente): en octubre
// no lo cargó nadie. Va solo si el recorrido tiene cliente y producto con precio (hoy Dinter CBA,
// Embotelladora del Atlántico y Crecer Río Cuarto). Mismo movimiento que "Ingresar Recorridos"
// (Clientes/Procesos/php/recorridos.php): fecha de la orden, precio del día del servicio
// (Logistica.PrecioRecorrido) o el de la lista. Nunca dos cargos para la misma orden: si ya tiene
// uno (cargado a mano o ya facturado) no hace nada.
// Copia de SistemaTriangular/Funciones/php/cargo_recorrido.php (repo del sistema, cierre desde
// Logística > Órdenes): acá lo usa "Finalizar recorrido" de la app. Si se cambia una, cambiar la otra.
function cargarRecorridoEnCtaCte(mysqli $mysqli, int $idLogistica, string $usuario): bool
{
    $st = $mysqli->prepare("SELECT L.id, L.Fecha, L.NumerodeOrden, L.Recorrido, R.Cliente, C.nombrecliente, C.Cuit,
            IF(L.PrecioRecorrido > 0, L.PrecioRecorrido, P.PrecioVenta) AS Precio
        FROM Logistica L
        JOIN Recorridos R ON R.Numero = L.Recorrido
        JOIN Productos P ON P.Codigo = R.CodigoProductos
        JOIN Clientes C ON C.id = R.Cliente
        WHERE L.id = ? AND L.Eliminado = 0
          AND NOT EXISTS (SELECT 1 FROM Ctasctes WHERE Ctasctes.idLogistica = L.id AND IFNULL(Ctasctes.Eliminado, 0) = 0)
        LIMIT 1");
    if (!$st) return false;
    $st->bind_param('i', $idLogistica);
    $st->execute();
    $o = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$o || (int) $o['Cliente'] <= 0 || (float) $o['Precio'] <= 0) return false;

    $tipo = 'RECORRIDO ' . $o['Recorrido'];
    $obs = 'ORDEN N ' . $o['NumerodeOrden'] . ' RECORRIDO ' . $o['Recorrido'];
    $precio = (float) $o['Precio'];
    $idCliente = (int) $o['Cliente'];
    $numero = (string) $o['NumerodeOrden'];
    $cuit = (string) ($o['Cuit'] ?? '');
    $st = $mysqli->prepare("INSERT INTO Ctasctes (Fecha, RazonSocial, Cuit, TipoDeComprobante, NumeroVenta, Debe, Usuario,
            Observaciones, idCliente, FacturacionxRecorrido, idLogistica, Eliminado, Facturado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, 0)");
    if (!$st) return false;
    $st->bind_param('sssssdssii', $o['Fecha'], $o['nombrecliente'], $cuit, $tipo, $numero, $precio, $usuario, $obs, $idCliente, $idLogistica);
    $ok = $st->execute();
    $st->close();
    return $ok;
}
