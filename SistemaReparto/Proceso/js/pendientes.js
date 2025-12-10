function geocodeResult(results, status) {
    // Verificamos el estatus
    if (status == 'OK') {
        // Si hay resultados encontrados, centramos y repintamos el mapa
        // esto para eliminar cualquier pin antes puesto
        // fitBounds acercará el mapa con el zoom adecuado de acuerdo a lo buscado
        map.fitBounds(results[0].geometry.viewport);
        // Dibujamos un marcador con la ubicación del primer resultado obtenido
        var markerOptions = { position: results[0].geometry.location,animation:google.maps.Animation.BOUNCE,labelContent: "A" }
        var marker = new google.maps.Marker(markerOptions);

        marker.setMap(map);
        map.setZoom(12);
      marker.addListener("click", eliminar);
//     }

    function eliminar(){
    marker.setMap(null);  
    }  
    } else {
        // En caso de no haber resultados o que haya ocurrido un error
        // lanzamos un mensaje con el error
        alert("Geocoding no tuvo éxito debido a: " + status);
    }
}

function initialize() {
   initMap();
   BuscarDireccion();
}

function BuscarDireccion() {
        var inputstart = document.getElementById('direccion_nc');
        var autocomplete = new google.maps.places.Autocomplete(inputstart, { types: ['geocode','establishment'], componentRestrictions: {country: ['AR']}});
        autocomplete.addListener('place_changed', function() {
        var place = autocomplete.getPlace();
        if (place.address_components) {
          var components= place.address_components;
          var ciudad='';
          var provincia='';  
          for (var i = 0, component; component = components[i]; i++) {
//             console.log(component);
            if (component.types[0] == 'administrative_area_level_1'){
               provincia=component['long_name'];
               }
            if(component.types[0] == 'locality') {
               ciudad = component['long_name'];
               document.getElementById('ciudad_nc').value = ciudad;
               }
            if(component.types[0] == 'postal_code'){
               document.getElementById('cp_nc').value= component['short_name'];   
               }
            if(component.types[0] == 'neighborhood'){
                 if(component['long_name']!=null){
                   document.getElementById('Barrio_nc').value= component['long_name']; 
                   }else if(component.types[0] == 'administrative_area_level_2'){
                   document.getElementById('Barrio_nc').value= component['long_name'];   
                  }  
               }
            if(component.types[0] == 'street_number'){
               document.getElementById('Numero_nc').value= component['long_name'];   
               }
            if(component.types[0] == 'route'){
               document.getElementById('Calle_nc').value= component['long_name'];   
                
              }
          }
        }
        }); 
   }
function modificardir(e) {
    $.ajax({
      data:{'BuscarDatosClienteDestino':1,'id':e},
      url:'Proceso/php/pendientes.php',
      type:'post',
      success: function(response)
       {
        var jsonData = JSON.parse(response);

        $('#standard-modal-dir').modal('show');
        $('#myCenterModalLabel').html('Modificar Direccion a '+jsonData.data[0].ClienteDestino);  
        $('#direccion_nc').val(jsonData.data[0].DomicilioDestino); 
        $('#id_nc').val(jsonData.data[0].idClienteDestino);
        $('#cs_nc').val(jsonData.data[0].CodigoSeguimiento); 
       }  
  });
}

$('#modificardir_ok').click(function(){
  var dir=$('#direccion_nc').val();
  var calle= $('#Calle_nc').val();
  var barrio= $('#Barrio_nc').val();
  var numero= $('#Numero_nc').val();
  var ciudad= $('#ciudad_nc').val();
  var cp= $('#cp_nc').val();
  var id=$('#id_nc').val();
  var cs=$('#cs_nc').val();
  var obs=$('#observaciones_nc').val();
  
  var origen="Reconquista 4986, Cordoba, Argentina";
  
      $.ajax({
          data:{'ActualizarDireccion':1,'Direccion':dir,'id':id,'calle':calle,'barrio':barrio,'numero':numero,'ciudad':ciudad,'cp':cp,'cs':cs,'obs':obs},
          url:'Proceso/php/pendientes.php',
          type:'post',
          success: function(response)
           {
            var jsonData = JSON.parse(response);
            var datatable = $('#seguimiento').DataTable();
            datatable.ajax.reload();
             
           $('#standard-modal-dir').modal('hide');
           var color=$('#header-title2').html();
             
             initMap(color);  
           }  
        });
 });


