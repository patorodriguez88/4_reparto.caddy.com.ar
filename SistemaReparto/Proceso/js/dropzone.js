/*=============================================
AGREGAR MULTIMEDIA CON DROPZONE
=============================================*/
var arrayFiles = [];

$(".multimediaFisica").dropzone({
  url: "/",
  addRemoveLinks: true,
  acceptedFiles: "image/jpeg, image/png",
  maxFilesize: 3, //3mb
  maxFiles: 3, //maximo 3 archivos
  init: function () {
    this.on("addedfile", function (file) {
      arrayFiles.push(file);
      // 			console.log("arrayFiles", arrayFiles);
    });
    this.on("removedfile", function (file) {
      var index = arrayFiles.indexOf(file);
      console.log("index", index);
      arrayFiles.splice(index, 1);
    });
  },
  success: function () {},
});
var multimediaFisica = null;

$(".guardarProducto").click(function () {
  /*=============================================
	PREGUNTAMOS SI LOS CAMPOS OBLIGATORIOS ESTÁN LLENOS
	=============================================*/

  if ($("#card-seguimiento").html() != "" && arrayFiles != "") {
    /*=============================================
	   	PREGUNTAMOS SI VIENEN IMÁGENES PARA MULTIMEDIA O LINK DE YOUTUBE
	   	=============================================*/

    if (arrayFiles.length > 0) {
      var listaMultimedia = [];
      var finalFor = 0;

      for (var i = 0; i < arrayFiles.length; i++) {
        var datosMultimedia = new FormData();
        datosMultimedia.append("file", arrayFiles[i]);
        datosMultimedia.append("tituloProducto", $("#card-seguimiento").html());
        $.ajax({
          url: "Proceso/php/main.php",
          method: "POST",
          data: datosMultimedia,
          cache: false,
          contentType: false,
          processData: false,
          beforeSend: function () {
            $(".guardarProducto").html("Enviando ...");
          },
          success: function (respuesta) {
            listaMultimedia.push({ foto: respuesta });
            multimediaFisica = JSON.stringify(listaMultimedia);
            if (finalFor + 1 == arrayFiles.length) {
              finalFor = 0;
            }
            finalFor++;
            $(".guardarProducto").html("Guardar producto");
            //CARGA SISTEMA DESPUES DE CARGAR FOTO
            cargasistema();
            //BORRAR IMAGEN
          },
        });
      }
    }
  } else {
    //CARGA SISTEMA
    cargasistema();
  }

  function cargasistema() {
    var cs = $("#card-seguimiento").html();
    var receptorname = $("#receptor-name").val();
    var receptordni = $("#receptor-dni").val();
    var receptorobservaciones = $("#receptor-observaciones").val();
    var retirado = $("#card-servicio").html();
    var razones = $("#razones").val();
    var etiquetas = $("#prueba").val();

    if (retirado == "RETIRO") {
      retirado = 0;
    } else {
      retirado = 1;
    }
    $.ajax({
      data: {
        ConfirmoEntrega: 1,
        Cs: cs,
        Name: receptorname,
        Dni: receptordni,
        Obs: receptorobservaciones,
        Retirado: retirado,
        Razones: razones,
        Etiquetas: etiquetas,
      },
      type: "POST",
      url: "https://www.caddy.com.ar/AppRecorridos/Proceso/php/funciones.php",
      success: function (response) {
        var jsonData = JSON.parse(response);
        $("#receptor-observaciones").val("");
        $("#card-envio").css("display", "none");
        $("#info-alert-modal-header").html("Cargando entrega..");
        webhooks(jsonData.estado);
        mail_status_notice(cs, jsonData.estado);
        paneles();
      },
    });
  }
});
//NO ENTREGADO
$(".guardarNoEntrega").click(function () {
  /*=============================================
	PREGUNTAMOS SI LOS CAMPOS OBLIGATORIOS ESTÁN LLENOS
	=============================================*/

  if ($("#card-seguimiento").html() != "" && arrayFiles != "") {
    /*=============================================
	   	PREGUNTAMOS SI VIENEN IMÁGENES PARA MULTIMEDIA O LINK DE YOUTUBE
	   	=============================================*/

    if (arrayFiles.length > 0) {
      var listaMultimedia = [];
      var finalFor = 0;

      for (var i = 0; i < arrayFiles.length; i++) {
        var datosMultimedia = new FormData();
        datosMultimedia.append("file", arrayFiles[i]);
        datosMultimedia.append("tituloProducto", $("#card-seguimiento").html());
        $.ajax({
          url: "Proceso/php/main.php",
          method: "POST",
          data: datosMultimedia,
          cache: false,
          contentType: false,
          processData: false,
          beforeSend: function () {
            $(".guardarProducto").html("Enviando ...");
          },
          success: function (respuesta) {
            listaMultimedia.push({ foto: respuesta });
            multimediaFisica = JSON.stringify(listaMultimedia);
            if (finalFor + 1 == arrayFiles.length) {
              finalFor = 0;
            }

            finalFor++;
            $(".guardarProducto").html("Guardar producto");
            //CARGA SISTEMA DESPUES DE CARGAR FOTO
            cargasistemaNoEntrega();
          },
        });
      }
    }
  } else {
    //CARGA SISTEMA
    cargasistemaNoEntrega();
  }

  function cargasistemaNoEntrega() {
    var cs = $("#card-seguimiento").html();
    var receptorname = $("#receptor-name").val();
    var receptordni = $("#receptor-dni").val();
    var receptorobservaciones = $("#receptor-observaciones").val();
    var retirado = $("#card-servicio").html();
    var razones = $("#razones").val();
    if (retirado == "RETIRO") {
      retirado = 0;
    } else {
      retirado = 0;
    }
    $.ajax({
      data: {
        ConfirmoNoEntrega: 1,
        Cs: cs,
        Name: receptorname,
        Dni: receptordni,
        Obs: receptorobservaciones,
        Retirado: retirado,
        Razones: razones,
      },
      type: "POST",
      url: "https://www.caddy.com.ar/AppRecorridos/Proceso/php/funciones.php",
      success: function (response) {
        var jsonData = JSON.parse(response);
        $("#receptor-observaciones").val("");
        $("#card-envio").css("display", "none");
        $("#info-alert-modal-header").html("Cargando entrega..");
        webhooks(jsonData.estado);
        mail_status_notice(cs, jsonData.estado);
        paneles();
      },
    });
  }
});
