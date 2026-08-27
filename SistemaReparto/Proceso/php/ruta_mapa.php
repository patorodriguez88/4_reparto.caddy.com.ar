<?php
// Paradas pendientes del Recorrido asignado al repartidor logueado, en el
// orden planificado (HojaDeRuta.Posicion) - para dibujar el mapa fullscreen
// del botón flotante "Mi Recorrido". Mismo criterio de origen/destino que
// Mapas/php/datos_hojaderuta.php (ya usado por sistema.caddy.com.ar).
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . "/../../Conexion/conexioni.php";

function responder(array $arr, int $code = 200): void
{
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

$idUsuario = (int) ($_SESSION['idusuario'] ?? 0);
if ($idUsuario <= 0) {
  responder(['success' => 0, 'error' => 'NO_SESSION'], 401);
}

$apiKeyPath = __DIR__ . "/../../Conexion/google_config.php";
if (!file_exists($apiKeyPath)) {
  responder(['success' => 0, 'error' => 'GOOGLE_CONFIG_FALTANTE']);
}
require_once $apiKeyPath;

$Recorrido = $mysqli->real_escape_string((string) ($_SESSION['RecorridoAsignado'] ?? ''));
if ($Recorrido === '') {
  responder(['success' => 0, 'error' => 'SIN_RECORRIDO']);
}

// Mismo criterio que datos_hojaderuta.php: el "cliente" del punto es el
// destino en Entrega/Retiro normal, o el origen (IngBrutosOrigen guarda el
// idCliente ahí, no un dato impositivo real) cuando es Colecta.
$resIds = $mysqli->query(
  "SELECT IF(Retirado = 1, idClienteDestino, IngBrutosOrigen) AS idCliente
   FROM TransClientes
   WHERE Recorrido = '{$Recorrido}' AND Entregado = 0 AND Eliminado = 0"
);

$ids = [];
if ($resIds) {
  while ($r = $resIds->fetch_array(MYSQLI_ASSOC)) {
    $idCliente = (int) $r['idCliente'];
    if ($idCliente > 0) {
      $ids[] = $idCliente;
    }
  }
}

if (!$ids) {
  responder([
    'success'   => 1,
    'recorrido' => $Recorrido,
    'apiKey'    => GOOGLE_API_KEY_BROWSER,
    'paradas'   => [],
  ]);
}

$idsIn = implode(',', array_unique($ids));

// GROUP BY Clientes.id: un mismo cliente puede tener varios bultos/paquetes
// pendientes (varias filas en HojaDeRuta) - se muestra como UNA sola parada
// numerada, no una por bulto.
$sql = "SELECT
          Clientes.id AS idCliente,
          MIN(Clientes.nombrecliente) AS nombre,
          MIN(Clientes.Direccion) AS direccion,
          MIN(Clientes.Latitud) AS lat,
          MIN(Clientes.Longitud) AS lng,
          MIN(HojaDeRuta.Posicion) AS posicion,
          MIN(HojaDeRuta.id) AS idHojaDeRuta,
          COUNT(*) AS bultos,
          GROUP_CONCAT(DISTINCT HojaDeRuta.Seguimiento SEPARATOR ',') AS seguimientos
        FROM HojaDeRuta
        INNER JOIN Clientes ON Clientes.id = HojaDeRuta.idCliente
        WHERE Clientes.id IN ({$idsIn})
          AND HojaDeRuta.Recorrido = '{$Recorrido}'
          AND HojaDeRuta.Estado = 'Abierto'
          AND HojaDeRuta.Eliminado = 0
          AND Clientes.Latitud <> ''
          AND Clientes.Longitud <> ''
        GROUP BY Clientes.id
        ORDER BY
          (MIN(HojaDeRuta.Posicion) IS NULL OR MIN(HojaDeRuta.Posicion) = 0) ASC,
          posicion ASC,
          idHojaDeRuta ASC";

$res = $mysqli->query($sql);

$paradas = [];
$orden = 1;
if ($res) {
  while ($row = $res->fetch_array(MYSQLI_ASSOC)) {
    $lat = (float) $row['lat'];
    $lng = (float) $row['lng'];
    if ($lat === 0.0 && $lng === 0.0) {
      continue; // coordenadas basura, no se puede ubicar en el mapa
    }
    $paradas[] = [
      'orden'        => $orden++,
      'idCliente'    => (int) $row['idCliente'],
      'nombre'       => (string) $row['nombre'],
      'direccion'    => (string) $row['direccion'],
      'lat'          => round($lat, 7),
      'lng'          => round($lng, 7),
      'bultos'       => (int) $row['bultos'],
      'seguimientos' => (string) $row['seguimientos'],
    ];
  }
}

responder([
  'success'   => 1,
  'recorrido' => $Recorrido,
  'apiKey'    => GOOGLE_API_KEY_BROWSER,
  'paradas'   => $paradas,
]);
