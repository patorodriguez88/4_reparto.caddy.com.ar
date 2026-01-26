<?php
function estadoPorSlug(mysqli $mysqli, string $slug): array
{
    static $cache = [];

    $slug = trim(strtolower($slug));
    if (isset($cache[$slug])) return $cache[$slug];

    $slugEsc = $mysqli->real_escape_string($slug);
    $sql = "SELECT id, Slug, Estado
            FROM Estados
            WHERE Slug = '{$slugEsc}'
            LIMIT 1";

    $res = $mysqli->query($sql);
    $row = $res ? $res->fetch_assoc() : null;

    if (!$row) {
        throw new RuntimeException("Estado no encontrado para slug={$slug}");
    }

    return $cache[$slug] = $row; // ['id'=>..., 'slug'=>..., 'nombre'=>...]
}

//IMPLEMENTACION
// $st = estadoPorSlug($mysqli, 'retirado_cliente'); // o 'entregado_cliente'
// $Estado_id = (int)$st['id'];
// $Estado    = $st['nombre'];