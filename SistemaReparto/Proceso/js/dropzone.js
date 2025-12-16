/*=============================================
AGREGAR MULTIMEDIA CON DROPZONE
=============================================*/
var arrayFiles = [];

$(".multimediaFisica").dropzone({
  url: "Proceso/php/main.php",
  autoProcessQueue: false, // no subir automáticamente; subimos cuando el usuario confirma
  paramName: "file",
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
  success: function (file, resp) {
    // Si por algún motivo se procesa la cola, evitamos romper por respuestas HTML
    // (igual no debería pasar porque autoProcessQueue=false)
    // console.log("Dropzone upload response", resp);
  },
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
  function swalToastOk(title) {
    if (window.Swal && Swal.fire) {
      Swal.fire({
        toast: true,
        position: "top",
        icon: "success",
        title: title || "Listo",
        showConfirmButton: false,
        timer: 1400,
        timerProgressBar: true,
      });
    } else {
      alert(title || "OK");
    }
  }

  function swalError(title, text) {
    if (window.Swal && Swal.fire) {
      Swal.fire({ icon: "error", title: title || "Error", text: text || "" });
    } else {
      alert((title || "Error") + (text ? "\n" + text : ""));
    }
  }

  function cargasistema() {
    // 1) codigo actual del card (puede venir con _1)
    let csRaw = ($("#card-seguimiento").html() || "").trim();
    if (!csRaw) {
      swalError("Falta código", "No hay CódigoSeguimiento en el card.");
      return;
    }

    // si viene T6NA..._1 => base T6NA...
    const csBase = csRaw.split("_")[0].trim();

    const receptorname = $("#receptor-name").val();
    const receptordni = $("#receptor-dni").val();
    const receptorobservaciones = $("#receptor-observaciones").val();
    const razones = $("#razones").val();

    // 2) servicio: RETIRO / ENTREGA
    let retiradoTxt = ($("#card-servicio").html() || "").trim();
    let retirado = retiradoTxt === "RETIRO" ? 0 : 1;

    // 3) etiquetas: si tu select2 tiene base_1 base_2...
    // Si vinieran bases sin _n, no pasa nada (se guardan igual)
    let etiquetas = $("#prueba").val();
    if (!Array.isArray(etiquetas)) etiquetas = [];

    // 4) Validación fuerte: si es RETIRO (colecta), exigir cantidad correcta
    if (retirado === 0) {
      const esperadoTxt = ($("#card-receptor-cantidad").text() || "").trim();
      const esperado = parseInt(esperadoTxt, 10) || 0;
      const cargado = etiquetas.length;

      if (esperado > 0 && cargado !== esperado) {
        swalError(
          "Cantidad incompleta",
          `Cargados ${cargado}/${esperado}. Escaneá o cargá todos los bultos antes de confirmar.`
        );
        return;
      }

      // Validar que lo escaneado pertenezca a ESTE envío (misma base)
      const malos = etiquetas.filter(
        (e) => (e || "").split("_")[0].trim() !== csBase
      );
      if (malos.length) {
        swalError(
          "Códigos incorrectos",
          `Estos no pertenecen a ${csBase}: ${malos.slice(0, 3).join(", ")}${
            malos.length > 3 ? "..." : ""
          }`
        );
        return;
      }
    }

    $.ajax({
      data: {
        ConfirmoEntrega: 1,
        Cs: csBase, // 👈 SIEMPRE BASE (evita que no actualice TransClientes)
        Name: receptorname,
        Dni: receptordni,
        Obs: receptorobservaciones,
        Retirado: retirado,
        Razones: razones,
        Etiquetas: etiquetas, // 👈 array (Etiquetas[])
      },
      type: "POST",
      dataType: "json",
      url: "Proceso/php/funciones.php",
      success: function (jsonData) {
        if (!jsonData || typeof jsonData !== "object") {
          swalError("Respuesta inválida", "El servidor no devolvió JSON.");
          return;
        }

        if (jsonData.success != 1) {
          swalError(
            "No se pudo confirmar",
            jsonData.error || "Error desconocido"
          );
          return;
        }

        // ✅ Mensaje OK
        swalToastOk(
          jsonData.estado ? `✅ ${jsonData.estado}` : "✅ Confirmado"
        );

        // ✅ Limpieza para que el próximo no herede datos
        limpiarInputsEntrega();

        // ✅ Volver al listado
        $("#card-envio").hide();
        $("#hdractivas").show();

        // ⚠️ webhooks: si te está tirando 404 / HTML, NO lo llames por ahora
        // webhooks(jsonData.estado);

        paneles();
      },
      error: function (xhr) {
        const txt = (
          xhr && xhr.responseText ? xhr.responseText : ""
        ).toString();
        console.error(txt || xhr);
        swalError(
          "Error de servidor",
          "El servidor devolvió HTML/WARNING o un 500. Revisá Network > Response."
        );
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
      dataType: "json",
      url: "Proceso/php/funciones.php",
      success: function (jsonData) {
        if (!jsonData || typeof jsonData !== "object") {
          Swal && Swal.fire
            ? Swal.fire({
                icon: "error",
                title: "Respuesta inválida",
                text: "El servidor no devolvió JSON",
              })
            : alert("Respuesta inválida del servidor");
          return;
        }

        $("#receptor-observaciones").val("");
        $("#card-envio").css("display", "none");
        $("#info-alert-modal-header").html("Cargando entrega..");
        webhooks(jsonData.estado);
        // mail_status_notice(cs, jsonData.estado);
        paneles();
      },
      error: function (xhr) {
        const txt = (
          xhr && xhr.responseText ? xhr.responseText : ""
        ).toString();
        console.error(txt || xhr);
        Swal && Swal.fire
          ? Swal.fire({
              icon: "error",
              title: "Error",
              text: "El servidor devolvió un error (posible warning/HTML). Revisá Network > Response.",
            })
          : alert("Error de servidor. Revisá consola.");
      },
    });
  }
});
