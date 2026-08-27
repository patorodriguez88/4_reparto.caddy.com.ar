<?php
// Motor de recálculo de ETA por parada pendiente.
//
// Portado de sistema.caddy.com.ar/SistemaTriangular/Logistica/Mapas/php/
// orden_automatico.php (el mismo cálculo que usa el despachante al apretar
// "Ordenar según Reparto": Routes API + TiempoPorParada acumulado), pero
// simplificado para uso desde la app del repartidor:
//   - No reordena nada. Usa el orden ya fijado por HojaDeRuta.Posicion
//     (el mismo que ve el mapa en ruta_mapa.php) - sólo recalcula A QUÉ
//     HORA se llega a cada parada dado un punto/hora de partida reales.
//   - No parte en tramos de a 25 (asume rutas diarias normales, muy por
//     debajo del límite de la Routes API); si algún día se pasa, sólo
//     recalcula las primeras 25 - son las paradas inmediatas, que son las
//     que importan.
//
// Nunca debe interrumpir el flujo que la llama (confirmar una entrega no
// puede fallar porque Google esté lento/caído) - por eso todo el cuerpo
// está en un try/catch que sólo loguea y devuelve false ante cualquier error.

declare(strict_types=1);

// Distancia en metros entre dos puntos (fórmula haversine) - usada para
// detectar si el repartidor ya se alejó lo suficiente del punto de
// referencia inicial como para considerar que arrancó el recorrido.
function haversineMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
{
  $earthRadiusM = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);
  $a = sin($dLat / 2) * sin($dLat / 2) +
    cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
    sin($dLng / 2) * sin($dLng / 2);
  $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
  return $earthRadiusM * $c;
}

function recalcularEtas(mysqli $mysqli, string $recorrido, float $lat, float $lng, DateTime $desde): bool
{
  try {
    if (!defined('GOOGLE_API_KEY_SERVER')) {
      error_log('recalcularEtas: falta GOOGLE_API_KEY_SERVER en google_config.php');
      return false;
    }

    $recorridoEsc = $mysqli->real_escape_string($recorrido);

    // Mismo criterio de origen/destino del punto que ya usa ruta_mapa.php:
    // el "cliente" es el destino en Entrega/Retiro normal, o el origen
    // (IngBrutosOrigen guarda el idCliente ahí) en Colecta.
    $resIds = $mysqli->query(
      "SELECT IF(Retirado = 1, idClienteDestino, IngBrutosOrigen) AS idCliente
       FROM TransClientes
       WHERE Recorrido = '{$recorridoEsc}' AND Entregado = 0 AND Eliminado = 0"
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
      return true; // nada pendiente, no hay nada que recalcular
    }
    $idsIn = implode(',', array_unique($ids));

    // Mismo agrupado por cliente que ruta_mapa.php (varios bultos del mismo
    // cliente = una sola parada), mismo orden por Posicion.
    $sql = "SELECT
              MIN(HojaDeRuta.id) AS idHojaDeRuta,
              Clientes.id AS idCliente,
              MIN(Clientes.Latitud) AS lat,
              MIN(Clientes.Longitud) AS lng,
              MIN(HojaDeRuta.Posicion) AS posicion
            FROM HojaDeRuta
            INNER JOIN Clientes ON Clientes.id = HojaDeRuta.idCliente
            WHERE Clientes.id IN ({$idsIn})
              AND HojaDeRuta.Recorrido = '{$recorridoEsc}'
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
    if ($res) {
      while ($row = $res->fetch_array(MYSQLI_ASSOC)) {
        $plat = (float) $row['lat'];
        $plng = (float) $row['lng'];
        if ($plat === 0.0 && $plng === 0.0) {
          continue;
        }
        $paradas[] = [
          'idHojaDeRuta' => (int) $row['idHojaDeRuta'],
          'lat' => $plat,
          'lng' => $plng,
        ];
      }
    }
    if (!$paradas) {
      return true;
    }

    $maxParadas = 25; // límite de intermedios+destino de la Routes API
    if (count($paradas) > $maxParadas) {
      $paradas = array_slice($paradas, 0, $maxParadas);
    }

    $timeDelivered = 5.0;
    $stmtVar = $mysqli->prepare("SELECT Valor FROM Variables WHERE Nombre = 'TiempoPorParada' LIMIT 1");
    $stmtVar->execute();
    $rowVar = $stmtVar->get_result()->fetch_assoc();
    if ($rowVar && is_numeric($rowVar['Valor'])) {
      $timeDelivered = (float) $rowVar['Valor'];
    }

    $destino = $paradas[count($paradas) - 1];
    $intermedios = array_map(function ($p) {
      return ['location' => ['latLng' => ['latitude' => $p['lat'], 'longitude' => $p['lng']]]];
    }, array_slice($paradas, 0, -1));

    // Routes API rechaza un departureTime que no sea estrictamente futuro -
    // mismo margen de 2 minutos que orden_automatico.php para absorber la
    // latencia de red.
    $departureTimestamp = max($desde->getTimestamp(), time() + 120);

    $body = [
      'origin' => ['location' => ['latLng' => ['latitude' => $lat, 'longitude' => $lng]]],
      'destination' => ['location' => ['latLng' => ['latitude' => $destino['lat'], 'longitude' => $destino['lng']]]],
      'intermediates' => $intermedios,
      'travelMode' => 'DRIVE',
      'routingPreference' => 'TRAFFIC_AWARE_OPTIMAL',
      'departureTime' => gmdate('Y-m-d\TH:i:s\Z', $departureTimestamp),
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://routes.googleapis.com/directions/v2:computeRoutes');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'X-Goog-Api-Key: ' . GOOGLE_API_KEY_SERVER,
      'X-Goog-FieldMask: routes.legs.duration,routes.legs.distanceMeters',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $respuesta = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
      error_log('recalcularEtas: error de conexión con Google: ' . $curlError);
      return false;
    }

    $data = json_decode((string) $respuesta, true);
    if (!isset($data['routes'][0]['legs'])) {
      error_log('recalcularEtas: Routes API sin ruta válida: ' . ($data['error']['message'] ?? (string) $respuesta));
      return false;
    }
    $legs = $data['routes'][0]['legs'];

    $horaActual = clone $desde;
    foreach ($paradas as $i => $parada) {
      $leg = $legs[$i] ?? null;
      $duracionSeg = 0;
      if ($leg && isset($leg['duration']) && preg_match('/(\d+)s/', (string) $leg['duration'], $m)) {
        $duracionSeg = intval($m[1]);
      }
      $distanciaKm = ((float) ($leg['distanceMeters'] ?? 0)) / 1000;

      $minutos = round($duracionSeg / 60) + $timeDelivered;
      $horaActual->modify('+' . $minutos . ' minute');

      $horaEsc = $mysqli->real_escape_string($horaActual->format('H:i:s'));
      $mysqli->query(
        "UPDATE HojaDeRuta
         SET Hora = '{$horaEsc}', Tiempo = {$duracionSeg}, KmO = {$distanciaKm}
         WHERE id = " . (int) $parada['idHojaDeRuta'] . " LIMIT 1"
      );
    }

    return true;
  } catch (Throwable $e) {
    error_log('recalcularEtas: excepción no esperada: ' . $e->getMessage());
    return false;
  }
}
