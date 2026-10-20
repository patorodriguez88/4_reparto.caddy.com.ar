<?php
// --------------------------------------------------
// FOTOS DE UN ENVÍO PARA SISTEMA (Servicios → Fotos)
// --------------------------------------------------
// Las fotos que sube el chofer quedan en Proceso/php/images/<CodigoSeguimiento>/ (main.php),
// dentro de este dominio. sistema.caddy.com.ar no las puede leer del disco: pide la lista acá,
// servidor a servidor, y muestra cada foto por su URL pública.
//
// GET ?codigo=<CodigoSeguimiento> con el header X-Clave-Interna. La clave es `fotos_envio`
// de la tabla ClavesInternas (sistema y reparto comparten base; el primero que la necesita
// la crea). Responde {success, fotos: [{nombre, url, fecha}]}.

define('ALLOW_NO_SESSION', true);

require_once "../../Conexion/conexioni.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder($data, $codigoHttp = 200)
{
  http_response_code($codigoHttp);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Misma función en sistema (Servicios/Procesos/php/fotos.php).
function clave_interna(mysqli $db, string $nombre): string
{
  $db->query("CREATE TABLE IF NOT EXISTS ClavesInternas (
      Nombre VARCHAR(50) NOT NULL PRIMARY KEY,
      Valor VARCHAR(100) NOT NULL,
      Creada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $nueva = bin2hex(random_bytes(24));
  $st = $db->prepare("INSERT IGNORE INTO ClavesInternas (Nombre, Valor) VALUES (?, ?)");
  $st->bind_param('ss', $nombre, $nueva);
  $st->execute();
  $st = $db->prepare("SELECT Valor FROM ClavesInternas WHERE Nombre = ?");
  $st->bind_param('s', $nombre);
  $st->execute();
  return (string) $st->get_result()->fetch_row()[0];
}

try {
  $clave = clave_interna($mysqli, 'fotos_envio');
} catch (Throwable $e) {
  error_log('fotos_envio.php clave: ' . $e->getMessage());
  responder(['success' => 0, 'error' => 'No se pudo leer la clave interna.'], 500);
}

$recibida = (string) ($_SERVER['HTTP_X_CLAVE_INTERNA'] ?? '');
if ($recibida === '' || !hash_equals($clave, $recibida)) {
  responder(['success' => 0, 'error' => 'No autorizado.'], 403);
}

// Los códigos de seguimiento son alfanuméricos; cualquier otra cosa no es una carpeta de fotos.
$codigo = trim((string) ($_GET['codigo'] ?? ''));
if (!preg_match('/^[A-Za-z0-9]{4,40}$/', $codigo)) {
  responder(['success' => 0, 'error' => 'Código de seguimiento inválido.'], 400);
}

$dir = __DIR__ . '/images/' . $codigo;
if (!is_dir($dir)) {
  responder(['success' => 1, 'fotos' => []]);
}

$esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
  ? 'https' : 'http';
$base = $esquema . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/images/' . rawurlencode($codigo) . '/';

$fotos = [];
foreach (scandir($dir) as $archivo) {
  if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $archivo) || !is_file($dir . '/' . $archivo)) continue;
  $fotos[] = [
    'nombre' => $archivo,
    'url'    => $base . rawurlencode($archivo),
    'fecha'  => date('Y-m-d H:i:s', filemtime($dir . '/' . $archivo)),
  ];
}
usort($fotos, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));

responder(['success' => 1, 'fotos' => $fotos]);