var datatable = $('#seguimiento').DataTable({
  dom: 'Bfrtip',
  buttons: ['pageLength'],
  paging: true,
  searching: true,
  lengthMenu: [
        [10, 25, 50, -1],
        [10, 25, 50, 'All']
      ],
    ajax: {
         url:"Proceso/php/pendientes.php",
         data:{'Pendientes':1},
         processing: true,
         type:'post'
         },
        columns: [
            {data:"Fecha",
             render: function (data, type, row) {
               console.log([0].Latitud);
              var Fecha=row.Fecha.split('-').reverse().join('.');
              return '<td><span style="display: none;">'+row.Fecha+'</span>'+Fecha+'</td>';  
              }
            },
            {data:"NumeroComprobante"},
            {data:"RazonSocial",
            render: function (data, type, row) { 
            if(row.Retirado==0){
              
            var color='success';  
            }else{
            color='muted';    
            }  
           return '<td><b>'+row.RazonSocial+'</br>'+  
                     '<i class="mdi mdi-18px mdi-map-marker text-'+color+'"></i><a class="text-muted">'+row.DomicilioOrigen+'</td>';
              }
            },
            {data:"DomicilioDestino",
            render: function (data, type, row) { 
            if(row.Retirado==1){
            var color1='success';  
            }else{
            color1='muted';    
            }  
            return '<td><b>'+row.ClienteDestino+'</br>'+ 
                     '<a data-id="' + row.id + '" id="'+row.id+'" onclick="modificardir(this.id);"class="action-icon">'+
                     '<i class="mdi mdi-18px mdi-map-marker text-'+color1+'"></i><a class="text-muted">'+row.DomicilioDestino+'</td>';
              }
            },
            {data:"CodigoSeguimiento",
            render: function (data, type, row) {
              if(row.Retirado==1){
            var color='success';
            var servicio='Entrega';    
            }else{
            var color='muted';    
            var servicio='Origen';  
            }  
                return '<td class="table-action">'+
                '<a>'+row.CodigoSeguimiento+'</a><br/>'+
                '<a><b>'+servicio+'</b></a>'+
                '</td>';
              }
            },
//           {data:"Recorrido"},
            {data:"Recorrido",
           render: function (data, type, row) {
                return '<td class="table-action">'+
                  '<a style="cursor:pointer" data-id="' + row.CodigoSeguimiento + '" id="'+row.CodigoSeguimiento+'" onclick="modificarrecorrido(this.id);" ><b class="text-primary">'+row.Recorrido+'</b></a>'+
                '</td>';
             }
            },
            {data:"id",
           render: function (data, type, row) {
                return '<td class="table-action">'+
                '<a data-id="' + row.DomicilioDestino + '" id="'+row.DomicilioDestino+'" onclick="ubicacion(this.id);" class="action-icon"> <i class="mdi mdi-18px mdi-map-marker"></i></a>'+  
                '<a data-id="' + row.id + '" id="'+row.id+'" onclick="modificar(this.id);" class="action-icon"> <i class="mdi mdi-pencil"></i></a>'+
                '<a data-id="' + row.id + '" id="'+row.id+'" onclick="eliminar(this.id);" class="action-icon"> <i class="mdi mdi-delete"></i></a>'+
                '</td>';
              }
            },
           
        ]
});

$('#entregado').change(function(e) {
    if(this.checked) {
        $('#entregado').val(1);
        }else{
        $('#entregado').val(0);
        } 
});

function ubicacion(i){
    // Obtenemos la dirección y la asignamos a una variable
var address = i;
// Creamos el Objeto Geocoder
var geocoder = new google.maps.Geocoder();
// Hacemos la petición indicando la dirección e invocamos la función
// geocodeResult enviando todo el resultado obtenido
geocoder.geocode({ 'address': address}, geocodeResult);

//     var latitudReal = -27.798521169850478;
//     var longitudReal = -63.683109002298416;
//     var markerPosicionReal = new google.maps.Marker({
//         position: {
//           lat: latitudReal,
//           lng: longitudReal
//         },
//         title: "Mi actual ubicación"
//     });
//     markerPosicionReal.setMap(map);
//     // Si quieres centrar el mapa en el nuevo marker:
//     map.setCenter(markerPosicionReal.getPosition());
}

function modificarrecorrido(i){
$('#cs_modificar_REC').val(i); 
$.ajax({
        data:{'BuscarRecorridos':1,'cs':i},
        type: "POST",
        url: "Proceso/php/pendientes.php",
        success: function(response)
        {
        $('.selector-recorrido select').html(response).fadeIn();
        }
    });

$('#myCenterModalLabel_rec').html('Modificar Recorrido a Código '+i);   
$('#standard-modal-rec').modal('show');
  
}

