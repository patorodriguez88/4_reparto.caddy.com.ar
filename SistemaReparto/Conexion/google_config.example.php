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

// Key "server" para llamadas del lado del servidor (Routes API) - se usa
// para recalcular la ETA de cada parada (eta.php). Reutiliza el mismo
// valor que sistema.caddy.com.ar/SistemaTriangular/Conexion/google_config.php
// (GOOGLE_API_KEY_SERVER) - las keys "server" normalmente se restringen por
// IP, no por dominio, así que no debería hacer falta tocar nada en Cloud
// Console además de confirmar que la IP del servidor de reparto esté
// permitida (si es que la key tiene esa restricción activada).
define('GOOGLE_API_KEY_SERVER', 'TU_KEY_SERVER_AQUI');
