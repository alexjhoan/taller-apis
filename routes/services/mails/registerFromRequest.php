<?php

include_once "const.php";

function sendRegisterFromRequest($post)
{
  // $post = json_decode(file_get_contents("php://input"), true);

  $full_name = $post['first_name'] . ' ' . $post['last_name'];

  $email = filter_var($post['email'], FILTER_SANITIZE_EMAIL);
  $password = $post["password"];

  $headers = array(
    'MIME-Version' => '1.0',
    'Content-type' => 'text/html; charset=iso-8859-1',
    'From' => emailFrom,
    'Bcc' => 'acontreras@bluewayisbetter.com,alexjhoan.25@gmail.com'
  );

  $body = "
  <html>
  <body>
    <p>
    $full_name bienvenido al sistema de consultingsbg.com, a continuacion se le presentara una clave provicional de 12 digitos para que pueda ingresar al sistema y ver el estado de sus tramites.
    
    <p>Email: $email</p>
    <p>Email: $password</p>
  </body>
  </html>
  ";
  // print_r($body);

  $to = $email;

  $subject = "Registro exitoso en consultingsbg.com";

  mail($to, $subject, $body, $headers);

  // if ($s) {
  //   $json = [
  //     'status' => 200,
  //     'body' => [$s],
  //     'statusText' => 'correo enviado'
  //   ];
  //   echo json_encode($json, http_response_code($json["status"]));
  // } else {
  //   $json = [
  //     'status' => 500,
  //     'body' => [$s],
  //     'statusText' => 'Se produjo un error al enviar su mensaje, intentelo de nuevo!'
  //   ];
  //   echo json_encode($json, http_response_code($json["status"]));
  // }
}
