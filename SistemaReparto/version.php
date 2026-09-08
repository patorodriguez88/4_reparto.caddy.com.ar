<?php
// Devuelve un "build id" (el mtime mas reciente del codigo de la app) para que
// el front detecte cuando se deployo una version nueva mientras la PWA estaba
// abierta y se recargue solo. A proposito NO usa sesion ni base de datos: se
// llama seguido (al abrir, al volver a primer plano y cada 10 min).
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$base = __DIR__;

$patrones = [
    $base . '/hdr.html',
    $base . '/warehouse.html',
    $base . '/scan.html',
    $base . '/style.css',
    $base . '/css/*.css',
    $base . '/pwa/js/*.js',
    $base . '/Proceso/js/*.js',
    $base . '/Proceso/php/*.php',
];

$mtime = 0;
foreach ($patrones as $pat) {
    foreach (glob($pat) ?: [] as $f) {
        $m = @filemtime($f);
        if ($m !== false && $m > $mtime) {
            $mtime = $m;
        }
    }
}

// Si existe el archivo FORCE_RELOAD en esta carpeta, la recarga pasa a ser
// obligatoria: sirve para empujar un fix critico aunque el chofer este en
// medio de algo (se borra el archivo cuando ya no hace falta).
$forzar = is_file($base . '/FORCE_RELOAD');

echo json_encode(['build' => (string) $mtime, 'forzar' => $forzar]);
