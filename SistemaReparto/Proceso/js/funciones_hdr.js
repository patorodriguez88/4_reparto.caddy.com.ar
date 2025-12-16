$(document).ajaxError(function (event, xhr) {
  if (xhr.status === 401) {
    console.warn("401 detectado → logout forzado");
    cerrarSesionForzada();
  }
});
function cerrarSesionForzada() {
  console.warn("Cerrando sesión forzada por 401");

  // Limpieza visual
  $("#hdr").hide();
  $("#navbar").hide();
  $("#topnav").hide();
  $("#mis_envios").hide();
  $("#hdractivas").hide();
  $("#card-envio").hide();

  // Volvemos al login
  $("#login").show();

  // Limpieza opcional de storage
  try {
    sessionStorage.clear();
    localStorage.removeItem("warehouse_init");
  } catch (e) {}

  // Aviso lindo
  if (window.Swal) {
    Swal.fire({
      icon: "warning",
      title: "Sesión finalizada",
      text: "Tu sesión expiró. Volvé a ingresar.",
      confirmButtonText: "OK",
    });
  } else {
    alert("Tu sesión expiró. Volvé a ingresar.");
  }
}
function esRetiro() {
  // vos ya usás: si dato.Retirado == 0 => RETIRO
  // acá lo determinamos por lo que muestra el card-servicio:
  return ($("#card-servicio").text() || "").toUpperCase().includes("RETIRO");
}

function baseActual() {
  const raw = ($("#card-seguimiento").text() || "").trim();
  return raw ? raw.split("_")[0].trim() : "";
}

function normalizarCode(code) {
  return (code || "").trim().toUpperCase();
}

function validarCodigosPickup() {
  const esperado = getCantidadEsperada(); // ya la tenés
  const base = baseActual();
  const seleccion = ($("#prueba").val() || []).map(normalizarCode);

  // si no hay base o no hay esperado, no validamos todavía
  if (!base || esperado <= 0)
    return { ok: false, msg: "Sin envío seleccionado" };

  // sin duplicados
  const uniq = new Set(seleccion);
  if (uniq.size !== seleccion.length)
    return { ok: false, msg: "Hay códigos repetidos" };

  // cantidad exacta
  if (seleccion.length !== esperado) {
    return {
      ok: false,
      msg: `Cantidad incorrecta: ${seleccion.length}/${esperado}`,
    };
  }

  // Validación por base
  if (esperado === 1) {
    // acepto BASE o BASE_1
    const c = seleccion[0];
    if (c !== base && c !== `${base}_1`)
      return { ok: false, msg: "Código no corresponde al envío" };
    return { ok: true };
  }

  // esperado > 1 -> deben ser BASE_1..BASE_n
  const validos = new Set();
  for (let i = 1; i <= esperado; i++) validos.add(`${base}_${i}`);

  for (const c of seleccion) {
    if (!validos.has(c)) return { ok: false, msg: `Código inválido: ${c}` };
  }

  return { ok: true };
}

function irAWarehouse() {
  try {
    // obliga a warehouse.html a (re)cargar expected desde backend
    sessionStorage.setItem("warehouse_init", "1");
  } catch (e) {}

  // ajustá la ruta si warehouse.html está en otra carpeta
  window.location.href = "warehouse.html";
}

// Inyecta un item "Escanear" en el menú si no existe en el HTML
function asegurarMenuWarehouse() {
  const $nav = $("#topnav-menu-content .navbar-nav");
  if (!$nav.length) return;

  // si ya existe (porque lo agregaste en el HTML), no duplicamos
  if ($("#menu-warehouse").length) return;

  const html = `
    <li class="nav-item">
      <a class="nav-link" href="#" id="menu-warehouse">
        <i class="mdi mdi-barcode-scan"></i> Escanear (Warehouse)
      </a>
    </li>
  `;

  $nav.prepend(html);

  // bind click
  $("#menu-warehouse").on("click", function (e) {
    e.preventDefault();

    // cierro el menú colapsable si está abierto (mobile)
    let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
    if (closeMenu) closeMenu.click();

    irAWarehouse();
  });
  // });
}

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
  asegurarMenuWarehouse();
});