$('#modificarrecorrido_ok').click(function(){
  var cs=$('#cs_modificar_REC').val();
  var r = $('#recorrido_t').val();
  $.ajax({
        data:{'ActualizaRecorrido':1,'r':r,'cs':cs},
        type: "POST",
        url: "Proceso/php/pendientes.php",
        success: function(response)
        {
         var jsonData=JSON.parse(response);
          if(jsonData.success==1){
         var datatable = $('#seguimiento').DataTable();
         datatable.ajax.reload(); 
        initMap();    
        $('#standard-modal-rec').modal('hide');
        $.NotificationApp.send("Registro Actualizado !","Se ha actualizado el Recorrido.","bottom-right","#FFFFFF","success");      
        }else{
        $.NotificationApp.send("Registro No Actualizado !","No pudimos actualizar el Recorrido.","bottom-right","#FFFFFF","danger");        
        }
       }
    });

  
});

function modificar(i){
$('#id_modificar').val(i);   
$('#standard-modal').modal('show'); 
 $('#myCenterModalLabel').html('Modificar id # '+i); 
}


$('#modificardireccion_ok').click(function(){
var entregado=$('#entregado').val();
var Fecha=$('#fecha_receptor').val();
var hora=$('#hora_receptor').val();
var i=$('#id_modificar').val();
var obs=$('#observaciones_receptor').val();  
  $('#myCenterModalLabel').html('Modificar id # '+i); 
  
if(entregado==1){  
  $.ajax({
      data:{'Actualiza':1,'id':i,'entregado':entregado,'Fecha':Fecha,'Hora':hora,'Observaciones':obs},
      url:'Procesos/php/pendientes.php',
      type:'post',
      success: function(response)
       {
        var jsonData = JSON.parse(response);
        $.NotificationApp.send("Registro Actualizado !","Se ha actualizado la tabla Clientes correctamente.","bottom-right","#FFFFFF","success");    
       var datatable = $('#seguimiento').DataTable();
        datatable.ajax.reload();  
       $('#standard-modal').modal('hide'); 
     $('#form')[0].reset();
       }  
      });
   }else{
     $.NotificationApp.send("Presione Entregado !","No se realizaron cambios.","bottom-right","#FFFFFF","warning");    
   }
});

function eliminar(e) {
       $.ajax({
      data:{'BuscarDatos':1,'id':e},
      url:'Proceso/php/pendientes.php',
      type:'post',
      success: function(response)
       {
        var jsonData = JSON.parse(response);
       $('#warning-modal-body').html('Estas por eliminar el Registro '+e+ ' Origen '+jsonData.RazonSocial);
       $('#id_eliminar').val(e);
       $('#codigoseguimiento_eliminar').val(jsonData.CodigoSeguimiento);  
       $('#warning-modal').modal('show');
       }  
      });
   }
    $('#warning-modal-ok').click(function(){
      var id=$('#id_eliminar').val();
      var cs=$('#codigoseguimiento_eliminar').val();
      $.ajax({
            data:{'EliminarRegistro':1,'id':id,'CodigoSeguimiento':cs},
            url:'Proceso/php/pendientes.php',
            type:'post',
            success:function(response){
            var jsonData = JSON.parse(response);
              $('#warning-modal').modal('hide');
              if(jsonData.success==1){
               if(jsonData.hojaderuta==1){ 
                $.NotificationApp.send("Registro Borrado !","Se ha borrado el registro en Hoja de Ruta correctamente.","bottom-right","#FFFFFF","success");  
               var datatable = $('#seguimiento').DataTable();
                datatable.ajax.reload();   
               }else{
                $.NotificationApp.send("Error !","No se han realizado cambios en Hoja de Ruta.","bottom-right","#FFFFFF","danger");       
               } 
               if(jsonData.transclientes==1){
               $.NotificationApp.send("Registro Borrado !","Se ha borrado el registro en Trans Clientes correctamente.","bottom-right","#FFFFFF","success");  
               var datatable = $('#seguimiento').DataTable();
               datatable.ajax.reload();  
               }else{
               $.NotificationApp.send("Error !","No se han realizado cambios en Trans Clientes.","bottom-right","#FFFFFF","danger");       
               } 
              }else{
              $.NotificationApp.send("Error !","No se han realizado cambios.","bottom-right","#FFFFFF","danger");    
              }
            }
        });  
     });