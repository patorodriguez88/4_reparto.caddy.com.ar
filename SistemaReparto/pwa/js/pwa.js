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

let deferredPrompt = null;

function isIOS() {
  return /iphone|ipad|ipod/i.test(navigator.userAgent);
}

function isInStandaloneMode() {
  // iOS
  if (window.navigator.standalone) return true;
  // Android/Chrome
  return window.matchMedia("(display-mode: standalone)").matches;
}

function shouldShowInstallBanner() {
  if (isInStandaloneMode()) return false;

  const last = parseInt(localStorage.getItem("install_banner_last") || "0", 10);
  const now = Date.now();
  const sevenDays = 7 * 24 * 60 * 60 * 1000;

  // no molestar por 7 días si tocó "Más tarde"
  if (last && now - last < sevenDays) return false;

  return true;
}

function isLoginVisible() {
  const el = document.getElementById("login");
  if (!el) return false;
  return getComputedStyle(el).display !== "none";
}

function showInstallBanner() {
  if (!shouldShowInstallBanner()) return;

  // 🚫 NO mostrar si el login está visible
  if (isLoginVisible() || document.body.classList.contains("login-lock"))
    return;

  $("#installBanner").fadeIn(150);
}

function hideInstallBanner(remember) {
  $("#installBanner").fadeOut(150);
  if (remember) localStorage.setItem("install_banner_last", String(Date.now()));
}

// Android/Chrome: captura el prompt nativo
window.addEventListener("beforeinstallprompt", (e) => {
  e.preventDefault();
  deferredPrompt = e;
});

// Click “Instalar”
$(document).on("click", "#btnInstallNow", async function () {
  // iOS: no hay prompt, mostramos instrucciones
  if (isIOS()) {
    hideInstallBanner(false);
    Swal.fire({
      icon: "info",
      title: "Agregar a pantalla de inicio",
      html: "En Safari tocá <b>Compartir</b> (cuadrado con flecha) y elegí <b>“Agregar a pantalla de inicio”</b>.",
      confirmButtonText: "Entendido",
      customClass: { container: "caddy-login-swal" }, // o el z-index alto que ya usás
    });
    return;
  }

  // Android: prompt real
  if (deferredPrompt) {
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    deferredPrompt = null;

    // Si aceptó, no mostrar más
    if (outcome === "accepted") {
      hideInstallBanner(true);
    } else {
      // si canceló, que vuelva a sugerir en unos días
      hideInstallBanner(true);
    }
  } else {
    // No está disponible el evento aún (o browser no compatible)
    hideInstallBanner(false);
    Swal.fire({
      icon: "info",
      title: "Instalación",
      text: "Tu navegador no permite el prompt automático. Podés instalar desde el menú del navegador.",
      confirmButtonText: "OK",
      customClass: { container: "caddy-login-swal" },
    });
  }
});

// Click “Más tarde”
$(document).on("click", "#btnInstallLater", function () {
  hideInstallBanner(true);
});
