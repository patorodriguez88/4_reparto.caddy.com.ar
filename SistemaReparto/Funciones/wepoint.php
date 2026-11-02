<?php
// Salida de bultos del depósito de Wepoint: lo que el chofer carga en Warehouse se anota en la cola
// wepoint_cola (misma base que el sistema) y el sistema se lo manda a Wepoint por cron cada minuto,
// con reintentos (sistema: Logistica/Proceso/php/wepoint_lib.php). La carga del chofer no espera a
// Wepoint. Solo los envíos que pasaron por Wepoint (escaneados en CrossDocking o avisados).

/** Misma definición que wepoint_cola_tabla() del sistema: la crea quien llegue primero. */
function wepointColaTabla(mysqli $mysqli): void
{
    $mysqli->query("CREATE TABLE IF NOT EXISTS wepoint_cola (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(10) NOT NULL,
        id_tc INT NOT NULL,
        codigo VARCHAR(100) NOT NULL,
        serie VARCHAR(100) NOT NULL,
        fecha DATE NOT NULL,
        recorrido VARCHAR(20) NULL,
        chofer VARCHAR(100) NULL,
        estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
        intentos INT NOT NULL DEFAULT 0,
        proximo_intento DATETIME NULL,
        ultimo_error VARCHAR(500) NULL,
        nro_orden VARCHAR(30) NULL,
        usuario VARCHAR(100) NULL,
        creado_en DATETIME NOT NULL,
        enviado_en DATETIME NULL,
        UNIQUE KEY uq_wepoint_cola (tipo, serie, fecha),
        KEY ix_wepoint_cola_estado (estado, proximo_intento)
    )");
}

/** @return int cuántos envíos se anotaron para salir de Wepoint */
function encolarSalidaWepoint(mysqli $mysqli, array $bases, string $recorrido, string $chofer): int
{
    $bases = array_values(array_filter(array_map('strval', $bases)));
    if (!$bases) {
        return 0;
    }

    $marcas = implode(',', array_fill(0, count($bases), '?'));
    $st = $mysqli->prepare("SELECT id, CodigoSeguimiento FROM TransClientes
                            WHERE CodigoSeguimiento IN ($marcas) AND Eliminado = 0
                              AND (wepoint_id > 0 OR Wepoint_status = 'Ingreso')");
    $st->bind_param(str_repeat('s', count($bases)), ...$bases);
    $st->execute();
    $envios = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    if (!$envios) {
        return 0;
    }

    wepointColaTabla($mysqli);
    $ins = $mysqli->prepare("INSERT INTO wepoint_cola (tipo, id_tc, codigo, serie, fecha, recorrido, chofer, usuario, creado_en)
        VALUES ('salida', ?, ?, ?, CURDATE(), ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE proximo_intento = IF(estado = 'error', NOW(), proximo_intento)");
    foreach ($envios as $e) {
        $idTc = (int)$e['id'];
        $codigo = (string)$e['CodigoSeguimiento'];
        $ins->bind_param('isssss', $idTc, $codigo, $codigo, $recorrido, $chofer, $chofer);
        $ins->execute();
    }
    $ins->close();

    return count($envios);
}
