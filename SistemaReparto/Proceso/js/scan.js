//scan.js
let scanLocked = false; // cuando está completo, no procesa más
let html5QrCode = null;
let lastCode = "";
let lastTime = 0;
let userInteracted = false;
let coolingDown = false; // 👈 ESTA LÍNEA FALTABA
let feedbackTimeout = null;
let scannerStarting = false;
let scannerRunning = false;

function existeAlgunoConBase(base, cb) {
  const t = db.transaction("expected", "readonly");
  const s = t.objectStore("expected");
  let found = false;

  s.openCursor().onsuccess = function (e) {
    const c = e.target.result;
    if (!c) return cb(found);
    const v = c.value;
    if (v && v.base === base) return cb(true);
    c.continue();
  };
}
function tieneSufijoBulto(code) {
  return /_\d+$/.test(code); // termina en _numero
}
function getNextPendingCodeForBase(base, retiradoObjetivo, callback) {
  const t = db.transaction("expected", "readonly");
  const store = t.objectStore("expected");

  store.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;
    if (cursor) {
      const v = cursor.value;

      // 👇 SOLO los de esta base + este tipo (0 retiro / 1 entrega) + pendientes

      const ret = Number(v.retirado ?? 1);
      if (v.base === base && ret === Number(retiradoObjetivo) && v.estado !== "ok" && v.estado !== "alias") {
        return callback(v.code);
      }
      cursor.continue();
    } else {
      callback(null);
    }
  };
}
function manejar401(xhr) {
  if (xhr && xhr.status === 401) {
    window.location.href = "hdr.html";
    return true;
  }
  return false;
}

function baseCompleto(base, retiradoObjetivo, callback) {
  const t = db.transaction("expected", "readonly");
  const store = t.objectStore("expected");

  let total = 0;
  let ok = 0;

  store.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;
    if (cursor) {
      const v = cursor.value;

      const ret = Number(v.retirado ?? 1);
      if (v.base === base && ret === Number(retiradoObjetivo) && v.estado !== "alias") {
        total++;
        if (v.estado === "ok") ok++;
      }
      cursor.continue();
    } else {
      callback(total > 0 && ok === total);
    }
  };
}
function baseYaRegistrada(base, cb) {
  const t = db.transaction("bases_done", "readonly");
  const s = t.objectStore("bases_done");
  const r = s.get(base);
  r.onsuccess = () => cb(!!r.result);
}

function marcarBaseRegistrada(base) {
  const t = db.transaction("bases_done", "readwrite");
  t.objectStore("bases_done").put({ base: base, ts: Date.now() });
}

// Toast simple arriba
function mostrarToast(txt) {
  let el = document.getElementById("scan-toast");
  if (!el) {
    el = document.createElement("div");
    el.id = "scan-toast";
    el.style.position = "fixed";
    el.style.top = "12px";
    el.style.left = "50%";
    el.style.transform = "translateX(-50%)";
    el.style.zIndex = "99999";
    el.style.padding = "10px 14px";
    el.style.borderRadius = "10px";
    el.style.background = "rgba(0,0,0,.75)";
    el.style.color = "#fff";
    el.style.fontSize = "16px";
    document.body.appendChild(el);
  }
  el.innerText = txt;
  el.style.display = "block";
  clearTimeout(window.__toastT);
  window.__toastT = setTimeout(() => (el.style.display = "none"), 900);
}

function mostrarFeedback(texto, tipo = "ok") {
  const el = document.getElementById("scan-feedback");
  if (!el) return;

  el.classList.remove("hidden"); // ✅ asegurar visible
  el.className = `scan-feedback ${tipo}`;
  el.innerText = texto;

  clearTimeout(feedbackTimeout);
  feedbackTimeout = setTimeout(() => {
    el.classList.add("hidden");
  }, 1000);
}

window.addEventListener(
  "pointerdown",
  () => {
    userInteracted = true;
  },
  { once: true },
);
// --------------------
// UI states
// --------------------
function setEstadoParcial(ok, total) {
  $("#estado").removeClass("bg-success").addClass("bg-warning");
  $("#wh-msg").text("Escaneá todos los bultos para salir");

  scanLocked = false; // 👈 por si volvés a parcial
}
async function setEstadoCompleto(total) {
  $("#estado").removeClass("bg-warning").addClass("bg-success");
  $("#wh-msg").text("✅ Todo OK. Volvé a Warehouse y presioná Confirmar carga.");

  // Bloqueo lógico
  scanLocked = true;

  // Paro definitivo la cámara (sin reanudar)
  try {
    await stopScanner();
  } catch (e) {}

  // Feedback
  try {
    beepOk();
  } catch (e) {}
  mostrarFeedback(`✅ Completo (${total}/${total})`, "ok");

  // Si querés, agrandá el botón volver
  $("#btn-volver").removeClass("btn-secondary").addClass("btn-success").text("Volver y Confirmar");
}
// --------------------
// Conteo desde IndexedDB
// --------------------

