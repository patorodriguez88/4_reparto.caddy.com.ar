<?php
require_once "../Conexion/Conexioni.php";
header('Content-Type: application/json; charset=utf-8');

$ok = false;
$stmt = $mysqli->prepare("SELECT 1");
if ($stmt) {
    $ok = method_exists($stmt, 'get_result');
}
echo json_encode([
    'php' => PHP_VERSION,
    'get_result_exists' => $ok,
]);
