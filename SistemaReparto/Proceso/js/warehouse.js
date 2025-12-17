let renderRunning = false;
let renderQueued = false;
let __allScannedToastShown = false;

function safeRenderScanned() {
  if (renderRunning) {
    renderQueued = true;
    return;
  }
  renderRunning = true;

  // llamamos al render real
  renderScanned(() => {
    renderRunning = false;
    if (renderQueued) {
      renderQueued = false;
      safeRenderScanned();
    }
  });
}
// ------------------------------
// SweetAlert2 helpers (reemplazo de alert())
// ------------------------------
function saAvailable() {
  return typeof window !== "undefined" && typeof window.Swal !== "undefined";
}

function saToast(icon, title, timer = 1100) {
  if (!saAvailable()) {
    // fallback
    try {
      console.log(`[${icon}] ${title}`);
    } catch (e) {}
    return;
  }

  Swal.fire({
    toast: true,
    position: "top",
    icon: icon,
    title: title,
    showConfirmButton: false,
    timer: timer,
    timerProgressBar: true,
  });
}

function saModal(icon, title, text = "", timer = null) {
  if (!saAvailable()) {
    // fallback
    alert(text ? `${title}\n${text}` : title);
    return;
  }

  const opts = {
    icon,
    title,
    text,
    confirmButtonText: "OK",
  };

  if (timer) {
    opts.showConfirmButton = false;
    opts.timer = timer;
    opts.timerProgressBar = true;
  }

  Swal.fire(opts);
}

