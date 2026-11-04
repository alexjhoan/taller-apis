<?php

$allowed_domains = [
  'http://localhost:3000',
  'http://ncbyiqsys3jptteev2yuoa1a.207.246.118.124.sslip.io'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Check if origin is allowed
if (in_array($origin, $allowed_domains)) {
  header("Access-Control-Allow-Origin: " . $origin);
} else {
  // Default fallback
  header("Access-Control-Allow-Origin: https://bwtne.com");
}

header("Access-Control-Allow-Origin: *");
header("Vary: Origin");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: OPTIONS,GET,PUT,PATCH,POST,DELETE");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Access-Control-Request-Headers, Authorization");

// Handle preflight requests early
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  header("HTTP/1.1 200 OK");
  exit();
}
