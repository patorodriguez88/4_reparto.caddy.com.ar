//SistemaReparto/Proceso/js/mail.js
//cs= Codigo de Seguimiento
//st= Status

function mail_status_notice(cs, st) {
  const urlNotices = "../../Mail/Proceso/php/notices.php";
  const urlSendMail = "../../Mail/delivered.php";

  // ---------- ORIGEN ----------
  $.ajax({
    url: urlNotices,
    type: "POST",
    dataType: "json",
    data: { Avisos: 1, cs: cs, st: st },
    success: function (jsonData) {
      if (String(jsonData.success) !== "1") return;

      const destino = jsonData.destination_name || "";
      const name = jsonData.name || "";
      const user = jsonData.mail || "";

      const mensaje =
        "</br> Queremos avisarte que el envío " +
        cs +
        " que nos diste para entregar a " +
        destino +
        " se encuentra " +
        st +
        ".";

      let asunto = `Tu Envío de Caddy ${cs} para ${destino}`;
      if (st === "Entregado al Cliente")
        asunto = `Entregamos tu envío ${cs} a ${destino}`;
      else if (st === "Retirado del Cliente")
        asunto = `Retiramos tu envío ${cs} para ${destino}`;

      $.ajax({
        url: urlSendMail,
        type: "POST",
        dataType: "json",
        data: {
          txtEmail: user,
          txtName: name,
          txtAsunto: asunto,
          txtMensa: mensaje,
          txtHtml: "delivered", // o lo que uses
        },
        success: function (jsonData1) {
          // opcional: console.log("mail origen", jsonData1);
        },
      });
    },
  });

  // ---------- DESTINO ----------
  $.ajax({
    url: urlNotices,
    type: "POST",
    dataType: "json",
    data: { Avisos: 2, cs: cs, st: st },
    success: function (jsonData) {
      if (String(jsonData.success) !== "1") return;

      const origen = jsonData.origen_name || "";
      const name = jsonData.name || "";
      const user = jsonData.mail || "";

      let mensaje = `</br> Queremos avisarte que el envío ${cs} de ${origen} se encuentra ${st}`;
      if (st === "Entregado al Cliente") {
        mensaje = `</br> Recibiste tu envío ${cs} de ${origen} !.`;
      } else if (st === "Retirado del Cliente" || st === "En Transito") {
        mensaje =
          "</br> Queremos avisarte que el envío " +
          cs +
          " de " +
          origen +
          " se encuentra " +
          st +
          " , pronto haremos la entrega en tu domicilio !.";
      }

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
          // opcional: console.log("mail destino", jsonData1);
        },
      });
    },
  });
}
