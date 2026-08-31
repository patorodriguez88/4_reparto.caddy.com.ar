<?php
// Cierre del recorrido ("Finalizar Recorrido"). Solo se permite cuando ya no
// quedan paquetes pendientes (entregados o no). Marca Logistica.Estado='Cerrada'
// con FechaRetorno/HoraRetorno y cierra cualquier pausa abierta. Devuelve el
// resumen (tiempo total en ruta + cantidad de paradas) para el modal final.
declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => 0, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . "/../../Conexion/conexioni.php";

function responder(array $arr, int $code = 200): void
{
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function formatoDuracion(int $seg): string
{
  $seg = max(0, $seg);
  $h = intdiv($seg, 3600);
  $m = intdiv($seg % 3600, 60);
  if ($h > 0) return $h . 'h ' . $m . 'm';
  return $m . 'm';
}

$userId = (int) ($_SESSION['idusuario'] ?? 0);
if ($userId <= 0) {
  responder(['success' => 0, 'error' => 'NO_SESSION'], 401);
}

try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  // Recorrido cargado del chofer
  $st = $mysqli->prepare("
    SELECT id, NumerodeOrden, Recorrido, Fecha, HoraSalidaReal
    FROM Logistica
    WHERE idUsuarioChofer = ? AND Estado = 'Cargada' AND Eliminado = 0
    LIMIT 1
  ");
  $st->bind_param('i', $userId);
  $st->execute();
  $log = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$log) {
    responder(['success' => 0, 'error' => 'SIN_RECORRIDO', 'msg' => 'No hay un recorrido activo para cerrar.']);
  }

  $idLog   = (int) $log['id'];
  $nOrden  = (int) $log['NumerodeOrden'];
  $recorr  = (string) $log['Recorrido'];

  // Guarda server-side: no cerramos si quedan paquetes sin resolver.
  $st = $mysqli->prepare("
    SELECT COUNT(h.id) AS pendientes
    FROM HojaDeRuta h
    INNER JOIN TransClientes t ON h.Seguimiento = t.CodigoSeguimiento
    WHERE h.Recorrido = ? AND h.NumerodeOrden = ?
      AND h.Eliminado = 0 AND h.Devuelto = 0
      AND t.Entregado = 0 AND t.Eliminado = 0
  ");
  $st->bind_param('si', $recorr, $nOrden);
  $st->execute();
  $pendientes = (int) $st->get_result()->fetch_assoc()['pendientes'];
  $st->close();

  if ($pendientes > 0) {
    responder([
      'success' => 0,
      'error'   => 'HAY_PENDIENTES',
      'msg'     => "Todavía quedan {$pendientes} paquete(s) sin resolver.",
      'pendientes' => $pendientes,
    ]);
  }

  // Total de paradas del recorrido
  $st = $mysqli->prepare("
    SELECT COUNT(id) AS paradas
    FROM HojaDeRuta
    WHERE Recorrido = ? AND NumerodeOrden = ? AND Eliminado = 0
  ");
  $st->bind_param('si', $recorr, $nOrden);
  $st->execute();
  $paradas = (int) $st->get_result()->fetch_assoc()['paradas'];
  $st->close();

  // Tiempo total en ruta (desde HoraSalidaReal hasta ahora)
  $ahora = new DateTime('now');
  $tiempoSeg = 0;
  $horaInicio = null;
  if (!empty($log['HoraSalidaReal']) && $log['HoraSalidaReal'] !== '0000-00-00 00:00:00') {
    try {
      $ini = new DateTime($log['HoraSalidaReal']);
      $tiempoSeg = max(0, $ahora->getTimestamp() - $ini->getTimestamp());
      $horaInicio = $ini->format('H:i');
    } catch (Throwable $e) {
      $tiempoSeg = 0;
    }
  }

  // Cierro pausas abiertas (si la tabla existe)
  try {
    $st = $mysqli->prepare("UPDATE PausasRecorrido SET Fin = NOW() WHERE idUsuario = ? AND Fin IS NULL");
    $st->bind_param('i', $userId);
    $st->execute();
    $st->close();
  } catch (Throwable $e) {
    // PausasRecorrido puede no existir en algún entorno - no rompe el cierre.
  }

  // Cierro el recorrido
  $fechaRet = $ahora->format('Y-m-d');
  $horaRet  = $ahora->format('H:i:s');
  $usuario  = (string) ($_SESSION['Usuario'] ?? '');
  $st = $mysqli->prepare("
    UPDATE Logistica
    SET Estado = 'Cerrada', FechaRetorno = ?, HoraRetorno = ?, UsuarioCierre = ?
    WHERE id = ? AND Estado = 'Cargada'
    LIMIT 1
  ");
  $st->bind_param('sssi', $fechaRet, $horaRet, $usuario, $idLog);
  $st->execute();
  $cerrado = $st->affected_rows;
  $st->close();

  if ($cerrado < 1) {
    responder(['success' => 0, 'error' => 'NO_SE_PUDO_CERRAR', 'msg' => 'El recorrido ya no estaba activo.']);
  }

  responder([
    'success' => 1,
    'resumen' => [
      'tiempo_texto' => $tiempoSeg > 0 ? formatoDuracion($tiempoSeg) : 'sin registrar',
      'tiempo_seg'   => $tiempoSeg,
      'paradas'      => $paradas,
      'hora_inicio'  => $horaInicio,
      'hora_fin'     => $ahora->format('H:i'),
    ],
  ]);
} catch (Throwable $e) {
  error_log('finalizar_recorrido.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  responder(['success' => 0, 'error' => 'ERROR_INTERNO', 'msg' => 'No se pudo finalizar el recorrido.'], 500);
}
