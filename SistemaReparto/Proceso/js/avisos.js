// ==================================================
// SERVICIOS NUEVOS EN EL RECORRIDO (23/11/2026): cuando la oficina le suma un servicio a la orden,
// el chofer se entera en el momento (con la app abierta): sonido, vibración, cartel y queda en la
// campanita. Datos manda los servicios abiertos de la orden; acá se comparan con los que el teléfono
// ya conocía (localStorage, por orden). La primera vez que se ve una orden se guarda sin avisar.
// Se carga en hdr.html (Recorrido/Cuenta, lo llama cargarHeader) y en warehouse.html (lo llama
// cargarHeaderWarehouse). Antes vivía en funciones_hdr.js y Warehouse no avisaba ni abría la campanita.
// ==================================================
const RP_AVISOS_KEY = "rp_avisos";

// Warehouse no carga pwa.js: la campanita abre los avisos directamente y se saca el punto rojo de la
// plantilla (no era un aviso). En hdr.html la campanita la maneja pwa.js (instalar / avisos).
$(function () {
  if (typeof isAppInstalled === "function") return; // hdr.html
  $(".noti-icon-badge").hide();
  $(document).on("click", "#btnInstallBell", function (e) {
    e.preventDefault();
    abrirAvisosChofer(true);
  });
  pintarBadgeAvisos();
});

function rpLeerAvisos() {
  try { return JSON.parse(localStorage.getItem(RP_AVISOS_KEY) || "[]"); } catch (e) { return []; }
}
function rpGuardarAvisos(lista) {
  try { localStorage.setItem(RP_AVISOS_KEY, JSON.stringify(lista.slice(0, 30))); } catch (e) {}
  pintarBadgeAvisos();
}
function pintarBadgeAvisos() {
  const n = rpLeerAvisos().filter((a) => !a.leido).length;
  const $bell = $("#btnInstallBell");
  let $b = $bell.find(".rp-avisos-badge");
  if (!$b.length) {
    $bell.css("position", "relative");
    $b = $('<span class="rp-avisos-badge"></span>').css({
      position: "absolute", top: "8px", right: "2px", minWidth: "18px", height: "18px", padding: "0 5px",
      borderRadius: "9px", background: "#fa5c7c", color: "#fff", fontSize: "11px", fontWeight: 800,
      lineHeight: "18px", textAlign: "center", display: "none",
    });
    $bell.append($b);
  }
  $b.text(n).toggle(n > 0);
}

