console.log("Version 1.16 - 2024-06-18");
$(".botonera-icon").on("click", function () {
  // sacamos activo de todos
  $(".botonera-icon").removeClass("active");

  // activamos el clickeado
  $(this).addClass("active");
});

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
      hideBottomnav();
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

// ============================================================
// MI CUENTA — resumen de rendición del repartidor.
// Consume Proceso/php/funciones_hdr.php?CuentaResumen=1 (JSON) y
// renderiza la pantalla #screen-cuenta. Los montos, el % de
// desempeño y los estados salen de los mismos datos que ve el
// operador en sistema.caddy.com.ar (Externos > Envios).
// ============================================================
const MC_META_DESEMPENO = 95;
const MC_MESES = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
const MC_TIPO = {
  ENTREGA: { cls: "ok", txt: "Entrega" },
  ENTREGA_CON_COBRANZA: { cls: "ok", txt: "Entrega" },
  NO_ENTREGA: { cls: "no", txt: "No entrega" },
  RETIRO: { cls: "", txt: "Retiro" },
  COLECTA: { cls: "col", txt: "Colecta" },
};
const MC_ESTADO = {
  revision: { cls: "rev", txt: "En revisión" },
  controlada: { cls: "ctl", txt: "Controlada" },
  facturada: { cls: "fac", txt: "Facturada" },
};

function mcMoney(n) {
  const v = Math.round(Number(n) || 0);
  return "$" + v.toLocaleString("es-AR");
}
function mcFechaCorta(iso) {
  if (!iso) return "";
  const p = String(iso).slice(0, 10).split("-");
  if (p.length !== 3) return iso;
  return parseInt(p[2], 10) + "." + (MC_MESES[parseInt(p[1], 10) - 1] || p[1]);
}
function mcEsc(s) {
  return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
    return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
  });
}

function cargarCuentaHTML(mes) {
  const $dst = $("#mis_envios_cuenta");
  $dst.html('<div class="mc"><div class="mc-skel"></div></div>');

  return $.ajax({
    url: "Proceso/php/funciones_hdr.php",
    type: "POST",
    dataType: "json",
    data: mes ? { CuentaResumen: 1, mes: mes } : { CuentaResumen: 1 },
  })
    .done(function (data) {
      if (!data || data.success !== 1) {
        $dst.html('<div class="mc"><div class="mc-alert">' + mcEsc((data && data.error) || "No se pudo cargar tu cuenta.") + "</div></div>");
        return;
      }
      mcRender($dst, data);
    })
    .fail(function (xhr) {
      if (xhr.status === 401) {
        cerrarSesionForzada("SESSION_EXPIRED");
        return;
      }
      $dst.html('<div class="mc"><div class="mc-alert">No se pudo cargar tu cuenta. Reintentá en un rato.</div></div>');
      console.error("CuentaResumen error:", xhr.status, xhr.responseText);
    });
}

function mcRender($dst, data) {
  const r = data.resumen || {};
  // Empleado de planta de Caddy: cobra sueldo, no por paquete. Mi Cuenta le
  // muestra solo cantidades (entregas, km, desempeño) — nada de importes ni
  // estados de facturación. El flag viene del backend (sesión).
  const emp = !!data.es_empleado;
  const desemp = r.desempeno;
  const ringCls = desemp == null ? "" : desemp >= MC_META_DESEMPENO ? "" : desemp >= 75 ? "warn" : "crit";
  const dash = desemp == null ? 0 : Math.max(0, Math.min(100, desemp));
  const delta = Number(r.delta_vs_prev) || 0;

  const mesTxt = (function () {
    const p = String(data.mes || "").split("-");
    if (p.length !== 2) return "";
    return (MC_MESES[parseInt(p[1], 10) - 1] || p[1]) + " " + p[0];
  })();

  let deltaHtml = "";
  if (delta !== 0) {
    deltaHtml =
      '<span class="mc-delta ' +
      (delta < 0 ? "neg" : "") +
      '">' +
      (delta < 0 ? "▼ " : "▲ ") +
      mcMoney(Math.abs(delta)) +
      " vs mes pasado</span>";
  }

  let html = '<div class="mc">';
  html +=
    '<div class="mc-head"><h2>Mi cuenta</h2><span class="mc-mes">' + mcEsc(mesTxt) + "</span></div>";

  // hero
  html += '<div class="mc-hero"><div class="mc-hero-row">';
  if (emp) {
    html +=
      '<div class="mc-earn"><div class="mc-label">Entregas este mes</div>' +
      '<div class="mc-amount mc-num">' + (r.entregas || 0) + "</div></div>";
  } else {
    html +=
      '<div class="mc-earn"><div class="mc-label">A cobrar este mes</div>' +
      '<div class="mc-amount mc-num">' + mcMoney(r.a_cobrar) + "</div>" + deltaHtml + "</div>";
  }
  html +=
    '<div class="mc-ring-wrap"><svg class="mc-ring ' + ringCls + '" viewBox="0 0 96 96" role="img" aria-label="Desempeño ' +
    (desemp == null ? "sin datos" : desemp + " por ciento") +
    '"><circle class="bg" cx="48" cy="48" r="40"></circle>' +
    '<circle class="fg" cx="48" cy="48" r="40" pathLength="100" stroke-dasharray="0 100"></circle>' +
    '<text x="48" y="49" text-anchor="middle" dominant-baseline="middle" font-size="21">' +
    (desemp == null ? "—" : desemp + "%") +
    "</text></svg>" +
    '<div class="mc-ring-cap">Desempeño · meta ' + MC_META_DESEMPENO + "%</div></div>";
  html += "</div>"; // hero-row

  // Facturado / Controlado / En revisión son conceptos de cobro: no van para
  // empleados de planta.
  if (!emp) {
    html += '<div class="mc-split">';
    html +=
      '<div class="fac"><span class="k">Facturado</span><span class="v mc-num">' + mcMoney(r.facturado) +
      '</span><span class="sub">' + (r.n_facturado || 0) + " órden" + ((r.n_facturado || 0) === 1 ? "" : "es") + "</span></div>";
    html +=
      '<div class="ctl"><span class="k">Controlado</span><span class="v mc-num">' + mcMoney(r.controlado) +
      '</span><span class="sub">' + (r.n_controlado || 0) + " órden" + ((r.n_controlado || 0) === 1 ? "" : "es") + "</span></div>";
    html +=
      '<div class="rev"><span class="k">En revisión</span><span class="v mc-num">' + (r.n_revision || 0) +
      '</span><span class="sub">órden' + ((r.n_revision || 0) === 1 ? "" : "es") + "</span></div>";
    html += "</div>";
  }
  html += "</div>"; // hero

  // chips
  html += '<div class="mc-chips">';
  html += '<div class="mc-chip good"><div class="cv mc-num">' + (r.entregas || 0) + '</div><div class="ck">Entregas</div></div>';
  html += '<div class="mc-chip bad"><div class="cv mc-num">' + (r.no_entregas || 0) + '</div><div class="ck">No entreg.</div></div>';
  html += '<div class="mc-chip"><div class="cv mc-num">' + (r.km || 0).toLocaleString("es-AR") + '</div><div class="ck">Km</div></div>';
  html += "</div>";

  // orders
  const ordenes = data.ordenes || [];
  html += '<div class="mc-sec"><h3>Tus órdenes</h3><span class="upd">' + ordenes.length + " este mes</span></div>";

  if (!ordenes.length) {
    html += '<div class="mc-empty">Todavía no tenés órdenes este mes.</div>';
  } else {
    // Abrimos por default la primera orden con detalle de envíos (controlada
    // o facturada); si no hay ninguna, la primera de la lista.
    var idxAbrir = ordenes.findIndex(function (o) {
      return o.envios && o.envios.length;
    });
    if (idxAbrir < 0) idxAbrir = 0;
    html += '<div class="mc-orders">';
    ordenes.forEach(function (o, i) {
      html += mcRenderOrden(o, i === idxAbrir, emp);
    });
    html += "</div>";
  }

  html +=
    '<p class="mc-foot">' +
    (emp
      ? "Cantidades tomadas de lo que registró el operador. "
      : "Los montos se confirman cuando el operador controla y factura cada orden. ") +
    "Deslizá para actualizar.</p>";
  html += "</div>";

  $dst.html(html);

  // animar el anillo
  const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const $fg = $dst.find(".mc-ring .fg");
  if ($fg.length) {
    if (reduce) {
      $fg.attr("stroke-dasharray", dash + " 100");
    } else {
      setTimeout(function () {
        $fg.attr("stroke-dasharray", dash + " 100");
      }, 120);
    }
  }
}