function actualizarEstado(retiradoObjetivo = 1) {
  const tx = db.transaction("expected", "readonly");
  const store = tx.objectStore("expected");

  let total = 0;
  let ok = 0;

  store.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;
    if (cursor) {
      const v = cursor.value;

      // if ((v.retirado ?? 1) === retiradoObjetivo && v.estado !== "alias") {
      const ret = Number(v.retirado ?? 1);
      if (ret === Number(retiradoObjetivo) && v.estado !== "alias") {
        total++;
        if (v.estado === "ok") ok++;
      }
      cursor.continue();
    } else {
      $("#wh-ok").text(ok);
      $("#wh-total").text(total);

      if (total > 0 && ok === total) setEstadoCompleto(total);
      else setEstadoParcial(ok, total);
    }
  };
}
// --------------------
// Validación
// --------------------
function normalizarCodigo(raw) {
  raw = (raw || "").trim();
  if (!raw) return "";
  return raw;
}

let audioCtx = null;

function beepOk() {
  try {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }

    const now = audioCtx.currentTime;

    for (let i = 0; i < 2; i++) {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();

      osc.type = "square";
      osc.frequency.value = 1000;

      gain.gain.value = 0.15;

      osc.connect(gain);
      gain.connect(audioCtx.destination);

      osc.start(now + i * 0.12);
      osc.stop(now + i * 0.12 + 0.08);
    }
  } catch (e) {}
}

function validarExacto(code, retiradoObjetivo, resolve) {
  const t = db.transaction(["expected", "scanned"], "readwrite");
  const expected = t.objectStore("expected");
  const scanned = t.objectStore("scanned");

  const req = expected.get(code);
  let marcadoNuevo = false;
  let baseOk = "";
  let eraAlias = false;

  req.onsuccess = function () {
    const item = req.result;

    if (!item) {
      mostrarFeedback("❌ No pertenece al recorrido", "error");
      return resolve("no_pertenece");
    }
    const ret = Number(item.retirado ?? 1);

    if (ret !== Number(retiradoObjetivo)) {
      mostrarFeedback("⚠️ Este QR no es una ENTREGA", "warn");
      return resolve("no_corresponde");
    }

    if (item.estado === "ok") {
      mostrarFeedback("⚠️ Ya escaneado", "warn");
      return resolve("ya_ok");
    }
    // Determinar primero si es alias
    // const eraAlias = item.estado === "alias";
    eraAlias = item.estado === "alias";
    // Si escaneé un alias, el código real es la base
    const realCode = eraAlias ? item.codigoSeguimiento || item.base : code;

    // Si era alias, NO cambiar estado
    if (eraAlias) {
      item.alias_ok = 1;
      item.alias_ts = Date.now();
      expected.put(item);
    }
    // Ahora marco ok el bulto real (base)
    const reqReal = expected.get(realCode);

    reqReal.onsuccess = function () {
      const realItem = reqReal.result;

      if (!realItem) {
        // raro: alias sin base, pero no rompemos
        mostrarFeedback("⚠️ Alias sin base", "warn");
        return resolve("no_pertenece");
      }

      if (realItem.estado === "ok") {
        mostrarFeedback("⚠️ Ya escaneado", "warn");
        return resolve("ya_ok");
      }

      realItem.estado = "ok";
      expected.put(realItem);
      marcadoNuevo = true;

      scanned.put({
        id: crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now() + "_" + Math.random(),
        code: realCode, // 👈 guardo el real
        base: realItem.base,
        retirado: realItem.retirado ?? 1,
        ts: Date.now(),
        origen: eraAlias ? "alias" : "directo",
      });
    };
    t.oncomplete = function () {
      try {
        actualizarEstado(1);
      } catch (e) {}

      // buscamos el real para obtener base consistente
      const tx2 = db.transaction("expected", "readonly");
      tx2.objectStore("expected").get(realCode).onsuccess = function (e3) {
        const it = e3.target.result || item;
        const base = it.codigoSeguimiento || it.base;
        baseYaRegistrada(base, (ya) => {
          // ✅ SOLO si fue un OK nuevo
          if (marcadoNuevo) {
            // mostrarFeedback(eraAlias ? `✅ OK (ML): ${base}` : `✅ OK: ${base}`, "ok");
            mostrarFeedback(`✅ OK: ${base}`, "ok");
            beepOk();
          }

          if (ya) return resolve("ok");

          baseCompleto(base, retiradoObjetivo, (completo) => {
            if (completo) {
              marcarBaseRegistrada(base);
            }
            resolve("ok");
          });
        });
      };
    };
  };
}

