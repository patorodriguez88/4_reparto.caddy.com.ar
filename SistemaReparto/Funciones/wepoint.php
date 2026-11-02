<?php
// Salida de bultos del depósito de Wepoint: cuando el chofer escanea en Warehouse lo que carga, se
// le avisa a Wepoint (POST /api/v2/integraciones/caddy/salidas) para que esos bultos salgan de su
// depósito y dejen de contar estadía. Solo se mandan los envíos que se le avisaron a Wepoint al
// entrar (TransClientes.wepoint_id). Si Wepoint no responde, la carga del chofer sigue igual.
// URL y token en Conexion/wepoint_config.php (fuera del repo; ver wepoint_config.example.php).

function wepointConfig(): array
{
    static $config = null;
    if ($config === null) {
        $archivo = __DIR__ . '/../Conexion/wepoint_config.php';
        if (is_file($archivo)) {
            require_once $archivo;
        }
        $config = [
            'url' => rtrim(defined('WEPOINT_API_URL') ? WEPOINT_API_URL : '', '/'),
            'token' => defined('WEPOINT_API_TOKEN') ? WEPOINT_API_TOKEN : '',
        ];
    }
    return $config;
}

/** @return array{ok:bool,egresados?:array,mensaje?:string}|null null si no había nada de Wepoint */
function avisarSalidaWepoint(mysqli $mysqli, array $bases, string $recorrido, string $chofer): ?array
{
    $bases = array_values(array_filter(array_map('strval', $bases)));
    if (!$bases) {
        return null;
    }

    $marcas = implode(',', array_fill(0, count($bases), '?'));
    $st = $mysqli->prepare("SELECT DISTINCT CodigoSeguimiento FROM TransClientes
                            WHERE CodigoSeguimiento IN ($marcas) AND wepoint_id > 0 AND Eliminado = 0");
    $st->bind_param(str_repeat('s', count($bases)), ...$bases);
    $st->execute();
    $deWepoint = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'CodigoSeguimiento');
    $st->close();
    if (!$deWepoint) {
        return null;
    }

    $config = wepointConfig();
    if ($config['url'] === '' || $config['token'] === '') {
        error_log('Salida a Wepoint: falta Conexion/wepoint_config.php');
        return ['ok' => false, 'mensaje' => 'Falta configurar Wepoint'];
    }

    $ch = curl_init($config['url'] . '/api/v2/integraciones/caddy/salidas');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['codigos' => $deWepoint, 'recorrido' => $recorrido, 'chofer' => $chofer], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $config['token']],
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = json_decode((string)$resp, true);
    if ($http < 200 || $http >= 300 || !is_array($json)) {
        error_log("Salida a Wepoint (recorrido $recorrido): HTTP $http $err " . substr((string)$resp, 0, 300));
        return ['ok' => false, 'mensaje' => 'Wepoint no respondió'];
    }

    return ['ok' => true, 'egresados' => $json['data']['egresados'] ?? []];
}