function mcRenderOrden(o, abrir, emp) {
  const est = MC_ESTADO[o.estado] || MC_ESTADO.revision;
  const ruta = o.recorrido_nombre
    ? "R." + mcEsc(o.recorrido) + " " + mcEsc(o.recorrido_nombre)
    : "R." + mcEsc(o.recorrido);
  const pct = o.desempeno == null ? "—" : o.desempeno + "%";

  let head = '<button class="mc-oh" aria-expanded="' + (abrir ? "true" : "false") + '">';
  head += '<div class="mc-oh-top">';
  head += '<span class="mc-oh-order">#' + mcEsc(o.norden) + "</span>";
  head += '<span class="mc-oh-date">' + mcEsc(mcFechaCorta(o.fecha)) + "</span>";
  head += '<span class="mc-oh-route">' + ruta + "</span>";
  head +=
    '<span class="mc-chev" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"></path></svg></span>';
  head += "</div>";

  // Badge de estado (En revisión / Controlada / Facturada) y comprobante son
  // de facturación: no van para empleados de planta.
  if (!emp) {
    head += '<span class="mc-badge ' + est.cls + '">' + est.txt + "</span>";
    if (o.comprobante && o.comprobante.numero) {
      head +=
        '<div class="mc-comp">Comprobante ' +
        mcEsc(o.comprobante.numero) +
        (o.comprobante.fecha ? " · " + mcEsc(mcFechaCorta(o.comprobante.fecha)) : "") +
        "</div>";
    }
  }

  head += '<div class="mc-oh-bot"><div class="mc-oh-stats">';
  head += '<span class="e">' + (o.entregados || 0) + " ✓</span>";
  head += '<span class="n">' + (o.no_entregados || 0) + " ✕</span>";
  head += '<span class="p">' + pct + "</span></div>";
  if (!emp) {
    if (o.total_confirmado) {
      head += '<div class="mc-oh-total mc-num">' + mcMoney(o.total) + "</div>";
    } else {
      head += '<div class="mc-oh-total pend">Monto a confirmar</div>';
    }
  }
  head += "</div>";

  if (!emp && o.ajustados > 0) {
    head +=
      '<span class="mc-flag"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"></path><path d="M12 17h.01"></path><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path></svg>' +
      o.ajustados +
      " envío" +
      (o.ajustados === 1 ? "" : "s") +
      " ajustado" +
      (o.ajustados === 1 ? "" : "s") +
      " por el operador</span>";
  }
  head += "</button>";

  // body
  let body = '<div class="mc-body"><div class="mc-body-inner">';
  const hayEnvios = o.envios && o.envios.length;
  if (emp) {
    if (hayEnvios) {
      o.envios.forEach(function (e) {
        body += mcRenderEnvio(e, emp);
      });
    } else {
      body +=
        '<div class="mc-rev-note">Todavía no hay detalle envío por envío de esta orden.</div>';
    }
  } else if (o.estado === "revision" || !hayEnvios) {
    body +=
      '<div class="mc-rev-note">El operador todavía no controló esta orden. Cuando revise las tarifas ' +
      "vas a ver acá el detalle envío por envío y el total a cobrar.</div>";
  } else {
    o.envios.forEach(function (e) {
      body += mcRenderEnvio(e, emp);
    });
  }

  const nEnvios = hayEnvios ? o.envios.length : o.entregados + o.no_entregados;
  const footTxt = emp
    ? nEnvios + " envío" + (nEnvios === 1 ? "" : "s")
    : o.estado === "facturada"
      ? "Facturada · " + nEnvios + " envíos"
      : o.estado === "controlada"
        ? "Controlada · pendiente de facturar"
        : "Entregada · " + (o.entregados + o.no_entregados) + " envíos";
  body += '<div class="mc-order-foot"><span>' + mcEsc(footTxt) + "</span>";
  if (!emp) {
    body += "<b>" + (o.total_confirmado ? mcMoney(o.total) : "—") + "</b>";
  }
  body += "</div>";
  body += "</div></div>";

  return '<div class="mc-order' + (abrir ? " open" : "") + '">' + head + body + "</div>";
}

