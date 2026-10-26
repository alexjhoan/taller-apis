<?php
// services/post.php

require_once "db.php";
require_once "utils.php";

function normalizeDynamicPayloadValue($key, $value)
{
  if ($value === null || $value === '') {
    return $value;
  }

  if (is_string($value) && preg_match('/^[\[{]/', $value)) {
    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
      return json_encode($decoded);
    }
  }

  if (is_array($value)) {
    return json_encode($value);
  }

  return $value;
}

function postData($table)
{
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

  // Capturar el payload sin importar si es JSON plano o Form-Data (Reutilizando tu lógica de detección)
  $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
  if (stripos($contentType, 'application/json') === 0 || stripos($contentType, 'text/plain') === 0) {
    $post = json_decode(file_get_contents("php://input"), true);
  } else if (stripos($contentType, 'multipart/form-data') === 0) {
    $post = $_POST;
    // Nota: Si manejas archivos ($_FILES), se procesarían en un controlador por excepción.
  } else {
    echo json_encode(['status' => 415, 'body' => [], 'statusText' => 'Unsupported Media Type'], http_response_code(415));
    return;
  }

  if (!is_array($post) || $post === []) {
    echo json_encode(['status' => 400, 'body' => [], 'statusText' => 'Bad Request: Empty Payload'], http_response_code(400));
    return;
  }

  // Preparación de componentes SQL dinámicos
  $fields = [];
  $placeholders = [];
  $types = '';
  $values = [];

  // Ciclo FOREACH iterativo para construir el INSERT parametrizado
  foreach ($post as $key => $value) {
    if (!is_string($key) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
      echo json_encode(['status' => 400, 'body' => [], 'statusText' => 'Bad Request: Invalid field name'], http_response_code(400));
      return;
    }

    if (!is_scalar($value) && $value !== null && !is_array($value)) {
      echo json_encode(['status' => 400, 'body' => [], 'statusText' => 'Bad Request: Invalid field value'], http_response_code(400));
      return;
    }

    if ($table === 'users' && $key === 'password') {
      if (!is_string($value) || $value === '') {
        echo json_encode(['status' => 400, 'body' => [], 'statusText' => 'Bad Request: Password must be a non-empty string'], http_response_code(400));
        return;
      }

      $value = password_hash($value, PASSWORD_DEFAULT);
      if ($value === false) {
        echo json_encode(['status' => 500, 'body' => [], 'statusText' => 'Internal Server Error: Could not hash password'], http_response_code(500));
        return;
      }
    }

    $value = normalizeDynamicPayloadValue($key, $value);
    $fields[] = "`$key`";
    $placeholders[] = "?";

    // Detectar tipo de dato para el bind_param
    if (is_int($value)) {
      $types .= 'i';
    } else if (is_float($value)) {
      $types .= 'd';
    } else {
      $types .= 's';
    }
    $values[] = $value;
  }

  $sql = "INSERT INTO `$table` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";

  $db = DataBase::getConnection();

  try {
    $stmt = $db->prepare($sql);

    // Empaquetar dinámicamente los parámetros usando el operador de esparcimiento (...)
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
      echo json_encode([
        'status' => 201,
        'body' => ['insert_id' => $db->insert_id],
        'statusText' => ucfirst($table) . ' creado exitosamente.'
      ], http_response_code(201));
    } else {
      echo json_encode(['status' => 500, 'body' => [], 'statusText' => 'No se pudo insertar el registro'], http_response_code(500));
    }
  } catch (mysqli_sql_exception $error) {
    echo json_encode(['status' => 500, 'body' => [], 'statusText' => 'Database Error: ' . $error->getMessage()], http_response_code(500));
  } finally {
    if (isset($stmt)) $stmt->close();
  }
}
