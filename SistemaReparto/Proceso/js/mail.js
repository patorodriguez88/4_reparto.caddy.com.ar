//cs= Codigo de Seguimiento
//st= Status

function mail_status_notice(cs,st){

$.ajax({
    data:{'Avisos':1,'cs':cs,'st':st},
    url:'https://www.caddy.com.ar/SistemaTriangular/Mail/Proceso/php/notices.php',
    type:'post',
    success: function(response)
     {
        var jsonData = JSON.parse(response);
        if (jsonData.success == "1")
        {            
        
        var mensaje = '</br> Queremos avisarte que el envío '+cs+' que nos diste para entregar a '+jsonData.destination_name+' se encuentra '+st+'.';
        
        if(st=='Entregado al Cliente'){
        
            var asunto=` Entregamos tu envío ${cs} a ${jsonData.destination_name}`;
        
        }else if(st=='Retirado del Cliente'){
        
            var asunto=` Retiramos Tu Envio ${cs} para ${jsonData.destination_name}`;    
        
        }else{

            var asunto=` Tu Envio de Caddy ${cs} para ${jsonData.destination_name}`;    
        
        }

        var html='Recupero';
        var name=jsonData.name; 
        var user=jsonData.mail; 

        $.ajax({
            data:{'txtEmail':user,'txtName':name,'txtAsunto':asunto,'txtMensa':mensaje,'$txtHtml':html},
            url:'https://www.caddy.com.ar/SistemaTriangular/Mail/delivered.php',
            type:'post',
            success: function(response1)
             {
             var jsonData1 = JSON.parse(response1);
            if (jsonData1.success == "1")
            {  
                //MAIL ENVIADO
                
             }else{
                //ERROR AL ENVIAR MAIL            
             
            }
           }
        });          
        }
     }  
  });

  //DESTINO
  $.ajax({
    data:{'Avisos':2,'cs':cs,'st':st},
    url:'https://www.caddy.com.ar/SistemaTriangular/Mail/Proceso/php/notices.php',
    type:'post',
    success: function(response)
     {
        var jsonData = JSON.parse(response);
        if (jsonData.success == "1")
        {
            // console.log('ver',jsonData);
        // var user=jsonData.mail;    
        var mensaje = ', recibiste tu pedido !.';

        if(st=='Entregado al Cliente'){
        
            var mensaje = `</br> Recibiste tu envío ${cs} de ${jsonData.origen_name} !.`;    
        
        }else if((st=='Retirado del Cliente')||(st=='En Transito')){
        
            var mensaje = '</br> Queremos avisarte que el envío '+cs+' de '+jsonData.origen_name+' se encuentra '+st+' , pronto haremos la entrega en tu domicilio !.';
        
        }else{
        
            var mensaje = `</br> Queremos avisarte que el envío ${cs} de ${jsonData.origen_name} se encuentra ${st}`;
        
        }        

        var asunto='Tu Envio de '+jsonData.origen_name+' te lo lleva Caddy !';
        var html='Recupero';
        var name=jsonData.name; 
        var user=jsonData.mail; 
        
        $.ajax({
            data:{'txtEmail':user,'txtName':name,'txtAsunto':asunto,'txtMensa':mensaje,'$txtHtml':html},
            url:'https://www.caddy.com.ar/SistemaTriangular/Mail/delivered.php',
            type:'post',
            success: function(response1)
             {
             var jsonData1 = JSON.parse(response1);
            if (jsonData1.success == "1")
            {  
             //MAIL ENVIADO

             }else{
             //ERROR AL ENVIAR MAIL

             }
           }
        });          
        }
     }  
  });  
};