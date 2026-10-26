<?php

// Check for local configuration file
if (file_exists(__DIR__ . '/config.local.php')) {
  require_once __DIR__ . '/config.local.php';
}

// Fallback or default values (Use with caution, better to fail if config is missing in production)
if (!defined('AUTHORIZATION_TOKEN')) {
  define("AUTHORIZATION_TOKEN", "Bearer DEFAULT_TOKEN_CHANGE_ME");
}
if (!defined('EMAIL_FROM')) {
  define("EMAIL_FROM", "no-reply@example.com");
}
if (!defined('EMAIL_TO')) {
  define("EMAIL_TO", "admin@example.com");
}
