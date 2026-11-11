<?php
// Router.php

class Router
{
  private $routes = [];

  private function addRoute($method, $uri, $callback)
  {
    $this->routes[] = [
      'method' => $method,
      'uri' => rtrim($uri, '/'),
      'callback' => $callback
    ];
  }

  public function get($uri, $callback)
  {
    $this->addRoute('GET', $uri, $callback);
  }
  public function post($uri, $callback)
  {
    $this->addRoute('POST', $uri, $callback);
  }
  public function put($uri, $callback)
  {
    $this->addRoute('PUT', $uri, $callback);
  }
  public function patch($uri, $callback)
  {
    $this->addRoute('PATCH', $uri, $callback);
  }
  public function delete($uri, $callback)
  {
    $this->addRoute('DELETE', $uri, $callback);
  }

  public function dispatch()
  {
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $scriptName = parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH);
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    // REQUEST_URI includes the project folder when hosted below the web root.
    if ($basePath !== '' && $basePath !== '/' && str_starts_with($uri, $basePath . '/')) {
      $uri = substr($uri, strlen($basePath));
    }
    $uri = rtrim($uri, '/') ?: '/';

    // 1. Intentar buscar coincidencia exacta en las rutas registradas (Excepciones/Controladores)
    foreach ($this->routes as $route) {
      if ($route['method'] === $method && ($route['uri'] === $uri || $this->matchesRoute($route['uri'], $uri))) {
        $GLOBALS['routeParams'] = $this->routeParams($route['uri'], $uri);
        call_user_func($route['callback']);
        return;
      }
    }

    // 2. Si no hay coincidencia, activar el Motor Dinámico Automatizado (Fallback)
    $this->handleDynamicRouting($method, $uri);
  }

  private function matchesRoute($route, $uri)
  {
    $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $route);
    return (bool) preg_match('#^' . $pattern . '$#', $uri);
  }

  private function routeParams($route, $uri)
  {
    $names = [];
    $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($match) use (&$names) {
      $names[] = $match[1];
      return '([^/]+)';
    }, $route);
    preg_match('#^' . $pattern . '$#', $uri, $matches);
    $params = [];
    foreach ($names as $index => $name) {
      $params[$name] = urldecode($matches[$index + 1] ?? '');
    }
    return $params;
  }

  private function handleDynamicRouting($method, $uri)
  {
    // Extraemos el último segmento de la URI como el nombre del recurso/tabla
    $segments = array_filter(explode('/', $uri));
    $resource = end($segments);

    if (!$resource) {
      $this->sendResponse(404, 'Recurso no especificado');
    }

    require_once __DIR__ . '/middleware/auth.php';

    // Limpieza de caracteres extraños para el nombre de la tabla
    // $table = preg_replace('/[^a-zA-Z0-9__-]/', '', $resource);
    $table = $resource;
    $segments = array_values($segments);
    $isTicketRoute = ($segments[0] ?? null) === 'api'
      && in_array(strtolower($segments[1] ?? ''), ['tickets', 'ticket_replies'], true);
    $isPaymentRoute = ($segments[0] ?? null) === 'api'
      && strtolower($segments[1] ?? '') === 'payments';
    if (
      $isTicketRoute
      || $isPaymentRoute
      || in_array(strtolower($table), ['alerts', 'alert_recipients', 'payments', 'tickets', 'ticket_replies'], true)
    ) {
      $this->sendResponse(404, 'Recurso no encontrado');
    }

    // Mapeo dinámico hacia los servicios automáticos por método HTTP
    switch ($method) {
      case 'GET':
        require_once __DIR__ . '/services/get.php';
        // Llama al motor pasándole el recurso limpio
        getDataFromDB($table);
        break;

      case 'POST':
        require_once __DIR__ . '/services/post.php';
        postData($table);
        break;

      case 'PATCH':
        require_once __DIR__ . '/services/patch.php';
        patchData($table);
        break;

      case 'DELETE':
        require_once __DIR__ . '/services/delete.php';
        // Suponiendo que el ID viene en la query string ?id=X
        deleteData($table);
        break;

      default:
        $this->sendResponse(405, 'Método HTTP no permitido');
        break;
    }
  }

  private function sendResponse($status, $text)
  {
    echo json_encode([
      'status' => $status,
      'body' => [],
      'statusText' => $text
    ], http_response_code($status));
    exit();
  }
}
