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
  function postColectaBulto(base, bulto) {
    return $.ajax({
      url: "Proceso/php/colecta_scan.php",
      type: "POST",
      dataType: "json",
      data: {
        ColectaBulto: 1,
        base: base, // BASE sin _n
        bulto: bulto, // lo escaneado: BASE_1 / BASE_2 / BASE_3 (o BASE)
      },
    });
  }

  // function getNextIndexForBase(base) {
  //   // Busca el próximo _n libre según lo que ya está en el select2
  //   const selected = getSelectedValues();
  //   let maxN = 0;

  //   selected.forEach((v) => {
  //     const s = (v || "").toString();
  //     const parts = s.split("_");
  //     if (parts[0] === base && parts.length === 2) {
  //       const n = parseInt(parts[1], 10);
  //       if (!isNaN(n)) maxN = Math.max(maxN, n);
  //     }
  //     if (s === base) {
  //       // si alguien cargó base “pelado”, lo consideramos como 1
  //       maxN = Math.max(maxN, 1);
  //     }
  //   });

  //   return maxN + 1;
  // }

  // function countForBase(base) {
  //   const selected = getSelectedValues();
  //   let c = 0;
  //   selected.forEach((v) => {
  //     const s = (v || "").toString();
  //     if (s === base) c++;
  //     if (s.startsWith(base + "_")) c++;
  //   });
  //   return c;
  // }

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
    const onSuccess = async (decodedText) => {
      const raw = (decodedText || "").trim();
      if (!raw) return;

      // anti-rebote (mismo frame)
      const now = Date.now();
      if (raw === colectaLast && now - colectaLastT < 900) return;
      colectaLast = raw;
      colectaLastT = now;

      const expectedBase = ($("#card-seguimiento").text() || "")
        .trim()
        .split("_")[0]
        .trim();
      const qtyExpected =
        parseInt(($("#card-receptor-cantidad").text() || "1").trim(), 10) || 1;

      // Ej: raw = BASE_2
      const base = raw.split("_")[0].trim();
      const hasN = raw.includes("_");

      // 1) validar que corresponde al envío abierto
      if (!expectedBase) {
        swalFire({
          icon: "warning",
          title: "Sin envío",
          text: "Abrí un envío antes de escanear.",
        });
        return;
      }
      if (base !== expectedBase) {
        swalFire({
          icon: "error",
          title: "Código incorrecto",
          text: `Escaneaste ${base} y se esperaba ${expectedBase}`,
          timer: 1400,
          showConfirmButton: false,
        });
        return;
      }

      // 2) si hay más de 1 bulto, el QR DEBE venir con _n
      if (qtyExpected > 1 && !hasN) {
        swalFire({
          icon: "info",
          title: "Falta el sufijo",
          text: "Para este envío necesitás escanear el QR que dice BASE_1 / BASE_2 / BASE_3…",
          timer: 1400,
          showConfirmButton: false,
        });
        return;
      }

      // 3) si qtyExpected == 1 aceptamos base pelado o base_1 (normalizamos a base)
      let codeToStore = raw;
      if (qtyExpected <= 1) {
        codeToStore = expectedBase; // para que no te quede BASE_1 mezclado
      }

      // 4) anti-duplicado real por código completo
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

      // 5) guardar y mostrar
      codigosEscaneados.add(codeToStore);
      addToSelect2(codeToStore);
      try {
        // Guardamos referencia exacta del bulto en Observaciones
        await postColectaBulto(expectedBase, raw);
      } catch (e) {
        console.error("ColectaBulto error:", e);
        swalFire({
          icon: "error",
          title: "No se pudo registrar",
          text: "Se leyó el QR pero no se pudo guardar el bulto en el sistema.",
        });
        return;
      }

      // 6) feedback con progreso real (cuántos _n ya tenés)
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

  // Abrir modal + start
  // $(document).on("click", "#btnEscanear", async function () {
  //   const modalEl = document.getElementById("colectaScanModal");
  //   // const modal = new bootstrap.Modal(modalEl);
  //   const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  //   codigosEscaneados.clear();
  //   colectaLast = "";
  //   colectaLastT = 0;

  //   modal.show();
  //   await startScanner();
  // });

  // Stop al cerrar
  // $(document).on("hidden.bs.modal", "#colectaScanModal", async function () {
  //   await stopScanner();
  // });
  // $(document).on("hide.bs.modal", "#colectaScanModal", function () {
  //   document.activeElement?.blur();
  // });

  // Stop manual si lo tenés
  // $(document).on("click", "#btnStopColectaScan", async function () {
  //   await stopScanner();
  //   swalFire({
  //     icon: "info",
  //     title: "Scanner detenido",
  //     timer: 700,
  //     showConfirmButton: false,
  //   });
  // });
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