// Sonido corto de aviso (el AudioContext se habilita con el primer toque en la pantalla).
let rpAudioCtx = null;
$(document).one("touchstart click", function () {
  try { rpAudioCtx = rpAudioCtx || new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
});
function sonidoAviso() {
  try {
    const ctx = rpAudioCtx || new (window.AudioContext || window.webkitAudioContext)();
    rpAudioCtx = ctx;
    if (ctx.state === "suspended") ctx.resume();
    const now = ctx.currentTime;
    [880, 1175, 880].forEach(function (f, i) {
      const osc = ctx.createOscillator();
      const g = ctx.createGain();
      osc.frequency.value = f;
      g.gain.setValueAtTime(0.25, now + i * 0.18);
      g.gain.exponentialRampToValueAtTime(0.001, now + i * 0.18 + 0.15);
      osc.connect(g).connect(ctx.destination);
      osc.start(now + i * 0.18);
      osc.stop(now + i * 0.18 + 0.16);
    });
  } catch (e) {}
}

function detectarServiciosNuevos(jsonData, cambioDeOrden) {
  const servicios = Array.isArray(jsonData.Servicios) ? jsonData.Servicios : null;
  if (!servicios || !jsonData.NOrden) return;
  const key = "rp_servicios_" + jsonData.NOrden;
  const actuales = servicios.map((s) => String(s.c));
  let conocidos = null;
  try { conocidos = JSON.parse(localStorage.getItem(key) || "null"); } catch (e) {}
  try { localStorage.setItem(key, JSON.stringify(actuales)); } catch (e) {}
  // Conciliación silenciosa: un aviso "Nuevo …" de esta orden cuyo servicio ya no está, se tacha
  // (cubre lo que se sacó con la app cerrada o antes de este cambio). Avisos sin orden = viejos de hoy.
  const orden = String(jsonData.NOrden);
  let tocados = false;
  const ahoraC = new Date().toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit", hour12: false });
  const avisosC = rpLeerAvisos().map(function (a) {
    const cs = a.c || String(a.titulo || "").split(": ").pop();
    if (!a.sacado && (!a.orden || a.orden === orden) && /^Nuev/.test(a.titulo || "") && actuales.indexOf(cs) === -1) {
      tocados = true;
      return Object.assign(a, { sacado: true, sacadoHora: ahoraC });
    }
    return a;
  });
  if (tocados) rpGuardarAvisos(avisosC);
  pintarBadgeAvisos();
  // Primera vez que se ve esta orden (o recién cambió: eso ya lo avisa avisarCambioDeOrden): solo se guarda.
  if (!Array.isArray(conocidos) || cambioDeOrden) return;

  const nuevos = servicios.filter((s) => conocidos.indexOf(String(s.c)) === -1);
  // Sacados del recorrido: ya no están en la orden (uno entregado sigue, cerrado; ver Datos).
  const sacados = conocidos.filter((c) => actuales.indexOf(String(c)) === -1);
  if (sacados.length) avisarServiciosSacados(sacados);
  if (!nuevos.length) return;

  const esc = (t) => $("<div>").text(t == null ? "" : String(t)).html();
  const ahora = new Date().toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit", hour12: false });
  const avisos = rpLeerAvisos();
  nuevos.forEach(function (s) {
    avisos.unshift({
      ts: Date.now(), hora: ahora, leido: false, c: String(s.c), orden: String(jsonData.NOrden),
      titulo: (Number(s.r) === 0 ? "Nuevo retiro: " : "Nueva entrega: ") + s.c,
      detalle: (s.n || "") + (s.d ? " · " + s.d : ""),
      entrega: Number(s.r) === 1,
    });
  });
  rpGuardarAvisos(avisos);

  sonidoAviso();
  try { if (navigator.vibrate) navigator.vibrate([300, 150, 300, 150, 300]); } catch (e) {}

  // En Warehouse no se ofrece "Ir a Warehouse" (ya está ahí); al confirmar la carga se le pide escanearla.
  const hayEntregas = nuevos.some((s) => Number(s.r) === 1) && typeof irAWarehouse === "function";
  const lista = nuevos.map(function (s) {
    return "<li><b>" + esc(s.c) + "</b> · " + (Number(s.r) === 0 ? "Retiro" : "Entrega") +
      "<br><small>" + esc(s.n) + (s.d ? " · " + esc(s.d) : "") + "</small></li>";
  }).join("");
  if (window.Swal && !Swal.isVisible()) {
    Swal.fire({
      icon: "info",
      title: nuevos.length === 1 ? "Te sumaron un servicio" : `Te sumaron ${nuevos.length} servicios`,
      html: "<ul style='text-align:left;margin:6px 0;padding-left:18px'>" + lista + "</ul>" +
        (hayEntregas ? "<small>Las entregas hay que escanearlas en Warehouse.</small>" : ""),
      confirmButtonText: hayEntregas ? "Ir a Warehouse" : "Entendido",
      showCancelButton: hayEntregas,
      cancelButtonText: "Después",
    }).then(function (r) {
      rpGuardarAvisos(rpLeerAvisos().map((a) => Object.assign(a, { leido: true })));
      if (hayEntregas && r.isConfirmed) irAWarehouse();
    });
  }
  // Si está en la lista de tarjetas, se refresca (un retiro nuevo ya tiene tarjeta).
  if (!$("#card-envio").is(":visible") && typeof paneles === "function") paneles(null, false);
}

// Le sacaron servicios del recorrido: el aviso de "Nuevo …" de ese servicio queda tachado con "Te lo
// sacaron del recorrido" (no se borra: si ya lo había visto, entiende qué pasó). Si no tenía aviso
// (era de la orden original) se agrega uno, con cartel: tiene que saber que ese paquete no lo lleva.
function avisarServiciosSacados(sacados) {
  const ahora = new Date().toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit", hour12: false });
  const avisos = rpLeerAvisos();
  const sinAviso = [];
  sacados.forEach(function (cs) {
    // (la conciliación de detectarServiciosNuevos puede haberlo tachado recién: igual cuenta como que tenía aviso)
    const delServicio = avisos.filter((a) => (a.c || String(a.titulo || "").split(": ").pop()) === cs);
    if (delServicio.length) {
      delServicio.forEach((a) => Object.assign(a, { sacado: true, sacadoHora: a.sacadoHora || ahora, leido: false }));
    } else {
      sinAviso.push(cs);
      avisos.unshift({ ts: Date.now(), hora: ahora, leido: false, c: cs, sacado: true, sacadoHora: ahora,
        titulo: "Te sacaron del recorrido: " + cs, detalle: "Si lo tenés arriba, devolvelo al depósito." });
    }
  });
  rpGuardarAvisos(avisos);

  try { if (navigator.vibrate) navigator.vibrate([300, 150, 300]); } catch (e) {}
  if (window.Swal && !Swal.isVisible()) {
    const esc = (t) => $("<div>").text(t == null ? "" : String(t)).html();
    Swal.fire({
      icon: "warning",
      title: sacados.length === 1 ? "Te sacaron un servicio" : `Te sacaron ${sacados.length} servicios`,
      html: "<b>" + sacados.map(esc).join(", ") + "</b> ya no " + (sacados.length === 1 ? "está" : "están") +
        " en tu recorrido.<br><small>Si lo tenés arriba, devolvelo al depósito.</small>",
      confirmButtonText: "Entendido",
    }).then(function () {
      rpGuardarAvisos(rpLeerAvisos().map((a) => Object.assign(a, { leido: true })));
    });
  }
  if (!$("#card-envio").is(":visible") && typeof paneles === "function") paneles(null, false);
}

// Campanita: lista de avisos. Devuelve false si no hay avisos y no se pidió forzar (así, con la app
// sin instalar, la campanita sigue ofreciendo "Instalar app"). La llama pwa.js.
function abrirAvisosChofer(forzar) {
  const avisos = rpLeerAvisos();
  if (!avisos.length && !forzar) return false;
  if (!window.Swal) return false;
  const esc = (t) => $("<div>").text(t == null ? "" : String(t)).html();
  const html = avisos.length
    ? "<ul style='text-align:left;margin:0;padding-left:0;list-style:none'>" + avisos.map(function (a) {
        // Sacado del recorrido: tachado y con la aclaración en rojo (salvo el aviso que ya lo dice).
        const tachar = a.sacado && !/^Te sacaron/.test(a.titulo || "");
        const estilo = tachar ? "text-decoration:line-through;color:#999" : "";
        return "<li style='padding:8px 0;border-bottom:1px solid #eee" + (a.leido ? ";opacity:.6" : "") + "'>" +
          "<b style='" + estilo + "'>" + esc(a.titulo) + "</b> <small style='color:#888'>" + esc(a.hora) + "</small><br>" +
          "<small style='" + estilo + "'>" + esc(a.detalle) + "</small>" +
          (tachar ? "<br><small style='color:#fa5c7c;font-weight:700'>Te lo sacaron del recorrido · " + esc(a.sacadoHora || "") + "</small>" : "") +
          "</li>";
      }).join("") + "</ul>"
    : "<div style='color:#888'>No tenés avisos.</div>";
  Swal.fire({
    title: "Avisos",
    html: html,
    confirmButtonText: "Cerrar",
    showDenyButton: avisos.length > 0,
    denyButtonText: "Borrar todo",
  }).then(function (r) {
    if (r.isDenied) return rpGuardarAvisos([]);
    rpGuardarAvisos(rpLeerAvisos().map((a) => Object.assign(a, { leido: true })));
  });
  return true;
}
