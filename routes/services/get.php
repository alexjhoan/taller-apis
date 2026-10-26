<?php
// services/get.php

require_once "db.php";
require_once "utils.php";

function getDataFromDB($table)
{
  // 1. Sanitizar el nombre de la tabla (Seguridad estructural)
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

  // Lista negra global de campos que JAMÁS deben salir al Front-End
  $blacklist = ['password', 'pwd', 'token_auth', 'user_token', 'secret_key', 'salt'];

  // Verificar si viene un ID específico por la Query String (?id=5)
  $id = isset($_GET['id']) ? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) : null;

  if ($id) {
    $sql = "SELECT * FROM `$table` WHERE `id` = ? LIMIT 1";
  } else {
    $sql = "SELECT * FROM `$table` ORDER BY `id` DESC";
  }

  $db = DataBase::getConnection();

  try {
    $stmt = $db->prepare($sql);

    if ($id) {
      $stmt->bind_param("i", $id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $json = [
      'status' => 200,
      'body' => [],
      'statusText' => 'success',
      'total' => $result->num_rows
    ];

    // Ciclo WHILE dinámico para procesar cada fila
    while ($row = $result->fetch_assoc()) {

      // FILTRO 1: Eliminación automática de columnas protegidas (Lista Negra)
      foreach ($blacklist as $sensitiveField) {
        if (array_key_exists($sensitiveField, $row)) {
          unset($row[$sensitiveField]);
        }
      }

      // FILTRO 2: Auto-decode de strings JSON almacenados en la DB (Tu lógica legacy optimizada)
      foreach ($row as $key => $value) {
        if ($value !== NULL && is_string($value)) {
          $start = substr($value, 0, 2);
          $startLong = substr($value, 0, 3);
          if ($startLong === '[{"' || $start === '{"' || $start === '["' || substr($value, 0, 4) === '[[{"') {
            $row[$key] = json_decode($value, true);
          }
        }
      }

      // Si se buscaba un ID único, guardamos el objeto directo, si no, lo acumulamos en el array
      if ($id) {
        $json['body'] = $row;
      } else {
        $json['body'][] = $row;
      }
    }

    echo json_encode($json, http_response_code(200));
  } catch (mysqli_sql_exception $error) {
    echo json_encode([
      'status' => 500,
      'body' => [],
      'statusText' => 'Database Error: ' . $error->getMessage()
    ], http_response_code(500));
  } finally {
    if (isset($stmt)) $stmt->close();
  }
}
