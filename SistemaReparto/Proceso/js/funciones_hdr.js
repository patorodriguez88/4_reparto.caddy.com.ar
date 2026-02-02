function msgReason(reason) {
  const r = (reason || "").toString().trim().toUpperCase();

  switch (r) {
    case "NO_RECORRIDO_ASIGNADO":
      return "No tenés un recorrido asignado o cargado. Avisá a administración.";
    case "NO_IDUSUARIO":
      return "No se detectó usuario activo (sesión perdida). Volvé a ingresar.";
    case "SESSION_EXPIRED":
      return "Tu sesión expiró. Volvé a ingresar.";
    default:
      return `No se pudo continuar (${r || "SIN_MOTIVO"}). Volvé a ingresar.`;
  }
}
$(document).ajaxError(function (event, xhr) {
  if (!xhr) return;

  // ✅ Si estoy en login, NO fuerces logout ni muestres swals por 401
  if ($("#login").is(":visible") || $("body").hasClass("login-lock")) {
    return;
  }

  let obj = null;
  try {
    obj = JSON.parse(xhr.responseText);
  } catch (e) {}

  if (obj && obj.forceLogout) {
    cerrarSesionForzada(obj.reason);
    return;
  }

  if (xhr.status === 401) {
    cerrarSesionForzada("SESSION_EXPIRED");
  }
});
function tryHandleForceLogout(xhr) {
  if (!xhr) return false;

  let obj = null;
  try {
    obj = JSON.parse(xhr.responseText);
  } catch (e) {}

  if (obj && obj.forceLogout) {
    cerrarSesionForzada(obj.reason);
    return true;
  }
  return false;
}
function mostrarErrorLogin(obj) {
  const msg = obj?.error || obj?.msg || "Error desconocido";
  const extra = obj?.detail ? `\n${obj.detail}` : "";
  const eid = obj?.error_id ? `\nID: ${obj.error_id}` : "";

  if (window.Swal) {
    Swal.fire({
      icon: "error",
      title: "No se pudo iniciar sesión",
      text: msg + extra + eid,
    });
  } else {
    alert(msg + extra + eid);
  }
}
function cerrarSesionForzada(reason) {
  const texto = msgReason(reason);

  $("#hdr, #navbar, #topnav, #mis_envios, #hdractivas, #card-envio").hide();
  $("#login").show();

  Swal.fire({
    icon: "warning",
    title: "Atención",
    text: texto,
  });
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
    url: "Proceso/php/funciones_hdr.php", // PHP que devuelve JSON
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

// $(document).ready(function () {
//   //OJO ESTO SINO LO SACAMOS.
//   if ($("#login").is(":visible") || $("body").hasClass("login-lock")) {
//     return;
//   }

//   $("#prueba").select2({
//     placeholder: "Select an option",
//     tags: true,
//     tokenSeparators: [",", " "],
//   });

//   Dropzone.autoDiscover = false;
//   paneles(); // carga inicial
//   asegurarMenuWarehouse();
// });
$(document).ready(function () {
  // UI init (esto sí puede correr siempre)
  $("#prueba").select2({
    placeholder: "Select an option",
    tags: true,
    tokenSeparators: [",", " "],
  });

  Dropzone.autoDiscover = false;

  // ✅ Chequeo sesión real
  initApp();
});

function initApp() {
  $.ajax({
    data: { Datos: 1 },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
    global: false, // ✅ NO dispara $(document).ajaxError
  })
    .done(function (jsonData) {
      // Si tu backend manda forceLogout
      if (jsonData && jsonData.forceLogout) {
        cerrarSesionForzada(jsonData.reason || "SESSION_EXPIRED");
        return;
      }

      // ✅ Hay sesión -> arrancamos
      if (jsonData && jsonData.success == 1) {
        $("#hdr,#navbar,#topnav").show();
        $("#login").hide();
        $("body").removeClass("login-lock");

        // Si querés usar esos datos del header acá también:
        $("#hdr-header").html(`H: ${jsonData.NOrden} R: ${jsonData.Recorrido}`);
        $("#badge-total").html(jsonData.Total);
        $("#badge-sinentregar").html(jsonData.Abiertos);
        $("#badge-entregados").html(jsonData.Cerrados);

        paneles(); // ✅ recién ahora
        asegurarMenuWarehouse(); // ✅ recién ahora
      } else {
        // ❌ No hay sesión -> login
        $("#hdr,#navbar,#topnav").hide();
        $("#login").show();
        $("body").addClass("login-lock");
      }
    })
    .fail(function (xhr) {
      // 401 o error -> login
      $("#hdr,#navbar,#topnav").hide();
      $("#login").show();
      $("body").addClass("login-lock");
    });
}
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
  let pendientes = 2;

  function doneRequest() {
    pendientes--;
    if (pendientes <= 0) $("#info-alert-modal").modal("hide");
  }

  // PANELES HTML
  $.ajax({
    data: { Paneles: 1, search: a },
    type: "POST",
    url: "Proceso/php/funciones_hdr.php",
    dataType: "text",
    success: function (responseText) {
      let obj = null;
      try {
        obj = JSON.parse(responseText);
      } catch (e) {}

      if (obj && obj.forceLogout) {
        cerrarSesionForzada(obj.reason);
        return;
      }

      $("#hdractivas").html(responseText).fadeIn();
    },
    error: function (xhr) {
      if (tryHandleForceLogout(xhr)) return;

      console.error("Error Paneles:", xhr.responseText || xhr);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "No se pudieron cargar los paneles.",
      });
    },
    complete: doneRequest,
  });

  // TOTALES
  $.ajax({
    data: { Datos: 1 },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
    success: function (jsonData) {
      if (jsonData && jsonData.forceLogout) {
        cerrarSesionForzada(jsonData.reason);
        return;
      }

      if (jsonData.success == 1) {
        $("#hdr-header").html(`H: ${jsonData.NOrden} R: ${jsonData.Recorrido}`);
        $("#badge-total").html(jsonData.Total);
        $("#badge-sinentregar").html(jsonData.Abiertos);
        $("#badge-entregados").html(jsonData.Cerrados);
        $("#hdr,#navbar,#topnav").show();
        asegurarMenuWarehouse();
        $("#login").hide();
      } else {
        console.warn("Datos no OK:", jsonData);
        $("#login").show();
      }
    },
    error: function (xhr) {
      if (tryHandleForceLogout(xhr)) return;

      console.error("Error Datos:", xhr.status, xhr.responseText);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "No se pudieron cargar los datos.",
      });
    },
    complete: doneRequest,
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
        (dato.CodigoSeguimiento || "").split("_")[0],
      );
    },
    error: function (xhr, status, error) {
      console.error(
        "Error BuscoDatos (verwrong):",
        status,
        error,
        xhr.responseText,
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
        (dato.CodigoSeguimiento || "").split("_")[0],
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
        xhr.responseText,
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
        jsonData.new,
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
  },
);
$(document).on("submit", "#loginForm", function (e) {
  e.preventDefault();
  e.stopPropagation();
  return false;
});

