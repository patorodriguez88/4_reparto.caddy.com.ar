// puente simple: botón salir dentro de Cuenta
$(document).on("click", "#btnCuentaSalir", function () {
  $("#salir").trigger("click");
});
function doLogout() {
  $.ajax({
    data: { Salir: 1 },
    type: "POST",
    url: "../../SistemaReparto/Conexion/admision.php",
    beforeSend: function () {
      $("#info-alert-modal-header").html("Cerrando Sesión...");
    },
    success: function () {
      $("#hdr, #navbar, #topnav, #screen-operacion, #screen-totales, #screen-cuenta").hide();
      $("#login").show();
      $("#info-alert-modal").modal("hide");
    },
    error: function (xhr, status, error) {
      $("#info-alert-modal").modal("hide");
      console.error("Error cerrar sesión:", status, error, xhr.responseText);
    },
  });
}
// function cargarMisEnvios() {
//   $.ajax({
//     data: { MisEnvios: 1 },
//     type: "POST",
//     url: "Proceso/php/funciones_hdr.php",
//     dataType: "json",
//     success: function (jsonData) {
//       if (jsonData && jsonData.success == 1) {
//         $("#mis_envios_total").html(jsonData.Total);
//         $("#mis_noenvios_total").html(jsonData.Totalno);
//       } else {
//         console.warn("MisEnvios no OK:", jsonData);
//       }
//     },
//     error: function (xhr, status, error) {
//       console.error("Error MisEnvios:", status, error, xhr.responseText);
//     },
//   });
// }
function cargarCuentaHTML() {
  return $.ajax({
    data: { MisEnviosHTML: 1 },
    type: "POST",
    url: "Proceso/php/funciones_hdr.php",
    dataType: "text",
    success: function (html) {
      $("#mis_envios_cuenta").html(html);
    },
    error: function (xhr) {
      if (xhr.status === 401) {
        cerrarSesionForzada("SESSION_EXPIRED");
        return;
      }
      $("#mis_envios_cuenta").html(`<div class="alert alert-danger">No se pudo cargar Cuenta.</div>`);
      console.error("CuentaHTML error:", xhr.status, xhr.responseText);
    },
  });
}
(function () {
  const screenMap = {
    operacion: "#screen-operacion",
    totales: "#screen-totales",
    cuenta: "#screen-cuenta",
  };

  // function showScreen(key) {
  //   $(".app-screen").removeClass("active").hide();

  //   const sel = screenMap[key] || screenMap.operacion;
  //   if (!$(sel).length) {
  //     console.warn("Screen no existe:", key, sel);
  //     return;
  //   }

  //   $(sel).addClass("active").show();

  //   $(".app-bottomnav .nav-item").removeClass("active");
  //   $(`.app-bottomnav .nav-item[data-screen="${key}"]`).addClass("active");

  //   if (key === "totales") {
  //     const $tpl = $("#mis_envios");
  //     const $dst = $("#mis_envios_clone");
  //     if ($tpl.length && $dst.length) $dst.empty().append($tpl.clone(true, true).children());
  //     cargarMisEnvios();
  //   }

  //   if (key === "cuenta") {
  //     const $tpl = $("#mis_envios");
  //     const $dst = $("#mis_envios_cuenta");
  //     if ($tpl.length && $dst.length) $dst.empty().append($tpl.clone(true, true).children());
  //     cargarMisEnvios();
  //   }
  // }

  // function showScreen(key) {
  //   $(".app-screen").removeClass("active").hide();

  //   const realKey = screenMap[key] ? key : "operacion";
  //   const sel = screenMap[realKey];

  //   $(sel).addClass("active").show();

  //   $(".app-bottomnav .nav-item").removeClass("active");
  //   $(`.app-bottomnav .nav-item[data-screen="${realKey}"]`).addClass("active");

  //   if (key === "totales") {
  //     const $tpl = $("#mis_envios");
  //     const $dst = $("#mis_envios_clone");
  //     if ($tpl.length && $dst.length) $dst.empty().append($tpl.clone(true, true).children());
  //     cargarMisEnvios();
  //   }

  //   if (key === "cuenta") {
  //     const $tpl = $("#mis_envios");
  //     const $dst = $("#mis_envios_cuenta");
  //     if ($tpl.length && $dst.length) $dst.empty().append($tpl.clone(true, true).children());
  //     cargarMisEnvios();
  //   }
  // }
  function showScreen(key) {
    // 1) Apago TODAS las screens
    $(".app-screen").removeClass("active").hide();

    // 2) Apago SIEMPRE los elementos que pueden quedar “colgados”
    $("#hdractivas").hide();
    $("#card-envio").hide();
    // 3) Determino screen real
    const realKey = screenMap[key] ? key : "operacion";
    const sel = screenMap[realKey];

    // 4) Muestro la screen pedida
    $(sel).addClass("active").show();

    // 5) Activo nav
    $(".app-bottomnav .nav-item").removeClass("active");
    $(`.app-bottomnav .nav-item[data-screen="${realKey}"]`).addClass("active");

    // 6) Acciones por screen
    if (realKey === "operacion") {
      $("#hdractivas").show(); // ✅ solo acá se ven paneles
    }

    if (realKey === "totales") {
      const $tpl = $("#mis_envios");
      const $dst = $("#mis_envios_clone");
      if ($tpl.length && $dst.length) $dst.empty().append($tpl.children().clone(true, true));
      // cargarMisEnvios();
    }

    // if (realKey === "cuenta") {
    //   const $tpl = $("#mis_envios");
    //   const $dst = $("#mis_envios_cuenta");
    //   if ($tpl.length && $dst.length) $dst.empty().append($tpl.children().clone(true, true));
    //   cargarMisEnvios();
    // }
    if (realKey === "cuenta") {
      cargarCuentaHTML();
    }
    // DEBUG útil
    console.log("showScreen:", realKey, {
      operacionVisible: $("#screen-operacion").is(":visible"),
      cuentaVisible: $("#screen-cuenta").is(":visible"),
      hdractivasVisible: $("#hdractivas").is(":visible"),
    });
  }

  // click bottom nav
  $(document).on("click", ".app-bottomnav .nav-item[data-screen]", function (e) {
    e.preventDefault();
    const key = $(this).data("screen");
    location.hash = key;
    showScreen(key);
  });

  // warehouse por acción
  $(document).on("click", '.app-bottomnav .nav-item[data-action="warehouse"]', function (e) {
    e.preventDefault();
    irAWarehouse();
  });

  // logout por acción
  // Salir desde bottomnav
  $(document).on("click", '.app-bottomnav .nav-item[data-action="logout"]', function (e) {
    e.preventDefault();
    doLogout();
  });

  // hash change (si entrás directo con #cuenta)
  window.addEventListener("hashchange", () => {
    const h = (location.hash || "").replace("#", "");
    showScreen(screenMap[h] ? h : "operacion");
  });

  // init
  $(function () {
    const h = (location.hash || "").replace("#", "");
    showScreen(screenMap[h] ? h : "operacion");
    window.showScreen = showScreen;
  });
})();
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
function esModoColecta() {
  return ($("#card-servicio").text() || "").trim().toUpperCase() === "COLECTA";
}
function determinarTipoServicio(dato) {
  const retirado = parseInt(dato?.Retirado, 10);
  const idDestino = parseInt(dato?.idClienteDestino, 10) || 0;

  // ENTREGA: Retirado != 0
  if (!Number.isNaN(retirado) && retirado !== 0) return "ENTREGA";

  // COLECTA: Retirado == 0 y destino especial
  if (retirado === 0 && idDestino === 18587) return "COLECTA";

  // RETIRO: Retirado == 0 (y no es colecta)
  if (retirado === 0) return "RETIRO";

  return "DESCONOCIDO";
}
/*************  ✨ Windsurf Command ⭐  *************/
/**
 * Limpia la UI de escaneo: Select2, total visual, estado de ML y oculta el botón de escaneo y el bloque de items (campo de escanear).
 */
