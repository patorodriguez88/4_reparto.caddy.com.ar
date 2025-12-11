// MENU

// MI CUENTA
$("#mi_cuenta").on("click", function () {
  // Cierro el menú colapsable si está abierto
  let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
  if (closeMenu) closeMenu.click();

  $("#mis_envios").show();
  $("#hdractivas").hide();

  $.ajax({
    data: { MisEnvios: 1 },
    type: "POST",
    url: "Proceso/php/funciones_hdr1.php", // PHP que devuelve JSON
    dataType: "json",
    beforeSend: function () {
      // $("#info-alert-modal-header").html("Cargando datos...");
      // $("#info-alert-modal").modal("show");
    },
    success: function (jsonData) {
      if (jsonData.success == 1) {
        $("#mis_envios_total").html(jsonData.Total);
        $("#mis_noenvios_total").html(jsonData.Totalno);
      } else {
        console.warn("MisEnvios no OK:", jsonData);
        // Podés mostrar un aviso suave si querés
        // alert(jsonData.error || "No se pudieron cargar tus envíos.");
      }
    },
    error: function (xhr, status, error) {
      console.error("Error MisEnvios:", status, error, xhr.responseText);
      alert("No se pudieron cargar tus envíos. Probá de nuevo.");
    },
    complete: function () {
      // 🔴 SE EJECUTA SIEMPRE, HAYA ÉXITO O ERROR (inclusive parsererror)
      $("#info-alert-modal").modal("hide");
    },
  });
});

// MI RECORRIDO
$("#mi_recorrido").click(function () {
  let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
  if (closeMenu) closeMenu.click();

  $("#mis_envios").hide();
  $("#hdractivas").show();
});

// NO DESPLEGAR EL MENU EN SELECT2 (ITEMS)
$("#prueba").on("select2:unselecting", function () {
  var opts = $(this).data("select2").options;
  opts.set("disabled", true);
  setTimeout(function () {
    opts.set("disabled", false);
  }, 1);
});

// CERRAR RECORRIDO
$("#close_rec").click(function () {
  let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
  if (closeMenu) closeMenu.click();

  $("#close_rec_div").show();
  $("#mis_envios").hide();
  $("#hdractivas").hide();
});

// CONTAR LOS ELEMENTOS DEL SELECT2 (ITEMS)
$("#prueba").on("change", function () {
  var count = $("#prueba :selected").length;
  $("#totalt").html(count);
});

$(document).ready(function () {
  $("#prueba").select2({
    placeholder: "Select an option",
    tags: true,
    tokenSeparators: [",", " "],
  });

  Dropzone.autoDiscover = false;
  paneles(); // carga inicial
});

// LOGIN
$("#ingreso").click(function () {
  var user = $("#user").val();
  var pass = $("#password").val();

  $.ajax({
    data: { Login: 1, user: user, password: pass },
    type: "POST",
    url: "../../SistemaReparto/Conexion/admision.php",
    dataType: "json",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Verificando Datos...");
      // $("#info-alert-modal").modal("show");
    },
    success: function (jsonData) {
      if (jsonData.success == 1) {
        $("#hdr").show();
        $("#navbar").show();
        $("#login").hide();
        $("#hdractivas").show();
        paneles();

        var codigos = jsonData.codigos || [];

        for (var i = 0; i < codigos.length; i++) {
          if (codigos[i]["Retirado"] == 1) {
            mail_status_notice(codigos[i]["Seguimiento"], "En Transito");
          } else {
            mail_status_notice(codigos[i]["Seguimiento"], "A Retirar");
          }
        }
      } else {
        // alert("Usuario o contraseña incorrectos.");
      }
      $("#info-alert-modal").modal("hide");
    },
    error: function (xhr, status, error) {
      $("#info-alert-modal").modal("hide");
      console.error("Error login:", status, error, xhr.responseText);
      // alert("Ocurrió un error al iniciar sesión.");
    },
  });
});

