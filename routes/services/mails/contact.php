<?php

include_once "const.php";

function sendContactEmail()
{
  $post = json_decode(file_get_contents("php://input"), true);

  $email = filter_var($post['email'], FILTER_SANITIZE_EMAIL);

  $headers = array(
    'MIME-Version' => '1.0',
    'Content-type' => 'text/html; charset=iso-8859-1',
    'From' => emailFrom
  );

  $body = "
  <html>
  <body>
    <p>
    Name: " . htmlspecialchars(strip_tags($post['full_name'])) . " </p>
    <p>Email: $email</p>
    <p>Pais: " . htmlspecialchars(strip_tags($post['country'])) . " </p>
    <p>Ciudad: " . htmlspecialchars(strip_tags($post['city'])) . " </p>
    <p>Telefono: " . htmlspecialchars(strip_tags($post['phone'])) . " </p>
    <p>Comment or message: " . htmlspecialchars(strip_tags($post['comment'])) . "</p> 
  </body>
  </html>
  ";
  // print_r($body);

  $to = emailTo;
  // $to = 'alexjhoan.25@gmail.com';

  $subject = "Nuevo formulario de contacto";

  $s = mail($to, $subject, $body, $headers);

  if ($s) {
    $json = [
      'status' => 200,
      'body' => [$s],
      'statusText' => 'correo enviado'
    ];
    echo json_encode($json, http_response_code($json["status"]));
  } else {
    $json = [
      'status' => 500,
      'body' => [$s],
      'statusText' => 'Se produjo un error al enviar su mensaje, intentelo de nuevo!'
    ];
    echo json_encode($json, http_response_code($json["status"]));
  }
}