// LOGIN
$("#ingreso").click(function () {
  var user = $("#user").val();
  var pass = $("#password").val();

  $.ajax({
    data: { Login: 1, user: user, password: pass },
    type: "POST",
    url: "Conexion/admision.php",
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
        $("#topnav").show(); // 👈 MOSTRAR MENÚ
        asegurarMenuWarehouse();

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
    url: "../../SistemaReparto/Conexion/admision.php",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Cerrando Sesión...");
      // $("#info-alert-modal").modal("show");
    },
    success: function () {
      $("#hdr").hide();
      $("#navbar").hide();
      $("#login").show();
      $("#info-alert-modal").modal("hide");
      $("#topnav").hide(); // 👈 MOSTRAR MENÚ
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
        $("#hdr-header").html(`H: ${jsonData.NOrden} R: ${jsonData.Recorrido}`);
        $("#badge-total").html(jsonData.Total);
        $("#badge-sinentregar").html(jsonData.Abiertos);
        $("#badge-entregados").html(jsonData.Cerrados);
        $("#hdr").show();
        $("#navbar").show();
        $("#topnav").show();
        asegurarMenuWarehouse();
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
  limpiarInputsEntrega();
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
      $("#btnEscanear").attr(
        "data-expected",
        (dato.CodigoSeguimiento || "").split("_")[0]
      );
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
function limpiarInputsEntrega() {
  $("#receptor-name").val("");
  $("#receptor-dni").val("");
  $("#receptor-observaciones").val("");
  $("#prueba").val(null).trigger("change"); // si estás usando select2 para colecta
  // select2 (colecta)
  $("#prueba").val(null).trigger("change");
  $("#totalt").html("0");
}

function verok(i) {
  limpiarInputsEntrega();
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
      $("#card-receptor-cantidad").html(dato.Cantidad);
      $("#btnEscanear").attr(
        "data-expected",
        (dato.CodigoSeguimiento || "").split("_")[0]
      );
      $("#prueba").val(null).trigger("change"); // limpia items del select2 del envío anterior
      onCargarNuevoEnvioEnCard();

      var servicio;
      if (dato.Retirado == 0) {
        servicio = "RETIRO";
        $("#card-servicio").addClass("text-warning");
        $("#icon-direccion").addClass("text-warning");
        $("#icon-servicio").removeClass("mdi mdi-calendar");
        $("#icon-servicio").addClass("mdi mdi-arrow-down-bold");
        document.getElementById("card-receptor-items").style.display = "block";
        document.getElementById("card-receptor-name").style.display = "none";
        document.getElementById("card-receptor-dni").style.display = "none";
        setAceptarPickupEnabled(false);
      } else {
        servicio = "ENTREGA";
        $("#card-servicio").addClass("text-success");
        $("#icon-direccion").addClass("text-success");
        $("#icon-servicio").removeClass("mdi mdi-calendar");
        $("#icon-servicio").addClass("mdi mdi-arrow-up-bold");
        document.getElementById("card-receptor-items").style.display = "none";
        document.getElementById("card-receptor-name").style.display = "block";
        document.getElementById("card-receptor-dni").style.display = "block";
      }
      onCargarNuevoEnvioEnCard();
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
  // var cs = $("#card-seguimiento").html();
  const cs = ($("#card-seguimiento").text() || "").trim();
  if (!cs) {
    console.warn("No hay CódigoSeguimiento en el card");
    return;
  }

  $.ajax({
    data: { Webhook: 1, state: i, cs: cs },
    type: "POST",
    url: "Proceso/php/webhook.php",
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

//CONTROL DE CANTIDAD EN RECEPCION

function getCantidadEsperada() {
  // <a id="card-receptor-cantidad">3</a>
  const txt = ($("#card-receptor-cantidad").text() || "").trim();
  const n = parseInt(txt, 10);
  return isNaN(n) ? 0 : n;
}

function getCantidadCargada() {
  const v = $("#prueba").val(); // select2 multiple
  return Array.isArray(v) ? v.length : 0;
}

function setAceptarPickupEnabled(enabled) {
  $("#boton-entrega-success, .guardarProducto").prop("disabled", !enabled);
}
function esRetiro() {
  // en tu UI cuando es RETIRO mostrás #card-receptor-items
  return $("#card-receptor-items").is(":visible");
}

// function actualizarEstadoCantidadPickup() {
//   // Si NO es retiro, no aplicamos esta validación
//   if (!esRetiro()) {
//     setAceptarPickupEnabled(true);
//     return;
//   }

//   const esperado = getCantidadEsperada();
//   const cargado = getCantidadCargada();

//   // Si todavía no hay cantidad esperada, bloqueamos sin avisar
//   if (esperado <= 0) {
//     setAceptarPickupEnabled(false);
//     return;
//   }

//   // ✅ Si recién abrís y todavía no cargaste nada, NO muestres cartel
//   if (cargado === 0) {
//     setAceptarPickupEnabled(false);
//     return;
//   }

//   if (cargado === esperado) {
//     setAceptarPickupEnabled(true);
//     return;
//   }

//   setAceptarPickupEnabled(false);

//   // ✅ ahora sí, avisamos (porque ya empezó a cargar o se pasó)
//   if (window.Swal) {
//     if (cargado < esperado) {
//       Swal.fire({
//         icon: "info",
//         title: "Faltan bultos",
//         text: `Cargados ${cargado}/${esperado} (faltan ${esperado - cargado})`,
//         timer: 900,
//         showConfirmButton: false,
//       });
//     } else {
//       Swal.fire({
//         icon: "warning",
//         title: "Cantidad excedida",
//         text: `Cargados ${cargado}/${esperado} (sobran ${cargado - esperado})`,
//         timer: 1100,
//         showConfirmButton: false,
//       });
//     }
//   }
// }
function actualizarEstadoCantidadPickup() {
  // Si NO es retiro, no bloquees por items
  if (!esRetiro()) {
    setAceptarPickupEnabled(true);
    return;
  }

  // Si todavía no cargó nada, bloqueá sin cartel
  const cargado = getCantidadCargada();
  if (cargado === 0) {
    setAceptarPickupEnabled(false);
    return;
  }

  const v = validarCodigosPickup();
  setAceptarPickupEnabled(v.ok);

  if (!v.ok && window.Swal) {
    Swal.fire({
      icon: "warning",
      title: "Revisá los bultos",
      text: v.msg || "Validación fallida",
      timer: 1200,
      showConfirmButton: false,
    });
  }
}
// ✅ cuando se agregan/quitam items (manual o escaneo)
$(document).on("change", "#prueba", actualizarEstadoCantidadPickup);

// ✅ cuando cambias de envío / actualizas el card (muy importante)
function onCargarNuevoEnvioEnCard() {
  // bloquea por defecto y recalcula
  setAceptarPickupEnabled(false);
  actualizarEstadoCantidadPickup();
}

$(document).on(
  "click",
  "#boton-entrega-success, .guardarProducto",
  function (e) {
    if (!esRetiro()) return; // entrega normal

    const v = validarCodigosPickup();
    if (!v.ok) {
      e.preventDefault();
      if (window.Swal) {
        Swal.fire({
          icon: "error",
          title: "No se puede confirmar",
          text: v.msg || "Códigos inválidos",
        });
      } else {
        alert(v.msg || "Códigos inválidos");
      }
      return false;
    }
  }
);
