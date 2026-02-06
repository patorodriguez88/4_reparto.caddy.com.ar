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
    const msg = (title || "Entregado al Cliente").toString();

    if (window.Swal && Swal.fire) {
      Swal.fire({
        title: "",
        backdrop: false,
        html: `
        <div class="caddy-stage caddy-stage--ok">
          <div class="caddy-icon-wrap">
            <div class="caddy-icon caddy-icon--ok">✓</div>
          </div>
          <div class="caddy-typing" data-text="${escapeHtml(msg)}"></div>
        </div>
      `,
        showConfirmButton: false,
        timer: 2600,
        allowOutsideClick: false,
        allowEscapeKey: false,
        background: "transparent",
        customClass: {
          container: "caddy-swal caddy-swal--ok",
          popup: "caddy-popup",
        },
      });
    } else {
      alert(msg);
    }
  }
  function swalToastError(title) {
    const msg = (title || "Ocurrió un error").toString();

    if (window.Swal && Swal.fire) {
      Swal.fire({
        title: "",
        backdrop: false,
        html: `
        <div class="caddy-stage caddy-stage--error">
          <div class="caddy-icon-wrap" id="caddyIcon">
            <div class="caddy-icon caddy-icon--error">✕</div>
          </div>
          <div class="caddy-typing"
               data-text="${escapeHtml(msg)}"></div>
        </div>
      `,
        showConfirmButton: true,
        confirmButtonText: "Entendido",
        allowEscapeKey: true,
        background: "transparent",
        customClass: {
          container: "caddy-swal caddy-swal--error",
          popup: "caddy-popup",
          confirmButton: "btn btn-light",
        },

        // 🚫 Evitamos cierre automático
        preConfirm: () => {
          // Opcional: marcar estado de cierre
          const icon = document.getElementById("caddyIcon");
          if (icon) {
            icon.classList.add("caddy-icon-hold");
          }

          // ⏱️ cerramos manualmente después
          setTimeout(() => {
            Swal.close();
          }, 400); // tiempo para que el icono quede visible

          return false; // MUY IMPORTANTE
        },
      });
    } else {
      alert(msg);
    }
  }
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
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

    if (retirado === 0) {
      const esperadoTxt = ($("#card-receptor-cantidad").text() || "").trim();
      const esperado = parseInt(esperadoTxt, 10) || 0;

      // ✅ NUEVO: flujo ML (confirmación visual)
      if (window.colectaML?.isML) {
        const conf = parseInt(window.colectaML.confirmedQty || 0, 10);

        if (esperado > 0 && conf !== esperado) {
          swalError(
            "Cantidad incompleta",
            `Confirmados ${conf}/${esperado}. Revisá los bultos antes de confirmar.`,
          );
          return;
        }

        // En ML NO validamos etiquetas por _n ni base
        // Podés igualmente mandar 1 etiqueta (shipments_id) a backend para auditoría
      } else {
        // 🔽 flujo clásico (QR con BASE_1/BASE_2/BASE_3)
        const cargado = etiquetas.length;

        if (esperado > 0 && cargado !== esperado) {
          swalError(
            "Cantidad incompleta",
            `Cargados ${cargado}/${esperado}. Escaneá o cargá todos los bultos antes de confirmar.`,
          );
          return;
        }

        // Validar que lo escaneado pertenezca a ESTE envío (misma base)
        const malos = etiquetas.filter(
          (e) => (e || "").split("_")[0].trim() !== csBase,
        );
        if (malos.length) {
          swalError(
            "Códigos incorrectos",
            `Estos no pertenecen a ${csBase}: ${malos.slice(0, 3).join(", ")}${
              malos.length > 3 ? "..." : ""
            }`,
          );
          return;
        }
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

        if (jsonData.success === 1) {
          swalToastOk(jsonData.estado || "Confirmado");
        } else {
          swalToastError(
            "Error para el Código " +
              csBase +
              " " +
              (jsonData.error || "No se pudo confirmar la entrega"),
          );
        }

        // ✅ Limpieza para que el próximo no herede datos
        limpiarInputsEntrega();

        // ✅ Volver al listado
        $("#card-envio").hide();
        $("#hdractivas").show();

        // ⚠️ webhooks: si te está tirando 404 / HTML, NO lo llames por ahora
        // webhooks(jsonData.estado);
        if (email_status_notice(csBase, jsonData.estado)) {
          console.log("Email de notificación enviado correctamente.");
        } else {
          console.error("Error al enviar el email de notificación.");
        }
        paneles();
      },
      error: function (xhr) {
        const txt = (
          xhr && xhr.responseText ? xhr.responseText : ""
        ).toString();
        console.error(txt || xhr);
        swalError(
          "Error de servidor",
          "El servidor devolvió HTML/WARNING o un 500. Revisá Network > Response.",
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
        // webhooks(jsonData.estado);
        mail_status_notice(cs, jsonData.estado);
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
