<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

$path = __DIR__ . '/../Conexion/Conexioni.php';
echo "Trying include: $path\n";

if (!file_exists($path)) {
    echo "FILE_NOT_FOUND\n";
    exit;
}

require_once $path;

echo "INCLUDED_OK\n";

if (!isset($mysqli)) {
    echo "\$mysqli NOT SET\n";
    exit;
}

echo "mysqli class: " . get_class($mysqli) . "\n";
echo "connect_errno=" . $mysqli->connect_errno . "\n";
echo "connect_error=" . $mysqli->connect_error . "\n";
