// Proceso/js/colecta_scan.js
(function () {
  let colectaQr = null;
  let colectaLast = "";
  let colectaLastT = 0;

  const codigosEscaneados = new Set(); // guarda BASE_1, BASE_2, etc.
  function swalFire(opts) {
    if (window.Swal && Swal.fire) return Swal.fire(opts);
    alert((opts.title ? opts.title + "\n" : "") + (opts.text || ""));
  }
  function esModoColecta() {
    return (
      ($("#card-servicio").text() || "").trim().toUpperCase() === "COLECTA"
    );
  }

  function getColectaExpected() {
    return window.colectaExpected || null;
  }

  function getServicioEsperadoPorBase(base) {
    const exp = getColectaExpected();
    if (!exp || !Array.isArray(exp.servicios_detalle)) return null;
    return (
      exp.servicios_detalle.find(
        (s) => String(s.cs_base).trim() === String(base).trim(),
      ) || null
    );
  }
  function getExpectedBase() {
    // card-seguimiento puede venir BASE o BASE_1
    const raw = ($("#card-seguimiento").text() || "").trim();
    return raw ? raw.split("_")[0].trim() : "";
  }

  function getCantidadEsperada() {
    const txt = ($("#card-receptor-cantidad").text() || "").trim();
    const n = parseInt(txt, 10);
    return isNaN(n) ? 1 : n;
  }

  function addToSelect2(code) {
    const $sel = $("#prueba");

    if ($sel.find(`option[value="${code}"]`).length === 0) {
      const opt = new Option(code, code, true, true);
      $sel.append(opt);
    } else {
      $sel.find(`option[value="${code}"]`).prop("selected", true);
    }

    $sel.trigger("change");
  }

  function getSelectedValues() {
    const v = $("#prueba").val();
    return Array.isArray(v) ? v : [];
  }
  function postColectaBulto(base, token, cantidad = 1) {
    const idColecta = esModoColecta()
      ? parseInt(window.idColectaActual, 10) || 0
      : 0;

    return $.ajax({
      url: "Proceso/php/colecta_scan.php",
      type: "POST",
      dataType: "json",
      data: {
        ColectaBulto: 1,
        idColecta: idColecta, // 👈 NUEVO
        base: base,
        bulto: token,
        cantidad: cantidad,
      },
    });
  }

  async function stopScanner() {
    try {
      if (colectaQr) {
        await colectaQr.stop();
        await colectaQr.clear();
        colectaQr = null;
      }
    } catch (e) {}
  }

  async function startScanner() {
    if (!("Html5Qrcode" in window)) {
      swalFire({
        icon: "error",
        title: "Falta librería",
        text: "No se cargó html5-qrcode",
      });
      return;
    }

    await stopScanner();
    colectaQr = new Html5Qrcode("colecta-qr-reader");

    const expectedBase = getExpectedBase();
    const qtyExpected = getCantidadEsperada();

    // Mostrar esperado en el modal (si tenés esos spans)
    $("#colecta-expected").text(expectedBase || "—");
    $("#colecta-expected-qty").text(qtyExpected || 1);

    const config = {
      fps: 12,
      qrbox: { width: 320, height: 320 },
      aspectRatio: 1,
    };
    function extraerIdDesdeJson(raw) {
      const t = (raw || "").trim();
      if (!t.startsWith("{") || !t.endsWith("}")) return null;
      try {
        const obj = JSON.parse(t);
        return obj?.id ? String(obj.id).trim() : null;
      } catch (e) {
        return null;
      }
    }

    const onSuccess = async (decodedText) => {
      const raw = (decodedText || "").trim();
      if (!raw) return;

      // anti-rebote
      const now = Date.now();
      if (raw === colectaLast && now - colectaLastT < 900) return;
      colectaLast = raw;
      colectaLastT = now;

      const expectedBase = ($("#card-seguimiento").text() || "")
        .trim()
        .split("_")[0]
        .trim();

      let qtyExpected =
        parseInt(($("#card-receptor-cantidad").text() || "1").trim(), 10) || 1;

      // 🔎 Detectar QR JSON
      const jsonId = extraerIdDesdeJson(raw);

      let scannedToken = raw; // lo que queda en Observaciones
      let base = "";
      let esQRML = false;

      let hasN = false;

      if (jsonId) {
        esQRML = true;

        // 🟢 QR de MercadoLibre (JSON)
        scannedToken = jsonId; // auditoría
        base = jsonId; // backend resuelve shipments_id → CodigoSeguimiento
        // qtyExpected = 1; // por ahora siempre 1
        // hasN = false;
      } else {
        // 🟢 QR normal (BASE o BASE_2)
        base = raw.split("_")[0].trim();
        hasN = raw.includes("_");
      }

      // 1️⃣ validar que haya envío abierto
      if (!expectedBase) {
        swalFire({
          icon: "warning",
          title: "Sin envío",
          text: "Abrí un envío antes de escanear.",
        });
        return;
      }

      // 2️⃣ VALIDACIÓN DE BASE
      if (esModoColecta()) {
        // En colecta: la base debe existir en servicios_detalle
        if (!jsonId) {
          const svc = getServicioEsperadoPorBase(base);
          if (!svc) {
            swalFire({
              icon: "error",
              title: "Servicio fuera de la colecta",
              text: `El servicio ${base} no pertenece a esta colecta.`,
              timer: 1400,
              showConfirmButton: false,
            });
            return;
          }
        }
      } else {
        // En retiro normal: base debe coincidir con expectedBase
        if (!jsonId && base !== expectedBase) {
          swalFire({
            icon: "error",
            title: "Código incorrecto",
            text: `Escaneaste ${base} y se esperaba ${expectedBase}`,
            timer: 1400,
            showConfirmButton: false,
          });
          return;
        }
      }
      let paquetesSvc = 1; // default
      // 3️⃣ múltiples bultos → exigir sufijo
      if (esModoColecta()) {
        if (!jsonId) {
          const svc = getServicioEsperadoPorBase(base);
          paquetesSvc = parseInt(svc?.paquetes || 1, 10) || 1;
          if (paquetesSvc > 1 && !hasN) {
            swalFire({
              icon: "info",
              title: "Falta el sufijo",
              text: `Para el servicio ${base} necesitás ${base}_1 / ${base}_2 / ...`,
              timer: 1400,
              showConfirmButton: false,
            });
            return;
          }
        }
      } else {
        // Retiro normal
        if (!jsonId && qtyExpected > 1 && !hasN) {
          swalFire({
            icon: "info",
            title: "Falta el sufijo",
            text: "Para este envío necesitás escanear BASE_1 / BASE_2 / BASE_3…",
            timer: 1400,
            showConfirmButton: false,
          });
          return;
        }
      }
      // ✅ NUEVO: validar rango del sufijo _n SOLO en COLECTA y QR normal
      if (esModoColecta() && !jsonId) {
        if (paquetesSvc > 1) {
          const parts = raw.split("_");
          const suf = parts.length > 1 ? parseInt(parts[1], 10) : NaN;

          if (!Number.isInteger(suf) || suf < 1 || suf > paquetesSvc) {
            swalFire({
              icon: "error",
              title: "Bulto inválido",
              text: `Para ${base} sólo se permiten ${base}_1 … ${base}_${paquetesSvc}`,
              timer: 1600,
              showConfirmButton: false,
            });
            return;
          }
        } else {
          // paquetesSvc === 1 → NO debería venir con _n
          if (hasN) {
            swalFire({
              icon: "error",
              title: "Bulto inválido",
              text: `El servicio ${base} tiene 1 bulto. Escaneá ${base} (sin sufijo).`,
              timer: 1600,
              showConfirmButton: false,
            });
            return;
          }
        }
      }

      // 4️⃣ normalización del código a guardar
      let codeToStore;

      if (jsonId) {
        // ML: guardamos el identificador (por ahora)
        codeToStore = base;
      } else if (esModoColecta()) {
        const svc = getServicioEsperadoPorBase(base);
        const paquetesSvc = parseInt(svc?.paquetes || 1, 10) || 1;

        codeToStore = paquetesSvc <= 1 ? base : raw;
      } else {
        // Retiro normal
        codeToStore = qtyExpected <= 1 ? expectedBase : raw;
      }

      // 5️⃣ anti-duplicado
      if (codigosEscaneados.has(codeToStore)) {
        swalFire({
          icon: "info",
          title: "Ya escaneado",
          text: codeToStore,
          timer: 900,
          showConfirmButton: false,
        });
        return;
      }

      // 6️⃣ guardar y mostrar
      codigosEscaneados.add(codeToStore);
      addToSelect2(codeToStore);

      if (esQRML && qtyExpected > 1) {
        Swal.fire({
          icon: "question",
          title: "Confirmar cantidad de bultos",
          html: `
      <p>Este envío indica <b>${qtyExpected}</b> bultos.</p>
      <p>¿Cuántos tenés físicamente?</p>
      <input 
        type="number" 
        id="confirmQty" 
        class="swal2-input"
        min="1"
        max="${qtyExpected}"
        value="${qtyExpected}">
    `,
          showCancelButton: true,
          confirmButtonText: "Confirmar",
          cancelButtonText: "Cancelar",
          preConfirm: () => {
            const v = parseInt(document.getElementById("confirmQty").value, 10);
            if (!v || v < 1) {
              Swal.showValidationMessage("Ingresá una cantidad válida");
              return false;
            }
            return v;
          },
        }).then(async (res) => {
          if (!res.isConfirmed) return;

          const cantidadConfirmada = res.value;

          await postColectaBulto(base, scannedToken, cantidadConfirmada);

          window.colectaML = window.colectaML || {};
          window.colectaML.confirmedQty = cantidadConfirmada;
          window.colectaML.isML = true;

          // refrescá estado UI
          actualizarEstadoCantidadPickup();

          // opcional: mostrar en el badge ITEMS
          $("#totalt").text(cantidadConfirmada);
          $("#badge-items, #items_badge").text(cantidadConfirmada); // ajustá el id real si existe

          Swal.fire({
            icon: "success",
            title: "Colecta registrada",
            text: `Cantidad confirmada: ${cantidadConfirmada}`,
            timer: 1200,
            showConfirmButton: false,
          });
        });

        return; // ⛔ IMPORTANTE: cortamos acá
      }

      try {
        await postColectaBulto(base, scannedToken);
      } catch (e) {
        console.error("ColectaBulto error:", e);
        swalFire({
          icon: "error",
          title: "No se pudo registrar",
          text: "Se leyó el QR pero no se pudo guardar el bulto en el sistema.",
        });
        return;
      }

      // 7️⃣ feedback
      const cargados = $("#prueba").val()?.length || 0;

      swalFire({
        icon: "success",
        title: "OK",
        text: `Cargado ${cargados}/${qtyExpected}`,
        timer: 650,
        showConfirmButton: false,
      });
    };

    try {
      const cams = await Html5Qrcode.getCameras();
      if (cams && cams.length) {
        const cam = cams[cams.length - 1];
        await colectaQr.start(
          { deviceId: { exact: cam.id } },
          config,
          onSuccess,
          () => {},
        );
      } else {
        await colectaQr.start(
          { facingMode: "environment" },
          config,
          onSuccess,
          () => {},
        );
      }
    } catch (e) {
      console.error(e);
      swalFire({
        icon: "error",
        title: "Cámara",
        text: "No se pudo abrir la cámara. Revisá permisos (HTTPS o localhost).",
      });
    }
  }

  // Abrir modal (sin recrear instancias) + start cuando está visible
  $(document).on("click", "#btnEscanear", function () {
    const modalEl = document.getElementById("colectaScanModal");
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    // reconstruyo el set a partir de lo que ya hay cargado en select2
    codigosEscaneados.clear();
    (getSelectedValues() || []).forEach((v) => codigosEscaneados.add(v));

    colectaLast = "";
    colectaLastT = 0;

    modal.show();
  });

  // Quitar foco ANTES de que bootstrap ponga aria-hidden=true
  $(document).on("hide.bs.modal", "#colectaScanModal", function () {
    document.activeElement?.blur();
  });

  // Start scanner cuando el modal terminó de mostrarse
  $(document).on("shown.bs.modal", "#colectaScanModal", async function () {
    await startScanner();
  });

  // Stop al cerrar del todo
  $(document).on("hidden.bs.modal", "#colectaScanModal", async function () {
    await stopScanner();
  });

  // Stop manual
  $(document).on("click", "#btnStopColectaScan", async function () {
    await stopScanner();
    swalFire({
      icon: "info",
      title: "Scanner detenido",
      timer: 700,
      showConfirmButton: false,
    });
  });
})();