// Etiqueta de MELI: QR en JSON {"id": "<shipments_id>", ...}. Antes se
// rechazaba directo ("Solo etiquetas Caddy") - ahora se resuelve por
// shipments_id contra lo que ya cacheamos en "expected" (viene de
// TransClientes.shipments_id via GetLista) y sigue el mismo camino de
// validarExacto que un código Caddy. Si no matchea nada, es un rechazo real
// (se loguea igual que un código Caddy que no pertenece).
function validarBultoMeli(meliId) {
  return new Promise((resolve) => {
    const retiradoObjetivo = 1; // esta pantalla: ENTREGAS
    const t = db.transaction("expected", "readonly");
    const idx = t.objectStore("expected").index("meli_id");

    idx.get(meliId).onsuccess = function (e) {
      const item = e.target.result;
      if (!item) {
        mostrarFeedback("❌ No pertenece al recorrido", "error");
        return resolve("no_pertenece");
      }
      validarExacto(item.code, retiradoObjetivo, resolve);
    };
  });
}

function validarBulto(rawCode) {
  return new Promise((resolve) => {
    const code = (rawCode || "").trim();
    if (!code) return resolve("vacio");

    const retiradoObjetivo = 1; // esta pantalla: ENTREGAS

    // 1) Si viene con sufijo: validar EXACTO (sin inventar nada)
    if (tieneSufijoBulto(code)) {
      return validarExacto(code, retiradoObjetivo, resolve);
    }

    // 2) Si viene SIN sufijo: SOLO puede ser alias de _1 (o base si existe como Cantidad=1)
    const base = code.split("_")[0];
    const alias1 = `${base}_1`;

    // a) Si tu BD guarda Cantidad=1 como BASE (sin _1), lo permitimos
    const t0 = db.transaction("expected", "readonly");
    const expected0 = t0.objectStore("expected");

    expected0.get(base).onsuccess = function (e) {
      const itemBase = e.target.result;

      if (itemBase && (itemBase.retirado ?? 1) === retiradoObjetivo) {
        // Cantidad=1 guardado como BASE
        return validarExacto(base, retiradoObjetivo, resolve);
      }

      // b) Si no existe BASE, probamos alias _1
      expected0.get(alias1).onsuccess = function (e2) {
        const item1 = e2.target.result;

        if (item1 && (item1.retirado ?? 1) === retiradoObjetivo) {
          mostrarFeedback(`✅ Tomado como ${alias1}`, "ok");
          return validarExacto(alias1, retiradoObjetivo, resolve);
        }

        // c) Si no existe ni BASE ni _1 => decidir si realmente hay múltiples bultos o no pertenece
        existeAlgunoConBase(base, function (existe) {
          if (existe) {
            mostrarFeedback(`⚠️ Envío con múltiples bultos: escaneá ${base}_1, ${base}_2, etc.`, "warn");
            return resolve("requiere_sufijo");
          } else {
            mostrarFeedback("❌ No pertenece al recorrido", "error");
            return resolve("no_pertenece");
          }
        });
      };
    };
  });
}
// --------------------
// Scanner
// --------------------

function registrarRechazo(codigo) {
  fetch("Proceso/php/warehouse.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "RegistrarRechazo=1&codigo=" + encodeURIComponent(codigo),
    credentials: "include",
  }).catch(function () {});
}

