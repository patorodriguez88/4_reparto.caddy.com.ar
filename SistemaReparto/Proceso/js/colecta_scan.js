// Proceso/js/colecta_scan.js
(function () {
  let colectaQr = null;
  let colectaLast = "";
  let colectaLastT = 0;
  let scannerStopPromise = Promise.resolve();
  let scannerStarting = false;
  let scannerStopping = false;
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
  function buildExpectedCodesForColecta() {
    const exp = getColectaExpected();
    if (!exp || !Array.isArray(exp.servicios_detalle)) return [];

    const out = [];
    exp.servicios_detalle.forEach((s) => {
      const base = String(s.cs_base || "").trim();
      if (!base) return;

      const paquetes = parseInt(s.paquetes || 1, 10) || 1;
      if (paquetes <= 1) {
        out.push(base);
      } else {
        for (let i = 1; i <= paquetes; i++) out.push(`${base}_${i}`);
      }
    });

    return out;
  }

  function getNextPendingExpectedCodeForColecta() {
    const expected = buildExpectedCodesForColecta();
    if (!expected.length) return "";

    // lo ya escaneado: tu set + select2
    const scanned = new Set(codigosEscaneados);
    (getSelectedValues() || []).forEach((v) => scanned.add(v));

    // devolvemos el primero pendiente
    const next = expected.find((c) => !scanned.has(c));
    return next || "";
  }

  function getTotalExpectedQtyForColecta() {
    return buildExpectedCodesForColecta().length || 0;
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
  function getFaltantesColecta() {
    const expected = buildExpectedCodesForColecta();
    const scanned = new Set();

    // set interno
    codigosEscaneados.forEach((v) => scanned.add(String(v)));

    // select2 por si ya estaba cargado antes
    (getSelectedValues() || []).forEach((v) => scanned.add(String(v)));

    return expected.filter((c) => !scanned.has(c));
  }

  function postColectaBulto(base, token, cantidad = 1) {
    const colectaId = esModoColecta()
      ? parseInt(window.idColectaActual, 10) || 0
      : 0;
    const padreId = esModoColecta()
      ? parseInt(window.colectaPadreId, 10) || 0
      : 0;

    return $.ajax({
      url: "Proceso/php/colecta_scan.php",
      type: "POST",
      dataType: "json",
      data: {
        ColectaBulto: 1,
        colectaId,
        padreId,
        base,
        bulto: token,
        cantidad,
      },
    });
  }
  async function stopScanner() {
    // try {
    //   if (colectaQr) {
    //     await colectaQr.stop();
    //     await colectaQr.clear();
    //     colectaQr = null;
    //   }
    // } catch (e) {}
    // Encolamos el stop para evitar transiciones superpuestas
    scannerStopPromise = scannerStopPromise.then(async () => {
      if (scannerStopping) return;
      scannerStopping = true;

      try {
        if (colectaQr) {
          // stop() a veces explota si ya está detenido: lo envolvemos
          try {
            await colectaQr.stop();
          } catch (e) {}
          try {
            await colectaQr.clear();
          } catch (e) {}
          colectaQr = null;
        }
      } finally {
        scannerStopping = false;
      }
    });

    return scannerStopPromise;
  }

  async function startColectaScanner() {
    if (!("Html5Qrcode" in window)) {
      swalFire({
        icon: "error",
        title: "Falta librería",
        text: "No se cargó html5-qrcode",
      });
      return;
    }

    // ✅ esperar cualquier stop en curso
    await scannerStopPromise;

    // ✅ evitar doble start
    if (scannerStarting) return;
    scannerStarting = true;

    try {
      // ✅ siempre frenar/limpiar antes de iniciar
      await stopScanner();

      colectaQr = new Html5Qrcode("colecta-qr-reader");

      // ---------------------------
      // UI: esperado
      // ---------------------------
      let expectedDisplay = "";
      let qtyExpected = 1;

      if (esModoColecta()) {
        const expectedList = buildExpectedCodesForColecta();
        qtyExpected = expectedList.length || 1;
        expectedDisplay = `${qtyExpected} PAQUETES TOTAL`;
      } else {
        expectedDisplay = getExpectedBase();
        qtyExpected = getCantidadEsperada();
      }

      $("#colecta-expected").text(expectedDisplay || "—");
      $("#colecta-expected-qty").text(qtyExpected || 1);

      // ---------------------------
      // Helpers + callback
      // ---------------------------
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

        let qtyExpectedLocal =
          parseInt(($("#card-receptor-cantidad").text() || "1").trim(), 10) ||
          1;

        const jsonId = extraerIdDesdeJson(raw);

        let scannedToken = raw;
        let base = "";
        let esQRML = false;
        let hasN = false;

        if (jsonId) {
          esQRML = true;
          scannedToken = jsonId;
          base = jsonId;
        } else {
          base = raw.split("_")[0].trim();
          hasN = raw.includes("_");
        }

        // ✅ validar "hay colecta" o "hay envio"
        if (esModoColecta()) {
          const exp = getColectaExpected();
          if (
            !exp ||
            !Array.isArray(exp.servicios_detalle) ||
            !exp.servicios_detalle.length
          ) {
            swalFire({
              icon: "warning",
              title: "Sin colecta",
              text: "Abrí una colecta antes de escanear.",
            });
            return;
          }
        } else {
          if (!expectedBase) {
            swalFire({
              icon: "warning",
              title: "Sin envío",
              text: "Abrí un envío antes de escanear.",
            });
            return;
          }
        }

        // 2️⃣ VALIDACIÓN DE BASE / PERTENENCIA (COLECTA + ENVÍO)
        if (esModoColecta()) {
          // En colecta validamos pertenencia siempre:
          // - Si viene JSON, usamos jsonId como base a chequear
          // - Si no, usamos base
          const baseCheck = jsonId
            ? String(jsonId).trim()
            : String(base).trim();
          const svc = getServicioEsperadoPorBase(baseCheck);

          if (!svc) {
            swalFire({
              icon: "error",
              title: "Servicio fuera de la colecta",
              text: `El servicio ${baseCheck} no pertenece a esta colecta.`,
              timer: 1400,
              showConfirmButton: false,
            });
            return;
          }
        } else {
          // En modo envío normal
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

        // 3️⃣ VALIDACIÓN DE CANTIDAD
        let paquetesSvc = 1;
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
          if (!jsonId && qtyExpectedLocal > 1 && !hasN) {
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

        // rango _n en colecta
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
          codeToStore = base;
        } else if (esModoColecta()) {
          codeToStore = paquetesSvc <= 1 ? base : raw;
        } else {
          codeToStore = qtyExpectedLocal <= 1 ? expectedBase : raw;
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
        if (esModoColecta()) {
          const faltan = getFaltantesColecta().length;
          $("#colecta-faltan").text(faltan); // si tenés un span
        }
        // guardar en backend
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

        // 7️⃣ feedback (en colecta usamos total global)
        const cargados = $("#prueba").val()?.length || 0;
        let totalEsperado = qtyExpectedLocal;

        if (esModoColecta()) {
          const n = buildExpectedCodesForColecta().length;
          totalEsperado = n > 0 ? n : Math.max(cargados, 1); // fallback razonable
        }

        swalFire({
          icon: "success",
          title: "OK",
          text: `Cargado ${cargados}/${totalEsperado}`,
          timer: 650,
          showConfirmButton: false,
        });
      };

      // ---------------------------
      // Config cámara (iPhone-safe + fallback)
      // ---------------------------
      const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);

      const configIOS = {
        fps: 10,
        qrbox: { width: 240, height: 240 },
        disableFlip: true,
        // En iOS conviene dejar que el browser elija aspect ratio real
        experimentalFeatures: { useBarCodeDetectorIfSupported: true },
        videoConstraints: { facingMode: "environment" }, // 👈 liviano
      };

      const configHiRes = {
        fps: 15,
        qrbox: { width: 280, height: 280 },
        disableFlip: true,
        experimentalFeatures: { useBarCodeDetectorIfSupported: true },
        // ⚠️ OJO: nada de advanced focusMode, y no fuerces 1920 en iPhone
        videoConstraints: {
          facingMode: "environment",
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
      };

      // Intento 1: en iOS usar config liviana
      // Intento 2: si falla, probar hi-res
      try {
        await colectaQr.start(
          { facingMode: "environment" },
          isIOS ? configIOS : configHiRes,
          onSuccess,
          () => {},
        );
      } catch (e1) {
        console.warn("Start failed, fallback...", e1);
        try {
          await colectaQr.start(
            { facingMode: "environment" },
            configIOS,
            onSuccess,
            () => {},
          );
        } catch (e2) {
          throw e2; // cae al catch general y muestra Swal
        }
      }

      // Ajuste visual del video
      setTimeout(() => {
        const v = document.querySelector("#colecta-qr-reader video");
        if (v) {
          v.setAttribute("playsinline", "true");
          v.setAttribute("webkit-playsinline", "true");
          v.style.width = "100%";
          v.style.height = "100%";
          v.style.objectFit = "cover";
          v.style.display = "block";
        }
      }, 250);
    } catch (e) {
      console.error(e);
      swalFire({
        icon: "error",
        title: "Cámara",
        text: "No se pudo abrir la cámara. Revisá permisos (HTTPS o localhost).",
      });
    } finally {
      scannerStarting = false;
    }
  }
  $(document).on("click", "#btnValidarFaltantes", function () {
    const faltan = getFaltantesColecta();

    if (!faltan.length) {
      swalFire({
        icon: "success",
        title: "Completo",
        text: "No falta ningún paquete.",
        timer: 1200,
        showConfirmButton: false,
      });
      return;
    }

    // mostrar lista corta o larga
    const maxShow = 15;
    const listado = faltan.slice(0, maxShow).join("<br>");
    const extra =
      faltan.length > maxShow ? `<br>… y ${faltan.length - maxShow} más` : "";

    Swal.fire({
      icon: "warning",
      title: `Faltan ${faltan.length} paquete(s)`,
      html: `<div style="text-align:left">${listado}${extra}</div>`,
    });
  });
  // Abrir modal (sin recrear instancias) + start cuando está visible
  $(document).on("click", "#btnEscanear", async function () {
    const $btn = $(this);
    if ($btn.data("busy")) return;
    $btn.data("busy", 1).prop("disabled", true);

    try {
      // Esperar a que termine cualquier stop pendiente antes de abrir
      await scannerStopPromise;

      const modalEl = document.getElementById("colectaScanModal");
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

      codigosEscaneados.clear();
      (getSelectedValues() || []).forEach((v) => codigosEscaneados.add(v));

      colectaLast = "";
      colectaLastT = 0;

      modal.show();
    } finally {
      $btn.data("busy", 0).prop("disabled", false);
    }
  });

  // Quitar foco ANTES de que bootstrap ponga aria-hidden=true
  $(document).on("hide.bs.modal", "#colectaScanModal", function () {
    document.activeElement?.blur();
    stopScanner(); // no await (bootstrap no espera), pero queda encolado
  });

  // Start scanner cuando el modal terminó de mostrarse
  $(document).on("shown.bs.modal", "#colectaScanModal", async function () {
    console.log("✅ MODAL shown -> startColectaScanner()");

    await startColectaScanner();
    // DEBUG: esperar a que aparezca el <video> real y loguear resolución / settings
    (function waitVideoAndLog() {
      const v = document.querySelector("#colecta-qr-reader video");
      if (!v) return setTimeout(waitVideoAndLog, 120);

      // cuando el video ya tiene metadata
      const logNow = () => {
        console.log("✅ COLECTA VIDEO size:", v.videoWidth, v.videoHeight);

        const stream = v.srcObject;
        const track = stream?.getVideoTracks?.()[0];
        if (track) {
          console.log("✅ COLECTA TRACK settings:", track.getSettings());
          console.log(
            "✅ COLECTA TRACK capabilities:",
            track.getCapabilities?.(),
          );
        } else {
          console.log("⚠️ No track en srcObject");
        }
      };

      if (v.readyState >= 2) logNow();
      else v.addEventListener("loadedmetadata", logNow, { once: true });
    })();
  });

  // Stop al cerrar del todo
  $(document).on("hidden.bs.modal", "#colectaScanModal", function () {
    document.body.style.overflowY = "auto";
    document.body.style.webkitOverflowScrolling = "touch";
  });
})();