function mcRenderEnvio(e, emp) {
  const t = MC_TIPO[e.tipo] || MC_TIPO.ENTREGA;
  let h = '<div class="mc-env">';
  h += '<div class="mc-env-top"><span class="mc-env-code">' + mcEsc(e.codigo) + "</span>";
  if (!emp) {
    h += '<span class="mc-env-amt mc-num">' + mcMoney(e.total) + "</span>";
  }
  h += "</div>";
  if (e.destino || e.domicilio) {
    h +=
      '<div class="mc-env-dest">' +
      mcEsc([e.destino, e.domicilio].filter(Boolean).join(" · ")) +
      "</div>";
  }
  h += '<div class="mc-env-tags"><span class="mc-tag ' + t.cls + '">' + t.txt + "</span>";
  if (e.tipo === "ENTREGA_CON_COBRANZA") h += '<span class="mc-tag cob">+ cobranza</span>';
  // La tarifa es una categoría de cobro: no va para empleados de planta.
  if (!emp && e.tarifa) h += '<span class="mc-tag">' + mcEsc(e.tarifa) + "</span>";
  if (e.km > 0) h += '<span class="mc-tag">' + e.km + " km</span>";
  h += "</div>";
  if (!emp && e.cobranza > 0) {
    h += '<div class="mc-env-break">' + mcMoney(e.precio) + " tarifa + " + mcMoney(e.cobranza) + " cobranza</div>";
  }
  if (!emp && e.ajustado) {
    h +=
      '<div class="mc-env-adj">Ajustado: <s>' +
      mcMoney(e.precio_anterior) +
      "</s> → " +
      mcMoney(e.precio) +
      (e.obs ? ' · «' + mcEsc(e.obs) + "»" : "") +
      "</div>";
  }
  h += "</div>";
  return h;
}

// Acordeón de órdenes (delegado)
$(document).on("click", ".mc-order .mc-oh", function () {
  const $o = $(this).closest(".mc-order");
  const open = $o.toggleClass("open").hasClass("open");
  $(this).attr("aria-expanded", open ? "true" : "false");
});
(function () {
  const screenMap = {
    operacion: "#screen-operacion",
    totales: "#screen-totales",
    cuenta: "#screen-cuenta",
  };

  function showScreen(key) {
    // 1) Apago TODAS las screens
    $(".app-screen").removeClass("active").hide();
    $(".botonera-icon").removeClass("active");
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
    // El FAB del mapa es exclusivo de Recorrido.
    $("#fab-mapa-recorrido").toggle(realKey === "operacion");

    if (realKey === "operacion") {
      $("#hdractivas").show(); // ✅ solo acá se ven paneles

      $("#app-footer").removeClass("d-none");
    }

    if (realKey === "totales") {
      const $tpl = $("#mis_envios");
      const $dst = $("#mis_envios_clone");
      if ($tpl.length && $dst.length) $dst.empty().append($tpl.children().clone(true, true));
      // cargarMisEnvios();
    }

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

  // Pantalla inicial: ?go=<screen> (navegación explícita desde warehouse.html)
  // tiene prioridad sobre el #hash (que puede haber quedado viejo en la
  // pestaña). Si no hay ninguno válido -> operacion.
  window.rpStartScreen = function () {
    const go = (new URLSearchParams(location.search).get("go") || "").toLowerCase();
    if (screenMap[go]) return go;
    const h = (location.hash || "").replace("#", "");
    return screenMap[h] ? h : "operacion";
  };

  // init
  $(function () {
    window.showScreen = showScreen;
    showScreen(window.rpStartScreen());
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
    case "RECORRIDO_CAMBIO":
      return "Te cambiaron el recorrido. Volvé a ingresar para cargar el nuevo.";
    case "OTHER_DEVICE":
      return "Se inició sesión con tu usuario en otro teléfono. Este quedó desconectado.";
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
  hideBottomnav();
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

  // Override de escaneo prendido para el recorrido + nada escaneado: se acepta
  // el retiro/colecta sin escanear (decisión del operador desde Órdenes de
  // Salida). Si escaneó algo, igual se valida para no aceptar códigos que no
  // correspondan.
  if (window.omitirEscaneo && seleccion.length === 0) {
    return { ok: true, omitido: true };
  }

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
  window.location.href = "warehouse.html?b=20260906c";
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
  $("#app-footer").addClass("d-none");
  if (isAppInstalled()) {
    disableBellIndicator();
  }
  lockBellClickIfInstalled();

  $("#prueba").select2({
    placeholder: "Seleccione ...",
    tags: false,
    closeOnSelect: false,
    width: "100%",
  });

  Dropzone.autoDiscover = false;

  // ✅ Chequeo sesión real
  initApp();

  // Al volver a la app (cambiar de pestaña, desbloquear el teléfono) refrescamos
  // el header. Así el toggle de "omitir escaneo" que prende la oficina desde
  // Órdenes de Salida se aplica sin tener que recargar toda la app.
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible" && typeof cargarHeader === "function") {
      cargarHeader();
    }
  });
});
function showBottomnav() {
  $("body").addClass("app-ready").removeClass("login-lock");
}
function hideBottomnav() {
  $("body").removeClass("app-ready").addClass("login-lock");
}

