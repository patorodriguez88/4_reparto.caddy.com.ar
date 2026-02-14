//scan.js
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
      if (v.base === base && (v.retirado ?? 1) === retiradoObjetivo && v.estado !== "ok") {
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

      if (v.base === base && (v.retirado ?? 1) === retiradoObjetivo) {
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

function registrarWarehouse(base) {
  $.ajax({
    url: "Proceso/php/warehouse.php",
    type: "POST",
    dataType: "json",
    data: {
      RegistrarWarehouse: 1,
      codigo: base, // 👈 base SIN _n
      state_id: 13, // opcional cuando lo tengas definido
    },
    success: function (res) {
      if (res.success === 1) {
        // Mensaje “OK” lindo (sin alert)
        mostrarToast(`✅ ${base} validado en warehouse`);
      } else {
        console.warn(res);
      }
    },
    error: function (xhr) {
      if (manejar401(xhr)) return;
      console.error(xhr.responseText);
    },
  });
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
  $("#btn-salir")
    .prop("disabled", true)
    .removeClass("btn-success")
    .addClass("btn-secondary")
    .text(`Faltan ${Math.max(total - ok, 0)} ENTREGAS`);
}

function setEstadoCompleto(total) {
  $("#estado").removeClass("bg-warning").addClass("bg-success");
  $("#wh-msg").text("Todo OK. Podés iniciar el recorrido.");
  $("#btn-salir")
    .prop("disabled", false)
    .removeClass("btn-secondary")
    .addClass("btn-success")
    .text("Iniciar recorrido");
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

      if ((v.retirado ?? 1) === retiradoObjetivo) {
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

  // QR MercadoLibre: viene como JSON {"id":"...","sender_id":...}
  if (raw.startsWith("{") && raw.endsWith("}")) {
    try {
      const obj = JSON.parse(raw);
      if (obj && obj.id) return String(obj.id).trim(); // <- "46460390729"
    } catch (e) {
      // si no parsea, seguimos con raw
    }
  }

  return raw;
}
function beepOk() {
  try {
    const audio = new Audio("ok.mp3");
    audio.play().catch(() => {});
  } catch (e) {}
}

function validarExacto(code, retiradoObjetivo, resolve) {
  const t = db.transaction(["expected", "scanned"], "readwrite");
  const expected = t.objectStore("expected");
  const scanned = t.objectStore("scanned");

  const req = expected.get(code);

  req.onsuccess = function () {
    const item = req.result;

    if (!item) {
      mostrarFeedback("❌ No pertenece al recorrido", "error");
      return resolve("no_pertenece");
    }

    if ((item.retirado ?? 1) !== retiradoObjetivo) {
      mostrarFeedback("⚠️ Este QR no es una ENTREGA", "warn");
      return resolve("no_corresponde");
    }

    if (item.estado === "ok") {
      mostrarFeedback("⚠️ Ya escaneado", "warn");
      return resolve("ya_ok");
    }

    item.estado = "ok";
    expected.put(item);

    scanned.put({
      id: crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now() + "_" + Math.random(),
      code: code,
      base: item.base,
      retirado: item.retirado ?? 1,
      ts: Date.now(),
    });
    t.oncomplete = function () {
      try {
        actualizarEstado(1);
      } catch (e) {}

      // const base = item.base;
      const base = item.codigoSeguimiento || item.base;
      baseYaRegistrada(base, (ya) => {
        if (ya) return resolve("ok");
        baseCompleto(base, retiradoObjetivo, (completo) => {
          if (completo) {
            registrarWarehouse(base);
            marcarBaseRegistrada(base);
          }
          resolve("ok");
        });
      });
    };
  };
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

const onSuccess = async (decodedText) => {
  const raw = (decodedText || "").trim();
  if (!raw) return;

  const now = Date.now();
  if (coolingDown) return;
  if (raw === lastCode && now - lastTime < 1500) return;

  lastCode = raw;
  lastTime = now;

  const normalized = normalizarCodigo(raw);
  const r = await validarBulto(normalized);

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

  $("#btn-salir")
    .off("click")
    .on("click", function () {
      const tx = db.transaction("expected", "readonly");
      const store = tx.objectStore("expected");

      let total = 0;
      let ok = 0;

      store.openCursor().onsuccess = function (e) {
        const cursor = e.target.result;
        if (cursor) {
          const v = cursor.value;
          const ret = v.retirado ?? 1;

          // ✅ SOLO ENTREGAS
          if (ret === 1) {
            total++;
            if (v.estado === "ok") ok++;
          }
          cursor.continue();
        } else {
          if (total > 0 && ok === total) window.location.href = "hdr.html";
          else mostrarToast(`⚠️ Faltan ${Math.max(total - ok, 0)} ENTREGAS`);
        }
      };
    });
});
