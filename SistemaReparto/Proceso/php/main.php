<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
	#RECIBIR ARCHIVOS MULTIMEDIA
#-----------------------------------------------------------
	if(isset($_FILES["file"])){
   // Prefijo del nombre de la foto: fecha y hora (antes 'H:m:s', con el mes en lugar de los minutos y ':' en el nombre).
   $hora=date('Ymd_His_');
			/*=============================================
	SUBIR MULTIMEDIA
	=============================================*/
		$aleatorio = mt_rand(100,999);
		if(isset($_FILES["file"]["tmp_name"]) && !empty($_FILES["file"]["tmp_name"])){

			/*=============================================
			DEFINIMOS LAS MEDIDAS
			=============================================*/

			list($ancho, $alto) = getimagesize($_FILES["file"]["tmp_name"]);	

			$nuevoAncho = 1000;
			$nuevoAlto = 1000;

			/*=============================================
			CREAMOS EL DIRECTORIO DONDE VAMOS A GUARDAR LA FOTO DE LA MULTIMEDIA
			=============================================*/
			// tituloProducto es el código de seguimiento (#card-seguimiento): solo alfanumérico,
			// para que no se pueda armar una ruta fuera de images/.
			$codigoFoto = trim(strip_tags($_POST['tituloProducto'] ?? ''));
			if (!preg_match('/^[A-Za-z0-9]{4,40}$/', $codigoFoto)) {
				http_response_code(400);
				echo "Código de seguimiento inválido";
				exit;
			}
			$directorio = "images/".$codigoFoto;

			/*=============================================
			PRIMERO PREGUNTAMOS SI EXISTE UN DIRECTORIO DE MULTIMEDIA CON ESTA RUTA
			=============================================*/

			if (!file_exists($directorio)){

				mkdir($directorio, 0755);
			
			}

			/*=============================================
			DE ACUERDO AL TIPO DE IMAGEN APLICAMOS LAS FUNCIONES POR DEFECTO DE PHP
			=============================================*/

			if($_FILES["file"]["type"] == "image/jpeg"){

				/*=============================================
				GUARDAMOS LA IMAGEN EN EL DIRECTORIO
				=============================================*/
        
				$rutaMultimedia = $directorio."/".$hora.$_FILES["file"]["name"];
          
        $origen = imagecreatefromjpeg($_FILES["file"]["tmp_name"]);						

				$destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

				imagecopyresized($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

				imagejpeg($destino, $rutaMultimedia);

			}

			if($_FILES["file"]["type"] == "image/png"){

				/*=============================================
				GUARDAMOS LA IMAGEN EN EL DIRECTORIO
				=============================================*/

				$rutaMultimedia = $directorio."/".$hora.$_FILES["file"]["name"];

				$origen = imagecreatefrompng($_FILES["file"]["tmp_name"]);						

				$destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

				imagealphablending($destino, FALSE);
		
				imagesavealpha($destino, TRUE);

				imagecopyresized($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

				imagepng($destino, $rutaMultimedia);

			}
			echo $rutaMultimedia;	
		}
	}
