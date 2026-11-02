<?php
// Copiar como wepoint_config.php (está en .gitignore). Lo usa Funciones/wepoint.php para avisarle a
// Wepoint los bultos que el chofer carga en Warehouse. Mismo token que Conexion/wepoint_config.php
// del sistema (usuario de integración de la empresa Caddy en Wepoint), sin el "Bearer ".
// Sandbox → testing de Wepoint; producción → producción.

define('WEPOINT_API_URL', 'https://testing.wepoint.ar'); // producción: https://sistema.wepoint.ar
define('WEPOINT_API_TOKEN', 'TOKEN_DEL_USUARIO_DE_INTEGRACION');
