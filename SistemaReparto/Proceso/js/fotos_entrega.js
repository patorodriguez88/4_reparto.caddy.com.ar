/* =============================================================
   FOTOS DE LA ENTREGA (25/11/2026) — reemplaza al Dropzone.
   -------------------------------------------------------------
   - Hasta 3 fotos por envío, en una sola fila, cada una cuadrada.
   - Sin fotos: un botón grande "Agregar foto". Con 1 o 2: las miniaturas + un casillero "+".
   - Antes de subir, la foto se achica en el teléfono (lado mayor 1600 px, JPEG): sube mucho
     más rápido con datos móviles y ya no se rechazan fotos de más de 3 MB.
   - limpiar() se llama desde limpiarInputsEntrega() (al abrir y al cerrar cada tarjeta). Antes
     la lista de fotos no se vaciaba nunca: la foto seguía en la tarjeta siguiente y se volvía a
     subir con el código de ese otro envío.
   - subir(codigo) sube todas y recién ahí resuelve, una sola vez (antes la confirmación de la
     entrega se mandaba una vez por cada foto).
   ============================================================= */
(function () {
  const MAX_FOTOS = 3;
  const LADO_MAX = 1600;
  const CALIDAD = 0.82;

  let lista = []; // [{ id, blob, url }]
  let secuencia = 0;

  function $fila() {
    return $("#rp-fotos-fila");
  }

  function render() {
    const $f = $fila();
    if (!$f.length) return;
    let html = "";
    lista.forEach(function (f) {
      html +=
        '<div class="rp-foto">' +
        '<img src="' + f.url + '" alt="Foto de la entrega">' +
        '<button type="button" class="rp-foto-quitar" data-id="' + f.id + '" aria-label="Quitar foto">' +
        '<i class="mdi mdi-close"></i></button>' +
        "</div>";
    });
    if (lista.length < MAX_FOTOS) {
      const vacio = lista.length === 0;
      html +=
        '<button type="button" class="rp-foto-agregar' + (vacio ? " rp-foto-agregar--grande" : "") + '">' +
        '<i class="mdi mdi-camera-plus-outline"></i>' +
        "<span>" + (vacio ? "Agregar foto" : "Agregar") + "</span>" +
        (vacio ? "<small>Hasta " + MAX_FOTOS + " fotos</small>" : "") +
        "</button>";
    }
    $f.html(html);
  }

  // Achica la foto en el teléfono. Si algo falla (navegador viejo, formato raro), sube la original.
  function achicar(file) {
    return new Promise(function (resolve) {
      try {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function () {
          try {
            const escala = Math.min(1, LADO_MAX / Math.max(img.naturalWidth, img.naturalHeight));
            const w = Math.max(1, Math.round(img.naturalWidth * escala));
            const h = Math.max(1, Math.round(img.naturalHeight * escala));
            const canvas = document.createElement("canvas");
            canvas.width = w;
            canvas.height = h;
            canvas.getContext("2d").drawImage(img, 0, 0, w, h);
            URL.revokeObjectURL(url);
            canvas.toBlob(
              function (blob) {
                resolve(blob || file);
              },
              "image/jpeg",
              CALIDAD
            );
          } catch (e) {
            URL.revokeObjectURL(url);
            resolve(file);
          }
        };
        img.onerror = function () {
          URL.revokeObjectURL(url);
          resolve(file);
        };
        img.src = url;
      } catch (e) {
        resolve(file);
      }
    });
  }

  function agregar(files) {
    const libres = MAX_FOTOS - lista.length;
    const nuevos = Array.prototype.slice.call(files || [], 0, Math.max(0, libres));
    if ((files || []).length > libres && window.Swal) {
      Swal.fire({ icon: "info", title: "Máximo " + MAX_FOTOS + " fotos", timer: 1800, showConfirmButton: false });
    }
    nuevos.forEach(function (file) {
      if (!/^image\//.test(file.type || "image/")) return;
      // Miniatura inmediata con la original; la versión achicada se prepara en segundo plano.
      const f = { id: ++secuencia, blob: file, url: URL.createObjectURL(file) };
      f.listo = achicar(file).then(function (b) {
        f.blob = b;
      });
      lista.push(f);
    });
    render();
  }

  function quitar(id) {
    lista = lista.filter(function (f) {
      if (f.id === id) {
        URL.revokeObjectURL(f.url);
        return false;
      }
      return true;
    });
    render();
  }

  function limpiar() {
    lista.forEach(function (f) {
      URL.revokeObjectURL(f.url);
    });
    lista = [];
    const input = document.getElementById("rp-foto-input");
    if (input) input.value = "";
    render();
  }

  // Sube todas las fotos del envío a main.php (mismo destino que antes: images/<codigo>/).
  // Una foto ya subida no se repite si la confirmación falla y el chofer vuelve a tocar Aceptar.
  // La carpeta es la del código base (sin _1): main.php solo acepta letras y números.
  function subir(codigo) {
    codigo = String(codigo || "").split("_")[0].trim();
    const fotos = lista.filter(function (f) {
      return !f.subida;
    });
    return Promise.all(
      fotos.map(function (f, i) {
        return Promise.resolve(f.listo).then(function () {
          const fd = new FormData();
          fd.append("file", f.blob, "foto" + (i + 1) + ".jpg");
          fd.append("tituloProducto", codigo);
          return $.ajax({
            url: "Proceso/php/main.php",
            method: "POST",
            data: fd,
            cache: false,
            contentType: false,
            processData: false,
            timeout: 60000,
          }).then(function (r) {
            f.subida = true;
            return r;
          });
        });
      })
    );
  }

  $(document).on("click", ".rp-foto-agregar", function () {
    const input = document.getElementById("rp-foto-input");
    if (input) input.click();
  });
  $(document).on("change", "#rp-foto-input", function () {
    agregar(this.files);
    this.value = ""; // permite volver a elegir la misma foto
  });
  $(document).on("click", ".rp-foto-quitar", function () {
    quitar(parseInt($(this).attr("data-id"), 10));
  });

  window.fotosEntrega = {
    cantidad: function () {
      return lista.length;
    },
    limpiar: limpiar,
    subir: subir,
    render: render,
  };

  $(render);
})();
