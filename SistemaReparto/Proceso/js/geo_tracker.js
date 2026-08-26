(function () {
  console.log("✅ geo_tracker.js cargado");

  // watchPosition dispara mucho más seguido de lo que conviene mandar al
  // servidor (puede tirar varias veces por minuto, o al toque si cambia de
  // celda/wifi) - esto throttlea el envío real para no gastar de más los
  // datos móviles del repartidor. 35s de mínimo entre envíos.
  var MIN_MS_ENTRE_ENVIOS = 35000;
  var ultimoEnvio = 0;
  var watchId = null;

  function mostrarBannerPermisoDenegado() {
    if (document.getElementById("geo-permiso-banner")) return;

    var banner = document.createElement("div");
    banner.id = "geo-permiso-banner";
    banner.style.cssText =
      "position:fixed;left:0;right:0;bottom:64px;z-index:2000;" +
      "background:#fef3c7;color:#78350f;padding:10px 16px;font-size:13px;" +
      "text-align:center;box-shadow:0 -2px 6px rgba(0,0,0,.08);";
    banner.textContent =
      "Activá la ubicación para que podamos verte en tu recorrido.";
    document.body.appendChild(banner);
  }

  function ocultarBannerPermisoDenegado() {
    var banner = document.getElementById("geo-permiso-banner");
    if (banner) banner.remove();
  }

  function enviarUbicacion(position) {
    var ahora = Date.now();
    if (ahora - ultimoEnvio < MIN_MS_ENTRE_ENVIOS) return;
    ultimoEnvio = ahora;

    var payload = {
      lat: position.coords.latitude,
      lng: position.coords.longitude,
      accuracy: position.coords.accuracy,
      ts: new Date().toISOString(),
    };

    fetch("/SistemaReparto/Proceso/php/ubicacion.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      credentials: "include",
    }).catch(function (e) {
      console.warn("⚠️ No se pudo enviar la ubicación:", e);
    });
  }

  function iniciarTracking() {
    if (!("geolocation" in navigator)) {
      console.warn("⚠️ Este dispositivo no soporta geolocalización.");
      return;
    }

    if (watchId !== null) return; // ya está corriendo

    watchId = navigator.geolocation.watchPosition(
      function (position) {
        ocultarBannerPermisoDenegado();
        enviarUbicacion(position);
      },
      function (error) {
        if (error.code === error.PERMISSION_DENIED) {
          mostrarBannerPermisoDenegado();
        }
        console.warn("⚠️ geolocation error:", error.message);
      },
      {
        enableHighAccuracy: false,
        maximumAge: 30000,
        timeout: 20000,
      }
    );
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciarTracking);
  } else {
    iniciarTracking();
  }
})();
