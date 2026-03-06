// Proceso/js/colecta_scan.js
//Colecta del repartidor en los clientes.
console.log("Version 1.16 - 2024-06-18");

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
    return ($("#card-servicio").text() || "").trim().toUpperCase() === "COLECTA";
  }
  // Si borran desde la X en el Select2, sincronizamos el Set
  $(document).on("select2:unselect", "#prueba", function (e) {
    const code = e.params?.data?.id || e.params?.data?.text;
    if (code) codigosEscaneados.delete(String(code));

    actualizarMetricasColectaUI({
      paquetes_ok: ($("#prueba").val() || []).length,
      paquetes_total: getColectaExpected()?.paquetes_total || 0,
    });
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
    return exp.servicios_detalle.find((s) => String(s.cs_base).trim() === String(base).trim()) || null;
  }

  function buildExpectedCodesForColecta() {
    const exp = getColectaExpected();
    if (!exp || !Array.isArray(exp.servicios_detalle)) return [];

    const out = [];
    exp.servicios_detalle.forEach((s) => {
      const base = String(s.cs_base || "").trim();
      if (!base) return;

      // const paquetes = parseInt(s.paquetes || 1, 10) || 1;
      const paquetes = parseInt(s.paquetes ?? s.Cantidad ?? s.bultos ?? 1, 10) || 1;
      if (paquetes <= 1)
        out.push(`${base}_1`); // ✅ canon
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
  function getNextSuffixForBase(baseReal, maxPaquetes) {
    const scanned = new Set();

    codigosEscaneados.forEach((v) => scanned.add(String(v)));
    (getSelectedValues() || []).forEach((v) => scanned.add(String(v)));

    for (let i = 1; i <= maxPaquetes; i++) {
      const candidate = `${baseReal}_${i}`;
      if (!scanned.has(candidate)) return candidate;
    }
    return null; // ya están todos
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
  function actualizarMetricasColectaUI(resume = null) {
    const exp = getColectaExpected();

    const servicios = parseInt(exp?.servicios || exp?.servicios_total || 0, 10) || 0;
    const bultos = parseInt(exp?.paquetes_total || 0, 10) || 0;

    let escaneados = 0;
    let faltan = bultos;

    if (resume) {
      escaneados = parseInt(resume.paquetes_ok || 0, 10) || 0;
      faltan = Math.max((parseInt(resume.paquetes_total || bultos, 10) || bultos) - escaneados, 0);
    } else {
      escaneados = ($("#prueba").val() || []).length;
      faltan = Math.max(bultos - escaneados, 0);
    }

    $("#totalServicios").text(servicios);
    $("#totalBultos").text(bultos);
    $("#totalt").text(escaneados);
    $("#totalFaltan").text(faltan);

    // Compatibilidad con tu badge viejo / otros textos si todavía los usás en otro lado
    $("#colecta-faltan").text(faltan);
    $("#card-receptor-cantidad").text(bultos);
  }
  // ===== Backend =====
  // function postColectaBulto(base, token, cantidad = 1, raw = "") {
  //   const colectaId = esModoColecta() ? parseInt(window.idColectaActual, 10) || 0 : 0;
  //   const padreId = esModoColecta() ? parseInt(window.colectaPadreId, 10) || 0 : 0;
  //   raw = (raw || "").toString();
  //   base = ($("#base").val() || "").toString().trim();

  //   try {
  //     const obj = JSON.parse(raw);
  //     if (!base && obj && obj.id) base = String(obj.id).trim();
  //   } catch (e) {}

  //   // si vienen sufijos tipo _1, _2 (bultos), normalizamos la base “padre”
  //   base = base.replace(/_\d+$/, "");

  //   // si no estás enviando bulto explícito, mandalo (para 1 bulto es 1)
  //   let bulto = ($("#bulto").val() || "1").toString().trim();
  //   if (!bulto) bulto = "1";

  //   // cantidad total de bultos (si es 1, queda 1)
  //   cantidad = ($("#cantidad").val() || "1").toString().trim();
  //   if (!cantidad) cantidad = "1";

  //   return $.ajax({
  //     url: "Proceso/php/colecta_scan.php",
  //     type: "POST",
  //     dataType: "json",
  //     data: {
  //       ColectaBulto: 1,
  //       colectaId: colectaId,
  //       padreId: padreId,
  //       raw: raw, // ✅ ahora sí
  //       base: base,
  //       bulto: bulto,
  //       cantidad: cantidad,
  //     },
  //   });
  // }
  function postColectaBulto(base, token, cantidad = 1, raw = "") {
    const colectaId = esModoColecta() ? parseInt(window.idColectaActual, 10) || 0 : 0;
    const padreId = esModoColecta() ? parseInt(window.colectaPadreId, 10) || 0 : 0;

    raw = (raw || "").toString();
    let baseFinal = (base || "").toString().trim();

    try {
      const obj = JSON.parse(raw);
      if (!baseFinal && obj && obj.id) baseFinal = String(obj.id).trim();
    } catch (e) {}

    baseFinal = baseFinal.replace(/_\d+$/, "");

    let bulto = ($("#bulto").val() || "1").toString().trim();
    if (!bulto) bulto = "1";

    let cantidadFinal = (cantidad || 1).toString().trim();
    if (!cantidadFinal) cantidadFinal = "1";

    return $.ajax({
      url: "Proceso/php/colecta_scan.php",
      type: "POST",
      dataType: "json",
      data: {
        ColectaBulto: 1,
        colectaId: colectaId,
        padreId: padreId,
        raw: raw,
        base: baseFinal,
        bulto: bulto,
        cantidad: cantidadFinal,
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
    $(document).on("keydown.colectaLock", ".select2-search__field", function (e) {
      e.preventDefault();
      return false;
    });

    $(document).off("input.colectaLock", ".select2-search__field");
    $(document).on("input.colectaLock", ".select2-search__field", function () {
      $(this).val("");
    });
  }

  function unlockSelect2ManualInput() {
    $(document).off("keydown.colectaLock", ".select2-search__field");
    $(document).off("input.colectaLock", ".select2-search__field");
  }
  function hardLockSelect2Tags() {
    const $sel = $("#prueba");
    if (!$sel.length) return;

    // Si ya está inicializado, le deshabilitamos la búsqueda editable visualmente
    const s2 = $sel.data("select2");
    if (s2 && s2.$container) {
      s2.$container.find(".select2-search__field").prop("disabled", true).attr("readonly", true);
    }

    // Evita que Select2 acepte texto libre
    $(document).off("keydown.colectaSelect2Lock", ".select2-search__field");
    $(document).on("keydown.colectaSelect2Lock", ".select2-search__field", function (e) {
      e.preventDefault();
      return false;
    });

    $(document).off("input.colectaSelect2Lock", ".select2-search__field");

    $(document).on("input.colectaSelect2Lock", ".select2-search__field", function () {
      $(this).val("");
      return false;
    });

    // Si algún plugin intenta crear una opción nueva, la borramos
    $sel.off("select2:selecting.colectaNoTags");
    $sel.on("select2:selecting.colectaNoTags", function (e) {
      const data = e.params?.args?.data || e.params?.data || {};
      const id = String(data.id || "");
      const text = String(data.text || "");

      const existe =
        $(this)
          .find("option")
          .filter(function () {
            return String(this.value) === id || String($(this).text()) === text;
          }).length > 0;

      if (!existe && (data.newTag || id === text)) {
        e.preventDefault();
        return false;
      }
    });

    // Si ya te metieron basura escrita a mano, la removemos al cambiar
    $sel.off("change.colectaCleanTags");
    $sel.on("change.colectaCleanTags", function () {
      const vals = $(this).val() || [];
      const validVals = vals.filter((v) => {
        return $(this).find(`option[value="${v}"]`).length > 0;
      });

      if (vals.length !== validVals.length) {
        $(this).val(validVals).trigger("change.select2");
      }
    });
  }

  //ACA PONGO PROCESARSCAN()????
  async function procesarScan(decodedText, source = "scanner") {
    // ===== SIEMPRE definidos (evita ReferenceError y sombras) =====
    let codeToStoreFinal = "";
    let baseReal = "";
    let paquetesSvcBack = 0;

    if (coolingDown) return;
    coolingDown = true;
    setTimeout(() => (coolingDown = false), cooldownMs);

    const raw = (decodedText || "").trim();
    if (!raw) return;

    const hasN = raw.includes("_");

    // anti-rebote (aplica igual para manual, pero no molesta)
    const now = Date.now();
    if (raw === colectaLast && now - colectaLastT < 900) return;
    colectaLast = raw;
    colectaLastT = now;

    const expectedBase = getExpectedBase();

    // 1) Detectar JSON ML / token / base para backend
    const jsonId = extraerIdDesdeJson(raw);

    const base = jsonId ? String(jsonId) : (hasN ? raw.split("_").slice(0, -1).join("_") : raw).trim();

    const looksLikeBase = !jsonId && /^[A-Z0-9]{8,}(?:_\d+)?$/i.test(raw);

    // 2) Contexto válido
    if (esModoColecta()) {
      const exp = getColectaExpected();
      if (!exp || !Array.isArray(exp.servicios_detalle) || !exp.servicios_detalle.length) {
        swalFire({ icon: "warning", title: "Sin colecta", text: "Abrí una colecta antes de escanear." });
        feedbackScan(false);
        return;
      }
    } else {
      if (!expectedBase) {
        swalFire({ icon: "warning", title: "Sin envío", text: "Abrí un envío antes de escanear." });
        feedbackScan(false);
        return;
      }
    }

    // 3) Validaciones rápidas SOLO para QR normal (no JSON)
    let paquetesSvc = 1;
    let qtyExpectedLocal = getCantidadEsperada(); // modo normal

    if (!jsonId && looksLikeBase) {
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

        paquetesSvc = parseInt(svc.paquetes ?? 1, 10) || 1;
        qtyExpectedLocal = paquetesSvc;

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

        if (hasN) {
          const parts = raw.split("_");
          const suf = parts.length > 1 ? parseInt(parts[parts.length - 1], 10) : NaN;

          if (!Number.isInteger(suf) || suf < 1) {
            swalFire({
              icon: "error",
              title: "Bulto inválido",
              text: `Sufijo inválido en ${raw}`,
              timer: 1600,
              showConfirmButton: false,
            });
            feedbackScan(false);
            return;
          }

          if (paquetesSvc === 1 && suf !== 1) {
            swalFire({
              icon: "error",
              title: "Bulto inválido",
              text: `Para ${base} sólo se permite ${base}_1`,
              timer: 1600,
              showConfirmButton: false,
            });
            feedbackScan(false);
            return;
          }

          if (paquetesSvc > 1 && suf > paquetesSvc) {
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

    // 4) Backend (autoridad) + duplicado local preliminar
    if (!jsonId && looksLikeBase) {
      const candidateLocal = esModoColecta()
        ? paquetesSvc > 1
          ? raw
          : `${base}_1`
        : qtyExpectedLocal > 1
          ? raw
          : `${expectedBase}_1`;

      if (codigosEscaneados.has(candidateLocal)) {
        swalFire({
          icon: "info",
          title: "Ya escaneado",
          text: candidateLocal,
          timer: 900,
          showConfirmButton: false,
        });
        feedbackScan(false);
        return;
      }
    }

    const uiLabel = jsonId ? `TOKEN: ${jsonId}` : looksLikeBase ? (hasN ? raw : `${base}_1`) : `TOKEN: ${raw}`;

    let res = null;
    try {
      res = await postColectaBulto(base, "", 1, raw);

      if (res && res.success == 1 && res.duplicate == 1) {
        feedbackScan(false);
        swalFire({
          icon: "info",
          title: "Ya registrado",
          text: uiLabel,
          timer: 700,
          showConfirmButton: false,
        });
        return;
      }

      if (!res || res.success != 1) throw new Error("backend rejected");

      baseReal = (res.cs_base || "").trim();
      paquetesSvcBack = parseInt(res.paquetes_servicio || 0, 10) || 0;

      feedbackScan(true);
    } catch (e) {
      console.error("ColectaBulto error:", { e, res, status: e?.status, responseText: e?.responseText });
      feedbackScan(false);
      swalFire({
        icon: "error",
        title: "No se pudo registrar",
        text: res?.detail || res?.error || "Ese código no pertenece a la colecta o no se pudo guardar.",
      });
      return;
    }

    // 5) Definir codeToStore FINAL
    if (!esModoColecta()) {
      codeToStoreFinal = qtyExpectedLocal <= 1 ? `${expectedBase}_1` : raw;
    } else {
      if (jsonId) {
        const paquetes = paquetesSvcBack || 1;

        if (!baseReal) {
          codeToStoreFinal = jsonId ? String(jsonId) : raw;
        } else if (paquetes <= 1) {
          codeToStoreFinal = `${baseReal}_1`;
        } else {
          const next = getNextSuffixForBase(baseReal, paquetes);
          if (!next) {
            swalFire({
              icon: "info",
              title: "Completo",
              text: `Ya están los ${paquetes} bultos de ${baseReal}`,
              timer: 900,
              showConfirmButton: false,
            });
            feedbackScan(false);
            return;
          }
          codeToStoreFinal = next;
        }
      } else {
        if (!looksLikeBase) {
          const paquetes = paquetesSvcBack || 1;

          if (!baseReal) {
            codeToStoreFinal = raw;
          } else if (paquetes <= 1) {
            codeToStoreFinal = `${baseReal}_1`;
          } else {
            const next = getNextSuffixForBase(baseReal, paquetes);
            if (!next) {
              swalFire({
                icon: "info",
                title: "Completo",
                text: `Ya están los ${paquetes} bultos de ${baseReal}`,
                timer: 900,
                showConfirmButton: false,
              });
              feedbackScan(false);
              return;
            }
            codeToStoreFinal = next;
          }
        } else {
          if (paquetesSvc > 1) {
            const next = getNextSuffixForBase(base, paquetesSvc);
            if (!next) {
              swalFire({
                icon: "info",
                title: "Completo",
                text: `Ya están los ${paquetesSvc} bultos de ${base}`,
                timer: 900,
                showConfirmButton: false,
              });
              feedbackScan(false);
              return;
            }
            codeToStoreFinal = next;
          } else {
            codeToStoreFinal = `${base}_1`;
          }
        }
      }
    }

    if (codigosEscaneados.has(codeToStoreFinal)) {
      swalFire({ icon: "info", title: "Ya escaneado", text: codeToStoreFinal, timer: 900, showConfirmButton: false });
      feedbackScan(false);
      return;
    }

    codigosEscaneados.add(codeToStoreFinal);
    addToSelect2(codeToStoreFinal);

    // 7) Progreso desde resume
    let ok = 0;
    let tot = 0;

    if (res && res.resume) {
      ok = parseInt(res.resume.paquetes_ok || 0, 10);
      tot = parseInt(res.resume.paquetes_total || 0, 10);

      actualizarMetricasColectaUI(res.resume);
    } else {
      ok = $("#prueba").val()?.length || 0;
      tot = esModoColecta()
        ? getColectaExpected()?.paquetes_total || buildExpectedCodesForColecta().length || 1
        : qtyExpectedLocal || 1;

      actualizarMetricasColectaUI({
        paquetes_ok: ok,
        paquetes_total: tot,
      });
    }

    swalFire({ icon: "success", title: "OK", text: `Cargado ${ok}/${tot}`, timer: 650, showConfirmButton: false });
  }

  //HASTA ACA PROCESAR SCAN

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

      actualizarMetricasColectaUI({
        paquetes_ok: ($("#prueba").val() || []).length,
        paquetes_total: esModoColecta() ? getColectaExpected()?.paquetes_total || qtyExpected || 1 : qtyExpected || 1,
      });
      // const onSuccess = async (decodedText) => {
      //   // ===== SIEMPRE definidos (evita ReferenceError y sombras) =====
      //   let codeToStoreFinal = "";
      //   let baseReal = "";
      //   let paquetesSvcBack = 0;

      //   if (coolingDown) return;
      //   coolingDown = true;
      //   setTimeout(() => (coolingDown = false), cooldownMs);

      //   const raw = (decodedText || "").trim();
      //   if (!raw) return;
      //   const hasN = raw.includes("_");
      //   // anti-rebote
      //   const now = Date.now();
      //   if (raw === colectaLast && now - colectaLastT < 900) return;
      //   colectaLast = raw;
      //   colectaLastT = now;

      //   const expectedBase = getExpectedBase();

      //   // 1) Detectar JSON ML / token / base para backend

      //   const jsonId = extraerIdDesdeJson(raw);

      //   // OJO: para JSON NO hay "base" real en el QR.
      //   // Para QR normal sí: base = BASE
      //   const base = jsonId ? String(jsonId) : (hasN ? raw.split("_").slice(0, -1).join("_") : raw).trim();

      //   const looksLikeBase = !jsonId && /^[A-Z0-9]{8,}(?:_\d+)?$/i.test(raw); // ajustá 8->10 si querés
      //   // 2) Contexto válido
      //   if (esModoColecta()) {
      //     const exp = getColectaExpected();
      //     if (!exp || !Array.isArray(exp.servicios_detalle) || !exp.servicios_detalle.length) {
      //       swalFire({ icon: "warning", title: "Sin colecta", text: "Abrí una colecta antes de escanear." });
      //       feedbackScan(false);
      //       return;
      //     }
      //   } else {
      //     if (!expectedBase) {
      //       swalFire({ icon: "warning", title: "Sin envío", text: "Abrí un envío antes de escanear." });
      //       feedbackScan(false);
      //       return;
      //     }
      //   }

      //   // 3) Validaciones rápidas SOLO para QR normal (no JSON)
      //   let paquetesSvc = 1;
      //   let qtyExpectedLocal = getCantidadEsperada(); // modo normal

      //   if (!jsonId && looksLikeBase) {
      //     if (esModoColecta()) {
      //       const svc = getServicioEsperadoPorBase(base);
      //       if (!svc) {
      //         swalFire({
      //           icon: "error",
      //           title: "Servicio fuera de la colecta",
      //           text: `El servicio ${base} no pertenece a esta colecta.`,
      //           timer: 1400,
      //           showConfirmButton: false,
      //         });
      //         feedbackScan(false);
      //         return;
      //       }

      //       paquetesSvc = parseInt(svc.paquetes || 1, 10) || 1;
      //       qtyExpectedLocal = paquetesSvc;

      //       if (paquetesSvc > 1 && !hasN) {
      //         swalFire({
      //           icon: "info",
      //           title: "Falta el sufijo",
      //           text: `Para ${base} necesitás ${base}_1 / ${base}_2 / ...`,
      //           timer: 1400,
      //           showConfirmButton: false,
      //         });
      //         feedbackScan(false);
      //         return;
      //       }

      //       if (hasN) {
      //         const parts = raw.split("_");
      //         const suf = parts.length > 1 ? parseInt(parts[parts.length - 1], 10) : NaN;

      //         if (!Number.isInteger(suf) || suf < 1) {
      //           swalFire({
      //             icon: "error",
      //             title: "Bulto inválido",
      //             text: `Sufijo inválido en ${raw}`,
      //             timer: 1600,
      //             showConfirmButton: false,
      //           });
      //           feedbackScan(false);
      //           return;
      //         }

      //         if (paquetesSvc === 1 && suf !== 1) {
      //           swalFire({
      //             icon: "error",
      //             title: "Bulto inválido",
      //             text: `Para ${base} sólo se permite ${base}_1`,
      //             timer: 1600,
      //             showConfirmButton: false,
      //           });
      //           feedbackScan(false);
      //           return;
      //         }

      //         if (paquetesSvc > 1 && suf > paquetesSvc) {
      //           swalFire({
      //             icon: "error",
      //             title: "Bulto inválido",
      //             text: `Para ${base} sólo se permiten ${base}_1 … ${base}_${paquetesSvc}`,
      //             timer: 1600,
      //             showConfirmButton: false,
      //           });
      //           feedbackScan(false);
      //           return;
      //         }
      //       }
      //     } else {
      //       // modo envío normal
      //       if (base !== expectedBase) {
      //         swalFire({
      //           icon: "error",
      //           title: "Código incorrecto",
      //           text: `Escaneaste ${base} y se esperaba ${expectedBase}`,
      //           timer: 1400,
      //           showConfirmButton: false,
      //         });
      //         feedbackScan(false);
      //         return;
      //       }

      //       if (qtyExpectedLocal > 1 && !hasN) {
      //         swalFire({
      //           icon: "info",
      //           title: "Falta el sufijo",
      //           text: "Para este envío necesitás escanear BASE_1 / BASE_2 / BASE_3…",
      //           timer: 1400,
      //           showConfirmButton: false,
      //         });
      //         feedbackScan(false);
      //         return;
      //       }
      //     }
      //   }

      //   // 4) Backend (autoridad) + duplicado local preliminar
      //   // Armamos un "candidate" inicial sólo para anti-duplicado rápido

      //   // Para JSON NO hacemos anti-duplicado PREVIO,
      //   // porque un mismo token (CodigoProveedor) puede sumar bultos (qty) hasta completar.
      //   // El backend ya te controla el tope con paquetes_servicio.
      //   if (!jsonId && looksLikeBase) {
      //     const candidateLocal = esModoColecta()
      //       ? paquetesSvc > 1
      //         ? raw
      //         : `${base}_1`
      //       : qtyExpectedLocal > 1
      //         ? raw
      //         : `${expectedBase}_1`;

      //     if (codigosEscaneados.has(candidateLocal)) {
      //       swalFire({
      //         icon: "info",
      //         title: "Ya escaneado",
      //         text: candidateLocal,
      //         timer: 900,
      //         showConfirmButton: false,
      //       });
      //       feedbackScan(false);
      //       return;
      //     }
      //   }
      //   // const uiLabel = jsonId ? "BULTO (token)" : hasN ? raw : `${base}_1`;
      //   const uiLabel = jsonId ? `TOKEN: ${jsonId}` : looksLikeBase ? (hasN ? raw : `${base}_1`) : `TOKEN: ${raw}`;
      //   let res = null;
      //   try {
      //     // ✅ Backend: si el servicio tiene múltiples bultos, mandamos SIEMPRE la BASE (sin sufijo)
      //     // Backend es autoridad: siempre mandamos raw.
      //     // base/bulto quedan "decorativos" por compatibilidad.
      //     res = await postColectaBulto(base, "", 1, raw);
      //     if (res && res.success == 1 && res.duplicate == 1) {
      //       feedbackScan(false);
      //       swalFire({
      //         icon: "info",
      //         title: "Ya registrado",
      //         text: uiLabel,
      //         timer: 700,
      //         showConfirmButton: false,
      //       });
      //       return;
      //     }

      //     if (!res || res.success != 1) throw new Error("backend rejected");

      //     // si backend devuelve estos campos (recomendado), los usamos
      //     baseReal = (res.cs_base || "").trim();
      //     paquetesSvcBack = parseInt(res.paquetes_servicio || 0, 10) || 0;

      //     feedbackScan(true);
      //   } catch (e) {
      //     console.error("ColectaBulto error:", {
      //       e,
      //       res,
      //       status: e?.status,
      //       responseText: e?.responseText,
      //     });

      //     feedbackScan(false);
      //     swalFire({
      //       icon: "error",
      //       title: "No se pudo registrar",
      //       text: "Ese código no pertenece a la colecta o no se pudo guardar.",
      //     });
      //     return;
      //   }
      //   // 5) Definir codeToStore FINAL (lo que va al select2 y al set)
      //   if (!esModoColecta()) {
      //     // envío normal

      //     codeToStoreFinal = qtyExpectedLocal <= 1 ? `${expectedBase}_1` : raw;
      //   } else {
      //     // colecta
      //     if (jsonId) {
      //       const paquetes = paquetesSvcBack || 1;

      //       if (!baseReal) {
      //         // backend no pudo devolver cs_base: guardamos el id del JSON como fallback
      //         codeToStoreFinal = jsonId ? String(jsonId) : raw;
      //       } else if (paquetes <= 1) {
      //         codeToStoreFinal = `${baseReal}_1`;
      //       } else {
      //         const next = getNextSuffixForBase(baseReal, paquetes);
      //         if (!next) {
      //           swalFire({
      //             icon: "info",
      //             title: "Completo",
      //             text: `Ya están los ${paquetes} bultos de ${baseReal}`,
      //             timer: 900,
      //             showConfirmButton: false,
      //           });
      //           feedbackScan(false);
      //           return;
      //         }
      //         codeToStoreFinal = next;
      //       }
      //     } else {
      //       // NO jsonId (puede ser QR base o token proveedor)
      //       if (!looksLikeBase) {
      //         // token proveedor: usamos lo que manda el backend
      //         const paquetes = paquetesSvcBack || 1;

      //         if (!baseReal) {
      //           codeToStoreFinal = raw; // fallback
      //         } else if (paquetes <= 1) {
      //           codeToStoreFinal = `${baseReal}_1`;
      //         } else {
      //           const next = getNextSuffixForBase(baseReal, paquetes);
      //           if (!next) {
      //             swalFire({
      //               icon: "info",
      //               title: "Completo",
      //               text: `Ya están los ${paquetes} bultos de ${baseReal}`,
      //               timer: 900,
      //               showConfirmButton: false,
      //             });
      //             feedbackScan(false);
      //             return;
      //           }
      //           codeToStoreFinal = next;
      //         }
      //       } else {
      //         // QR normal colecta (tu lógica actual)
      //         if (paquetesSvc > 1) {
      //           const next = getNextSuffixForBase(base, paquetesSvc);
      //           if (!next) {
      //             swalFire({
      //               icon: "info",
      //               title: "Completo",
      //               text: `Ya están los ${paquetesSvc} bultos de ${base}`,
      //               timer: 900,
      //               showConfirmButton: false,
      //             });
      //             feedbackScan(false);
      //             return;
      //           }
      //           codeToStoreFinal = next;
      //         } else {
      //           codeToStoreFinal = `${base}_1`;
      //         }
      //       }
      //     }
      //   }

      //   // anti-duplicado final (por si el backend resolvió distinto)
      //   if (codigosEscaneados.has(codeToStoreFinal)) {
      //     swalFire({
      //       icon: "info",
      //       title: "Ya escaneado",
      //       text: codeToStoreFinal,
      //       timer: 900,
      //       showConfirmButton: false,
      //     });
      //     feedbackScan(false);
      //     return;
      //   }

      //   // 6) Persistir UI
      //   codigosEscaneados.add(codeToStoreFinal);
      //   addToSelect2(codeToStoreFinal);

      //   // 7) Progreso: SIEMPRE desde resume si viene (autoridad)
      //   let ok = 0;
      //   let tot = 0;

      //   if (res && res.resume) {
      //     ok = parseInt(res.resume.paquetes_ok || 0, 10);
      //     tot = parseInt(res.resume.paquetes_total || 0, 10);

      //     if (esModoColecta()) {
      //       $("#colecta-faltan").text(Math.max(tot - ok, 0));
      //     }
      //   } else {
      //     // fallback: el select2 cuenta "items", no "paquetes"
      //     ok = $("#prueba").val()?.length || 0;
      //     tot = esModoColecta() ? buildExpectedCodesForColecta().length || 1 : qtyExpectedLocal || 1;

      //     if (esModoColecta()) {
      //       $("#colecta-faltan").text(Math.max(tot - ok, 0));
      //     }
      //   }

      //   swalFire({
      //     icon: "success",
      //     title: "OK",
      //     text: `Cargado ${ok}/${tot}`,
      //     timer: 650,
      //     showConfirmButton: false,
      //   });
      // };
      const onSuccess = async (decodedText) => {
        await procesarScan(decodedText, "scanner");
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
        await colectaQr.start({ facingMode: "environment" }, isIOS ? configIOS : configHiRes, onSuccess, () => {});
      } catch (e1) {
        console.warn("Start failed, fallback...", e1);
        await colectaQr.start({ facingMode: "environment" }, configIOS, onSuccess, () => {});
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
  $(document).on("click", "#btnIngresoManual", async function () {
    const r = await Swal.fire({
      icon: "question",
      title: "Ingresar código manual",
      input: "text",
      inputPlaceholder: "Pegá o escribí el código (QR / ML JSON / Cod. Proveedor)",
      showCancelButton: true,
      confirmButtonText: "Validar",
      cancelButtonText: "Cancelar",
      inputAttributes: {
        autocapitalize: "none",
        autocorrect: "off",
        spellcheck: "false",
      },
      preConfirm: (val) => {
        const v = String(val || "").trim();
        if (!v) {
          Swal.showValidationMessage("Ingresá un código.");
          return false;
        }
        return v;
      },
    });

    if (!r.isConfirmed) return;

    await procesarScan(r.value, "manual");
  });
  $(document).on("click", "#btnValidarFaltantes", function () {
    const expected = buildExpectedCodesForColecta();

    if (!expected.length) {
      swalFire({
        icon: "info",
        title: "Todavía no cargó la colecta",
        text: "Esperá un segundo y volvé a intentar.",
        timer: 1200,
        showConfirmButton: false,
      });
      return;
    }

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
    const extra = faltan.length > maxShow ? `<br>… y ${faltan.length - maxShow} más` : "";

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
    hardLockSelect2Tags();
    await startColectaScanner();
  });
  $(document).on("hidden.bs.modal", "#colectaScanModal", function () {
    document.body.style.overflowY = "auto";
    document.body.style.webkitOverflowScrolling = "touch";
    unlockSelect2ManualInput();
  });

  setTimeout(() => {
    hardLockSelect2Tags();
  }, 300);
})();
