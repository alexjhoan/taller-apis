<?php

include_once "const.php";

function sendPasswordRecoveryEmail($userData, $token)
{
  $headers = array(
    'MIME-Version' => '1.0',
    'Content-type' => 'text/html; charset=iso-8859-1',
    'From' => emailFrom,
  );

  $body = "
  <html>
  <body>
    <p>
    " . htmlspecialchars(strip_tags($userData['first_name']))  . " " . htmlspecialchars(strip_tags($userData['last_name'])) . ", hemos recibido una solicitud para cambiar su contrase&ntilde;a. Si esta solicitud es leg&iacute;tima, le pedimos que siga el enlace proporcionado a continuaci&oacute;n. En caso contrario, por favor, ignore este correo.
    <p>En el caso de que la solicitud sea v&aacute;lida, le recordamos que el token de validaci&oacute;n para el cambio de contrase&ntilde;a caducar&aacute; en una hora.</p>
    <p style='text-align: center;'>Enlace para cambiar la contrase&ntilde;a: <a href='" . $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . "/get-new-password?inf=" . $token['jwt'] . "'>Cambiar contrase&ntilde;a</a></p>
  </body>
  </html>
  ";

  $to = $userData["email"];

  $subject = "solicitud de cambio de contraseña";

  $s = mail($to, $subject, $body, $headers);

  if (!$s) {
    $json = [
      'status' => 500,
      'body' => [$s],
      'statusText' => 'Se produjo un error al enviar su mensaje, intentelo de nuevo!'
    ];
    echo json_encode($json, http_response_code($json["status"]));
  }
}
