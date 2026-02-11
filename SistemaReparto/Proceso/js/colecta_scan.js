// Proceso/js/colecta_scan.js

(function () {
  let colectaQr = null;
  let colectaLast = "";
  let colectaLastT = 0;

  let scannerStopPromise = Promise.resolve();
  let scannerStarting = false;
  let scannerStopping = false;
  let coolingDown = false;
  let cooldownMs = 1200; // ajustable

  const codigosEscaneados = new Set(); // guarda codeToStore válidos (solo cuando backend confirma)
  let _audioCtx = null;

  function swalFire(opts) {
    if (window.Swal && Swal.fire) return Swal.fire(opts);
    alert((opts.title ? opts.title + "\n" : "") + (opts.text || ""));
  }

  function esModoColecta() {
    return (
      ($("#card-servicio").text() || "").trim().toUpperCase() === "COLECTA"
    );
  }
  // Si borran desde la X en el Select2, sincronizamos el Set
  $(document).on("select2:unselect", "#prueba", function (e) {
    const code = e.params?.data?.id || e.params?.data?.text;
    if (code) codigosEscaneados.delete(String(code));
  });
  // ===== Feedback beep/vibra (iOS-safe, 1 solo contexto) =====
  function feedbackScan(ok = true) {
    if (navigator.vibrate) navigator.vibrate(ok ? 120 : [60, 60, 60]);

    try {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      if (!_audioCtx) _audioCtx = new AudioContext();
      if (_audioCtx.state === "suspended") _audioCtx.resume();

      const o = _audioCtx.createOscillator();
      const g = _audioCtx.createGain();

      o.type = "sine";
      o.frequency.value = ok ? 1200 : 300;
      g.gain.value = 0.12;

      o.connect(g);
      g.connect(_audioCtx.destination);

      o.start();
      o.stop(_audioCtx.currentTime + (ok ? 0.1 : 0.18));
    } catch (e) {}
  }

  // ===== Colecta expected (solo para UX rápida en QR “normal”) =====
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
      if (paquetes <= 1) out.push(base);
      else for (let i = 1; i <= paquetes; i++) out.push(`${base}_${i}`);
    });
    return out;
  }

  function getSelectedValues() {
    const v = $("#prueba").val();
    return Array.isArray(v) ? v : [];
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

  function getFaltantesColecta() {
    const expected = buildExpectedCodesForColecta();
    const scanned = new Set();

    codigosEscaneados.forEach((v) => scanned.add(String(v)));
    (getSelectedValues() || []).forEach((v) => scanned.add(String(v)));

    return expected.filter((c) => !scanned.has(c));
  }

  function getExpectedBase() {
    const raw = ($("#card-seguimiento").text() || "").trim();
    return raw ? raw.split("_")[0].trim() : "";
  }

  function getCantidadEsperada() {
    const txt = ($("#card-receptor-cantidad").text() || "").trim();
    const n = parseInt(txt, 10);
    return isNaN(n) ? 1 : n;
  }

  // ===== Backend =====
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

  // ===== Scanner lifecycle =====
  async function stopScanner() {
    scannerStopPromise = scannerStopPromise.then(async () => {
      if (scannerStopping) return;
      scannerStopping = true;

      try {
        if (colectaQr) {
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

  function lockSelect2ManualInput() {
    const $sel = $("#prueba");
    if (!$sel.length) return;

    const s2 = $sel.data("select2");
    if (s2 && s2.$dropdown) {
      s2.$dropdown.find(".select2-search__field").prop("disabled", true);
    }

    $(document).off("keydown.colectaLock", ".select2-search__field");
    $(document).on(
      "keydown.colectaLock",
      ".select2-search__field",
      function (e) {
        e.preventDefault();
        return false;
      },
    );

    $(document).off("input.colectaLock", ".select2-search__field");
    $(document).on("input.colectaLock", ".select2-search__field", function () {
      $(this).val("");
    });
  }

  function unlockSelect2ManualInput() {
    $(document).off("keydown.colectaLock", ".select2-search__field");
    $(document).off("input.colectaLock", ".select2-search__field");
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

    await scannerStopPromise;
    if (scannerStarting) return;
    scannerStarting = true;

    try {
      await stopScanner();
      colectaQr = new Html5Qrcode("colecta-qr-reader");

      // UI esperado
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

      const onSuccess = async (decodedText) => {
        if (coolingDown) return;
        coolingDown = true;
        setTimeout(() => (coolingDown = false), cooldownMs);
        const raw = (decodedText || "").trim();
        if (!raw) return;

        // anti-rebote
        const now = Date.now();
        if (raw === colectaLast && now - colectaLastT < 900) return;
        colectaLast = raw;
        colectaLastT = now;

        const expectedBase = getExpectedBase();
        const qtyExpectedLocal = getCantidadEsperada();

        const jsonId = extraerIdDesdeJson(raw);
        const scannedToken = jsonId ? jsonId : raw;

        // base "visual" para QR normal (para validar sufijo y UX)
        const base = raw.split("_")[0].trim();
        const hasN = raw.includes("_");

        // Validación de contexto: hay colecta / hay envío
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
            feedbackScan(false);
            return;
          }
        } else {
          if (!expectedBase) {
            swalFire({
              icon: "warning",
              title: "Sin envío",
              text: "Abrí un envío antes de escanear.",
            });
            feedbackScan(false);
            return;
          }
        }

        // ===== Validaciones rápidas SOLO para QR normal (no JSON) =====
        // JSON/Proveedor: lo decide el backend (para evitar el bug original)
        let paquetesSvc = 1;

        if (!jsonId) {
          if (esModoColecta()) {
            const svc = getServicioEsperadoPorBase(base);
            if (!svc) {
              swalFire({
                icon: "error",
                title: "Servicio fuera de la colecta",
                text: `El servicio ${base} no pertenece a esta colecta.`,
                timer: 1400,
                showConfirmButton: false,
              });
              feedbackScan(false);
              return;
            }

            paquetesSvc = parseInt(svc?.paquetes || 1, 10) || 1;

            // si requiere sufijo y no hay, freno
            if (paquetesSvc > 1 && !hasN) {
              swalFire({
                icon: "info",
                title: "Falta el sufijo",
                text: `Para ${base} necesitás ${base}_1 / ${base}_2 / ...`,
                timer: 1400,
                showConfirmButton: false,
              });
              feedbackScan(false);
              return;
            }

            // rango sufijo
            if (hasN) {
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
                feedbackScan(false);
                return;
              }
              if (paquetesSvc === 1) {
                swalFire({
                  icon: "error",
                  title: "Bulto inválido",
                  text: `El servicio ${base} tiene 1 bulto. Escaneá ${base} (sin sufijo).`,
                  timer: 1600,
                  showConfirmButton: false,
                });
                feedbackScan(false);
                return;
              }
            }
          } else {
            // modo envío normal
            if (base !== expectedBase) {
              swalFire({
                icon: "error",
                title: "Código incorrecto",
                text: `Escaneaste ${base} y se esperaba ${expectedBase}`,
                timer: 1400,
                showConfirmButton: false,
              });
              feedbackScan(false);
              return;
            }
            if (qtyExpectedLocal > 1 && !hasN) {
              swalFire({
                icon: "info",
                title: "Falta el sufijo",
                text: "Para este envío necesitás escanear BASE_1 / BASE_2 / BASE_3…",
                timer: 1400,
                showConfirmButton: false,
              });
              feedbackScan(false);
              return;
            }
          }
        }

        // ===== codeToStore (solo para UI/duplicados locales) =====
        // - JSON: guardamos el token (id) (pero el backend es la autoridad final)
        // - QR normal: base o raw según paquetes
        let codeToStore;
        if (jsonId) {
          codeToStore = scannedToken;
        } else if (esModoColecta()) {
          codeToStore = paquetesSvc <= 1 ? base : raw;
        } else {
          codeToStore = qtyExpectedLocal <= 1 ? expectedBase : raw;
        }

        // anti-duplicado local
        if (codigosEscaneados.has(codeToStore)) {
          swalFire({
            icon: "info",
            title: "Ya escaneado",
            text: codeToStore,
            timer: 900,
            showConfirmButton: false,
          });
          feedbackScan(false);
          return;
        }

        // ===== Backend primero (autoridad) =====
        let res;
        try {
          res = await postColectaBulto(base, scannedToken);

          // 🔹 Si el backend dice que ya estaba registrado
          if (res && res.success == 1 && res.duplicate == 1) {
            feedbackScan(false); // sonido diferente
            swalFire({
              icon: "info",
              title: "Ya registrado",
              text: codeToStore,
              timer: 700,
              showConfirmButton: false,
            });
            return;
          }

          // 🔹 Si falla realmente
          if (!res || res.success != 1) {
            throw new Error("backend rejected");
          }

          // 🔹 OK real
          feedbackScan(true);
        } catch (e) {
          console.error("ColectaBulto error:", e);
          feedbackScan(false);
          swalFire({
            icon: "error",
            title: "No se pudo registrar",
            text: "Ese código no pertenece a la colecta o no se pudo guardar.",
          });
          return;
        }

        // UI: recién acá lo damos por válido
        codigosEscaneados.add(codeToStore);
        addToSelect2(codeToStore);

        if (esModoColecta()) {
          $("#colecta-faltan").text(getFaltantesColecta().length);
        }

        // feedback texto
        const cargados = $("#prueba").val()?.length || 0;
        let totalEsperado = qtyExpectedLocal;

        if (esModoColecta()) {
          const n = buildExpectedCodesForColecta().length;
          totalEsperado = n > 0 ? n : Math.max(cargados, 1);
        }

        swalFire({
          icon: "success",
          title: "OK",
          text: `Cargado ${cargados}/${totalEsperado}`,
          timer: 650,
          showConfirmButton: false,
        });
      };

      // Config cámara (iPhone-safe + fallback)
      const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);

      const configIOS = {
        fps: 10,
        qrbox: { width: 240, height: 240 },
        disableFlip: true,
        experimentalFeatures: { useBarCodeDetectorIfSupported: true },
        videoConstraints: { facingMode: "environment" },
      };

      const configHiRes = {
        fps: 15,
        qrbox: { width: 280, height: 280 },
        disableFlip: true,
        experimentalFeatures: { useBarCodeDetectorIfSupported: true },
        videoConstraints: {
          facingMode: "environment",
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
      };

      try {
        await colectaQr.start(
          { facingMode: "environment" },
          isIOS ? configIOS : configHiRes,
          onSuccess,
          () => {},
        );
      } catch (e1) {
        console.warn("Start failed, fallback...", e1);
        await colectaQr.start(
          { facingMode: "environment" },
          configIOS,
          onSuccess,
          () => {},
        );
      }

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

  // ===== UI handlers =====
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

  $(document).on("click", "#btnEscanear", async function () {
    const $btn = $(this);
    if ($btn.data("busy")) return;
    $btn.data("busy", 1).prop("disabled", true);

    try {
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

  $(document).on("hide.bs.modal", "#colectaScanModal", function () {
    document.activeElement?.blur();
    stopScanner();
  });

  $(document).on("shown.bs.modal", "#colectaScanModal", async function () {
    lockSelect2ManualInput();
    await startColectaScanner();
  });

  $(document).on("hidden.bs.modal", "#colectaScanModal", function () {
    document.body.style.overflowY = "auto";
    document.body.style.webkitOverflowScrolling = "touch";
    unlockSelect2ManualInput();
  });
})();
