<?php

class Controller
{
  protected function jsonResponse($data, $status = 200, $statusText = 'success', ?int $total = null)
  {
    http_response_code($status);
    $response = [
      'status' => $status,
      'body' => $data,
      'statusText' => $statusText
    ];

    if ($total !== null) {
      $response['total'] = $total;
    }

    echo json_encode($response);
    exit;
  }

  protected function getBody()
  {
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

    if (stripos($contentType, 'application/json') === 0 || stripos($contentType, 'text/plain') === 0) {
      return json_decode(file_get_contents("php://input"), true);
    } else if (stripos($contentType, 'multipart/form-data') === 0) {
      return array_merge($_POST, ['_files' => $_FILES]);
    }

    return [];
  }
}
