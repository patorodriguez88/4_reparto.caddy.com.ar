// Warehouse: ir a warehouse.html
$(document).on("click", '.app-bottomnav .nav-item[data-action="warehouse"]', function (e) {
  e.preventDefault();
  window.location.href = "warehouse.html";
});
// puente simple: botón salir dentro de Cuenta
$(document).on("click", "#btnCuentaSalir", function () {
  $("#salir").trigger("click");
});
$(document).on("click", "#wh-salir", function (e) {
  e.preventDefault();

  $.ajax({
    data: { Salir: 1 },
    type: "POST",
    url: "../../SistemaReparto/Conexion/admision.php",
    success: function () {
      window.location.href = "hdr.html";
    },
    error: function (xhr) {
      console.error("Error cerrar sesión:", xhr.responseText);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "No se pudo cerrar sesión.",
      });
    },
  });
});
let renderRunning = false;
let renderQueued = false;
let __allScannedToastShown = false;

function cargarHeaderWarehouse() {
  return $.ajax({
    url: "Proceso/php/funciones.php",
    type: "POST",
    dataType: "json",
    data: { Datos: 1 },
  })
    .done(function (jsonData) {
      if (jsonData && jsonData.success == 1) {
        $("#hdr-header").html(`H: ${jsonData.NOrden} R: ${jsonData.Recorrido}`);
        $("#badge-total").html(jsonData.Total);
        $("#badge-sinentregar").html(jsonData.Abiertos);
        $("#badge-entregados").html(jsonData.Cerrados);
      } else {
        console.warn("Header Datos no OK:", jsonData);
      }
    })
    .fail(function (xhr) {
      console.warn("No se pudo cargar header:", xhr.status, xhr.responseText);
      if (manejar401(xhr)) return;
    });
}
function marcarEnTransitoBackend(done) {
  $.ajax({
    url: "Proceso/php/warehouse.php",
    type: "POST",
    dataType: "json",
    data: { PuedeSalir: 1 }, // o EnTransito:1 (como prefieras)
    success: function (res) {
      if (res && res.success == 1) {
        done(true, res);
      } else {
        done(false, res || { error: "Respuesta inválida" });
      }
    },
    error: function (xhr) {
      if (manejar401(xhr)) return;
      done(false, {
        error: "Error de conexión",
        detail: xhr.responseText || "",
      });
    },
  });
}

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
function validarCacheConBackend(done) {
  // 1) leo hash guardado
  const reqHash = tx("meta").get("hash");
  reqHash.onsuccess = function () {
    const localHash = reqHash.result ? reqHash.result.value : "";

    // 2) pido hash actual al backend (sin reescribir todo)
    $.ajax({
      url: "Proceso/php/warehouse.php",
      type: "POST",
      dataType: "json",
      data: { GetLista: 1, solo_hash: 1 },
      success: function (res) {
        if (!res || res.success !== 1) {
          if (res && (res.logged === 0 || res.reason === "NO_IDUSUARIO")) {
            window.location.href = "hdr.html";
            return;
          }
          done(true);
          return;
        }
        const remoteHash = res.hash || "";
        done(localHash !== "" && localHash === remoteHash);
      },
      error: function (xhr) {
        if (manejar401(xhr)) return;
        done(true);
      },
    });
  };

  reqHash.onerror = function () {
    done(false);
  };
}