const onSuccess = async (decodedText) => {
  if (scanLocked) return; // 👈 no procesa nada más

  const raw = (decodedText || "").trim();
  if (!raw) return;

  const now = Date.now();
  if (coolingDown) return;

  // Etiqueta MELI: JSON {"id": "<shipments_id>", ...} - se resuelve por
  // shipments_id en vez de rechazarse de plano.
  let r;
  if (raw.startsWith("{")) {
    let meliId = null;
    try {
      const obj = JSON.parse(raw);
      if (obj && obj.id) meliId = String(obj.id).trim();
    } catch (e) {}

    if (!meliId) {
      mostrarFeedback("❌ Etiqueta MELI inválida", "error");
      return;
    }
    if (meliId === lastCode && now - lastTime < 1500) return;
    lastCode = meliId;
    lastTime = now;

    r = await validarBultoMeli(meliId);
    if (r === "no_pertenece") registrarRechazo(meliId);
  } else {
    if (/^\d+$/.test(raw)) {
      mostrarFeedback("❌ Solo etiquetas Caddy (no proveedor)", "warn");
      return;
    }
    // ✅ Validar formato Caddy: BASE o BASE_n (solo letras/números/guión y sufijo opcional)
    if (!/^[A-Za-z0-9\-]+(_[1-9][0-9]*)?$/.test(raw)) {
      mostrarFeedback("❌ Formato inválido. Escaneá etiqueta Caddy.", "error");
      return;
    }

    const normalized = normalizarCodigo(raw);
    if (normalized === lastCode && now - lastTime < 1500) return;

    lastCode = normalized;
    lastTime = now;

    r = await validarBulto(normalized);
    if (r === "no_pertenece") registrarRechazo(normalized);
  }

  if (r === "ok") {
    coolingDown = true;

    try {
      if (html5QrCode && typeof html5QrCode.pause === "function") {
        html5QrCode.pause(true);
        setTimeout(() => {
          try {
            html5QrCode.resume();
          } catch (e) {}
          coolingDown = false;
        }, 900);
      } else {
        setTimeout(() => (coolingDown = false), 900);
      }
    } catch (e) {
      setTimeout(() => (coolingDown = false), 900);
    }
  }
};

const onError = () => {}; // silencioso

async function startScanner() {
  if (scannerStarting || scannerRunning) return;
  scannerStarting = true;

  try {
    if (!("Html5Qrcode" in window)) {
      alert("No se cargó la librería QR (html5-qrcode).");
      return;
    }

    // si quedó una instancia anterior “colgada”, limpiamos
    if (html5QrCode) {
      try {
        await html5QrCode.stop();
      } catch (e) {}
      try {
        await html5QrCode.clear();
      } catch (e) {}
      html5QrCode = null;
    }

    html5QrCode = new Html5Qrcode("qr-reader");

    const qrConfig = {
      fps: 10,
      qrbox: { width: 280, height: 280 },
      aspectRatio: 1,
      disableFlip: true,
      experimentalFeatures: { useBarCodeDetectorIfSupported: true },
      videoConstraints: {
        facingMode: { ideal: "environment" },
        width: { ideal: 1920 },
        height: { ideal: 1080 },
      },
    };

    await html5QrCode.start(
      { facingMode: "environment" }, // 👈 1 sola key => OK
      qrConfig,
      onSuccess,
      onError,
    );

    scannerRunning = true;

    setTimeout(() => {
      const v = document.querySelector("#qr-reader video");
      if (v) {
        v.setAttribute("playsinline", "true");
        v.setAttribute("webkit-playsinline", "true");
        v.style.width = "100%";
        v.style.height = "auto";
        v.style.objectFit = "cover";
      }
    }, 250);
  } catch (e) {
    console.error(e);
    alert("No se pudo abrir la cámara: " + (e?.message || e));
  } finally {
    scannerStarting = false;
  }
}

async function stopScanner() {
  try {
    if (html5QrCode) {
      await html5QrCode.stop();
      await html5QrCode.clear();
      html5QrCode = null;
    }
  } catch (e) {}
  scannerRunning = false;
  scannerStarting = false;
}

// --------------------
// Eventos
// --------------------
$(document).ready(function () {
  abrirDB(() => {
    console.log("📦 DB abierta correctamente en scan");
    actualizarEstado(1);
    startScanner();
  });

  $("#btn-volver").on("click", async function () {
    await stopScanner();
    window.location.href = "warehouse.html";
  });
  function normalizar(code) {
    return (code || "").trim().toUpperCase();
  }

  function esSufijoNumerico(code) {
    return /_\d+$/.test(code);
  }

  function resolverAliasPrimero(code, expectedStore, cb) {
    const c = normalizar(code);
    const base = c.split("_")[0];

    if (esSufijoNumerico(c)) return cb(c);

    const req = expectedStore.get(`${base}_1`);
    req.onsuccess = function () {
      if (req.result) return cb(`${base}_1`);
      cb(c);
    };
    req.onerror = function () {
      cb(c);
    };
  }
});