// SALIR
$("#salir").click(function () {
  let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
  if (closeMenu) closeMenu.click();

  $.ajax({
    data: { Salir: 1 },
    type: "POST",
    url: "SistemaReparto/Conexion/admision.php",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Cerrando Sesión...");
      // $("#info-alert-modal").modal("show");
    },
    success: function () {
      $("#hdr").hide();
      $("#navbar").hide();
      $("#login").show();
      $("#info-alert-modal").modal("hide");
    },
    error: function (xhr, status, error) {
      $("#info-alert-modal").modal("hide");
      console.error("Error cerrar sesión:", status, error, xhr.responseText);
    },
  });
});

$("#ver_mapa").click(function () {
  document.getElementById("hdractivas").style.display = "none";
  document.getElementById("card-envio").style.display = "none";
});

$("#btn-dark-el").click(function () {
  paneles();
  document.getElementById("btn-dark-el").style.display = "none";
});

// BUSCAR POR NOMBRE
$("#btn-search").click(function () {
  var n = $("#buscarnombre").val();

  if (n) {
    paneles(n);
    $("#full-width-modal").modal("hide");
    document.getElementById("btn-dark-el").style.display = "block";
    document.getElementById("btn-dark").style.display = "none";
  }
});

// ==================================================
// FUNCION PARA MOSTRAR LOS PANELES
// ==================================================
function paneles(a) {
  // Cantidad de requests que voy a hacer en esta función
  let pendientes = 2;

  // Mostrar el modal UNA sola vez al inicio
  // $("#info-alert-modal-header").html("Cargando datos...");
  // $("#info-alert-modal").modal("show");

  // Función helper: se llama al terminar CADA AJAX
  function doneRequest() {
    pendientes--;
    if (pendientes <= 0) {
      // Cuando terminaron LOS DOS AJAX, cierro el modal
      $("#info-alert-modal").modal("hide");
    }
  }

  // PANELES HTML
  $.ajax({
    data: { Paneles: 1, search: a },
    type: "POST",
    url: "Proceso/php/funciones_hdr.php",
    success: function (response) {
      $("#hdractivas").html(response).fadeIn();
    },
    error: function (xhr, status, error) {
      console.error("Error Paneles:", status, error, xhr.responseText);
      alert("No se pudieron cargar los paneles.");
    },
    complete: function () {
      doneRequest(); // 🔴 siempre se ejecuta
    },
  });

  // TOTALES (usa funciones.php → devuelve JSON)
  $.ajax({
    data: { Datos: 1 },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
    success: function (jsonData) {
      if (jsonData.success == 1) {
        $("#hdr-header").html(`H: ${jsonData.data} R: ${jsonData.Recorrido}`);
        $("#badge-total").html(jsonData.Total);
        $("#badge-sinentregar").html(jsonData.Abiertos);
        $("#badge-entregados").html(jsonData.Cerrados);
        $("#hdr").show();
        $("#navbar").show();
        $("#login").hide();
      } else {
        console.warn("Datos no OK:", jsonData);
        $("#login").show();
        // Podés mostrar un mensaje si querés
        // alert(jsonData.error || 'No se pudieron cargar los totales.');
      }
    },
    error: function (xhr, status, error) {
      console.error("Error Datos:", status, error, xhr.responseText);
      // Muchas veces acá es porque el PHP devuelve HTML o error 500 y no JSON
      // alert("Error al cargar los totales.");
    },
    complete: function () {
      doneRequest(); // 🔴 siempre se ejecuta
    },
  });
}

// BOTONERA / DROPZONE

$("#boton-entrega-wrong").click(function () {
  document.getElementById("hdractivas").style.display = "block";
  document.getElementById("card-envio").style.display = "none";

  $("#receptor-name").val("");
  $("#receptor-dni").val("");
  $("#receptor-observaciones").val("");

  $(".dz-preview").fadeOut("slow");
  $(".dz-preview:hidden").remove();
});

$("#boton-no-entrega-wrong").click(function () {
  document.getElementById("hdractivas").style.display = "block";
  document.getElementById("card-envio").style.display = "none";

  $(".dz-preview").fadeOut("slow");
  $(".dz-preview:hidden").remove();

  // Limpia observaciones
  $("#receptor-observaciones").val("");
});

