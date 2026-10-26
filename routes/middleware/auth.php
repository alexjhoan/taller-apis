<?php
// middleware/auth.php

require_once __DIR__ . "/../../utils.php";

// Ejecuta la validación del Header. Si falla, el método 'sendJsonError' detendrá la ejecución (exit).
$authenticatedUser = Utils::validateBearerToken();

// Si pasa la validación, la variable $authenticatedUser queda disponible para el index.php / routes.php