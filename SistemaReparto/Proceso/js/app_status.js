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
      (window.matchMedia &&
        window.matchMedia("(display-mode: standalone)").matches) ||
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
        console.warn(
          "⚠️ app_status devolvió HTML (no JSON). Posible sesión caída/redirect.",
        );
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
})();
