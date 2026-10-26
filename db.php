<?php
class DataBase
{
  private static ?mysqli $connection = null;

  public static function getConnection(): mysqli
  {
    if (self::$connection instanceof mysqli) {
      return self::$connection;
    }

    $host = 'localhost';
    $user = 'root';
    $password = '';
    $dbName = 'taller';
    $port = 3306;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    self::$connection = new mysqli($host, $user, $password, $dbName, $port);
    self::$connection->set_charset('utf8mb4');

    return self::$connection;
  }
}
