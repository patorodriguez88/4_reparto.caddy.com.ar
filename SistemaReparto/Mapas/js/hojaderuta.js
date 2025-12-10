
function verentabla(a){
  var table = $('#seguimiento').DataTable();
  table.search(a).draw();
}
const pato=22;

function initMap(c) {
    var divMapa = document.getElementById('map');
    var xhttp;
    var resultado = [];
    var markers = [];
    var co =[];
  
 var markerss=[];
    
    var infowindowActivo = false;
    xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function() {
      if (xhttp.readyState == 4 && xhttp.status == 200) {
        resultado = xhttp.responseText;
        var objeto_json = JSON.parse(resultado);
        console.log(objeto_json.data.length);
        $('#cantidad').html(objeto_json.data.length);
        $('#header-title2').html(c);
        
        for (var i = 0; i < objeto_json.data.length; i++) {
          
        //ICONO DE COLORES
              function pinSymbol(color) {
                  return {
  //              path: 'M 0,0 C -2,-20 -10,-22 -10,-30 A 10,10 0 1,1 10,-30 C 10,-22 2,-20 0,0 z M -2,-30 a 2,2 0 1,1 4,0 2,2 0 1,1 -4,0',
                  path: 'M 0,0 C -2,-20 -10,-22 -10,-30 A 10,10 0 1,1 10,-30 C 10,-22 2,-20 0,0 z',
//                   path: 'M0-48c-9.8 0-17.7 7.8-17.7 17.4 0 15.5 17.7 30.6 17.7 30.6s17.7-15.4 17.7-30.6c0-9.6-7.9-17.4-17.7-17.4z',
                  fillColor: '#'+color,
                  fillOpacity: 1,
                  strokeColor: '#FFFFFF',
                  strokeWeight: 1,
                  scale: 1,
                  };
                } 

                if(c!=''){
                  if(c=='t'){
                    if(objeto_json[0][i]==null){
                     var icono= null;
                    }else{
                     var icono=pinSymbol(objeto_json[0][i]);      
                    } 
                    }else{
                    var icono=pinSymbol(c);  
                  }
                }else{
                icono=null;  
                }
          
                var latlong = objeto_json.data[i].coordenadas.split(',');
                myLatLng = {
                    lat: Number(latlong[0]),
                    lng: Number(latlong[1])
                };
          
                var marker= new google.maps.Marker({
                  position: myLatLng,
                  map: map,
                  title: objeto_json.data[i].nombrecliente,
                  icon: icono
                });
               markers.push(marker);
                
                var tel1=objeto_json.data[i].Celular;
                var tel2=objeto_json.data[i].Telefono;
                var tel3=objeto_json.data[i].Telefono2;
                
                if(tel2==tel1){
                 var cel=tel1; 
                }else{
                 var cel=tel1+' | '+tel2; 
                }
                
                var contentString = '<h4 id="firstHeading" class="firstHeading">' +
                    objeto_json.data[i].nombrecliente + '</h4>'+ '<div id="bodyContent">'+
                    '<p><b>Recorrido: ' + objeto_json.data[i].Recorrido + '</b></p>'+
                    '<p><b><a target="t_blank" href="https://api.whatsapp.com/send?phone='+ cel +
                    '&text=Hola '+ objeto_json.data[i].nombrecliente +' !,%20 nos comunicamos de Caddy Logística, tenemos un envío para entregarte, pero necesitamos corroborar tu dirección, ya que nuestro cliente nos indicó que la misma era '+ objeto_json.data[i].Direccion +'... pero no logramos ubicarnos. Nos podrás ayudar ?. "> Teléfono: '+ cel +'</a></b></p>'+
                    '<p><b>Seguimiento: ' + objeto_json.data[i].Seguimiento + '</b></p>'+
                    '<p>Dir:' + objeto_json.data[i].Direccion +'</p>'+
                    '<td class="table-action">'+
                    '<a style="cursor:pointer" data-id="' + objeto_json.data[i].Seguimiento + '" id="'+objeto_json.data[i].Seguimiento+'" onclick="verentabla(this.id)"><b class="text-primary">Ver en Tabla</b></a>'+
                    '</td></div>';
          
                markers[i].infoWindow = new google.maps.InfoWindow({
                  content: contentString
                });
          
                
                google.maps.event.addListener(markers[i], 'click', function(){     
                  if(infowindowActivo){
                    infowindowActivo.close();
                  }   
                  
                  
                  infowindowActivo = this.infoWindow;
                  infowindowActivo.open(map, this);
                });
              }
      }
    }
     var myLatLng = {
              lat: -31.4448988,
              lng: -64.177743
          };
    var url = "Mapas/php/datos_hojaderuta.php";
    xhttp.open("POST", url, true);
    xhttp.send(); 
  
  map = new google.maps.Map(document.getElementById("map"), {
    center: new google.maps.LatLng(-31.4448988, -64.177743),
    zoom: 10,
  });
}