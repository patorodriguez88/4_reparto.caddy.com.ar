let html5QrCode = null;
let lastCode = "";
let lastTime = 0;
let userInteracted = false;
let coolingDown = false; // 👈 ESTA LÍNEA FALTABA
let feedbackTimeout = null;
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
      if (
        v.base === base &&
        (v.retirado ?? 1) === retiradoObjetivo &&
        v.estado !== "ok"
      ) {
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
  { once: true }
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
  // if (!raw) return "";
  // return raw.includes("_") ? raw : raw + "_1";
  // return raw;
  return (raw || "").trim();
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
      id:
        crypto && crypto.randomUUID
          ? crypto.randomUUID()
          : Date.now() + "_" + Math.random(),
      code: code,
      base: item.base,
      retirado: item.retirado ?? 1,
      ts: Date.now(),
    });
    t.oncomplete = function () {
      try {
        actualizarEstado(1);
      } catch (e) {}

      const base = item.base;
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

        // c) Si no existe ni BASE ni _1 => NO permitir “autonumerar”
        mostrarFeedback(
          "⚠️ Envío con múltiples bultos: escaneá el QR con _2, _3, etc.",
          "warn"
        );
        return resolve("requiere_sufijo");
      };
    };
  });
}
// --------------------
// Scanner
// --------------------
async function startScanner() {
  if (!("Html5Qrcode" in window)) {
    alert("No se cargó la librería QR (html5-qrcode).");
    return;
  }

  html5QrCode = new Html5Qrcode("qr-reader");

  const config = {
    fps: 12,
    qrbox: { width: 360, height: 360 },
    aspectRatio: 1,
  };

  const onSuccess = async (decodedText) => {
    const raw = (decodedText || "").trim();
    if (!raw) return;

    // anti-rebote + cooldown
    const now = Date.now();
    if (coolingDown) return;
    if (raw === lastCode && now - lastTime < 1500) return;

    lastCode = raw;
    lastTime = now;

    const normalized = normalizarCodigo(raw);

    // validarBulto debería devolver: "ok" | "ya_ok" | "no_pertenece"
    const r = await validarBulto(normalized);

    // Si lo marcó OK recién, frenamos un toque el scanner
    if (r === "ok") {
      coolingDown = true;

      try {
        // html5-qrcode soporta pause/resume en varias versiones
        if (html5QrCode && typeof html5QrCode.pause === "function") {
          html5QrCode.pause(true);
          setTimeout(() => {
            try {
              html5QrCode.resume();
            } catch (e) {}
            coolingDown = false;
          }, 900);
        } else {
          // fallback: cooldown sin pausar cámara
          setTimeout(() => (coolingDown = false), 900);
        }
      } catch (e) {
        setTimeout(() => (coolingDown = false), 900);
      }
    }
  };

  const onError = () => {}; // silencioso

  try {
    // preferimos cámara trasera si existe
    const cams = await Html5Qrcode.getCameras();
    if (cams && cams.length) {
      const cam = cams[cams.length - 1]; // suele ser back
      await html5QrCode.start(
        { deviceId: { exact: cam.id } },
        config,
        onSuccess,
        onError
      );
    } else {
      await html5QrCode.start(
        { facingMode: "environment" },
        config,
        onSuccess,
        onError
      );
    }
  } catch (e) {
    console.error(e);
    alert("No se pudo abrir la cámara. Revisá permisos (HTTPS o localhost).");
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