$(document).ready(function () {
  abrirDB(() => {
    $("#navbar").show();
    $("#topnav").show();
    cargarHeaderWarehouse();

    const reqCount = tx("expected").count();

    reqCount.onsuccess = function () {
      const expectedCount = reqCount.result || 0;

      if (expectedCount === 0) {
        console.log("🆕 expected vacío → cargando lista desde backend");
        cargarLista();
        return;
      }

      // expected tiene datos → validamos si cache sigue vigente
      validarCacheConBackend(function (okToUseCache) {
        if (okToUseCache) {
          console.log("✅ Cache vigente → render local");
          cargarRecorridoLocal();
          safeRenderScanned();
        } else {
          console.log("♻️ Cache viejo → recargando desde backend");
          cargarLista();
        }
      });
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
  const t = db.transaction("expected", "readonly");
  const store = t.objectStore("expected");

  let pendientesEntrega = 0;

  store.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;
    if (cursor) {
      const v = cursor.value;
      const ret = Number(v.retirado ?? 1);

      if (ret === 1 && v.estado === "pendiente") pendientesEntrega++;
      cursor.continue();
      return;
    }

    if (pendientesEntrega > 0) {
      saModal("warning", "Faltan entregas", "Hay ENTREGAS sin validar. No se puede salir.");
      return;
    }

    // ✅ Todo OK local → ahora persistimos “En Tránsito” en backend
    saToast("info", "Validando salida…", 900);

    marcarEnTransitoBackend(function (ok, res) {
      if (!ok) {
        saModal("error", "No se pudo registrar En Tránsito", res.error || "Error");
        return;
      }

      saModal("success", "Listo", "Entregas validadas. Envíos en tránsito.");
      // si querés redirigir automáticamente:
      // setTimeout(() => (window.location.href = "hdr.html"), 900);
    });
  };
}
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
        // ✅ UNA sola transacción para todo
        const t = db.transaction(["expected", "meta"], "readwrite");
        const expected = t.objectStore("expected");
        const meta = t.objectStore("meta");

        let totalEntregas = 0;

        // ✅ ACÁ adentro va el forEach (NO afuera)
        res.items.forEach((item) => {
          const bultos = parseInt(item.bultos, 10) || 1;
          const retirado = Number(item.retirado); // 0 o 1

          const codigoSeguimiento = (item.base || "").trim(); // tu código interno
          const meliId = (item.meli_id || "").trim(); // shipment_id (si backend lo manda)

          if (!codigoSeguimiento) return;

          // 1) Normal (por CodigoSeguimiento)
          if (bultos === 1) {
            expected.put({
              code: codigoSeguimiento,
              base: codigoSeguimiento,
              estado: "pendiente",
              retirado: retirado,
              codigoSeguimiento: codigoSeguimiento,
              meli_id: meliId,
            });
          } else {
            for (let i = 1; i <= bultos; i++) {
              expected.put({
                code: `${codigoSeguimiento}_${i}`,
                base: codigoSeguimiento,
                estado: "pendiente",
                retirado: retirado,
                codigoSeguimiento: codigoSeguimiento,
                meli_id: meliId,
              });
            }
          }

          // 2) Alias ML (por meli_id)
          if (meliId) {
            if (bultos === 1) {
              expected.put({
                code: meliId,
                base: codigoSeguimiento,
                estado: "pendiente",
                retirado: retirado,
                codigoSeguimiento: codigoSeguimiento,
                meli_id: meliId,
              });
            } else {
              for (let i = 1; i <= bultos; i++) {
                expected.put({
                  code: `${meliId}_${i}`,
                  base: codigoSeguimiento,
                  estado: "pendiente",
                  retirado: retirado,
                  codigoSeguimiento: codigoSeguimiento,
                  meli_id: meliId,
                });
              }
            }
          }

          if (retirado === 1) totalEntregas += bultos;
        });

        meta.put({ key: "recorrido", value: res.recorrido });
        meta.put({ key: "total", value: totalEntregas });
        meta.put({ key: "hash", value: res.hash });

        t.oncomplete = function () {
          cargarRecorridoLocal();
          actualizarHUD(1);
          safeRenderScanned();
          saToast("success", `Lista cargada: ${totalEntregas} entregas`, 1200);
        };

        t.onerror = function () {
          console.error("Error guardando expected/meta", t.error);
          saToast("error", "Error guardando en IndexedDB", 1600);
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
// function cargarLista() {
//   $.ajax({
//     url: "Proceso/php/warehouse.php",
//     type: "POST",
//     dataType: "json",
//     data: { GetLista: 1 },
//     success: function (res) {
//       if (res.success !== 1) {
//         saModal("error", "Error", res.error || "Error cargando lista");
//         return;
//       }

//       $("#wh-recorrido").text(res.recorrido);

//       limpiarDB(() => {
//         // ✅ UNA sola transacción para todo
//         const t = db.transaction(["expected", "meta"], "readwrite");
//         const expected = t.objectStore("expected");
//         const meta = t.objectStore("meta");

//         let totalEntregas = 0;

//         res.items.forEach((item) => {
//           const bultos = parseInt(item.bultos, 10) || 1;
//           const retirado = Number(item.retirado); // 0 o 1
//           const base = (item.base || "").trim();
//           const meliId = (item.meli_id || "").trim(); // shipment_id si lo tenés

//           if (!base) return;

//           if (bultos === 1) {
//             expected.put({
//               code: base,
//               base: base,
//               estado: "pendiente",
//               retirado: retirado,
//             });
//           } else {
//             for (let i = 1; i <= bultos; i++) {
//               expected.put({
//                 code: `${base}_${i}`,
//                 base: base,
//                 estado: "pendiente",
//                 retirado: retirado,
//               });
//             }
//           }

//           if (retirado === 1) totalEntregas += bultos;
//         });

//         meta.put({ key: "recorrido", value: res.recorrido });
//         meta.put({ key: "total", value: totalEntregas });
//         meta.put({ key: "hash", value: res.hash });
//         meta.put({ key: "recorrido", value: res.recorrido });
//         // ✅ recién acá, cuando terminó de grabar TODO
//         t.oncomplete = function () {
//           cargarRecorridoLocal();
//           actualizarHUD(1);
//           safeRenderScanned();
//           saToast("success", `Lista cargada: ${totalEntregas} entregas`, 1200);
//         };

//         t.onerror = function () {
//           console.error("Error guardando expected/meta", t.error);
//           saToast("error", "Error guardando en IndexedDB", 1600);
//         };
//       });
//     },
//     error: function (xhr) {
//       if (manejar401(xhr)) return;
//       console.error(xhr.responseText);
//       saToast("error", "Error de conexión cargando lista", 1600);
//     },
//   });
// }
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
function actualizarHUD(retiradoObjetivo = 1) {
  const t = db.transaction(["expected"], "readonly");
  const store = t.objectStore("expected");

  let total = 0;
  let ok = 0;

  store.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;
    if (cursor) {
      const v = cursor.value;
      // const ret = v.retirado ?? 1;
      const ret = Number(v.retirado);

      if (ret === retiradoObjetivo) {
        total++;
        if (v.estado === "ok") ok++;
      }
      cursor.continue();
    } else {
      const faltantes = Math.max(total - ok, 0);

      $("#wh-esperados").text(total);
      $("#wh-ok").text(ok);
      $("#wh-faltantes").text(faltantes);

      if (faltantes === 0 && total > 0) {
        $("#btn-confirmar").prop("disabled", false);
        $(".btn-primary[onclick='irAScan()']").hide();
      } else {
        $("#btn-confirmar").prop("disabled", true);
        $(".btn-primary[onclick='irAScan()']").show();
      }
    }
  };
}

function renderScanned(done) {
  $("#wh-lista").empty();

  const t = db.transaction(["scanned", "expected"], "readonly");
  const scannedStore = t.objectStore("scanned");
  const expectedStore = t.objectStore("expected");

  const scannedCount = {}; // { base: {entrega} }
  const expectedCount = {}; // { base: {entrega} }

  // 1) leo SCANNED -> SOLO ENTREGAS (retirado=1)
  scannedStore.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;
    if (cursor) {
      const v = cursor.value;
      const base = v.base || (v.code ? v.code.split("_")[0] : "");
      const ret = v.retirado ?? 1;

      if (base && ret === 1) {
        if (!scannedCount[base]) scannedCount[base] = { entrega: 0 };
        scannedCount[base].entrega++;
      }

      cursor.continue();
      return;
    }

    // 2) leo EXPECTED (para saber el total por base)
    expectedStore.openCursor().onsuccess = function (e2) {
      const c2 = e2.target.result;
      if (c2) {
        const v = c2.value;
        const base = v.base || (v.code ? v.code.split("_")[0] : "");
        const ret = v.retirado ?? 1;

        if (base && ret === 1) {
          if (!expectedCount[base]) expectedCount[base] = { entrega: 0 };
          expectedCount[base].entrega++;
        }

        c2.continue();
        return;
      }

      // 3) ✅ render SOLO bases que fueron escaneadas
      const bases = Object.keys(scannedCount).sort();

      bases.forEach((base) => {
        const okE = scannedCount[base]?.entrega || 0;
        const totE = expectedCount[base]?.entrega || 0;

        const cls = totE > 0 && okE === totE ? "bg-success" : "bg-warning text-dark";
        const badge =
          totE > 0
            ? `<span class="badge ${cls} ms-2">${okE}/${totE}</span>`
            : `<span class="badge bg-secondary ms-2">${okE}</span>`;

        $("#wh-lista").append(`
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>🟢 ${base}</div>
            <div>${badge}</div>
          </li>
        `);
      });

      // mantiene tu HUD (si ya lo ajustaste a “solo entregas”)
      actualizarHUD(1);

      if (typeof done === "function") done();
    };
  };
}

window.addEventListener("pageshow", function () {
  // cuando vuelvo desde scan.html
  try {
    cargarHeaderWarehouse();
    cargarRecorridoLocal();
    safeRenderScanned(); // 👈 NO llames renderScanned directo
  } catch (e) {}
});

$("#mi_recorrido").on("click", function (e) {
  e.preventDefault();

  const t = db.transaction("expected", "readonly");
  const store = t.objectStore("expected");

  let pendientesEntrega = 0;

  store.openCursor().onsuccess = function (ev) {
    const cursor = ev.target.result;
    if (cursor) {
      const v = cursor.value;
      const ret = v.retirado ?? 1;

      // ✅ SOLO ENTREGAS bloquean
      if (ret === 1 && v.estado === "pendiente") {
        pendientesEntrega++;
      }

      cursor.continue();
      return;
    }

    if (pendientesEntrega > 0) {
      saModal("warning", "Todavía faltan", `Todavía hay ${pendientesEntrega} ENTREGAS sin escanear.`);
      return;
    }

    // Todo validado (ENTREGAS) → volvemos a HDR
    saToast("success", "Entregas validadas. Volviendo a HDR…", 900);
    window.location.href = "hdr.html";
  };
});
