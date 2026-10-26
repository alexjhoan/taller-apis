<?php

require_once "db.php";
require_once "vendor/autoload.php";
require_once "const.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Utils
{
  static function diverseArray($vector)
  {
    $result = [];
    foreach ($vector as $key1 => $value1) {
      foreach ($value1 as $key2 => $value2) {
        $result[$key2][$key1] = $value2;
      }
    }
    return $result;
  }
  static function isRecordExistsMulti($table, $column, $record, $db = null)
  {

    $recordArray = explode(',', $record);

    // Limpieza de los IDs (opcional, en caso de que haya espacios)
    $recordArray = array_map('trim', $recordArray);

    // Convertir el array en enteros para seguridad
    $recordArray = array_map('intval', $recordArray);

    // Crear los marcadores de posición para la consulta
    $placeholders = implode(',', array_fill(0, count($recordArray), '?'));
    $sql = "SELECT * FROM $table WHERE `$column` IN ($placeholders)";

    try {

      $db = DataBase::getConnection($db);
      $stmt = $db->prepare($sql);
      $row = [];

      // Vincular los IDs de productos
      $bindParams = [];
      $types = str_repeat('i', count($recordArray)); // Suponiendo que son todos enteros

      // Vincular los parámetros
      foreach ($recordArray as $key => $id) {
        $bindParams[$key] = $id; // Asignar cada ID a una posición del array
      }

      // El operador splat (...) se usa para pasar el array como parámetros
      $stmt->bind_param($types, ...$bindParams);
      $stmt->execute();
      $result = $stmt->get_result();

      // Agregar productos a la respuesta

      if ($result->num_rows == 0) {
        return ["boolean" => false, "data" => null, "total" => 0];
      } else if ($result->num_rows == 1) {
        foreach ($result->fetch_assoc() as $key => $value) {
          ($value !== NULL && substr($value, 0, 3) === '[{"') ? $row[$key] = json_decode($value, true) : $row[$key] = $value;
        }
        return ["boolean" => true, "data" => $row, "total" => $result->num_rows];
      } else {
        $myArray = [];
        while ($row = $result->fetch_assoc()) {
          foreach ($row as $key => $value) {
            ($value !== NULL && substr($value, 0, 3) === '[{"') ? $row[$key] = json_decode($value, true) : $row[$key] = $value;
          }
          array_push($myArray, $row);
        }
        return ["boolean" => true, "data" => $myArray, "total" => $result->num_rows];
      }
    } catch (mysqli_sql_exception $error) {
      $json = [
        'status' => 500,
        'body' => [],
        'statusText' => $error->getMessage()
      ];
      echo json_encode($json, http_response_code($json["status"]));
      die();
    } finally {
      if (isset($stmt)) $stmt->close();
    }
  }
  static function isRecordExists($table, $column, $record)
  {
    // Limpieza básica de nombres de tabla/columna para evitar inyecciones estructurales
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

    $sql = "SELECT * FROM `$table` WHERE `$column` = ? LIMIT 1";

    try {
      $db = DataBase::getConnection(); // Conexión única genérica
      $stmt = $db->prepare($sql);

      $type = is_numeric($record) ? 'i' : 's';
      $stmt->bind_param($type, $record);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result->num_rows === 0) {
        return ["boolean" => false, "data" => null];
      }

      return ["boolean" => true, "data" => $result->fetch_assoc()];
    } catch (mysqli_sql_exception $error) {
      return ["boolean" => false, "data" => null, "error" => $error->getMessage()];
    } finally {
      if (isset($stmt)) $stmt->close();
    }
  }
  /**
   * Valida el Token JWT recibido desde el Header HTTP Authorization Bearer
   */
  static function validateBearerToken()
  {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
      self::sendJsonError(401, 'Authorization token missing or malformed');
    }

    $token = $matches[1]; // Extrae el string puro del token JWT
    $decodedToken = self::jwtDecode($token);

    if ($decodedToken === 'empty') {
      self::sendJsonError(401, 'The token is invalid or expired');
    }

    $tokenData = (array) $decodedToken["data"];

    // Buscamos al usuario de forma genérica usando el id del token en la tabla 'users'
    // Asumiendo que tu tabla base de usuarios se llama 'users' y la llave primaria 'id'
    $userCheck = self::isRecordExists('users', 'id', $tokenData['id']);

    if (!$userCheck['boolean']) {
      self::sendJsonError(401, 'User associated with this token no longer exists');
    }

    // Retorna los datos del usuario autenticado para que el resto de la app los use si es necesario
    return $tokenData;
  }

  /**
   * Utilidad estandarizada para responder errores JSON rápidamente
   */
  static function sendJsonError($statusCode, $message)
  {
    $json = [
      'status' => $statusCode,
      'body' => [],
      'statusText' => $message
    ];
    echo json_encode($json, http_response_code($statusCode));
    exit();
  }
  static function ResizeAndSaveImg($img, $ext, $folder)
  {
    $nAncho = 1000; // Nuevo ancho
    $nAlto = 1000;  // Nuevo alto

    // Validamos la extensión y creamos una nueva imagen a partir del fichero inicial
    switch ($ext) {
      case 'jpg':
      case 'jpeg':
        $imagen = imagecreatefromjpeg($img);
        break;
      case 'png':
        $imagen = imagecreatefrompng($img);
        break;
      default:
        $json = [
          'status' => 400,
          'body' => [],
          'statusText' => "It is not a valid image, only formats, jpg and png"
        ];
        echo json_encode($json, http_response_code($json["status"]));
        die();
    }

    // Obtenemos el tamaño 
    $x = imagesx($imagen);
    $y = imagesy($imagen);

    if ($x > 1000 || $y > 1000) {
      // Validamos los tamaños y calculamos la relación de aspecto
      if ($x >= $y) {
        $nAlto = $nAncho * $y / $x;
      } else {
        $nAncho = $x / $y * $nAlto;
      }

      // Crear una nueva imagen, copia y cambia el tamaño de la imagen
      // Aquí es donde configuramos la transparencia
      $img = imagecreatetruecolor($nAncho, $nAlto);

      // Habilitar la transparencia en la nueva imagen
      imagealphablending($img, false);
      imagesavealpha($img, true);
      $transparent = imagecolorallocatealpha($img, 255, 255, 255, 127); // Color blanco con 100% de transparencia
      imagefill($img, 0, 0, $transparent);

      imagecopyresampled($img, $imagen, 0, 0, 0, 0, floor($nAncho), floor($nAlto), $x, $y);
    } else {
      $img = $imagen; // Usar la imagen original si no es necesaria la redimensión
    }

    // Verificar si el directorio existe, si no, crearlo
    if (!is_dir(dirname($folder))) {
      mkdir(dirname($folder), 0755, true); // Crea el directorio con permisos 0755
    }

    // Guardar la imagen con la extensión correcta
    switch ($ext) {
      case 'jpg':
      case 'jpeg':
        imagejpeg($img, $folder);
        break;
      case 'png':
        imagepng($img, $folder);
        break;
    }

    // Liberamos la memoria
    imagedestroy($img);
    imagedestroy($imagen);
  }

  static function normalizeFileName($filename)
  {
    // Remove accents
    $filename = iconv('UTF-8', 'ASCII//TRANSLIT', $filename);
    // Replace spaces and special characters with underscores
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);
    // Remove multiple underscores
    $filename = preg_replace('/_+/', '_', $filename);
    // Trim underscores from start and end
    $filename = trim($filename, '_');
    return $filename;
  }

  static function validateAppToken()
  {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;

    if (!$authHeader || $authHeader !== AUTHORIZATION_TOKEN) {
      $json = [
        'status' => 403,
        'body' => [],
        'statusText' => 'Forbidden: Invalid Authorization Token'
      ];
      echo json_encode($json, http_response_code($json["status"]));
      die();
    }
  }

  static public function JwtEncode($data)
  {
    $key = AUTHORIZATION_TOKEN;
    $token = [
      "iat" => time(),
      "exp" => time() + (60 * 60 * 24 * 30),
      "data" => $data
    ];

    $jwt = JWT::encode($token, $key, 'HS512');
    // foreach ($data as $clave => $valor) {
    //   $token[$clave] = $valor;
    // }
    return ["jwt" => $jwt, "iat" => $token['iat'], "exp" => $token['exp']];
  }

  /**
   * Decodifica un Token JWT de forma segura
   */
  static function jwtDecode($token)
  {
    try {
      // Reemplaza 'TuClaveSecretaGlobal' por tu constante o variable de entorno
      $decoded = JWT::decode($token, new Key(AUTHORIZATION_TOKEN, 'HS512'));
      return (array) $decoded;
    } catch (\Exception $e) {
      return 'empty';
    }
  }
}
