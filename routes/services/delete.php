<?php

require_once "db.php";
require_once "utils.php";

function deleteData($table)
{
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $column = $_GET['column'] ?? 'id';
  $id = $_GET['id'] ?? null;

  if (
    !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)
    || !is_string($column)
    || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)
    || !is_scalar($id)
    || (string) $id === ''
  ) {
    http_response_code(400);
    echo json_encode(['status' => 400, 'body' => [], 'statusText' => 'Bad Request: Invalid column or ID']);
    return;
  }

  $sql = "DELETE FROM `$table` WHERE `$column` = ?";
  $db = DataBase::getConnection();

  try {
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $id);
    $stmt->execute();

    $status = $stmt->affected_rows > 0 ? 200 : 404;
    http_response_code($status);
    echo json_encode([
      'status' => $status,
      'body' => [],
      'statusText' => $status === 200 ? 'Record deleted successfully' : 'Record not found'
    ]);
  } catch (mysqli_sql_exception $error) {
    error_log($error->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 500, 'body' => [], 'statusText' => 'Database Error']);
  } finally {
    if (isset($stmt)) {
      $stmt->close();
    }
  }
}
