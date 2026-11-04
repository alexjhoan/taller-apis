<?php
class DataBase
{
  private static ?mysqli $connection = null;

  public static function getConnection(): mysqli
  {
    if (self::$connection instanceof mysqli) {
      return self::$connection;
    }

    // Usamos las variables de entorno que inyecta Docker Compose
    $host = getenv('DB_HOST') ?: 'mariadb';
    $user = getenv('DB_USER') ?: 'usuario_php';
    $password = getenv('DB_PASSWORD') ?: 'MiPasswordDeUsuarioSeguro456!';
    $dbName = getenv('DB_NAME') ?: 'taller';
    $port = 3306;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    self::$connection = new mysqli($host, $user, $password, $dbName, $port);
    self::$connection->set_charset('utf8mb4');

    return self::$connection;
  }
}
