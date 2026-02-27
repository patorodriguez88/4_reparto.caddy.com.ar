// Warehouse: ir a warehouse.html
function borrarEscaneoBase(base, callback) {
  const t = db.transaction(["scanned", "expected", "bases_done"], "readwrite");
  const scanned = t.objectStore("scanned");
  const expected = t.objectStore("expected");
  const basesDone = t.objectStore("bases_done");

  // 1) borrar en scanned todos los registros de esa base (solo entregas)
  scanned.openCursor().onsuccess = function (e) {
    const c = e.target.result;
    if (c) {
      const v = c.value;
      const b = v.base || (v.code ? v.code.split("_")[0] : "");
      const ret = Number(v.retirado ?? 1);
      if (b === base && ret === 1) {
        c.delete();
      }
      c.continue();
      return;
    }

    // 2) revertir expected: todo lo de esa base vuelve a "pendiente" (excepto alias)
    expected.openCursor().onsuccess = function (e2) {
      const c2 = e2.target.result;
      if (c2) {
        const v2 = c2.value;
        const b2 = v2.base || (v2.code ? v2.code.split("_")[0] : "");
        const ret2 = Number(v2.retirado ?? 1);

        if (b2 === base && ret2 === 1) {
          v2.estado = "pendiente";
          expected.put(v2);
        }
        c2.continue();
        return;
      }

      // 3) base ya no está completa -> la saco de bases_done
      try {
        basesDone.delete(base);
      } catch (e) {}
    };
  };

  t.oncomplete = function () {
    if (typeof callback === "function") callback(true);
  };
  t.onerror = function () {
    console.error("borrarEscaneoBase error:", t.error);
    if (typeof callback === "function") callback(false);
  };
}

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
// ------------------------------------
// Obtener bases completadas (IndexedDB)
// ------------------------------------
function obtenerBasesDone(callback) {
  const t = db.transaction("bases_done", "readonly");
  const s = t.objectStore("bases_done");
  const bases = [];

  s.openCursor().onsuccess = function (e) {
    const c = e.target.result;
    if (!c) return callback(bases);
    bases.push(c.value.base);
    c.continue();
  };
}
function puedeSalir() {
  const t = db.transaction("expected", "readonly");
  const store = t.objectStore("expected");

  let pendientesEntrega = 0;

  store.openCursor().onsuccess = function (e) {
    const cursor = e.target.result;

    if (cursor) {
      const v = cursor.value;
      const ret = Number(v.retirado ?? 1);

      if (ret === 1 && v.estado !== "ok") pendientesEntrega++;
      cursor.continue();
      return;
    }

    console.log("pendientesEntrega:", pendientesEntrega);

    if (pendientesEntrega > 0) {
      saModal("warning", "Faltan entregas", "Hay ENTREGAS sin validar. No se puede salir.");
      return;
    }

    saToast("info", "Validando salida…", 900);

    // ✅ tomamos bases_done y enviamos
    obtenerBasesDone(function (basesDone) {
      console.log("Bases enviadas:", basesDone);

      $.ajax({
        url: "Proceso/php/warehouse.php",
        type: "POST",
        dataType: "json",
        data: {
          RegistrarWarehouseBatch: 1,
          bases: JSON.stringify(basesDone),
          state_id: 13,
        },
        success: function (res) {
          if (!res || res.success !== 1) {
            saModal("error", "Error", res && res.error ? res.error : "No se pudo registrar");
            return;
          }

          marcarEnTransitoBackend(function (ok2, r2) {
            if (!ok2) {
              saModal("error", "Error", r2.error || "No se pudo registrar En Tránsito");
              return;
            }

            saModal("success", "Listo", "Carga confirmada correctamente");
          });
        },
        error: function (xhr) {
          if (manejar401(xhr)) return;
          saModal("error", "Error", "No se pudo conectar con el servidor");
        },
      });
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
          const codigoSeguimiento = String(item.base ?? "").trim();
          if (!codigoSeguimiento) return;

          // 1) Normal (por CodigoSeguimiento)
          if (bultos === 1) {
            expected.put({
              code: codigoSeguimiento,
              base: codigoSeguimiento,
              estado: "pendiente",
              retirado: retirado,
              codigoSeguimiento: codigoSeguimiento,
              // meli_id: meliId,
            });
          } else {
            for (let i = 1; i <= bultos; i++) {
              expected.put({
                code: `${codigoSeguimiento}_${i}`,
                base: codigoSeguimiento,
                estado: "pendiente",
                retirado: retirado,
                codigoSeguimiento: codigoSeguimiento,
                // meli_id: meliId,
              });
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
  console.log("CLICK confirmar");

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
  const $whLista = $("#wh-lista"); // ✅ DEFINIDA ACÁ

  $whLista.empty();

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

        $whLista.append(`
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-2">
            <i class="mdi mdi-package-variant-closed mdi-18px text-success"></i>
            <span class="fw-semibold">${base}</span>
          </div>
          <div class="d-flex align-items-center gap-2">
            ${badge}
            <button type="button"
              class="wh-trash-btn"
              data-base="${base}"
              title="Borrar escaneo">
              <i class="mdi mdi-trash-can-outline mdi-18px text-danger"></i>
            </button>
          </div>
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
$(document).on("click", ".wh-trash-btn", function () {
  const base = $(this).data("base");
  if (!base) return;

  // Confirmación (opcional)
  if (typeof Swal !== "undefined") {
    Swal.fire({
      icon: "warning",
      title: "Borrar escaneo",
      text: `¿Querés borrar el escaneo de ${base}?`,
      showCancelButton: true,
      confirmButtonText: "Sí, borrar",
      cancelButtonText: "Cancelar",
    }).then((r) => {
      if (!r.isConfirmed) return;
      borrarEscaneoBase(base, () => {
        safeRenderScanned();
        actualizarHUD(1);
        saToast("success", "Escaneo borrado", 900);
      });
    });
  } else {
    borrarEscaneoBase(base, () => {
      safeRenderScanned();
      actualizarHUD(1);
      mostrarToast("Escaneo borrado");
    });
  }
});
(function initSwipeToDelete() {
  let startX = 0;
  let current = null;
  let moved = false;

  // cerrar otros abiertos
  function closeAll(except) {
    document.querySelectorAll(".wh-swipe.open").forEach((el) => {
      if (except && el === except) return;
      el.classList.remove("open");
    });
  }

  document.addEventListener("pointerdown", function (e) {
    const row = e.target.closest(".wh-swipe");
    if (!row) return;

    closeAll(row);

    startX = e.clientX;
    current = row;
    moved = false;
  });

  document.addEventListener("pointermove", function (e) {
    if (!current) return;

    const dx = e.clientX - startX;
    if (Math.abs(dx) < 8) return;

    moved = true;

    // swipe izquierda
    if (dx < -30) current.classList.add("open");
    // swipe derecha
    if (dx > 30) current.classList.remove("open");
  });

  document.addEventListener("pointerup", function () {
    current = null;
    moved = false;
  });

  // tap afuera cierra
  document.addEventListener("click", function (e) {
    const row = e.target.closest(".wh-swipe");
    const isTrash = e.target.closest(".wh-trash-btn");
    if (isTrash) return; // no cierres antes del click
    if (!row) closeAll(null);
  });
})();