Dropzone.prototype.removeThumbnail = function () {
  $(".dz-preview").fadeOut("slow");
  $(".dz-preview:hidden").remove();
};

// ==================================================
// VER WRONG (NO ENTREGA)
// ==================================================
function verwrong(i) {
  $.ajax({
    data: { BuscoDatos: 1, id: i },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
    success: function (jsonData) {
      var dato = jsonData.data[0];

      document.getElementById("botones-no-entrega").style.display = "block";
      document.getElementById("botones-entrega").style.display = "none";
      document.getElementById("botonera").style.display = "block";
      document.getElementById("hdractivas").style.display = "none";
      document.getElementById("card-envio").style.display = "block";

      $("#card-receptor-observaciones").show();
      $("#posicioncliente").html(dato.NombreCliente);
      $("#direccion").html(dato.Domicilio);
      $("#card-receptor-dni").css("display", "none");
      $("#card-receptor-name").css("display", "none");
      $("#receptor-observaciones").val("");
      $("#razones").val("");
      $("#card-seguimiento").html(dato.CodigoSeguimiento);
    },
    error: function (xhr, status, error) {
      console.error(
        "Error BuscoDatos (verwrong):",
        status,
        error,
        xhr.responseText
      );
      alert("No se pudo cargar la información del envío.");
    },
  });
}

// ==================================================
// VER OK (ENTREGA)
// ==================================================
function verok(i) {
  $.ajax({
    data: { BuscoDatos: 1, id: i },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
    success: function (jsonData) {
      var dato = jsonData.data[0];

      document.getElementById("botones-no-entrega").style.display = "none";
      document.getElementById("botones-entrega").style.display = "block";
      document.getElementById("hdractivas").style.display = "none";
      document.getElementById("card-envio").style.display = "block";
      document.getElementById("botonera").style.display = "none";

      $("#card-receptor-name").show();
      $("#card-receptor-dni").show();
      $("#card-receptor-observaciones").show();
      $("#posicioncliente").html(dato.NombreCliente);
      $("#direccion").html(dato.Domicilio);
      $("#contacto").html(dato.NombreCliente);
      $("#observaciones").html(dato.Observaciones);
      $("#card-seguimiento").html(dato.CodigoSeguimiento);

      var servicio;
      if (dato.Retirado == 0) {
        servicio = "RETIRO";
        $("#card-servicio").addClass("text-warning");
        $("#icon-direccion").addClass("text-warning");
        $("#icon-servicio").removeClass("mdi mdi-calendar");
        $("#icon-servicio").addClass("mdi mdi-arrow-down-bold");
        document.getElementById("card-receptor-items").style.display = "block";
      } else {
        servicio = "ENTREGA";
        $("#card-servicio").addClass("text-success");
        $("#icon-direccion").addClass("text-success");
        $("#icon-servicio").removeClass("mdi mdi-calendar");
        $("#icon-servicio").addClass("mdi mdi-arrow-up-bold");
        document.getElementById("card-receptor-items").style.display = "none";
      }
      $("#card-servicio").html(servicio);
    },
    error: function (xhr, status, error) {
      console.error(
        "Error BuscoDatos (verok):",
        status,
        error,
        xhr.responseText
      );
      alert("No se pudo cargar la información del envío.");
    },
  });
}

// ==================================================
// WEBHOOKS
// ==================================================
function webhooks(i) {
  var cs = $("#card-seguimiento").html();

  $.ajax({
    data: { Webhook: 1, state: i, cs: cs },
    type: "POST",
    url: "/Proceso/php/webhook.php",
    dataType: "json",
    success: function (jsonData) {
      console.log(
        "idOrigen",
        jsonData.idOrigen,
        "idDestino",
        jsonData.idDestino,
        "codigo",
        jsonData.codigo,
        "new",
        jsonData.new
      );
    },
    error: function (xhr, status, error) {
      console.error("Error webhook:", status, error, xhr.responseText);
    },
  });
}

// Limpieza de observaciones cuando se abre el card-envio como modal
$("#card-envio").on("show.bs.modal", function () {
  $("#receptor-observaciones").val("");
});
