//MENU

// MI CUENTA
$("#mi_cuenta").click(function () {
  let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
  closeMenu.click();
  $("#mis_envios").show();
  $("#hdractivas").hide();

  $.ajax({
    data: { MisEnvios: 1 },
    type: "POST",
    url: "Proceso/php/funciones_hdr1.php",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Cargando datos..");
      $("#info-alert-modal").modal("show");
    },
    success: function (response) {
      var jsonData = JSON.parse(response);
      // $('#offcanvasExample').modal('hide');

      $("#info-alert-modal").modal("hide");
      if (jsonData.success == 1) {
        $("#mis_envios_total").html(jsonData.Total);
        $("#mis_noenvios_total").html(jsonData.Totalno);
      }
    },
  });
});

//MI RECORRIDO
$("#mi_recorrido").click(function () {
  let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
  closeMenu.click();
  $("#mis_envios").hide();
  $("#hdractivas").show();
});

//NO DESPLEGAR EL MENU EN SELECT2 (ITEMS)
$("#prueba").on("select2:unselecting", function () {
  var opts = $(this).data("select2").options;
  opts.set("disabled", true);
  setTimeout(function () {
    opts.set("disabled", false);
  }, 1);
});
//CERRAR RECORRIDO
$("#close_rec").click(function () {
  let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
  closeMenu.click();

  $("#close_rec_div").show();
  $("#mis_envios").hide();
  $("#hdractivas").hide();
});

//CONTAR LOS ELEMENTOS DEL SELECT2 (ITEMS)
$("#prueba").on("change", function (e) {
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
  paneles();
});

$("#ingreso").click(function () {
  var user = $("#user").val();
  var pass = $("#password").val();

  $.ajax({
    data: { Login: 1, user: user, password: pass },
    type: "POST",
    url: "https://www.caddy.com.ar/AppRecorridos/Conexion/admision.php",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Verificando Datos..");
      $("#info-alert-modal").modal("show");
    },
    success: function (response) {
      var jsonData = JSON.parse(response);

      if (jsonData.success == 1) {
        $("#hdr").show();
        $("#navbar").show();
        $("#login").hide();
        $("#hdractivas").show();
        paneles();

        var codigos = jsonData.codigos;

        for (var i = 0; i < codigos.length; i++) {
          if (codigos[i]["Retirado"] == 1) {
            mail_status_notice(codigos[i]["Seguimiento"], "En Transito");
          } else {
            mail_status_notice(codigos[i]["Seguimiento"], "A Retirar");
          }
        }

        $("#info-alert-modal").modal("hide");
      }
    },
  });
});

$("#salir").click(function () {
  let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
  closeMenu.click();
  $.ajax({
    data: { Salir: 1 },
    type: "POST",
    url: "https://www.caddy.com.ar/AppRecorridos/Conexion/admision.php",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Cerrando Sesion..");
      $("#info-alert-modal").modal("show");
    },
    success: function (response) {
      $("#hdr").hide();
      $("#navbar").hide();
      //   $('#topnav_navbar').hide();
      $("#login").show();
      $("#info-alert-modal").modal("hide");
    },
  });
});

$("#ver_mapa").click(function () {
  // document.getElementById('card_mapa').style.display="block";
  document.getElementById("hdractivas").style.display = "none";
  document.getElementById("card-envio").style.display = "none";
});

$("#btn-dark-el").click(function () {
  var n = "";
  paneles();
  // document.getElementById('btn-dark').style.display="block";
  document.getElementById("btn-dark-el").style.display = "none";
});

// $('#buscarnombre').blur(function(){

$("#btn-search").click(function () {
  var n = $("#buscarnombre").val();

  if (n) {
    paneles(n);
    $("#full-width-modal").modal("hide");
    document.getElementById("btn-dark-el").style.display = "block";
    document.getElementById("btn-dark").style.display = "none";
  }
});

//FUNCION PARA MOSTRAR LOS PANELES
function paneles(a) {
  $.ajax({
    data: { Paneles: 1, search: a },
    type: "POST",
    url: "Proceso/php/funciones_hdr.php",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Cargando datos..");
      $("#info-alert-modal").modal("show");
    },
    success: function (response) {
      $("#info-alert-modal").modal("hide");
      $("#hdractivas").html(response).fadeIn();
      if (response != null) {
      } else {
        alert("vacio");
      }
    },
  });
  //TOTALES
  $.ajax({
    data: { Datos: 1 },
    type: "POST",
    url: "Proceso/php/funciones.php",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Cargando datos..");
      $("#info-alert-modal").modal("show");
    },
    success: function (response) {
      var jsonData = JSON.parse(response);

      if (jsonData.success == 1) {
        $("#hdr-header").html(`H: ${jsonData.data} R: ${jsonData.Recorrido}`);
        $("#badge-total").html(jsonData.Total);
        $("#badge-sinentregar").html(jsonData.Abiertos);
        $("#badge-entregados").html(jsonData.Cerrados);
        $("#hdr").show();
        // $('#topnav_navbar').show();
        $("#navbar").show();
        $("#login").hide();
      } else {
        // $('#hdr').hide();

        $("#login").show();
      }
    },
  });
}

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
  //agrego aca para ver si limpia las obs
  $("#receptor-observaciones").val("");
});

Dropzone.prototype.removeThumbnail = function () {
  $(".dz-preview").fadeOut("slow");
  $(".dz-preview:hidden").remove();
};

function verwrong(i) {
  $.ajax({
    data: { BuscoDatos: 1, id: i },
    type: "POST",
    url: "https://www.caddy.com.ar/AppRecorridos/Proceso/php/funciones.php",
    success: function (response) {
      var jsonData = JSON.parse(response);
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
  });
}

function verok(i) {
  $.ajax({
    data: { BuscoDatos: 1, id: i },
    type: "POST",
    url: "https://www.caddy.com.ar/AppRecorridos/Proceso/php/funciones.php",
    success: function (response) {
      var jsonData = JSON.parse(response);
      var dato = jsonData.data[0];

      // document.getElementById('botoneraOk').style.display='block';
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
      if (dato.Retirado == 0) {
        var servicio = "RETIRO";
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
  });
}
function webhooks(i) {
  var cs = $("#card-seguimiento").html();

  $.ajax({
    data: { Webhook: 1, state: i, cs: cs },
    type: "POST",
    url: "https://www.caddy.com.ar/AppRecorridos/Proceso/php/webhook.php",
    success: function (response) {
      var jsonData = JSON.parse(response);
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
  });
}

$("#card-envio").on("show.bs.modal", function (e) {
  $("#receptor-observaciones").val();
});

// function veo(i){

//   $.ajax({
//           data:{'Mapa':1,'Rec':i},
//           type: "POST",
//           url: "https://www.caddy.com.ar/SistemaTriangular/AppRecorridos/Mapas/php/datos_hojaderuta.php",
//           success: function(response)
//           {
//             var jsonData= JSON.parse(response);
//             $('#hdractivas').hide();
//             $('#card_mapa').show();
//             $('#header-title2').html(jsonData.Color);
//             $('#header-title').html('Servicios Pendientes Recorrido '+jsonData.Recorrido);
//             $('#card_tabla').show();
//             initMap(jsonData.Color);
//             var datatable = $('#seguimiento').DataTable();
//             datatable.ajax.reload();
//           }
//       });
// }
