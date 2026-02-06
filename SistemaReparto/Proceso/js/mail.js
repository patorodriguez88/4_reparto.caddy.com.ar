//SistemaReparto/Proceso/js/mail.js
//cs= Codigo de Seguimiento
//st= Status

function textoEstado(slug, capitalizar = false) {
  const estados = {
    delivered: "fue entregado correctamente.",
    "1st_visit_fail": "no pudo ser entregado en esta visita.",
    picked_up: "fue retirado correctamente.",
    in_transit: "se encuentra en tránsito.",
    pending: "se encuentra pendiente.",
    canceled: "fue cancelado.",
    returned: "fue devuelto al remitente.",
  };

  let texto = estados[slug] || "actualizó su estado.";

  if (capitalizar) {
    texto = texto.charAt(0).toUpperCase() + texto.slice(1);
  }

  return texto;
}
function asuntoEstado(slug, cs, destino) {
  const asuntos = {
    delivered: `Entregamos tu envío ${cs} a ${destino}`,
    picked_up: `Retiramos tu envío ${cs} para ${destino}`,
    in_transit: `Tu envío ${cs} está en tránsito hacia ${destino}`,
    "1st_visit_fail": `No pudimos entregar tu envío ${cs} a ${destino}`,
    pending: `Tu envío ${cs} está pendiente`,
    canceled: `Tu envío ${cs} fue cancelado`,
    returned: `Tu envío ${cs} fue devuelto`,
  };

  return asuntos[slug] || `Actualización de tu envío ${cs} para ${destino}`;
}
function mail_status_notice(cs, slug) {
  const urlNotices = "/SistemaReparto/Mail/notices.php";
  const urlSendMail = "/SistemaReparto/Mail/delivered.php";

  // ---------- ORIGEN ----------
  $.ajax({
    url: urlNotices,
    type: "POST",
    dataType: "json",
    data: { Avisos: 1, cs: cs, slug: slug },
    success: function (jsonData) {
      if (String(jsonData.success) !== "1") return;

      const ctx = jsonData.context || {};

      const destino = (ctx.destination_name || "").trim();
      const name = (ctx.name || "").trim();
      const user = (ctx.mail || "").trim();

      console.log("NOTICES ORIGEN:", jsonData);
      if (jsonData.code === "SKIPPED") {
        console.log("SKIPPED (Estados):", { cs, st, avisos: 1 });
        return;
      }

      if (!user) {
        console.warn("NO MAIL ORIGEN -> no envío delivered.php", {
          cs,
          st,
          avisos: 1,
          jsonData,
        });
        return;
      }

      const mensaje =
        "Queremos avisarte que el envío <strong>" +
        cs +
        "</strong> para <strong>" +
        destino +
        "</strong> " +
        textoEstado(slug) +
        ".";

      const asunto = asuntoEstado(slug, cs, destino);

      $.ajax({
        url: urlSendMail,
        type: "POST",
        dataType: "json",
        data: {
          txtEmail: user,
          txtName: name,
          txtAsunto: asunto,
          txtMensa: mensaje,
          txtHtml: "delivered",
        },
        success: function (jsonData1) {
          console.log("MAIL ORIGEN:", jsonData1);
        },
      });
    },
  });

  // ---------- DESTINO ----------
  $.ajax({
    url: urlNotices,
    type: "POST",
    dataType: "json",
    data: { Avisos: 2, cs: cs, slug: slug },
    success: function (jsonData) {
      if (String(jsonData.success) !== "1") return;

      const ctx = jsonData.context || {};
      const origen = (ctx.origen_name || "").trim();
      const name = (ctx.name || "").trim();
      const user = (ctx.mail || "").trim();

      console.log("NOTICES DESTINO:", jsonData);
      if (jsonData.code === "SKIPPED") {
        console.log("SKIPPED (Estados):", { cs, st, avisos: 1 });
        return;
      }

      if (!user) {
        console.warn("NO MAIL DESTINO -> no envío delivered.php", {
          cs,
          st,
          avisos: 2,
          jsonData,
        });
        return;
      }

      const mensaje =
        "<p>Queremos avisarte que el envío <strong>" +
        cs +
        "</strong> de <strong>" +
        origen +
        "</strong> " +
        textoEstado(slug) +
        "</p>";

      const asunto = `Tu envío de ${origen} te lo lleva Caddy !`;

      $.ajax({
        url: urlSendMail,
        type: "POST",
        dataType: "json",
        data: {
          txtEmail: user,
          txtName: name,
          txtAsunto: asunto,
          txtMensa: mensaje,
          txtHtml: "delivered",
        },
        success: function (jsonData1) {
          console.log("MAIL DESTINO:", jsonData1);
        },
      });
    },
  });
}
