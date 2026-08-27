(function () {
  var MAX_PARADAS_CON_RUTA = 23; // límite de waypoints intermedios de Directions API (25 - origen - destino)
  var POSICION_MAX_EDAD_MS = 2 * 60 * 1000; // 2 min: más vieja que eso, mejor pedir una nueva

  var mapsCargado = false;
  var mapsCargando = null; // Promise en curso, para no inyectar el <script> dos veces
  var map = null;
  var directionsService = null;
  var directionsRenderer = null;
  var markers = [];

  function byId(id) {
    return document.getElementById(id);
  }

  function cargarGoogleMaps(apiKey) {
    if (mapsCargado) return Promise.resolve();
    if (mapsCargando) return mapsCargando;

    mapsCargando = new Promise(function (resolve, reject) {
      var resuelto = false;

      var timeoutId = setTimeout(function () {
        if (resuelto) return;
        resuelto = true;
        reject(
          new Error(
            "Google Maps no respondió. Revisá que la key tenga habilitado este dominio y la Directions API.",
          ),
        );
      }, 10000);

      // Google llama esto específicamente cuando la key falla por auth
      // (dominio no permitido, key inválida, facturación, etc.) - sin esto,
      // un rechazo de la key deja la promesa colgada para siempre porque el
      // script "carga" bien (200 OK) pero nunca llama al callback normal.
      window.gm_authFailure = function () {
        if (resuelto) return;
        resuelto = true;
        clearTimeout(timeoutId);
        reject(
          new Error(
            "Google rechazó la key (dominio no permitido o API no habilitada).",
          ),
        );
      };

      window.__caddyInitMapaRecorrido = function () {
        if (resuelto) return;
        resuelto = true;
        clearTimeout(timeoutId);
        mapsCargado = true;
        resolve();
      };
      var script = document.createElement("script");
      script.src =
        "https://maps.googleapis.com/maps/api/js?key=" +
        encodeURIComponent(apiKey) +
        "&callback=__caddyInitMapaRecorrido&loading=async";
      script.async = true;
      script.onerror = function () {
        if (resuelto) return;
        resuelto = true;
        clearTimeout(timeoutId);
        reject(new Error("No se pudo cargar Google Maps."));
      };
      document.head.appendChild(script);
    });

    // Si falla, no dejamos la promesa rota "pegada" - el próximo intento de
    // abrir el mapa (por ejemplo después de arreglar la key) vuelve a probar
    // desde cero en vez de fallar para siempre con el mismo error viejo.
    mapsCargando.catch(function () {
      mapsCargando = null;
    });

    return mapsCargando;
  }

  function obtenerPosicionActual() {
    var ultima = window.CaddyGeo && window.CaddyGeo.getLastPosition ? window.CaddyGeo.getLastPosition() : null;
    if (ultima && Date.now() - ultima.ts < POSICION_MAX_EDAD_MS) {
      return Promise.resolve(ultima);
    }

    if (!("geolocation" in navigator)) {
      return Promise.resolve(null);
    }

    return new Promise(function (resolve) {
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          resolve({
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            ts: Date.now(),
          });
        },
        function () {
          resolve(null); // sin permiso/fix: seguimos mostrando las paradas igual
        },
        { enableHighAccuracy: false, maximumAge: 60000, timeout: 8000 },
      );
    });
  }

  function limpiarMarkers() {
    markers.forEach(function (m) {
      m.setMap(null);
    });
    markers = [];
    if (directionsRenderer) {
      directionsRenderer.setDirections({ routes: [] });
    }
  }

  function pinNumerado(numero) {
    return {
      path: google.maps.SymbolPath.CIRCLE,
      scale: 16,
      fillColor: "#e24f30",
      fillOpacity: 1,
      strokeColor: "#ffffff",
      strokeWeight: 2,
      labelOrigin: new google.maps.Point(0, 0),
    };
  }

  function dibujarMapa(origen, paradas) {
    var bounds = new google.maps.LatLngBounds();

    if (!map) {
      map = new google.maps.Map(byId("mapaRecorrido"), {
        zoom: 13,
        center: origen || { lat: paradas[0].lat, lng: paradas[0].lng },
        disableDefaultUI: true,
        zoomControl: true,
        gestureHandling: "greedy",
      });
      directionsRenderer = new google.maps.DirectionsRenderer({
        map: map,
        suppressMarkers: true,
        preserveViewport: true,
        polylineOptions: { strokeColor: "#1a73e8", strokeWeight: 5, strokeOpacity: 0.85 },
      });
      directionsService = new google.maps.DirectionsService();
    }

    limpiarMarkers();

    if (origen) {
      var marcadorYo = new google.maps.Marker({
        position: origen,
        map: map,
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 9,
          fillColor: "#1a73e8",
          fillOpacity: 1,
          strokeColor: "#ffffff",
          strokeWeight: 3,
        },
        title: "Tu ubicación",
        zIndex: 999,
      });
      markers.push(marcadorYo);
      bounds.extend(origen);
    }

    paradas.forEach(function (p) {
      var pos = { lat: p.lat, lng: p.lng };
      var marker = new google.maps.Marker({
        position: pos,
        map: map,
        icon: pinNumerado(p.orden),
        label: { text: String(p.orden), color: "#fff", fontSize: "13px", fontWeight: "bold" },
        title: p.nombre + " - " + p.direccion,
      });
      var bultosTxt = p.bultos > 1 ? p.bultos + " bultos" : "1 bulto";
      var info = new google.maps.InfoWindow({
        content:
          '<div style="font-size:13px;max-width:220px">' +
          "<strong>" + p.orden + ". " + escapeHtml(p.nombre) + "</strong><br>" +
          escapeHtml(p.direccion) + "<br>" +
          '<span style="color:#666">' + bultosTxt + "</span>" +
          "</div>",
      });
      marker.addListener("click", function () {
        info.open(map, marker);
      });
      markers.push(marker);
      bounds.extend(pos);
    });

    map.fitBounds(bounds, 60);

    // Trazado real (siguiendo calles) sólo si entra en el límite de la
    // Directions API. Con más paradas mostramos igual los números en orden,
    // pero sin la línea de ruta dibujada.
    //
    // Si no hay posición del repartidor (permiso denegado, sin fix de GPS
    // todavía, etc.) igual dibujamos el trazado conectando las paradas entre
    // sí (arrancando desde la parada 1) - antes, sin "origen", no se pedía
    // ninguna ruta y el mapa quedaba sin ninguna línea aunque hubiera 2+
    // paradas para conectar.
    var puntosRuta = origen ? [origen].concat(paradas) : paradas;

    if (puntosRuta.length > 1 && puntosRuta.length <= MAX_PARADAS_CON_RUTA + 2) {
      var origenRuta = puntosRuta[0];
      var destino = puntosRuta[puntosRuta.length - 1];
      var waypoints = puntosRuta.slice(1, -1).map(function (p) {
        return { location: { lat: p.lat, lng: p.lng }, stopover: true };
      });

      directionsService.route(
        {
          origin: origenRuta,
          destination: { lat: destino.lat, lng: destino.lng },
          waypoints: waypoints,
          optimizeWaypoints: false, // preservar el orden ya planificado (HojaDeRuta.Posicion)
          travelMode: google.maps.TravelMode.DRIVING,
        },
        function (result, status) {
          if (status === "OK") {
            directionsRenderer.setDirections(result);
          } else {
            console.warn("⚠️ Directions falló:", status);
          }
        },
      );
    }
  }

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function mostrarEstado(estado) {
    // estado: 'loading' | 'vacio' | 'mapa'
    byId("mapaRecorridoLoading").style.display = estado === "loading" ? "flex" : "none";
    byId("mapaRecorridoVacio").style.display = estado === "vacio" ? "flex" : "none";
    byId("mapaRecorrido").style.display = estado === "mapa" ? "block" : "none";
  }

  function abrirMapa() {
    byId("mapaRecorridoOverlay").classList.add("show");
    mostrarEstado("loading");

    $.ajax({
      url: "Proceso/php/ruta_mapa.php",
      type: "POST",
      dataType: "json",
    })
      .done(function (jsonData) {
        if (!jsonData || jsonData.success !== 1) {
          Swal.fire({
            icon: "error",
            title: "No se pudo cargar el recorrido",
            text: jsonData && jsonData.error ? jsonData.error : "Reintentá en unos segundos.",
          });
          cerrarMapa();
          return;
        }

        var paradas = jsonData.paradas || [];
        if (!paradas.length) {
          mostrarEstado("vacio");
          return;
        }

        cargarGoogleMaps(jsonData.apiKey)
          .then(function () {
            return obtenerPosicionActual();
          })
          .then(function (origen) {
            mostrarEstado("mapa");
            dibujarMapa(origen, paradas);
            // El mapa se crea con el contenedor recién visible - hay que
            // avisarle que recalcule tamaño o queda con el tile gris.
            google.maps.event.trigger(map, "resize");
            if (origen) {
              map.setCenter(origen);
            }
            var bounds = new google.maps.LatLngBounds();
            markers.forEach(function (m) {
              bounds.extend(m.getPosition());
            });
            map.fitBounds(bounds, 60);
          })
          .catch(function (err) {
            console.error(err);
            Swal.fire({ icon: "error", title: "No se pudo cargar el mapa", text: err.message || "" });
            cerrarMapa();
          });
      })
      .fail(function (xhr) {
        console.error("Error ruta_mapa.php:", xhr.status, xhr.responseText);
        Swal.fire({ icon: "error", title: "Error de servidor", text: "No se pudo consultar el recorrido." });
        cerrarMapa();
      });
  }

  function cerrarMapa() {
    byId("mapaRecorridoOverlay").classList.remove("show");
  }

  document.addEventListener("DOMContentLoaded", function () {
    byId("fab-mapa-recorrido").addEventListener("click", abrirMapa);
    byId("mapaRecorridoCerrar").addEventListener("click", cerrarMapa);
  });
})();
