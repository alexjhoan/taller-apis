<?php

ini_set('display_errors', 1); // para mostrar errores en el client
ini_set("log_error", 1); //guarda errores en local
// ini_set('error_log', "D:/xampp/php-api-restful/php_error_log"); // ubicacion de donde se guradaron los errores

header('Content-type: application/json; charset=utf-8'); // esto es para que mantegan los caracteres esperaciales que trae desde la base de datos

require_once 'routes/routes.php';
// $crypt = password_hash('alex123', PASSWORD_BCRYPT);

// var_dump($crypt);
// string(60) "$2y$10$Uhbe4ONn3bHxNLVZCTAOs.ASiFq2dHrTBiyQ5ixSFZxN6n7oPIIAa"
