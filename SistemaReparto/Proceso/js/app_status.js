(function () {
  console.log("✅ app_status.js cargado");

  function getOrCreateDeviceId() {
    const key = "caddy_device_id";
    let id = localStorage.getItem(key);
    if (!id) {
      id =
        crypto && crypto.randomUUID
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

  function sendAppStatus() {
    const payload = {
      app: "reparto",
      version: window.APP_VERSION || "1.0.0",
      device_id: getOrCreateDeviceId(),
      is_standalone: isStandaloneMode(),
      platform: navigator.platform || null,
    };

    // ⚠️ poné la ruta ABSOLUTA real de tu proyecto
    const url = "/SistemaReparto/Proceso/php/app_status.php";

    console.log("📡 Enviando app_status a:", url, payload);

    fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      credentials: "include",
    })
      .then((r) => r.text())
      .then((t) => console.log("✅ Respuesta app_status:", t))
      .catch((e) => console.error("❌ Error app_status:", e));
  }

  // Ejecutar SIEMPRE, incluso si DOMContentLoaded ya pasó
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", sendAppStatus);
  } else {
    sendAppStatus();
  }
})();
