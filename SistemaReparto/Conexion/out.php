<?php
if ($_POST['Salir']==1){
session_destroy();
echo json_encode(array('success'=>1));      
};
?>