/*******  6fb114c4-dfe4-47f3-bdf6-2d3cebc37fc4  *******/
function resetEscaneoUI() {
  // Limpia items seleccionados (Select2)
  try {
    $("#prueba").val(null).trigger("change");
  } catch (e) {}

  // Total visual
  $("#totalt").html("0");

  // Si estabas en flujo ML, resetealo también
  window.colectaML = { isML: false, confirmedQty: 0 };

  // Oculta el botón de escaneo y el bloque de items (campo de escanear)

  $("#card-receptor-items").hide();
}
function mostrarCancelarColecta(show) {
  if (show) {
    $("#alert-cancelar-colecta").show();
  } else {
    $("#alert-cancelar-colecta").hide();
  }
}
function actualizarColorHeaderCard(tipo) {
  const $card = $("#border-single-card");
  if (!$card.length) return;

  // limpiamos bordes previos
  $card.removeClass("border-success border-danger border-warning border-dark border-primary");

  switch ((tipo || "").toUpperCase()) {
    case "ENTREGA":
      $card.addClass("border-success");
      break;
    case "NO_ENTREGA":
      $card.addClass("border-danger");
      break;
    case "RETIRO":
      $card.addClass("border-warning");
      break;
    case "COLECTA":
      $card.addClass("border-dark");
      break;
    default:
      $card.addClass("border-primary");
  }
}
function actualizarEscaneoPorServicio(tipo) {
  switch ((tipo || "").toUpperCase()) {
    case "COLECTA":
    case "RETIRO":
      $("#btnEscanear").show();
      $("#card-receptor-items").show();
      break;

    case "NO_ENTREGA":
    case "ENTREGA":
    default:
      $("#btnEscanear").hide();
      $("#card-receptor-items").hide();
      break;
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
let forcingLogout = false;

function cerrarSesionForzada(reason) {
  if (forcingLogout) return;
  forcingLogout = true;

  const texto = msgReason(reason);

  // $("#hdr, #navbar, #topnav, #mis_envios, #hdractivas, #card-envio").hide();
  $("#screen-operacion, #screen-totales, #screen-recorrido, #screen-cuenta, #navbar").hide();

  $("#login").show();
  $("body").addClass("login-lock");
  $("body").addClass("loading"); // si querés reaprovechar la clase
  document.body.style.overflow = "hidden";
  Swal.fire({
    icon: "warning",
    title: "Atención",
    text: texto,
  }).finally(() => {
    // después de mostrar, permitimos futuras (por si reintenta)
    forcingLogout = false;
  });
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
  if (!base || esperado <= 0) return { ok: false, msg: "Sin envío seleccionado" };

  // sin duplicados
  const uniq = new Set(seleccion);
  if (uniq.size !== seleccion.length) return { ok: false, msg: "Hay códigos repetidos" };

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
    if (c !== base && c !== `${base}_1`) return { ok: false, msg: "Código no corresponde al envío" };
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
// $("#mi_cuenta").on("click", function () {
//   // Cierro el menú colapsable si está abierto
//   let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
//   if (closeMenu) closeMenu.click();

//   $("#mis_envios").show();
//   $("#hdractivas").hide();

//   $.ajax({
//     data: { MisEnvios: 1 },
//     type: "POST",
//     url: "Proceso/php/funciones_hdr.php", // PHP que devuelve JSON
//     dataType: "json",
//     beforeSend: function () {
//       // $("#info-alert-modal-header").html("Cargando datos...");
//       // $("#info-alert-modal").modal("show");
//     },
//     success: function (jsonData) {
//       if (jsonData.success == 1) {
//         $("#mis_envios_total").html(jsonData.Total);
//         $("#mis_noenvios_total").html(jsonData.Totalno);
//       } else {
//         console.warn("MisEnvios no OK:", jsonData);
//         // Podés mostrar un aviso suave si querés
//         // alert(jsonData.error || "No se pudieron cargar tus envíos.");
//       }
//     },
//     error: function (xhr, status, error) {
//       console.error("Error MisEnvios:", status, error, xhr.responseText);
//       alert("No se pudieron cargar tus envíos. Probá de nuevo.");
//     },
//     complete: function () {
//       // 🔴 SE EJECUTA SIEMPRE, HAYA ÉXITO O ERROR (inclusive parsererror)
//       $("#info-alert-modal").modal("hide");
//     },
//   });
// });

// MI RECORRIDO
// $("#mi_recorrido").click(function () {
//   let closeMenu = document.querySelector('[data-bs-toggle="collapse"]');
//   if (closeMenu) closeMenu.click();

//   $("#mis_envios").hide();
//   $("#hdractivas").show();
// });

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
  let count = $("#prueba :selected").length;

  // ✅ Si es flujo ML, el total real viene de la confirmación
  if (window.colectaML?.isML) {
    const conf = parseInt(window.colectaML.confirmedQty || 0, 10);
    if (conf > 0) count = conf;
  }

  $("#totalt").html(count);
});

$(document).ready(function () {
  if (isAppInstalled()) {
    disableBellIndicator();
  }
  lockBellClickIfInstalled();

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
  })
    .done(function (jsonData) {
      // Si tu backend manda forceLogout
      if (jsonData && jsonData.forceLogout) {
        // ✅ Si es la primera carga o no hay usuario, NO muestres cartel
        if (jsonData.reason === "NO_IDUSUARIO") {
          $("#hdr,#navbar,#topnav").hide();
          $("#login").show();
          $("body").addClass("login-lock");
          return;
        }

        // ✅ Si realmente expiró sesión, ahí sí
        cerrarSesionForzada(jsonData.reason || "SESSION_EXPIRED");
        return;
      }
      // ✅ Hay sesión -> arrancamos
      if (jsonData && jsonData.success == 1) {
        // $("#hdr,#navbar,#topnav").show();
        $("#screen-operacion,#navbar,#topnav").show();
        $("#login").hide();
        $("body").removeClass("login-lock");
        // 🔓 habilitar scroll (mobile fix)
        document.body.classList.remove("loading");
        document.body.style.overflow = "auto";
        document.body.style.overflowY = "auto";
        document.body.style.webkitOverflowScrolling = "touch";
        $("#hdractivas").show();
        $("#mis_envios").hide();
        $("#card-envio").hide();
        $("#hdr-header").html(`H: ${jsonData.NOrden} R: ${jsonData.Recorrido}`);
        if (isAppInstalled()) {
          disableBellIndicator();
        }

        // Si querés usar esos datos del he
        // ader acá también:

        $("#badge-total").html(jsonData.Total);
        $("#badge-sinentregar").html(jsonData.Abiertos);
        $("#badge-entregados").html(jsonData.Cerrados);

        if (window.AppStatus) {
          AppStatus.postStatus({ stage: "session_ok" });
        }
        paneles(null, false); // ✅ recién ahora
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
  paneles(null, false);
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
function renderPanelesSkeleton() {
  return `
    <div class="col-12">
      <div class="card mb-2">
        <div class="card-body">
          <div class="skeleton sk-title" style="width:60%"></div>
          <div class="skeleton sk-line" style="width:85%"></div>
          <div class="skeleton sk-line" style="width:70%"></div>
          <div class="d-flex gap-2 mt-3">
            <div class="skeleton sk-btn" style="width:33%"></div>
            <div class="skeleton sk-btn" style="width:33%"></div>
            <div class="skeleton sk-btn" style="width:33%"></div>
          </div>
        </div>
      </div>
    </div>
  `;
}
// ==================================================
// FUNCION PARA MOSTRAR LOS PANELES
// ==================================================
function paneles(a, refrescarTotales = false) {
  // if (!$("#screen-operacion").is(":visible")) return;
  if (!$("#screen-operacion").hasClass("active")) return;
  let pendientes = refrescarTotales ? 2 : 1;
  function doneRequest() {
    pendientes--;
    if (pendientes <= 0) $("#info-alert-modal").modal("hide");
  }

  const tStart = performance.now();
  console.log("🟦 paneles() start", { search: a, refrescarTotales });

  // $("#hdractivas").html(`<div class="p-3 text-center text-muted">
  //                         <div class="spinner-border" role="status"></div>
  //                         <div class="mt-2">Cargando envíos...</div>
  //                       </div>`);

  // PANELES HTML
  $.ajax({
    data: { Paneles: 1, search: a },
    type: "POST",
    url: "Proceso/php/funciones_hdr.php",
    dataType: "text",

    beforeSend: function () {
      // Mostrar skeleton SOLO si estás en Operación
      if ($("#screen-operacion").is(":visible")) {
        $("#hdractivas")
          .show()
          .html(renderPanelesSkeleton() + renderPanelesSkeleton());
      }
    },

    success: function (responseText) {
      const tResponse = performance.now();
      console.log("🟩 Paneles response received (ms):", (tResponse - tStart).toFixed(0));

      // ✅ Limpio espacios
      const limpio = (responseText || "").trim();

      // ✅ Empty state (y OJO: acá también deberías cerrar loader)
      if (!limpio || limpio === "[]" || limpio === "{}") {
        const tRender0 = performance.now();
        $("#hdractivas").stop(true, true).show().html(responseText);
        $("#hdractivas")
          .html(
            `
            <div class="empty-state text-center p-4">
              <div class="mb-3">
                <i class="mdi mdi-car-wrench mdi-48px text-muted"></i>
              </div>
              <h4 class="text-muted mb-2">Sin envíos por ahora</h4>
              <p class="text-muted">
                Todavía no tenés paquetes para retirar ni entregar.<br>
                Cuando se asignen, van a aparecer automáticamente acá.
              </p>
            </div>
          `,
          )
          .fadeIn();

        console.log("🟧 Paneles render empty (ms):", (performance.now() - tRender0).toFixed(0));
        return;
      }

      // ✅ Render normal
      const tRender1 = performance.now();
      $("#hdractivas").stop(true, true).show().html(responseText);

      console.log("🟧 Paneles render html (ms):", (performance.now() - tRender1).toFixed(0));
      console.log("🟦 Paneles total (ms):", (performance.now() - tStart).toFixed(0));

      console.log("hdractivas exists:", $("#hdractivas").length);
      console.log("hdractivas html len:", ($("#hdractivas").html() || "").length);
      console.log("hdractivas visible:", $("#hdractivas").is(":visible"));
    },

    error: function (xhr) {
      if (tryHandleForceLogout(xhr)) return;
      console.error("Error Paneles:", xhr.status, xhr.responseText || xhr);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "No se pudieron cargar los paneles.",
      });
    },

    complete: function () {
      console.log("✅ Paneles complete total (ms):", (performance.now() - tStart).toFixed(0));
      doneRequest();
    },
  });
}
// function paneles(a, refrescarTotales = false) {
//   let pendientes = refrescarTotales ? 2 : 1;

//   function doneRequest() {
//     pendientes--;
//     if (pendientes <= 0) $("#info-alert-modal").modal("hide");
//   }

//   // PANELES HTML
//   $.ajax({
//     data: { Paneles: 1, search: a },
//     type: "POST",
//     url: "Proceso/php/funciones_hdr.php",
//     dataType: "text",
//     success: function (responseText) {
//       let obj = null;
//       try {
//         obj = JSON.parse(responseText);
//       } catch (e) {}

//       if (obj && obj.forceLogout) {
//         cerrarSesionForzada(obj.reason);
//         return;
//       }
//       // Limpio espacios
//       const limpio = (responseText || "").trim();

//       if (!limpio || limpio === "[]" || limpio === "{}") {
//         // 👉 EMPTY STATE
//         $("#hdractivas")
//           .html(
//             `
//       <div class="empty-state text-center p-4">
//         <div class="mb-3">
//           <i class="mdi mdi-car-wrench mdi-48px text-muted"></i>
//         </div>
//         <h4 class="text-muted mb-2">Sin envíos por ahora</h4>
//         <p class="text-muted">
//           Todavía no tenés paquetes para retirar ni entregar.<br>
//           Cuando se asignen, van a aparecer automáticamente acá.
//         </p>
//       </div>
//     `,
//           )
//           .fadeIn();
//         return;
//       }

//       // $("#hdractivas").html(responseText).fadeIn();
//       $("#hdractivas").stop(true, true).show().html(responseText);
//       console.log("hdractivas exists:", $("#hdractivas").length);
//       console.log(
//         "hdractivas html len:",
//         ($("#hdractivas").html() || "").length,
//       );
//       console.log("hdractivas visible:", $("#hdractivas").is(":visible"));
//     },
//     error: function (xhr) {
//       if (tryHandleForceLogout(xhr)) return;

//       console.error("Error Paneles:", xhr.responseText || xhr);
//       Swal.fire({
//         icon: "error",
//         title: "Error",
//         text: "No se pudieron cargar los paneles.",
//       });
//     },
//     complete: doneRequest,
//   });
// }

// BOTONERA / DROPZONE

$("#boton-entrega-wrong").click(function () {
  document.getElementById("hdractivas").style.display = "block";
  document.getElementById("card-envio").style.display = "none";

  $("#receptor-name").val("");
  $("#receptor-dni").val("");
  $("#receptor-observaciones").val("");

  $(".dz-preview").fadeOut("slow");
  $(".dz-preview:hidden").remove();
  $("#razones").val("");
});

$("#boton-no-entrega-wrong").click(function () {
  document.getElementById("hdractivas").style.display = "block";
  document.getElementById("card-envio").style.display = "none";

  $(".dz-preview").fadeOut("slow");
  $(".dz-preview:hidden").remove();

  // Limpia observaciones
  $("#receptor-observaciones").val("");
  $("#razones").val("");
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
      const dato = jsonData?.data?.[0];
      if (!dato) return;

      // Detecto si era COLECTA
      const esRetiro = parseInt(dato?.Retirado, 10) === 0;
      const idDestino = parseInt(dato?.idClienteDestino, 10) || 0;
      const eraColecta = esRetiro && idDestino === 18587;

      // Seteo tipo + color + UI de escaneo (NO_ENTREGA siempre sin escaneo)
      window.tipoServicioActual = "NO_ENTREGA";
      actualizarColorHeaderCard("NO_ENTREGA");
      actualizarEscaneoPorServicio("NO_ENTREGA"); // ✅ BLINDAJE

      // UI específica: cancelar colecta
      if (eraColecta) {
        resetEscaneoUI(); // ✅ limpia select2 + totalt + flags ML
        mostrarCancelarColecta(true); // ✅ muestra el título / aviso
      } else {
        mostrarCancelarColecta(false);
      }

      // Mostrar pantalla NO ENTREGA
      $("#botones-no-entrega").show();
      $("#botones-entrega").hide();
      $("#botonera").show();
      $("#hdractivas").hide();
      $("#card-envio").show();

      // Datos básicos
      $("#card-receptor-observaciones").show();
      $("#posicioncliente").html(dato.NombreCliente || "");
      $("#direccion").html(dato.Domicilio || "");
      $("#card-receptor-dni").hide();
      $("#card-receptor-name").hide();
      $("#receptor-observaciones").val("");
      $("#razones").val("");
      $("#card-seguimiento").html(dato.CodigoSeguimiento || "");

      // data-expected (no molesta aunque esté oculto)
      $("#btnEscanear").attr("data-expected", (dato.CodigoSeguimiento || "").split("_")[0]);
    },
    error: function (xhr, status, error) {
      console.error("Error BuscoDatos (verwrong):", status, error, xhr.responseText);
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
  $("#razones").val(""); // ✅ NUEVO (motivo no entrega)

  $("#observaciones").html(""); // ✅ NUEVO (card)
  $("#posicioncliente").html(""); // ✅ NUEVO (card)
  $("#direccion").html(""); // ✅ NUEVO (card)
  $("#contacto").html(""); // ✅ NUEVO (card)
  $("#card-seguimiento").html(""); // ✅ NUEVO (card)
  $("#card-receptor-cantidad").html("0"); // ✅ NUEVO

  $("#prueba").val(null).trigger("change"); // si estás usando select2 para colecta
  // select2 (colecta)
  $("#prueba").val(null).trigger("change");
  $("#totalt").html("0");
  window.tipoServicioActual = "";
  actualizarColorHeaderCard(""); // vuelve a border-primary
  mostrarCancelarColecta(false);
  actualizarEscaneoPorServicio(""); // default → oculto
}

function initColectaExpected(colectaId, padreId) {
  return $.ajax({
    url: "Proceso/php/colecta_scan.php",
    type: "POST",
    dataType: "json",
    data: { InitColecta: 1, colectaId, padreId },
  }).done(function (r) {
    window.colectaExpected = r?.expected || null;
    window.colectaExpectedId = r?.colectaId || colectaId;
    window.colectaPadreId = r?.padreId || padreId;
  });
}
function verok(i) {
  limpiarInputsEntrega();

  $.ajax({
    data: { BuscoDatos: 1, id: i },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
    success: function (jsonData) {
      const dato = jsonData?.data?.[0];
      if (!dato) {
        Swal.fire({
          icon: "error",
          title: "Error",
          text: "No se recibió dato del envío.",
        });
        return;
      }

      // ===== UI base =====
      $("#botones-no-entrega").hide();
      $("#botones-entrega").show();
      $("#hdractivas").hide();
      $("#card-envio").show();
      $("#botonera").hide();

      $("#card-receptor-observaciones").show();
      $("#posicioncliente").html(dato.NombreCliente || "");
      $("#direccion").html(dato.Domicilio || "");
      $("#contacto").html(dato.NombreCliente || "");
      $("#observaciones").html(dato.Observaciones || "");
      $("#card-seguimiento").html(dato.CodigoSeguimiento || "");
      $("#card-receptor-cantidad").html(dato.Cantidad || 0);

      $("#btnEscanear").attr("data-expected", (dato.CodigoSeguimiento || "").split("_")[0]);

      // Limpia select2 items del envío anterior
      $("#prueba").val(null).trigger("change");

      // ===== Reset de clases (evita acumulación) =====
      $("#card-servicio").removeClass("text-warning text-success text-dark text-black");
      $("#icon-direccion").removeClass("text-warning text-success text-dark text-black");

      // ojo: icon-servicio tiene clases tipo "mdi mdi-xxx"
      $("#icon-servicio").removeClass("mdi-calendar mdi-arrow-down-bold mdi-arrow-up-bold").addClass("mdi"); // aseguramos base mdi

      // ===== Lógica servicio =====
      const idDestino = parseInt(dato.idClienteDestino, 10) || 0;
      const esRetiro = parseInt(dato.Retirado, 10) === 0;
      const esColecta = esRetiro && idDestino === 18587;
      const tipoServicio = determinarTipoServicio(dato);
      window.tipoServicioActual = tipoServicio;
      actualizarColorHeaderCard(tipoServicio);
      actualizarEscaneoPorServicio(tipoServicio);
      let servicio = "";

      if (esRetiro) {
        servicio = esColecta ? "COLECTA" : "RETIRO";

        // Bootstrap 5: text-dark (si vos tenés text-black custom, cambiá acá)
        const clase = esColecta ? "text-dark" : "text-warning";

        $("#card-servicio").addClass(clase);
        $("#icon-direccion").addClass(clase);
        $("#icon-servicio").addClass("mdi-arrow-down-bold");

        $("#card-receptor-items").show();
        $("#card-receptor-name, #card-receptor-dni").hide();

        // Bloquea hasta validar/confirmar bultos
        setAceptarPickupEnabled(false);

        if (esColecta) {
          const padreId = parseInt(dato.id, 10) || 0; // TransClientes.id (padre)
          const colectaId = parseInt(dato.idColecta, 10) || 0; // Colecta.id (real)

          window.colectaPadreId = padreId;
          window.idColectaActual = colectaId;

          if (colectaId > 0) {
            initColectaExpected(colectaId, padreId);
          } else {
            console.warn("⚠️ El padre no tiene idColecta cargado en TransClientes");
          }
        }
      } else {
        servicio = "ENTREGA";

        $("#card-servicio").addClass("text-success");
        $("#icon-direccion").addClass("text-success");
        $("#icon-servicio").addClass("mdi-arrow-up-bold");

        $("#card-receptor-items").hide();
        $("#card-receptor-name, #card-receptor-dni").show();
      }

      $("#card-servicio").text(servicio);

      // ✅ Se llama una sola vez, al final, con el servicio ya seteado
      onCargarNuevoEnvioEnCard();
    },
    error: function (xhr, status, error) {
      console.error("Error BuscoDatos (verok):", status, error, xhr.responseText);
      Swal.fire({
        icon: "error",
        title: "No se pudo cargar el envío",
        text: "Revisá consola / backend (funciones.php).",
      });
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
  // ✅ COLECTA: validación por expected.paquetes_total
  if (esModoColecta()) {
    const exp = window.colectaExpected;
    const esperado = parseInt(exp?.paquetes_total || 0, 10);

    const cargado = getCantidadCargada();

    // si no tengo expected todavía, bloqueo
    if (!esperado) {
      setAceptarPickupEnabled(false);
      return;
    }

    // habilita SOLO cuando coincide exacto
    setAceptarPickupEnabled(cargado === esperado);
    return;
  }

  const esperado = getCantidadEsperada();
  // ✅ NUEVO: si es flujo ML y ya confirmó cantidad, habilitar por confirmación
  if (window.colectaML?.isML) {
    const conf = parseInt(window.colectaML.confirmedQty || 0, 10);

    if (conf >= esperado) {
      setAceptarPickupEnabled(true);
      return;
    } else {
      setAceptarPickupEnabled(false);

      // opcional: avisito si querés
      // Swal.fire({ icon:"warning", title:"Faltan bultos", text:`Confirmaste ${conf}/${esperado}` });

      return;
    }
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
  window.colectaML = { isML: false, confirmedQty: 0 };
  // bloquea por defecto y recalcula
  setAceptarPickupEnabled(false);
  actualizarEstadoCantidadPickup();
}

$(document).on("click", "#boton-entrega-success, .guardarProducto", function (e) {
  if (!esRetiro()) return;

  // ✅ NUEVO: bypass validación clásica si es flujo ML
  if (window.colectaML?.isML) {
    const esperado = getCantidadEsperada();
    const conf = parseInt(window.colectaML.confirmedQty || 0, 10);

    if (conf >= esperado) {
      return; // 👉 permitimos confirmar
    }

    e.preventDefault();
    Swal.fire({
      icon: "error",
      title: "Cantidad incorrecta",
      text: `Confirmaste ${conf}/${esperado}`,
    });
    return false;
  }

  // 🔽 flujo tradicional (QR con _1 _2 _3)
  const v = validarCodigosPickup();
  if (!v.ok) {
    e.preventDefault();
    Swal.fire({
      icon: "error",
      title: "No se puede confirmar",
      text: v.msg || "Códigos inválidos",
    });
    return false;
  }
});
$(document).on("submit", "#loginForm", function (e) {
  e.preventDefault();
  e.stopPropagation();
  return false;
});
function cargarHeader() {
  return $.ajax({
    data: { Datos: 1 },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
  }).done(function (jsonData) {
    if (jsonData && jsonData.success == 1) {
      $("#hdr-header").html(`H: ${jsonData.NOrden} R: ${jsonData.Recorrido}`);
      $("#badge-total").html(jsonData.Total);
      $("#badge-sinentregar").html(jsonData.Abiertos);
      $("#badge-entregados").html(jsonData.Cerrados);
    }
  });
}
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
        // $("#hdr,#navbar,#topnav").show();
        $("#screen-operacion,#navbar,#topnav").show();
        $("body").removeClass("login-lock");
        $("#hdractivas").show();
        $("#mis_envios").hide();
        $("#card-envio").hide();
        // 🔓 habilitar scroll (mobile fix)
        document.body.classList.remove("loading");
        document.body.style.overflowY = "auto";
        document.body.style.webkitOverflowScrolling = "touch";

        cargarHeader().done(() => {
          paneles(null, false);
          asegurarMenuWarehouse();
        });
      } else {
        Swal.fire({
          icon: "error",
          title: "Login inválido",
          text: (jsonData && (jsonData.msg || jsonData.error)) || "Usuario o contraseña incorrectos.",
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
        text: (obj && (obj.error || obj.msg)) || "El servidor devolvió HTML/Warning y no JSON.",
        customClass: {
          container: "caddy-login-swal",
        },
      });

      console.error(xhr.responseText);
    },
  });

  return false;
});
