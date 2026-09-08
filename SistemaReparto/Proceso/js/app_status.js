(function () {
  console.log("✅ app_status.js cargado");

  function getOrCreateDeviceId() {
    const key = "caddy_device_id";
    let id = localStorage.getItem(key);
    if (!id) {
      id =
        window.crypto && crypto.randomUUID
          ? crypto.randomUUID()
          : "dev_" + Date.now() + "_" + Math.random().toString(16).slice(2);
      localStorage.setItem(key, id);
    }
    return id;
  }

  function isStandaloneMode() {
    return (
      (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches) ||
      window.navigator.standalone === true
    );
  }

  async function sendAppStatus() {
    const payload = {
      app: "reparto",
      version: window.APP_VERSION || "1.0.0",
      device_id: getOrCreateDeviceId(),
      is_standalone: isStandaloneMode(),
      platform: navigator.platform || null,
      ua: navigator.userAgent || null,
      ts: new Date().toISOString(),
    };

    // ✅ ruta absoluta (como ya venías usando)
    const url = "/SistemaReparto/Proceso/php/app_status.php";

    console.log("📡 Enviando app_status a:", url, payload);

    try {
      const r = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "Cache-Control": "no-cache",
        },
        body: JSON.stringify(payload),
        credentials: "include",
      });

      const text = await r.text();
      const t = (text || "").trim();

      // ⚠️ Si el server devolvió HTML (login/redirect/error), lo detectamos y no seguimos
      if (t.startsWith("<!DOCTYPE") || t.startsWith("<html")) {
        console.warn("⚠️ app_status devolvió HTML (no JSON). Posible sesión caída/redirect.");
        // Si querés: recargar o ignorar. Yo prefiero ignorar para no molestar.
        return;
      }

      let data = null;
      try {
        data = JSON.parse(t);
      } catch (e) {
        console.warn("⚠️ app_status no devolvió JSON válido:", t.slice(0, 200));
        return;
      }

      // Si el backend pide logout
      if (data && data.forceLogout) {
        console.warn("⚠️ app_status forceLogout:", data.reason || "");
        if (typeof cerrarSesionForzada === "function") {
          cerrarSesionForzada(data.reason || "NO_SESSION");
        } else {
          // fallback
          window.location.reload();
        }
        return;
      }

      console.log("✅ app_status JSON:", data);
    } catch (e) {
      console.error("❌ Error app_status:", e);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", sendAppStatus);
  } else {
    sendAppStatus();
  }

  // -------------------------------------------------------------------------
  // Chequeo de versión. No hay Service Worker y el navegador no vuelve a bajar
  // el JS hasta que se cierra la app, así que si se deploya código nuevo
  // mientras la PWA está abierta el chofer se queda con la versión vieja en
  // memoria (fue lo que pasó con los km de regreso). Esto consulta
  // /version.php al abrir, cada vez que la app vuelve a primer plano y cada
  // 10 min; si cambió el "build" -> recarga (o banner si el chofer está en
  // medio de un modal).
  // -------------------------------------------------------------------------
  var CADDY_BUILD = null;
  var bannerUpdateVisible = false;

  function pantallaSegura() {
    // Login a la vista = seguro. Con un modal de SweetAlert abierto (escaneo,
    // cierre de recorrido, confirmaciones) NO recargamos para no cortarlo.
    var login = document.getElementById("login");
    if (login && window.getComputedStyle(login).display !== "none") return true;
    if (document.querySelector(".swal2-container")) return false;
    return true;
  }

  function mostrarBannerUpdate() {
    if (bannerUpdateVisible || document.getElementById("caddy-update-bar")) return;
    bannerUpdateVisible = true;
    var b = document.createElement("div");
    b.id = "caddy-update-bar";
    b.style.cssText =
      "position:fixed;left:0;right:0;top:0;z-index:99999;background:#ea580c;" +
      "color:#fff;padding:11px 14px;font:600 14px/1.3 system-ui,-apple-system,sans-serif;" +
      "text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.28);cursor:pointer";
    b.textContent = "Hay una versión nueva. Tocá acá para actualizar.";
    b.addEventListener("click", function () {
      location.reload();
    });
    document.body.appendChild(b);
  }

  function chequearVersion() {
    fetch("/SistemaReparto/version.php?t=" + Date.now(), {
      cache: "no-store",
      credentials: "include",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        if (!d || !d.build) return;
        if (CADDY_BUILD === null) {
          CADDY_BUILD = d.build; // primera lectura = build con el que arrancó la app
          return;
        }
        if (d.build === CADDY_BUILD) return; // al día
        // Hay código nuevo en el server.
        if (d.forzar || pantallaSegura()) {
          location.reload();
        } else {
          mostrarBannerUpdate();
        }
      })
      .catch(function () {
        /* sin red: no molestamos */
      });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", chequearVersion);
  } else {
    chequearVersion();
  }
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") chequearVersion();
  });
  setInterval(chequearVersion, 10 * 60 * 1000);
})();