//INGRESO!
$(document).on("click", "#ingreso", function (e) {
  e.preventDefault();
  e.stopPropagation();

  var user = $("#user").val();
  var pass = $("#password").val();

  $.ajax({
    url: "Conexion/admision.php",
    type: "POST",
    dataType: "json",
    data: { Login: 1, user: user, password: pass },
    success: function (jsonData) {
      if (jsonData && jsonData.forceLogout) {
        Swal.fire({
          icon: "warning",
          title: "No hay recorrido asignado",
          text: "No tenés un recorrido cargado. Avisá a administración.",
        });
        return;
      }

      if (jsonData && jsonData.success == 1) {
        $("#login").hide();
        $("#hdr").show();
        $("#navbar").show();
        $("#topnav").show();
        paneles();
        console.log(
          "standalone?",
          window.matchMedia("(display-mode: standalone)").matches,
          "iosStandalone?",
          window.navigator.standalone,
        );
        setTimeout(showInstallBanner, 1200); // ✅ acá
      } else {
        Swal.fire({
          icon: "error",
          title: "Login inválido",
          text:
            (jsonData && (jsonData.msg || jsonData.error)) ||
            "Usuario o contraseña incorrectos.",
          customClass: {
            container: "caddy-login-swal",
          },
        });
      }
    },
    error: function (xhr) {
      let obj = null;

      // Intento parsear JSON aunque jQuery diga parsererror
      try {
        obj = JSON.parse(xhr.responseText);
      } catch (e) {}

      if (obj && obj.forceLogout) {
        Swal.fire({
          icon: "warning",
          title: "Error",
          title: "No hay recorrido asignado",
          text: "No tenés un recorrido cargado. Avisá a administración.",
        });
        return;
      }

      Swal.fire({
        icon: "error",
        title: "Error",
        text:
          (obj && (obj.error || obj.msg)) ||
          "El servidor devolvió HTML/Warning y no JSON.",
        customClass: {
          container: "caddy-login-swal",
        },
      });

      console.error(xhr.responseText);
    },
  });

  return false;
});
