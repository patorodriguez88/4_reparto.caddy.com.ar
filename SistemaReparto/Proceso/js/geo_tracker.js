(function () {
  console.log("✅ geo_tracker.js cargado");

  // watchPosition dispara mucho más seguido de lo que conviene mandar al
  // servidor (puede tirar varias veces por minuto, o al toque si cambia de
  // celda/wifi) - esto throttlea el envío real para no gastar de más los
  // datos móviles del repartidor. 35s de mínimo entre envíos.
  var MIN_MS_ENTRE_ENVIOS = 35000;
  var ultimoEnvio = 0;
  var watchId = null;
  var ultimaPosicion = null;

  // Expuesto para que otras pantallas (mapa del recorrido, "Iniciar Recorrido")
  // reusen la misma posición ya obtenida y el mismo pedido de permiso.
  window.CaddyGeo = {
    getLastPosition: function () {
      return ultimaPosicion;
    },
    // Pide una posición ahora. Si el permiso está sin decidir, dispara el
    // prompt del sistema. onOk({lat,lng,accuracy}), onFail(codigo) donde
    // codigo: 'denied' | 'unavailable' | 'timeout' | 'nosoporta'.
    solicitar: function (onOk, onFail) {
      pedirUbicacionAhora(onOk, onFail);
    },
  };

  // -------------------------------------------------------------------------
  // Estado de la app: solo molestamos con el permiso cuando el chofer ya está
  // adentro (login oculto).
  // -------------------------------------------------------------------------
  function estaLogueado() {
    var login = document.getElementById("login");
    // Si no existe el nodo de login, asumimos que estamos en la app.
    if (!login) return true;
    var vis = window.getComputedStyle(login).display !== "none";
    return !vis;
  }

  // 'granted' | 'prompt' | 'denied' | 'desconocido'
  function estadoPermiso() {
    return new Promise(function (resolve) {
      if (!navigator.permissions || !navigator.permissions.query) {
        resolve("desconocido");
        return;
      }
      navigator.permissions
        .query({ name: "geolocation" })
        .then(function (st) {
          resolve(st.state || "desconocido");
          // Si el chofer cambia el permiso desde los ajustes, reaccionamos.
          st.onchange = function () {
            if (st.state === "granted") {
              ocultarTarjetaPermiso();
              iniciarTracking();
            } else {
              revisarPermisoYMostrar();
            }
          };
        })
        .catch(function () {
          resolve("desconocido");
        });
    });
  }

  // -------------------------------------------------------------------------
  // Tarjeta de permiso (más visible que el banner fino de antes).
  // modo: 'pedir'  -> permiso sin decidir: botón "Permitir ubicación"
  //       'bloqueado' -> permiso denegado: instrucciones + "Reintentar"
  // -------------------------------------------------------------------------
  function ocultarTarjetaPermiso() {
    var t = document.getElementById("geo-permiso-card");
    if (t) t.remove();
    var b = document.getElementById("geo-permiso-banner");
    if (b) b.remove();
  }

  function mostrarTarjetaPermiso(modo) {
    ocultarTarjetaPermiso();

    var card = document.createElement("div");
    card.id = "geo-permiso-card";
    card.style.cssText =
      "position:fixed;left:12px;right:12px;bottom:70px;z-index:3000;" +
      "background:#fff7ed;border:1px solid #fdba74;color:#7c2d12;" +
      "border-radius:14px;padding:14px 16px;font-size:14px;line-height:1.45;" +
      "box-shadow:0 6px 20px rgba(0,0,0,.18);";

    if (modo === "bloqueado") {
      card.innerHTML =
        '<div style="font-weight:700;margin-bottom:4px;">La ubicación está bloqueada</div>' +
        "<div>Activala para que administración te vea en el recorrido:</div>" +
        '<ol style="margin:6px 0 10px 18px;padding:0;">' +
        "<li>Tocá el candado 🔒 (o el ⓘ) al lado de la dirección, arriba.</li>" +
        "<li>Entrá a <b>Permisos</b> → <b>Ubicación</b> → <b>Permitir</b>.</li>" +
        "<li>Si no aparece, andá a <b>Ajustes del teléfono</b> → <b>Ubicación</b> y activala.</li>" +
        "</ol>" +
        '<button id="geo-card-retry" style="background:#ea580c;color:#fff;border:0;border-radius:10px;padding:9px 16px;font-size:14px;font-weight:600;">Ya la activé, reintentar</button>';
    } else {
      card.innerHTML =
        '<div style="font-weight:700;margin-bottom:4px;">Necesitamos tu ubicación</div>' +
        "<div style=\"margin-bottom:10px;\">Es para que administración te vea en el recorrido y calcule los tiempos. Tocá <b>Permitir</b> cuando el teléfono te lo pregunte.</div>" +
        '<button id="geo-card-allow" style="background:#ea580c;color:#fff;border:0;border-radius:10px;padding:10px 18px;font-size:14px;font-weight:700;">Permitir ubicación</button>';
    }

    document.body.appendChild(card);

    var allow = document.getElementById("geo-card-allow");
    if (allow) {
      allow.addEventListener("click", function () {
        allow.disabled = true;
        allow.textContent = "Esperando...";
        pedirUbicacionAhora(
          function () {
            ocultarTarjetaPermiso();
            iniciarTracking();
          },
          function (codigo) {
            if (codigo === "denied") {
              mostrarTarjetaPermiso("bloqueado");
            } else {
              allow.disabled = false;
              allow.textContent = "Reintentar";
            }
          }
        );
      });
    }

    var retry = document.getElementById("geo-card-retry");
    if (retry) {
      retry.addEventListener("click", function () {
        retry.disabled = true;
        retry.textContent = "Probando...";
        pedirUbicacionAhora(
          function () {
            ocultarTarjetaPermiso();
            iniciarTracking();
          },
          function () {
            revisarPermisoYMostrar();
          }
        );
      });
    }
  }

  function revisarPermisoYMostrar() {
    if (!estaLogueado()) {
      ocultarTarjetaPermiso();
      return;
    }
    if (!("geolocation" in navigator)) return;

    estadoPermiso().then(function (estado) {
      if (estado === "granted") {
        ocultarTarjetaPermiso();
        iniciarTracking();
      } else if (estado === "denied") {
        mostrarTarjetaPermiso("bloqueado");
      } else {
        // 'prompt' o 'desconocido': ofrecemos el botón para disparar el prompt.
        mostrarTarjetaPermiso("pedir");
      }
    });
  }

  // -------------------------------------------------------------------------
  function pedirUbicacionAhora(onOk, onFail) {
    onOk = onOk || function () {};
    onFail = onFail || function () {};

    if (!("geolocation" in navigator)) {
      onFail("nosoporta");
      return;
    }

    navigator.geolocation.getCurrentPosition(
      function (pos) {
        ultimaPosicion = {
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          accuracy: pos.coords.accuracy,
          ts: Date.now(),
        };
        onOk(ultimaPosicion);
      },
      function (error) {
        var c = "unavailable";
        if (error && error.code === error.PERMISSION_DENIED) c = "denied";
        else if (error && error.code === error.TIMEOUT) c = "timeout";
        console.warn("⚠️ geolocation getCurrentPosition:", error && error.message);
        onFail(c);
      },
      { enableHighAccuracy: true, maximumAge: 30000, timeout: 15000 }
    );
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
        ocultarTarjetaPermiso();
        ultimaPosicion = {
          lat: position.coords.latitude,
          lng: position.coords.longitude,
          accuracy: position.coords.accuracy,
          ts: Date.now(),
        };
        enviarUbicacion(position);
      },
      function (error) {
        if (error.code === error.PERMISSION_DENIED) {
          revisarPermisoYMostrar();
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

  // -------------------------------------------------------------------------
  // Arranque: apenas carga la app (y el chofer ya entró), revisamos el
  // permiso. Si falta, mostramos la tarjeta para resolverlo AL INICIO en vez
  // de que falle recién al tocar "Iniciar Recorrido".
  // -------------------------------------------------------------------------
  function arrancar() {
    iniciarTracking(); // dispara el prompt nativo si nunca se decidió
    // Damos un momento a que el login se resuelva antes de decidir si molestar.
    setTimeout(revisarPermisoYMostrar, 1200);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", arrancar);
  } else {
    arrancar();
  }

  // Al volver a la app (cambió de app y volvió), revalidamos.
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") revisarPermisoYMostrar();
  });
})();
