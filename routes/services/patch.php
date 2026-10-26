<?php
// services/patch.php

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

function patchData($table, ?array $post = null)
{
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  if ($post === null) {
    $post = json_decode(file_get_contents("php://input"), true);
  }

  if (!is_array($post) || !isset($post['id'])) {
    echo json_encode(['status' => 400, 'body' => [], 'statusText' => 'Bad Request: Missing ID parameter'], http_response_code(400));
    return;
  }

  $id = filter_var($post['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($id === false) {
    echo json_encode(['status' => 400, 'body' => [], 'statusText' => 'Bad Request: Invalid ID parameter'], http_response_code(400));
    return;
  }

  $sqlSets = [];
  $types = '';
  $values = [];

  // Ciclo FOREACH para armar el SET dinámico
  foreach ($post as $key => $value) {
    if ($key === 'id') continue; // Excluir el ID de las columnas a actualizar

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
    $sqlSets[] = "`$key` = ?";

    if (is_int($value)) {
      $types .= 'i';
    } else if (is_float($value)) {
      $types .= 'd';
    } else {
      $types .= 's';
    }
    $values[] = $value;
  }

  if (empty($sqlSets)) {
    echo json_encode(['status' => 400, 'body' => [], 'statusText' => 'Bad Request: No fields to update'], http_response_code(400));
    return;
  }

  // Inyectar el ID al final de los parámetros para el WHERE
  $sql = "UPDATE `$table` SET " . implode(', ', $sqlSets) . " WHERE `id` = ?";
  $types .= 'i';
  $values[] = $id;

  $db = DataBase::getConnection();

  try {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    // Nota: affected_rows puede ser 0 si mandan los mismos datos que ya existían
    echo json_encode([
      'status' => 200,
      'body' => [],
      'statusText' => ucfirst($table) . ' actualizado correctamente.'
    ], http_response_code(200));
  } catch (mysqli_sql_exception $error) {
    echo json_encode(['status' => 500, 'body' => [], 'statusText' => 'Database Error: ' . $error->getMessage()], http_response_code(500));
  } finally {
    if (isset($stmt)) $stmt->close();
  }
}
