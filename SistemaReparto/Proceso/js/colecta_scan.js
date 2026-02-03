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
  function postColectaBulto(base, token, cantidad = 1) {
    return $.ajax({
      url: "Proceso/php/colecta_scan.php",
      type: "POST",
      dataType: "json",
      data: {
        ColectaBulto: 1,
        base: base, // shipments_id o CodigoSeguimiento
        bulto: token, // auditoría
        cantidad: cantidad, // 👈 NUEVO
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

      // 2️⃣ VALIDACIÓN DE BASE (solo si NO es JSON)
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

      // 3️⃣ múltiples bultos → exigir sufijo (solo QR normal)
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

      // 4️⃣ normalización del código a guardar
      let codeToStore;
      if (jsonId) {
        codeToStore = base; // shipments_id o tracking resuelto
      } else if (qtyExpected <= 1) {
        codeToStore = expectedBase;
      } else {
        codeToStore = raw;
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

    // const onSuccess = async (decodedText) => {
    //   // const raw = (decodedText || "").trim();
    //   // if (!raw) return;

    //   // // anti-rebote (mismo frame)
    //   // const now = Date.now();
    //   // if (raw === colectaLast && now - colectaLastT < 900) return;
    //   // colectaLast = raw;
    //   // colectaLastT = now;

    //   // const expectedBase = ($("#card-seguimiento").text() || "")
    //   //   .trim()
    //   //   .split("_")[0]
    //   //   .trim();
    //   // const qtyExpected =
    //   //   parseInt(($("#card-receptor-cantidad").text() || "1").trim(), 10) || 1;

    //   // // Ej: raw = BASE_2
    //   // const base = raw.split("_")[0].trim();
    //   // const hasN = raw.includes("_");
    //   const raw = (decodedText || "").trim();
    //   if (!raw) return;

    //   // anti-rebote (mismo frame)
    //   const now = Date.now();
    //   if (raw === colectaLast && now - colectaLastT < 900) return;
    //   colectaLast = raw;
    //   colectaLastT = now;

    //   const expectedBase = ($("#card-seguimiento").text() || "")
    //     .trim()
    //     .split("_")[0]
    //     .trim();
    //   const qtyExpected =
    //     parseInt(($("#card-receptor-cantidad").text() || "1").trim(), 10) || 1;

    //   // Ej: raw = BASE_2
    //   // const base = raw.split("_")[0].trim();
    //   // const hasN = raw.includes("_");
    //   // const expectedBase = ($("#card-seguimiento").text() || "")
    //   //   .trim()
    //   //   .split("_")[0]
    //   //   .trim();
    //   // let qtyExpected =
    //   //   parseInt(($("#card-receptor-cantidad").text() || "1").trim(), 10) || 1;

    //   // ✅ Si el QR viene como JSON, uso su "id" como token escaneado
    //   const jsonId = extraerIdDesdeJson(raw);

    //   // scannedToken = lo que guardo en observaciones (auditoría)
    //   // base = lo que uso para buscar y validar
    //   let scannedToken = raw;
    //   let base = "";
    //   let hasN = false;

    //   if (jsonId) {
    //     scannedToken = jsonId; // 👈 guardamos SOLO el id externo (4225...)
    //     base = jsonId; // 👈 se lo mandamos al backend para que resuelva shipments_id
    //     qtyExpected = 1; // 👈 por ahora, siempre 1 bulto
    //     hasN = false;
    //   } else {
    //     base = raw.split("_")[0].trim();
    //     hasN = raw.includes("_");
    //   }
    //   function extraerIdDesdeJson(raw) {
    //     const t = (raw || "").trim();
    //     if (!t.startsWith("{") || !t.endsWith("}")) return null;
    //     try {
    //       const obj = JSON.parse(t);
    //       return obj?.id ? String(obj.id).trim() : null;
    //     } catch (e) {
    //       return null;
    //     }
    //   }

    //   // 1) validar que corresponde al envío abierto
    //   if (!expectedBase) {
    //     swalFire({
    //       icon: "warning",
    //       title: "Sin envío",
    //       text: "Abrí un envío antes de escanear.",
    //     });
    //     return;
    //   }
    //   // if (base !== expectedBase) {
    //   //   swalFire({
    //   //     icon: "error",
    //   //     title: "Código incorrecto",
    //   //     text: `Escaneaste ${base} y se esperaba ${expectedBase}`,
    //   //     timer: 1400,
    //   //     showConfirmButton: false,
    //   //   });
    //   //   return;
    //   // }

    //   // 2) si hay más de 1 bulto, el QR DEBE venir con _n
    //   if (qtyExpected > 1 && !hasN) {
    //     swalFire({
    //       icon: "info",
    //       title: "Falta el sufijo",
    //       text: "Para este envío necesitás escanear el QR que dice BASE_1 / BASE_2 / BASE_3…",
    //       timer: 1400,
    //       showConfirmButton: false,
    //     });
    //     return;
    //   }

    //   // 3) si qtyExpected == 1 aceptamos base pelado o base_1 (normalizamos a base)
    //   let codeToStore = raw;
    //   if (qtyExpected <= 1) {
    //     codeToStore = expectedBase; // para que no te quede BASE_1 mezclado
    //   }

    //   // 4) anti-duplicado real por código completo
    //   if (codigosEscaneados.has(codeToStore)) {
    //     swalFire({
    //       icon: "info",
    //       title: "Ya escaneado",
    //       text: codeToStore,
    //       timer: 900,
    //       showConfirmButton: false,
    //     });
    //     return;
    //   }

    //   // 5) guardar y mostrar
    //   codigosEscaneados.add(codeToStore);
    //   addToSelect2(codeToStore);
    //   try {
    //     // Guardamos referencia exacta del bulto en Observaciones
    //     // await postColectaBulto(expectedBase, raw);
    //     await postColectaBulto(base, scannedToken);
    //   } catch (e) {
    //     console.error("ColectaBulto error:", e);
    //     swalFire({
    //       icon: "error",
    //       title: "No se pudo registrar",
    //       text: "Se leyó el QR pero no se pudo guardar el bulto en el sistema.",
    //     });
    //     return;
    //   }

    //   // 6) feedback con progreso real (cuántos _n ya tenés)
    //   const cargados = $("#prueba").val()?.length || 0;

    //   swalFire({
    //     icon: "success",
    //     title: "OK",
    //     text: `Cargado ${cargados}/${qtyExpected}`,
    //     timer: 650,
    //     showConfirmButton: false,
    //   });
    // };

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
