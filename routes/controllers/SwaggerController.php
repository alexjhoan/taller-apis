<?php

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="Base API Documentation",
 *     version="1.0.0",
 *     description="API Documentation generated automatically by Swagger-PHP"
 * )
 * @OA\Server(
 *     url="http://localhost/base-apis",
 *     description="Local Development Server"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class SwaggerController
{
  public function getDocs()
  {
    require_once __DIR__ . '/../../vendor/autoload.php';

    // Scan the routes directory (Controllers)
    $openapi = \OpenApi\Generator::scan([__DIR__]);

    header('Content-Type: application/json');
    echo $openapi->toJson();
  }
}
