<?php
// La raíz del dominio no tenía ningún index - sin Options -Indexes (ver
// .htaccess), Apache mostraba el listado de archivos del sitio en vez de
// mandar al repartidor a algún lado. hdr.html hace de login y de home: si no
// hay sesión válida, conexioni.php ya lo manda al formulario de acceso.
header('Location: /SistemaReparto/hdr.html');
exit;
