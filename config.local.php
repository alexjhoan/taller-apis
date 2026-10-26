<?php
// config.local.php

// SEGURIDAD: Este archivo contiene llaves sensibles y NO debe subirse a Git (.gitignore)

// Token de aplicación global (para validaciones estáticas si se requieren)
define("APPLICATION_KEY", "Bearer Y29kZSBiYXNlNjQgZGUgSW5mJm5pVDMtZDNUZWNUJm9OIEF1VDBSaVpAdEkwbiBDb04zWFRpME4gQFBpIERAVEEgME5seSBMb0NBbA==");

// Firma Secreta para la codificación/decodificación de JWT (La que usamos en utils.php)
define("JWT_SECRET_KEY", "TuClaveSecretaSuperSeguraParaJWT_2026");

// Configuración de Servidor de Correos (Genérico)
define("MAIL_DEFAULT_FROM", "App No-Reply <noreply@tudominio.com>");
define("MAIL_SUPPORT_TO", "alexjhoan.25@gmail.com");

// Configuración de Entorno e Inyección de Errores
define("DISPLAY_ERRORS", 1);
define("LOG_ERRORS", 1);

if (DISPLAY_ERRORS) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', 0);
  error_reporting(0);
}
