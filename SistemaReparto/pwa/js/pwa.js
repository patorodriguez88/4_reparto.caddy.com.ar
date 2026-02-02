(function () {
  function syncLoginLock() {
    var login = document.getElementById("login");
    if (!login) return;
    var visible = getComputedStyle(login).display !== "none";
    document.body.classList.toggle("login-lock", visible);
  }
  function setActiveNav(screen) {
    document.querySelectorAll("#app-bottomnav .nav-item").forEach((a) => {
      a.classList.toggle("active", a.dataset.screen === screen);
    });
  }

  function showScreen(screen) {
    // Screens:
    // - operacion: tu contenedor #hdr
    // - totales: #screen-totales
    // - recorrido: usa el menú existente (mi_recorrido)
    // - cuenta: usa el menú existente (mi_cuenta)

    // Mostrar bottom nav cuando ya está logueado (navbar/topnav visibles)
    var bottom = document.getElementById("app-bottomnav");
    if (
      bottom &&
      document.getElementById("navbar") &&
      document.getElementById("topnav")
    ) {
      // Si tu lógica ya hace display:block en navbar/topnav al entrar, acompañamos
      if (
        getComputedStyle(document.getElementById("navbar")).display !== "none"
      ) {
        bottom.style.display = "flex";
      }
    }
    syncLoginLock();

    // Cambios visuales
    if (screen === "operacion") {
      document.getElementById("screen-totales")?.classList.remove("active");
      document.getElementById("hdr")?.classList.add("active");
      setActiveNav("operacion");
      syncLoginLock();
      return;
    }

    if (screen === "totales") {
      // Clonar el bloque mis_envios a la pantalla Totales (una sola vez)
      var src = document.getElementById("mis_envios");
      var dst = document.getElementById("mis_envios_clone");
      if (src && dst && dst.children.length === 0) {
        var clone = src.cloneNode(true);
        clone.id = "mis_envios_screen";
        clone.style.display = "block";
        dst.appendChild(clone);
      }

      document.getElementById("hdr")?.classList.remove("active");
      document.getElementById("screen-totales")?.classList.add("active");
      setActiveNav("totales");
      syncLoginLock();
      return;
    }

    if (screen === "recorrido") {
      // Reutiliza tu click existente
      document.getElementById("mi_recorrido")?.click();
      setActiveNav("recorrido");
      syncLoginLock();
      return;
    }

    if (screen === "cuenta") {
      document.getElementById("mi_cuenta")?.click();
      setActiveNav("cuenta");
      syncLoginLock();
      return;
    }
  }

  document.addEventListener("click", function (ev) {
    var a = ev.target.closest && ev.target.closest("#app-bottomnav .nav-item");
    if (!a) return;
    ev.preventDefault();
    var screen = a.dataset.screen;
    if (!screen) return;
    // Actualiza hash para back/forward
    history.pushState({ screen: screen }, "", "#" + screen);
    showScreen(screen);
  });

  window.addEventListener("popstate", function (e) {
    var screen =
      (e.state && e.state.screen) ||
      (location.hash || "#operacion").replace("#", "");
    showScreen(screen);
  });

  // Inicio por hash
  document.addEventListener("DOMContentLoaded", function () {
    var initial = (location.hash || "#operacion").replace("#", "");
    showScreen(initial);
    syncLoginLock();
  });
})();
// =========================
// PWA Install (campanita)
// =========================

window.deferredPromptPWA = null;

function isIOS() {
  return /iphone|ipad|ipod/i.test(navigator.userAgent);
}

function isInStandaloneMode() {
  if (window.navigator.standalone) return true; // iOS
  return window.matchMedia("(display-mode: standalone)").matches; // others
}

// Click campanita (delegado, funciona aunque el navbar aparezca después)
$(document).on("click", "#btnInstallBell", async function (e) {
  e.preventDefault();
  e.stopPropagation();

  // Marcar cooldown (no insistir 7 días)
  localStorage.setItem("install_offer_last", String(Date.now()));
  // refreshInstallDot();

  // iOS: instrucciones
  if (isIOS()) {
    Swal.fire({
      icon: "info",
      title: "Instalar la app",
      html: "En Safari tocá <b>Compartir</b> y elegí <b>“Agregar a pantalla de inicio”</b>.",
      confirmButtonText: "Entendido",
      customClass: { container: "caddy-login-swal" },
    });
    return;
  }

  // Android/Chrome: prompt real si está disponible
  if (window.deferredPromptPWA) {
    window.deferredPromptPWA.prompt();
    await window.deferredPromptPWA.userChoice;
    window.deferredPromptPWA = null;
    return;
  }

  // Fallback
  Swal.fire({
    icon: "info",
    title: "Instalar",
    text: "Abrí el menú del navegador (⋮) y elegí “Instalar app”.",
    confirmButtonText: "OK",
    customClass: { container: "caddy-login-swal" },
  });
});
function isAppInstalled() {
  return (
    (window.matchMedia &&
      window.matchMedia("(display-mode: standalone)").matches) ||
    window.navigator.standalone === true
  );
}
function disableBellIndicator() {
  // Ajustá estos selectores a tu HTML real
  $(".noti-icon-badge").hide();
}
function lockBellClickIfInstalled() {
  // Ajustá selector al botón real de la campanita
  // Ej: #bell, #notificationDropdown, .nav-link[data-bs-toggle="dropdown"]
  $(document).on(
    "click",
    "#notificationDropdown, #bell, .noti-icon",
    function (e) {
      if (!isAppInstalled()) return;

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation(); // corta handlers ya registrados
      return false;
    },
  );
}
