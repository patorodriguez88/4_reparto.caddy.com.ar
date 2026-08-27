<?php
// Copiar este archivo como google_config.php y completar con la key real.
// google_config.php está en .gitignore — nunca se sube al repositorio.

// Key de Maps JavaScript API (front-end) para dibujar el mapa del recorrido
// en la app de reparto. Reutiliza la misma key "browser" que
// sistema.caddy.com.ar/SistemaTriangular/Conexion/google_config.php, pero
// hay que agregar reparto.caddy.com.ar y sandbox.reparto.caddy.com.ar como
// dominios permitidos para esa key en Google Cloud Console (restricciones
// HTTP referrer), y confirmar que la Directions API esté habilitada.
define('GOOGLE_API_KEY_BROWSER', 'TU_KEY_BROWSER_AQUI');