$(document).ready(function () {
  abrirDB(() => {
    $("#navbar").show();
    $("#topnav").show();

    const reqCount = tx("expected").count();

    reqCount.onsuccess = function () {
      const expectedCount = reqCount.result || 0;

      if (expectedCount === 0) {
        console.log("🆕 expected vacío → cargando lista desde backend");
        cargarLista();
        return;
      }

      console.log("🔁 expected con datos → render local");
      cargarRecorridoLocal();
      safeRenderScanned(); // 👈 en vez de renderScanned + actualizarHUD
    };

    reqCount.onerror = function () {
      console.warn("No se pudo contar expected, recargando lista");
      cargarLista();
    };
  });
});
function cargarRecorridoLocal() {
  const req = tx("meta").get("recorrido");
  req.onsuccess = function () {
    if (req.result) {
      $("#wh-recorrido").text(req.result.value);
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

let lastCode = "";
let lastTime = 0;

function puedeSalir() {
  const tx = db.transaction("expected", "readonly");
  const store = tx.objectStore("expected");
  const index = store.index("estado");

  const req = index.getAll("pendiente");

  req.onsuccess = function () {
    if (req.result.length > 0) {
      saModal(
        "warning",
        "Faltan bultos",
        "Hay bultos sin validar. No se puede salir."
      );
    } else {
      saModal(
        "success",
        "Listo",
        "Todos los bultos fueron validados. Podés continuar."
      );
      // acá después avisamos al backend
    }
  };
}

// function limpiarDB(callback) {
//   const t1 = db.transaction(["expected", "scanned", "meta"], "readwrite");
//   t1.objectStore("expected").clear();
//   t1.objectStore("scanned").clear();
//   t1.objectStore("meta").put({ key: "total", value: 0 });
//   t1.oncomplete = () => callback();
// }
function limpiarDB(callback) {
  const t1 = db.transaction(["expected", "scanned", "bases_done"], "readwrite");
  t1.objectStore("expected").clear();
  t1.objectStore("scanned").clear();
  t1.objectStore("bases_done").clear();
  t1.oncomplete = () => callback();
}

function guardarBulto(code, base, retirado) {
  const tx = db.transaction("expected", "readwrite");
  const store = tx.objectStore("expected");

  store.put({
    code: code,
    base: base,
    estado: "pendiente",
    retirado: retirado, // 👈 0=RETIRO, 1=ENTREGA
  });
}

function cargarLista() {
  $.ajax({
    url: "Proceso/php/warehouse.php",
    type: "POST",
    dataType: "json",
    data: { GetLista: 1 },
    success: function (res) {
      if (res.success !== 1) {
        saModal("error", "Error", res.error || "Error cargando lista");
        return;
      }

      $("#wh-recorrido").text(res.recorrido);

      limpiarDB(() => {
        let total = 0;

        res.items.forEach((item) => {
          const bultos = parseInt(item.bultos, 10) || 1;
          const retirado = parseInt(item.retirado, 10) || 0; // 👈 viene del backend

          if (bultos === 1) {
            guardarBulto(item.base, item.base, retirado);
            total++;
          } else {
            for (let i = 1; i <= bultos; i++) {
              guardarBulto(`${item.base}_${i}`, item.base, retirado);
              total++;
            }
          }
        });

        const metaTx = db.transaction("meta", "readwrite");
        const meta = metaTx.objectStore("meta");
        meta.put({ key: "recorrido", value: res.recorrido });
        meta.put({ key: "total", value: total });

        metaTx.oncomplete = function () {
          cargarRecorridoLocal();
          renderScanned();
          actualizarHUD();
        };
      });
    },
    error: function (xhr) {
      if (manejar401(xhr)) return;
      console.error(xhr.responseText);
      saToast("error", "Error de conexión cargando lista", 1600);
    },
  });
}

function renderDesdeDB(totalEsperados) {
  $("#wh-lista").html("");

  const tx = db.transaction("expected", "readonly");
  const store = tx.objectStore("expected");

  let ok = 0;

  store.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;
    if (cursor) {
      const item = cursor.value;

      let icon = "⬜";
      if (item.estado === "ok") {
        icon = "🟢";
        ok++;
      }

      $("#wh-lista").append(`
        <li class="list-group-item" data-code="${item.code}">
          ${icon} ${item.code}
        </li>
      `);

      cursor.continue();
    } else {
      $("#wh-esperados").text(totalEsperados);
      $("#wh-ok").text(ok);
      $("#wh-faltantes").text(totalEsperados - ok);
    }
  };
}

function agregarBulto(codigo) {
  $("#wh-lista").append(`
    <li class="list-group-item" data-code="${codigo}">
      ⬜ ${codigo}
    </li>
  `);
}
//BOTON CONFIRMAR
$("#btn-confirmar").click(function () {
  puedeSalir();
});

function irAScan() {
  window.location.href = "scan.html";
}
function getTotal(callback) {
  const req = tx("meta").get("total");
  req.onsuccess = () => callback(req.result ? req.result.value : 0);
}

function contarScanned(callback) {
  const req = tx("scanned").count();
  req.onsuccess = () => callback(req.result || 0);
}

// function actualizarHUD() {
//   getTotal((total) => {
//     contarScanned((ok) => {
//       $("#wh-esperados").text(total);
//       $("#wh-ok").text(ok);
//       $("#wh-faltantes").text(Math.max(total - ok, 0));
//     });
//   });
// }
function actualizarHUD() {
  getTotal((total) => {
    contarScanned((ok) => {
      const faltantes = Math.max(total - ok, 0);

      $("#wh-esperados").text(total);
      $("#wh-ok").text(ok);
      $("#wh-faltantes").text(faltantes);

      // 🎯 LÓGICA DE BOTONES
      if (faltantes === 0 && total > 0) {
        // Todo escaneado
        $("#btn-confirmar").prop("disabled", false);
        $(".btn-primary[onclick='irAScan()']").hide();

        $("#scan-status-msg").remove(); // evita duplicar
        $("#wh-lista").before(`
          <div id="scan-status-msg" class="alert alert-success text-center">
            ✅ Todos los bultos fueron escaneados
          </div>
        `);
        if (!__allScannedToastShown) {
          __allScannedToastShown = true;
          saToast("success", "✅ Todos los bultos escaneados", 1100);
        }
      } else {
        // Faltan bultos
        $("#btn-confirmar").prop("disabled", true);
        $(".btn-primary[onclick='irAScan()']").show();
        $("#scan-status-msg").remove();
        __allScannedToastShown = false;
      }
    });
  });
}
// ✅ SOLO muestra lo escaneado
function renderScanned(done) {
  $("#wh-lista").empty(); // mejor que html("")

  const t = db.transaction(["scanned", "expected"], "readonly");
  const scannedStore = t.objectStore("scanned");
  const expectedStore = t.objectStore("expected");

  const scannedCount = {};
  const expectedCount = {};

  scannedStore.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;
    if (cursor) {
      const v = cursor.value;
      const base = v.base || (v.code ? v.code.split("_")[0] : "");
      if (base) scannedCount[base] = (scannedCount[base] || 0) + 1;
      cursor.continue();
      return;
    }

    expectedStore.openCursor().onsuccess = function (e2) {
      const c2 = e2.target.result;
      if (c2) {
        const v = c2.value;
        const base = v.base || (v.code ? v.code.split("_")[0] : "");
        if (base) expectedCount[base] = (expectedCount[base] || 0) + 1;
        c2.continue();
        return;
      }

      const bases = Object.keys(scannedCount).sort();
      bases.forEach((base) => {
        const ok = scannedCount[base] || 0;
        const total = expectedCount[base] || 0;

        let badge = "";
        if (total > 1) {
          const cls = ok === total ? "bg-success" : "bg-warning text-dark";
          badge = `<span class="badge ${cls} ms-2">${ok}/${total}</span>`;
        }

        $("#wh-lista").append(`
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>🟢 ${base}</div>
            <div>${badge}</div>
          </li>
        `);
      });

      actualizarHUD();
      if (typeof done === "function") done();
    };
  };

  t.oncomplete = function () {
    // por si algún navegador completa antes del último onsuccess (raro)
    // no hacemos nada acá para no duplicar; el done lo llamamos al final del cursor expected.
  };
}
window.addEventListener("pageshow", function () {
  // cuando vuelvo desde scan.html
  try {
    cargarRecorridoLocal();
    safeRenderScanned(); // 👈 NO llames renderScanned directo
  } catch (e) {}
});

$("#mi_recorrido").on("click", function (e) {
  e.preventDefault();

  // Verificamos si está todo OK antes de salir
  const tx = db.transaction("expected", "readonly");
  const store = tx.objectStore("expected");
  const index = store.index("estado");

  const req = index.getAll("pendiente");

  req.onsuccess = function () {
    if (req.result.length > 0) {
      saModal("warning", "Todavía faltan", "Todavía hay bultos sin escanear.");
      return;
    }

    // Todo validado → volvemos a HDR
    saToast("success", "Carga completa. Volviendo a HDR…", 900);
    window.location.href = "hdr.html";
  };
});
