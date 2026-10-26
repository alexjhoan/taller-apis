<?php

include_once "const.php";

function sendApplicationEmail($post)
{
  $data = $post;

  $DataToSend = "";

  foreach ($data['data_form'] as $key => $value) {
    if ($key === "prefix") continue;
    $formattedKey = ucfirst($key[0]) . substr($key, 1);
    $formattedKey = str_replace('_', ' ', $formattedKey);
    $DataToSend .= "<p>$formattedKey: $value</p>";
  }

  $headers = array(
    'MIME-Version' => '1.0',
    'Content-type' => 'text/html; charset=iso-8859-1',
    'From' => emailFrom,
    'Bcc' => emailTo
  );

  $body = "
  <html>
  <body>
    <h2>Nombre del tramite: " . $data['name'] . " </h2>
    <h2>Numero de tramite: " . $data["data_form"]["prefix"] . $data["id_application"] . " </h2>
    <p>Datos</p>\n\n\n
    $DataToSend
  </body>
  </html>
  ";

  // print_r($body);

  $to = $data['data_form']['email'];

  $subject = "Nueva solicitud";

  mail($to, $subject, $body, $headers);
  // var_dump($s);
}