function initApp() {
  $.ajax({
    data: { Datos: 1 },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
  })
    .done(function (jsonData) {
      // Le cambiaron el recorrido al chofer (RecorridoAsignado viejo en sesión).
      if (jsonData && jsonData.RecorridoCambio) {
        cerrarSesionForzada("RECORRIDO_CAMBIO");
        return;
      }
      // Si tu backend manda forceLogout
      if (jsonData && jsonData.forceLogout) {
        // ✅ Si es la primera carga o no hay usuario, NO muestres cartel
        if (jsonData.reason === "NO_IDUSUARIO") {
          hideBottomnav();
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
        showBottomnav();
        // OJO: no forzar #screen-operacion acá. initApp() resuelve async y si
        // el usuario cargó/recargó la app en #cuenta o #totales, mostrar
        // Operación además de esa screen deja "todo visible a la vez".
        // La visibilidad de las .app-screen la maneja showScreen() (abajo).
        $("#navbar,#topnav").show();
        $("#login").hide();

        $("#app-footer").removeClass("d-none");
        $("body").removeClass("login-lock");
        // 🔓 habilitar scroll (mobile fix)
        document.body.classList.remove("loading");
        document.body.style.overflow = "auto";
        document.body.style.overflowY = "auto";
        document.body.style.webkitOverflowScrolling = "touch";
        $("#hdractivas").show();
        $("#mis_envios").hide();
        $("#card-envio").hide();
        $("#hdr-header").html(`Rec. ${jsonData.Recorrido} · H${jsonData.NOrden}`);
        if (isAppInstalled()) {
          disableBellIndicator();
        }

        // Si querés usar esos datos del he
        // ader acá también:

        $("#badge-total").html(jsonData.Total);
        $("#badge-sinentregar").html(jsonData.Abiertos);
        $("#badge-entregados").html(jsonData.Cerrados);
        pintarEstadoRecorrido(jsonData);

        if (window.AppStatus) {
          AppStatus.postStatus({ stage: "session_ok" });
        }

        // Pantalla inicial: normalmente Recorrido, salvo que se haya pedido
        // otra explícitamente con ?go= (p.ej. "Cuenta" desde warehouse.html).
        // showScreen() deja UNA sola .app-screen visible y con .active
        // (evita el "se ve todo junto" cuando initApp resuelve async).
        const startKey =
          typeof window.rpStartScreen === "function" ? window.rpStartScreen() : "operacion";

        // Dejo el hash acorde y saco el ?go= de la URL (para que un reload
        // posterior no repita la navegación). El ?b= (build, anti-cache del
        // proxy) SE MANTIENE, así un reload sigue pegándole a la URL nueva.
        try {
          const sp = new URLSearchParams(location.search);
          sp.delete("go");
          const qs = sp.toString();
          history.replaceState(null, "", location.pathname + (qs ? "?" + qs : "") + "#" + startKey);
        } catch (e) {
          location.hash = startKey;
        }

        if (typeof window.showScreen === "function") {
          window.showScreen(startKey);
        } else {
          $(".app-screen").removeClass("active").hide();
          $("#screen-" + startKey).addClass("active").show();
          if (startKey === "operacion") $("#hdractivas").show();
        }

        paneles(null, false); // ✅ recién ahora (paneles() ya chequea que operacion este .active)
        asegurarMenuWarehouse(); // ✅ recién ahora
      } else {
        hideBottomnav();
        // ❌ No hay sesión -> login
        $("#hdr,#navbar,#topnav").hide();
        $("#login").show();
        $("body").addClass("login-lock");
      }
    })
    .fail(function (xhr) {
      hideBottomnav();
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
    <div class="col-12 rp">
      <div class="rp-skel" style="margin-bottom:12px"></div>
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

      // Encabezado "Próximas paradas": visible sólo si hay tarjetas de
      // parada de verdad (no en empty state ni con el cartel del gate).
      const hayParadas = /rp-stop-actions|class="col-xl-7 rp"/.test(limpio);
      const nPend = parseInt($("#badge-sinentregar").text(), 10) || 0;
      $("#rp-paradas-head").toggle(hayParadas);
      $("#rp-paradas-count").text(
        hayParadas ? nPend + (nPend === 1 ? " pendiente" : " pendientes") : "",
      );

      // ✅ Empty state (y OJO: acá también deberías cerrar loader)
      if (!limpio || limpio === "[]" || limpio === "{}") {
        const tRender0 = performance.now();
        $("#hdractivas").stop(true, true).show().html(responseText);
        $("#hdractivas")
          .html(
            `
            <div class="col-12 rp">
              <div class="rp-empty">
                <span class="rp-empty-t">Sin envíos por ahora</span>
                Todavía no tenés paquetes para retirar ni entregar.<br>
                Cuando se asignen, aparecen automáticamente acá.
              </div>
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
// Guarda contra respuestas fuera de orden: si el repartidor navega a otro
// código antes de que responda un BuscoDatos anterior, esa respuesta tardía
// no debe pintar datos viejos sobre el código nuevo (ver verok/verwrong).
let idSolicitudActual = 0;

function verwrong(i) {
  limpiarInputsEntrega();

  const miSolicitud = ++idSolicitudActual;

  $.ajax({
    data: { BuscoDatos: 1, id: i },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
    success: function (jsonData) {
      if (miSolicitud !== idSolicitudActual) return; // respuesta vieja, descartar

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
  if (window.pararPollML) window.pararPollML();
  return $.ajax({
    url: "Proceso/php/colecta_scan.php",
    type: "POST",
    dataType: "json",
    data: { InitColecta: 1, colectaId, padreId },
  }).done(function (r) {
    window.colectaExpected = r?.expected || null;
    window.colectaExpectedId = r?.colectaId || colectaId;
    window.colectaPadreId = r?.padreId || padreId;

    const expected = r?.expected || {};
    const resume = r?.resume || {};

    const servicios = parseInt(expected.servicios || 0, 10) || 0;
    const flex = parseInt(expected.servicios_flex || 0, 10) || 0;
    const bultos = parseInt(expected.paquetes_total || 0, 10) || 0;
    const escaneados = parseInt(resume.paquetes_ok || 0, 10) || 0;
    const faltan = Math.max(bultos - escaneados, 0);

    $("#card-receptor-cantidad").html(bultos);
    $("#totalServicios").html(servicios);
    $("#totalServiciosMeli").text(flex > 0 ? flex + " MELI" : "").prop("hidden", flex <= 0);
    $("#totalBultos").html(bultos);
    $("#totalt").html(escaneados);
    $("#totalFaltan").html(faltan);

    // Muestra los servicios que MercadoLibre ya confirmó (badge amarillo) y
    // arranca el poll para levantar los que confirme mientras retira.
    if (window.aplicarMlEnUI) window.aplicarMlEnUI(expected, resume, []);
    if (window.arrancarPollML) window.arrancarPollML(colectaId);
    if (bultos > 0 && escaneados >= bultos && typeof setAceptarPickupEnabled === "function") {
      setAceptarPickupEnabled(true);
    }

    // opcional: auditoría visual si hay inconsistencia
    if (parseInt(expected.inconsistencia_cantidad || 0, 10) === 1) {
      $("#colecta-cantidad-msg").html(`
        <div class="alert alert-warning py-1 px-2 mt-2 mb-0">
          Declarado por operador: <strong>${expected.paquetes_operador || 0}</strong> |
          Escaneable: <strong>${expected.paquetes_sistema || bultos}</strong>
        </div>
      `);
    } else {
      $("#colecta-cantidad-msg").html("");
    }
  });
}
function verok(i) {
  if (window.pararPollML) window.pararPollML();
  window.mlCodes && window.mlCodes.clear && window.mlCodes.clear();
  limpiarInputsEntrega();

  const miSolicitud = ++idSolicitudActual;

  $.ajax({
    data: { BuscoDatos: 1, id: i },
    type: "POST",
    url: "Proceso/php/funciones.php",
    dataType: "json",
    success: function (jsonData) {
      if (miSolicitud !== idSolicitudActual) return; // respuesta vieja, descartar

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
      // $("#card-receptor-cantidad").html(dato.Cantidad || 0);
      $("#card-receptor-cantidad").html(0);
      $("#totalServicios").html(0);
      $("#totalBultos").html(0);
      $("#totalt").html(0);
      $("#totalFaltan").html(0);

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
      // Padre de colecta ya retirado: se entrega en el depósito con un tap,
      // sin receptor ni foto.
      const esColectaEntregaDeposito = !esRetiro && idDestino === 18587;
      const tipoServicio = determinarTipoServicio(dato);
      window.tipoServicioActual = tipoServicio;
      actualizarColorHeaderCard(tipoServicio);
      actualizarEscaneoPorServicio(tipoServicio);
      let servicio = "";

      if (esRetiro) {
        servicio = esColecta ? "COLECTA" : "RETIRO";
        if (!esColecta) {
          const cant = parseInt(dato.Cantidad || 0, 10) || 0;
          $("#card-receptor-cantidad").html(cant);
          $("#totalServicios").html(cant > 0 ? 1 : 0);
          $("#totalBultos").html(cant);
          $("#totalt").html(0);
          $("#totalFaltan").html(cant);
        }
        // Bootstrap 5: text-dark (si vos tenés text-black custom, cambiá acá)
        const clase = esColecta ? "text-dark" : "text-warning";

        $("#card-servicio").addClass(clase);
        $("#icon-direccion").addClass(clase);
        $("#icon-servicio").addClass("mdi-arrow-down-bold");

        $("#card-receptor-items").show();
        $("#card-receptor-name, #card-receptor-dni").hide();
        // En colecta no se usa la carga de fotos -> se oculta para no confundir.
        $("#zona-multimedia").toggle(!esColecta);

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
        // Colecta contra depósito: sin receptor / DNI / foto, es un tap.
        const mostrarReceptor = !esColectaEntregaDeposito;
        $("#card-receptor-name, #card-receptor-dni").toggle(mostrarReceptor);
        $("#zona-multimedia").toggle(mostrarReceptor);
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
  // COLECTA: Aceptar SIEMPRE habilitado. Si faltan bultos, el modal de
  // confirmación (en el click) deja avanzar igual y marca los faltantes para
  // que la oficina los confirme (avanzar, no frenar).
  if (esModoColecta()) {
    setAceptarPickupEnabled(true);
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
  // Si todavía no cargó nada, bloqueá sin cartel - salvo que el recorrido tenga
  // el escaneo desactivado (Logistica.OmitirControlEscaneo): ahí se puede
  // aceptar el retiro sin escanear (decisión del operador desde Órdenes).
  const cargado = getCantidadCargada();
  if (cargado === 0) {
    setAceptarPickupEnabled(!!window.omitirEscaneo);
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
  // El botón positivo dice "Aceptar" (dropzone.js lo deja en "Guardar producto"
  // después de una entrega).
  $("#boton-entrega-success, .guardarProducto").text("Aceptar");
  // bloquea por defecto y recalcula
  setAceptarPickupEnabled(false);
  actualizarEstadoCantidadPickup();
}

// Servicios de la colecta que NO están cubiertos (ni escaneados a mano ni ML).
function getColectaFaltantes() {
  const exp = window.colectaExpected;
  if (!exp || !Array.isArray(exp.servicios_detalle)) return [];
  const manoByBase = {};
  ($("#prueba").val() || []).forEach((c) => {
    if (window.mlCodes && window.mlCodes.has(String(c))) return; // ML no cuenta como "mano"
    const b = String(c).split("_")[0].trim().toUpperCase();
    manoByBase[b] = (manoByBase[b] || 0) + 1;
  });
  const out = [];
  exp.servicios_detalle.forEach((sd) => {
    if (sd.ml_confirmado) return;
    const base = String(sd.cs_base || "").trim().toUpperCase();
    const need = parseInt(sd.paquetes || 1, 10) || 1;
    const mano = manoByBase[base] || 0;
    if (mano >= need) return;
    out.push({ base: base, need: need, mano: mano, cliente: sd.cliente || "" });
  });
  return out;
}

// Cierra la colecta (backend: colecta_scan.php ColectaCerrar) y vuelve al listado.
function cerrarColectaAhora(obs) {
  if (window._cerrandoColecta) return;
  window._cerrandoColecta = true;
  $("#boton-entrega-success, .guardarProducto").prop("disabled", true);

  $.ajax({
    url: "Proceso/php/colecta_scan.php",
    type: "POST",
    dataType: "json",
    data: {
      ColectaCerrar: 1,
      colectaId: window.idColectaActual || window.colectaExpectedId || 0,
      padreId: window.colectaPadreId || 0,
      obs: obs || "",
    },
  })
    .done(function (r) {
      if (!r || r.success != 1) {
        Swal.fire({
          icon: "error",
          title: "No se pudo cerrar la colecta",
          text: (r && (r.detail || r.error)) || "",
        });
        return;
      }
      if (window.pararPollML) window.pararPollML();
      const nF = (r.faltantes || []).length;
      if (window.Swal) {
        Swal.fire({
          toast: true,
          position: "top",
          timer: 2800,
          showConfirmButton: false,
          icon: nF ? "warning" : "success",
          title: nF
            ? `Colecta cerrada · ${nF} paquete(s) para confirmar en oficina`
            : "Colecta cerrada",
        });
      }
      if (typeof limpiarInputsEntrega === "function") limpiarInputsEntrega();
      $("#card-envio").hide();
      $("#hdractivas").show();
      if (typeof cargarHeader === "function") cargarHeader();
      if (typeof paneles === "function") paneles();
    })
    .fail(function () {
      Swal.fire({ icon: "error", title: "Error de red", text: "No se pudo cerrar la colecta." });
    })
    .always(function () {
      window._cerrandoColecta = false;
      $("#boton-entrega-success, .guardarProducto").prop("disabled", false);
    });
}

$(document).on("click", "#boton-entrega-success, .guardarProducto", function (e) {
  if (!esRetiro()) return;

  // ===== COLECTA: cierre propio, no frena aunque falten bultos =====
  if (esModoColecta()) {
    e.preventDefault();
    e.stopImmediatePropagation();
    if (window._cerrandoColecta) return;

    const faltan = getColectaFaltantes();
    if (!faltan.length) {
      cerrarColectaAhora("");
      return;
    }
    const totalFaltan = faltan.reduce((a, f) => a + (f.need - f.mano), 0);
    const lista = faltan
      .map(
        (f) =>
          `<li><b>${f.cliente || f.base}</b> — faltan ${f.need - f.mano} de ${f.need}</li>`,
      )
      .join("");
    Swal.fire({
      icon: "warning",
      title: `Faltan ${totalFaltan} bulto(s)`,
      html:
        `<ul style="text-align:left;margin:0 0 10px;padding-left:18px">${lista}</ul>` +
        `<textarea id="colecta-cierre-obs" class="form-control" rows="2" placeholder="Observación (opcional)"></textarea>` +
        `<div class="text-muted mt-1" style="font-size:12px">Vas a tener que avisar en la oficina que los levantaste.</div>`,
      showCancelButton: true,
      confirmButtonText: "Confirmar igual",
      cancelButtonText: "Volver a escanear",
      confirmButtonColor: "#1c8f61",
    }).then((r) => {
      if (r.isConfirmed) {
        cerrarColectaAhora(($("#colecta-cierre-obs").val() || "").trim());
      }
    });
    return;
  }

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
    // El backend detectó que le cambiaron el recorrido al chofer a mitad de
    // turno: RecorridoAsignado quedó viejo y hay que reingresar.
    if (jsonData && jsonData.RecorridoCambio) {
      cerrarSesionForzada("RECORRIDO_CAMBIO");
      return;
    }
    if (jsonData && jsonData.success == 1) {
      $("#hdr-header").html(`Rec. ${jsonData.Recorrido} · H${jsonData.NOrden}`);
      $("#badge-total").html(jsonData.Total);
      $("#badge-sinentregar").html(jsonData.Abiertos);
      $("#badge-entregados").html(jsonData.Cerrados);
      // Override de escaneo del recorrido (Logistica.OmitirControlEscaneo): si
      // está prendido, aceptar retiro/colecta no exige haber escaneado.
      window.omitirEscaneo = String(jsonData.OmitirEscaneo) === "1";
      pintarEstadoRecorrido(jsonData);
    }
  });
}

// Reloj de "tiempo en ruta" al lado de "En ruta desde HH:MM" - se actualiza
// solo cada 30s a partir de la hora real de inicio, sin pedirle nada más al
// servidor.
let horaSalidaRealActual = null;
let relojEnRutaIniciado = false;

function formatearDuracion(ms) {
  const totalSeg = Math.max(0, Math.floor(ms / 1000));
  const horas = Math.floor(totalSeg / 3600);
  const minutos = Math.floor((totalSeg % 3600) / 60);
  const segundos = totalSeg % 60;
  return (
    String(horas).padStart(2, "0") +
    ":" +
    String(minutos).padStart(2, "0") +
    ":" +
    String(segundos).padStart(2, "0")
  );
}

function actualizarRelojEnRuta() {
  if (!horaSalidaRealActual) return;
  const horaTxt = horaSalidaRealActual.toLocaleTimeString("es-AR", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
  const duracionTxt = formatearDuracion(Date.now() - horaSalidaRealActual.getTime());
  $("#en-ruta-texto").text(`En ruta desde ${horaTxt} · ${duracionTxt}`);
}

// Estado "detenido" del banner (recorrido todavía no iniciado):
// icono de stop + hora actual + contador en 00:00:00.
function pintarBannerDetenido() {
  const ahora = new Date().toLocaleTimeString("es-AR", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
  $("#en-ruta-icon").attr("class", "mdi mdi-stop-circle-outline");
  $("#banner-en-ruta").addClass("stopped");
  $("#en-ruta-texto").text(`Sin iniciar · ${ahora} · 00:00:00`);
  $("#btn-parar-ruta").hide();
  $("#banner-en-ruta").show();
}

// Banner "Iniciar Recorrido" / "En ruta desde..." + card resumen
// (pendientes / km / tiempo estimado). Se alimenta de los campos que
// agrega el handler Datos de funciones.php: HoraSalidaReal, KmPendientes,
// TiempoPendienteMin.
function pintarEstadoRecorrido(jsonData) {
  if (jsonData.HoraSalidaReal) {
    $("#btn-iniciar-recorrido").hide();
    const fecha = new Date(jsonData.HoraSalidaReal.replace(" ", "T"));
    horaSalidaRealActual = isNaN(fecha.getTime()) ? null : fecha;
    $("#en-ruta-icon").attr("class", "mdi mdi-check-circle");
    $("#banner-en-ruta").removeClass("stopped");
    $("#btn-parar-ruta").show();
    actualizarRelojEnRuta();
    if (!relojEnRutaIniciado) {
      relojEnRutaIniciado = true;
      setInterval(actualizarRelojEnRuta, 1000);
    }
    $("#banner-en-ruta").show();
  } else {
    horaSalidaRealActual = null;
    // Recorrido no iniciado: banner "detenido" (stop + hora actual + 00:00:00)
    // junto al botón de arrancar.
    pintarBannerDetenido();
    // Sólo tiene sentido ofrecer arrancar el recorrido si hay algo
    // pendiente - si ya está todo entregado, no mostramos el botón.
    if ((jsonData.Abiertos || 0) > 0) {
      $("#btn-iniciar-recorrido").show();
    } else {
      $("#btn-iniciar-recorrido").hide();
    }
  }

  // "Finalizar Recorrido": solo cuando el recorrido tiene paradas y ya no
  // queda ninguna pendiente (entregada o no).
  const sinPendientes = (jsonData.Abiertos || 0) === 0 && (jsonData.Total || 0) > 0;
  $("#btn-finalizar-recorrido").toggle(sinPendientes);
  if (sinPendientes) {
    $("#btn-iniciar-recorrido").hide();
  }

  const pendientes = jsonData.Abiertos || 0;
  if (pendientes > 0) {
    $("#resumen-pendientes").text(pendientes);
    $("#resumen-km").text(
      jsonData.KmPendientes != null ? Number(jsonData.KmPendientes).toFixed(1) : "-",
    );
    if (jsonData.TiempoPendienteMin != null) {
      const horas = Math.floor(jsonData.TiempoPendienteMin / 60);
      const minutos = jsonData.TiempoPendienteMin % 60;
      $("#resumen-tiempo").text(horas > 0 ? `${horas}h ${minutos}m` : `${minutos}m`);
    } else {
      $("#resumen-tiempo").text("-");
    }
    $("#card-resumen-recorrido").show();
  } else {
    $("#card-resumen-recorrido").hide();
  }

  pintarPausa(jsonData.PausaActiva || null);
}

// ===== Parar / Reanudar recorrido =====
const MOTIVOS_PAUSA_TEXTO = {
  mecanico: "Mecánico / Rotura",
  descanso: "Descanso",
  transito: "Tránsito / Accidente",
  otro: "Otro",
};

let pausaInicioActual = null;
let relojPausaIniciado = false;

function actualizarRelojPausa() {
  if (!pausaInicioActual) return;
  $("#pausaOverlayDuracion").text("Pausado hace " + formatearDuracion(Date.now() - pausaInicioActual.getTime()) + " hs");
}

function pintarPausa(pausaActiva) {
  if (!pausaActiva) {
    pausaInicioActual = null;
    $("#pausaOverlay").removeClass("show");
    return;
  }

  const fecha = new Date((pausaActiva.Inicio || "").replace(" ", "T"));
  pausaInicioActual = isNaN(fecha.getTime()) ? new Date() : fecha;

  const motivoTxt = MOTIVOS_PAUSA_TEXTO[pausaActiva.Motivo] || pausaActiva.Motivo || "";
  $("#pausaOverlayMotivo").text(motivoTxt);
  $("#pausaOverlayDetalle")
    .text(pausaActiva.Detalle || "")
    .toggle(!!pausaActiva.Detalle);

  actualizarRelojPausa();
  if (!relojPausaIniciado) {
    relojPausaIniciado = true;
    setInterval(actualizarRelojPausa, 1000);
  }

  $("#pausaOverlay").addClass("show");
}

// Botón "Parar" del banner: abre el modal para elegir motivo.
$(document).on("click", "#btn-parar-ruta", function () {
  $("#pausa-otro-detalle").val("");
  const modal = new bootstrap.Modal(document.getElementById("pararRutaModal"));
  modal.show();
});

// Cualquiera de los botones de motivo (los 3 predefinidos, o "Otro" con el
// texto libre) dispara el mismo POST - solo cambia qué motivo/detalle manda.
$(document).on("click", ".btn-motivo-pausa", function () {
  const motivo = $(this).data("motivo");
  const detalle = motivo === "otro" ? $("#pausa-otro-detalle").val() : "";

  const modalEl = document.getElementById("pararRutaModal");
  const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
  modal.hide();

  function enviarPausa(lat, lng) {
    $.ajax({
      url: "Proceso/php/pausar_recorrido.php",
      type: "POST",
      dataType: "json",
      data: { motivo, detalle, lat, lng },
    })
      .done(function (jsonData) {
        if (!jsonData || jsonData.success !== 1) {
          Swal.fire({
            icon: "error",
            title: "No se pudo parar la ruta",
            text: (jsonData && jsonData.error) || "Reintentá en unos segundos.",
          });
          return;
        }
        cargarHeader();
      })
      .fail(function () {
        Swal.fire({ icon: "error", title: "Error de servidor", text: "No se pudo parar la ruta." });
      });
  }

  const ultima = window.CaddyGeo && window.CaddyGeo.getLastPosition ? window.CaddyGeo.getLastPosition() : null;
  if (ultima && Date.now() - ultima.ts < 2 * 60 * 1000) {
    enviarPausa(ultima.lat, ultima.lng);
    return;
  }
  if ("geolocation" in navigator) {
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        enviarPausa(pos.coords.latitude, pos.coords.longitude);
      },
      function () {
        enviarPausa(null, null); // sin posición igual se registra la pausa
      },
      { enableHighAccuracy: false, maximumAge: 60000, timeout: 8000 },
    );
  } else {
    enviarPausa(null, null);
  }
});

$(document).on("click", "#btn-reanudar-ruta", function () {
  const $btn = $(this);
  $btn.prop("disabled", true).html('<i class="mdi mdi-loading mdi-spin"></i> Reanudando...');

  $.ajax({
    url: "Proceso/php/reanudar_recorrido.php",
    type: "POST",
    dataType: "json",
  })
    .done(function (jsonData) {
      if (!jsonData || jsonData.success !== 1) {
        Swal.fire({
          icon: "error",
          title: "No se pudo reanudar",
          text: (jsonData && jsonData.error) || "Reintentá en unos segundos.",
        });
        return;
      }
      cargarHeader();
    })
    .fail(function () {
      Swal.fire({ icon: "error", title: "Error de servidor", text: "No se pudo reanudar." });
    })
    .always(function () {
      $btn.prop("disabled", false).html('<i class="mdi mdi-play-circle-outline"></i> Reanudar');
    });
});

// Click en "Iniciar Recorrido": toma la posición actual (reusa la del
// tracker si es reciente, si no pide una nueva) y avisa al backend para
// que marque el inicio real y dispare el primer recálculo de ETA.
// ===== Finalizar Recorrido =====
function _resetBtnFinalizar($btn) {
  $btn.prop("disabled", false).html('<i class="mdi mdi-flag-checkered"></i> Finalizar Recorrido');
}

// Formulario de cierre para vehículo propio: km de regreso, combustible y
// observaciones. Solo aparece cuando el backend lo pide (esPropio).
function _pedirDatosCierreYFinalizar($btn, res) {
  const kmSalida = res && res.kmSalida ? Number(res.kmSalida) : 0;
  const niveles = (res && Array.isArray(res.niveles) && res.niveles.length)
    ? res.niveles
    : ["Vacio", "1/4", "1/2", "3/4", "Lleno"];
  const opciones = niveles
    .map((n) => '<option value="' + n + '">' + (n === "Vacio" ? "Vacío" : n) + "</option>")
    .join("");

  Swal.fire({
    icon: res.error === "KM_MENOR" ? "warning" : "info",
    title: "Cierre del recorrido",
    html:
      '<div style="text-align:left;font-size:14px">' +
      (res.error === "KM_MENOR"
        ? '<p style="color:#c0392b;margin:0 0 8px">' + res.msg + "</p>"
        : "") +
      '<label style="display:block;margin-top:4px">Km de regreso (odómetro)</label>' +
      '<input id="fr-km" type="number" class="swal2-input" style="margin:4px 0" ' +
      'min="' + (kmSalida > 0 ? kmSalida : 1) + '" step="1" placeholder="Km de regreso">' +
      (kmSalida > 0
        ? '<small style="color:#666">Km al salir: ' + kmSalida + "</small>"
        : "") +
      '<label style="display:block;margin-top:10px">Combustible al volver</label>' +
      '<select id="fr-comb" class="swal2-select" style="margin:4px 0">' +
      '<option value="">Seleccionar…</option>' + opciones + "</select>" +
      '<label style="display:block;margin-top:10px">Observaciones (opcional)</label>' +
      '<textarea id="fr-obs" class="swal2-textarea" style="margin:4px 0" ' +
      'placeholder="Novedades del vehículo o de la ruta"></textarea>' +
      "</div>",
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "Cerrar recorrido",
    cancelButtonText: "Cancelar",
    allowOutsideClick: false,
    preConfirm: function () {
      const km = (document.getElementById("fr-km").value || "").trim();
      const comb = document.getElementById("fr-comb").value;
      const obs = (document.getElementById("fr-obs").value || "").trim();
      if (!km || Number(km) <= 0) {
        Swal.showValidationMessage("Ingresá los km de regreso.");
        return false;
      }
      if (kmSalida > 0 && Number(km) < kmSalida) {
        Swal.showValidationMessage(
          "Los km no pueden ser menores a los de salida (" + kmSalida + ").",
        );
        return false;
      }
      if (!comb) {
        Swal.showValidationMessage("Elegí el nivel de combustible.");
        return false;
      }
      return { km: km, comb: comb, obs: obs };
    },
  }).then(function (r) {
    if (!r.isConfirmed) {
      _resetBtnFinalizar($btn);
      return;
    }
    _enviarFinalizar($btn, r.value);
  });
}

function _enviarFinalizar($btn, datos) {
  const data = { Finalizar: 1 };
  if (datos && typeof datos === "object") {
    data.Km = datos.km;
    data.Combustible = datos.comb;
    data.Observaciones = datos.obs || "";
  }

  $btn.prop("disabled", true).html('<i class="mdi mdi-loading mdi-spin"></i> Cerrando...');

  $.ajax({
    url: "Proceso/php/finalizar_recorrido.php",
    type: "POST",
    dataType: "json",
    data: data,
  })
    .done(function (res) {
      if (
        res &&
        (res.error === "FALTA_DATOS_CIERRE" ||
          res.error === "FALTA_KM" ||
          res.error === "KM_MENOR")
      ) {
        _pedirDatosCierreYFinalizar($btn, res);
        return;
      }
      if (!res || res.success !== 1) {
        Swal.fire({
          icon: "error",
          title: "No se pudo finalizar",
          text: (res && (res.msg || res.error)) || "Reintentá en unos segundos.",
        });
        _resetBtnFinalizar($btn);
        return;
      }

      const rr = res.resumen || {};
      Swal.fire({
        icon: "success",
        title: "¡Felicitaciones! 🎉",
        html:
          "Terminaste el recorrido.<br><br>" +
          "<b>Tiempo total:</b> " + (rr.tiempo_texto || "sin registrar") + "<br>" +
          "<b>Paradas:</b> " + (rr.paradas || 0) +
          (rr.km_recorridos != null
            ? "<br><b>Km recorridos:</b> " + rr.km_recorridos
            : "") +
          (rr.combustible
            ? "<br><b>Combustible:</b> " +
              (rr.combustible === "Vacio" ? "Vacío" : rr.combustible)
            : "") +
          (rr.hora_inicio
            ? "<br><small class='text-muted'>" + rr.hora_inicio + " → " + (rr.hora_fin || "") + "</small>"
            : ""),
        confirmButtonText: "Fin",
        allowOutsideClick: false,
        customClass: { container: "caddy-login-swal" },
      }).then(function () {
        location.reload();
      });
    })
    .fail(function (xhr) {
      if (xhr.status === 401) {
        cerrarSesionForzada("SESSION_EXPIRED");
        return;
      }
      Swal.fire({ icon: "error", title: "Error", text: "No se pudo finalizar el recorrido." });
      _resetBtnFinalizar($btn);
    });
}

$(document).on("click", "#btn-finalizar-recorrido", function () {
  const $btn = $(this);

  Swal.fire({
    icon: "question",
    title: "¿Finalizar el recorrido?",
    text: "Ya no quedan paquetes pendientes.",
    showCancelButton: true,
    confirmButtonText: "Sí, finalizar",
    cancelButtonText: "Todavía no",
  }).then(function (r) {
    if (!r.isConfirmed) return;
    _enviarFinalizar($btn, null);
  });
});

$(document).on("click", "#btn-iniciar-recorrido", function () {
  const $btn = $(this);
  $btn.prop("disabled", true).html('<i class="mdi mdi-loading mdi-spin"></i> Iniciando...');

  function enviar(lat, lng) {
    $.ajax({
      url: "Proceso/php/iniciar_recorrido.php",
      type: "POST",
      dataType: "json",
      data: { lat, lng },
    })
      .done(function (jsonData) {
        if (!jsonData || jsonData.success !== 1) {
          Swal.fire({
            icon: "error",
            title: "No se pudo iniciar el recorrido",
            text: (jsonData && (jsonData.msg || jsonData.error)) || "Reintentá en unos segundos.",
          });
        }
        cargarHeader();
      })
      .fail(function () {
        Swal.fire({ icon: "error", title: "Error de servidor", text: "No se pudo iniciar el recorrido." });
      })
      .always(function () {
        $btn.prop("disabled", false).html('<i class="mdi mdi-rocket-launch-outline"></i> Iniciar Recorrido');
      });
  }

  const ultima = window.CaddyGeo && window.CaddyGeo.getLastPosition ? window.CaddyGeo.getLastPosition() : null;
  if (ultima && Date.now() - ultima.ts < 2 * 60 * 1000) {
    enviar(ultima.lat, ultima.lng);
    return;
  }

  function resetIniciar() {
    $btn.prop("disabled", false).html('<i class="mdi mdi-rocket-launch-outline"></i> Iniciar Recorrido');
  }

  function pedirYArrancar() {
    if (window.CaddyGeo && window.CaddyGeo.solicitar) {
      window.CaddyGeo.solicitar(
        function (p) {
          enviar(p.lat, p.lng);
        },
        function (codigo) {
          resetIniciar();
          if (codigo === "nosoporta") {
            Swal.fire({ icon: "error", title: "Sin GPS", text: "Este dispositivo no tiene geolocalización disponible." });
            return;
          }
          if (codigo === "denied") {
            Swal.fire({
              icon: "error",
              title: "La ubicación está bloqueada",
              html:
                "Tocá el candado 🔒 al lado de la dirección (arriba) → <b>Permisos</b> → <b>Ubicación</b> → <b>Permitir</b>.<br>" +
                "Si no aparece, activala desde <b>Ajustes del teléfono → Ubicación</b>.",
            });
            return;
          }
          Swal.fire({
            icon: "warning",
            title: "No se pudo obtener tu ubicación",
            text:
              codigo === "timeout"
                ? "Tardó demasiado (¿estás bajo techo?). Salí a un lugar más abierto y reintentá."
                : "Activá el GPS e intentá de nuevo.",
            confirmButtonText: "Reintentar",
            showCancelButton: true,
            cancelButtonText: "Cancelar",
          }).then(function (r) {
            if (r.isConfirmed) {
              $btn.prop("disabled", true).html('<i class="mdi mdi-loading mdi-spin"></i> Iniciando...');
              pedirYArrancar();
            }
          });
        },
      );
      return;
    }

    // Fallback si geo_tracker.js no cargó
    if (!("geolocation" in navigator)) {
      Swal.fire({ icon: "error", title: "Sin GPS", text: "Este dispositivo no tiene geolocalización disponible." });
      resetIniciar();
      return;
    }
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        enviar(pos.coords.latitude, pos.coords.longitude);
      },
      function () {
        Swal.fire({
          icon: "error",
          title: "No se pudo obtener tu ubicación",
          text: "Activá el GPS/permiso de ubicación e intentá de nuevo.",
        });
        resetIniciar();
      },
      { enableHighAccuracy: false, maximumAge: 60000, timeout: 10000 },
    );
  }

  pedirYArrancar();
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
    data: { Login: 1, user: user, password: pass, device_id: window.CADDY_DEVICE_ID || "" },
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
        showBottomnav();
        $("#login").hide();
        $("#app-footer").removeClass("d-none");
        $("#navbar,#topnav").show();
        $("body").removeClass("login-lock");
        $("#mis_envios").hide();
        $("#card-envio").hide();
        // 🔓 habilitar scroll (mobile fix)
        document.body.classList.remove("loading");
        document.body.style.overflowY = "auto";
        document.body.style.webkitOverflowScrolling = "touch";

        // Un login siempre arranca en Recorrido, sin importar el hash que
        // haya quedado de una sesión anterior en esta pestaña.
        if (typeof window.showScreen === "function") {
          window.showScreen("operacion");
        } else {
          $("#screen-operacion").addClass("active").show();
          $("#hdractivas").show();
        }
        if (location.hash && location.hash !== "#operacion") {
          location.hash = "operacion";
        }

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
