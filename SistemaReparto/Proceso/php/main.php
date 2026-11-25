<?php
ini_set('display_errors', '0');
date_default_timezone_set('America/Argentina/Buenos_Aires');
// Fotos de la entrega: quedan en images/<CodigoSeguimiento>/ (las lista fotos_envio.php para sistema).
// 25/11/2026: la app ya las manda achicadas (lado mayor 1600 px, JPEG). Acá se guardan sin
// deformar (antes se estiraban a 1000x1000) y con un formato no soportado se responde error
// en vez de una respuesta vacía.

function fallar(int $http, string $msg): void
{
	http_response_code($http);
	echo $msg;
	exit;
}

if (!isset($_FILES["file"]["tmp_name"]) || empty($_FILES["file"]["tmp_name"])) {
	fallar(400, "No llegó la foto");
}

// tituloProducto es el código de seguimiento (#card-seguimiento): solo alfanumérico,
// para que no se pueda armar una ruta fuera de images/.
$codigoFoto = trim(strip_tags($_POST['tituloProducto'] ?? ''));
if (!preg_match('/^[A-Za-z0-9]{4,40}$/', $codigoFoto)) {
	fallar(400, "Código de seguimiento inválido");
}

$tmp  = $_FILES["file"]["tmp_name"];
$info = @getimagesize($tmp);
if (!$info) {
	fallar(415, "El archivo no es una imagen");
}
[$ancho, $alto] = $info;
$tipo = $info['mime'] ?? '';

if ($tipo === 'image/jpeg') {
	$origen = @imagecreatefromjpeg($tmp);
} elseif ($tipo === 'image/png') {
	$origen = @imagecreatefrompng($tmp);
} elseif ($tipo === 'image/webp' && function_exists('imagecreatefromwebp')) {
	$origen = @imagecreatefromwebp($tmp);
} else {
	fallar(415, "Formato de foto no soportado");
}
if (!$origen) {
	fallar(415, "No se pudo leer la foto");
}

$directorio = "images/" . $codigoFoto;
if (!file_exists($directorio)) {
	mkdir($directorio, 0755);
}

// Proporción original, lado mayor como máximo 1600 px.
$escala     = min(1, 1600 / max($ancho, $alto));
$nuevoAncho = max(1, (int) round($ancho * $escala));
$nuevoAlto  = max(1, (int) round($alto * $escala));

$destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255)); // PNG con transparencia: fondo blanco
imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

// Nombre: fecha y hora + número al azar (todas en JPEG).
$rutaMultimedia = $directorio . "/" . date('Ymd_His_') . mt_rand(100, 999) . ".jpg";
if (!imagejpeg($destino, $rutaMultimedia, 85)) {
	fallar(500, "No se pudo guardar la foto");
}

echo $rutaMultimedia;
