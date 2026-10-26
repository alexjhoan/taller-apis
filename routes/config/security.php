<?php
// config/security.php

return [
  // Columnas que NUNCA deben salir en un GET hacia el Front-End
  'global_blacklist' => [
    'password',
    'pwd',
    'token_auth',
    'user_token',
    'secret_key',
    'salt',
    'auth_key'
  ],

  // Tablas del sistema que están completamente protegidas y no se pueden acceder vía CRUD dinámico
  'protected_tables' => [
    'migrations',
    'api_errors',
    'database_logs'
  ]
];
