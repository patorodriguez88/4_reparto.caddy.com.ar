function numerolee(){
var count = $("#prueba :selected").length;
    
   $("#totalt").html(count);    
        
}

document.addEventListener("DOMContentLoaded", () => {
            
    select = document.getElementById("prueba");
    

	const $btnEscanear = document.querySelector("#btnEscanear"),
    $input = document.querySelector("#prueba");  
    
	$btnEscanear.addEventListener("click", ()=>{
		window.open("leer.html");
	});

	window.onCodigoLeido = datosCodigo => {
        option = document.createElement("option")
        option.value = datosCodigo.codeResult.code
        option.text = datosCodigo.codeResult.code
        select.appendChild(option)    
        option.setAttribute ("selected", true)
        // select.removeChild(option)
        numerolee();        

    }
});