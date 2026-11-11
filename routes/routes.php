<?php
// routes.php

require_once 'Router.php';
require_once 'cors.php';
require_once __DIR__ . '/../utils.php';
// Nota: Recomiendo mover security.php/const.php a una carpeta dedicada si deseas.
// require_once 'config/security.php'; 

date_default_timezone_set("America/New_York");

$router = new Router();

// ==========================================
// RUTAS PÚBLICAS (No requieren Token)
// ==========================================

$router->get('/swagger-json', function () {
  require_once 'controllers/SwaggerController.php';
  $swagger = new SwaggerController();
  $swagger->getDocs();
});

$router->get('/get-version-app', function () {
  require_once __DIR__ . '/services/get.php';
  getDataFromDB('versions');
});

$loginHandler = function () {
  require_once 'controllers/UserController.php';
  $userController = new UserController();
  $userController->login();
};

// Alias compatibles: el frontend puede usar cualquiera de estas rutas de login.
$router->post('/login-user', $loginHandler);

$router->get('/api/push/public-key', function () {
  require_once __DIR__ . '/controllers/PushController.php';
  (new PushController())->publicKey();
});
$router->post('/api/push/subscribe', function () {
  require_once __DIR__ . '/controllers/PushController.php';
  (new PushController())->subscribe();
});
$router->post('/api/push/unsubscribe', function () {
  require_once __DIR__ . '/controllers/PushController.php';
  (new PushController())->unsubscribe();
});
$router->post('/api/push/test', function () {
  require_once __DIR__ . '/controllers/PushController.php';
  (new PushController())->test();
});

$router->get('/api/public/order/{token}', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->publicOrder();
});
$router->patch('/api/public/order/{token}', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->patchPublicOrder();
});
$router->post('/api/public/order/{token}/decision', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->publicDecision();
});
$router->post('/api/orders', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->createOrder();
});
$router->get('/api/orders', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->listOrders();
});
$router->get('/api/orders/delivered', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->listDeliveredOrders();
});
$router->get('/api/orders/{id}/audit-log', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->orderAuditLog();
});
$router->patch('/api/orders', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->updateOrder();
});
$ordersDeleteDisabled = static function (string $allow): void {
  http_response_code(405);
  header("Allow: {$allow}");
  echo json_encode([
    'status' => 405,
    'body' => [],
    'statusText' => 'Method not allowed'
  ]);
};
$router->delete('/api/orders', static function () use ($ordersDeleteDisabled): void {
  $ordersDeleteDisabled('GET, POST, PATCH');
});
$router->delete('/api/orders/{id}', static function () use ($ordersDeleteDisabled): void {
  $ordersDeleteDisabled('GET');
});
$router->get('/api/orders/{id}', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->getOrder();
});
$router->get('/api/orders/{id}/send-email', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->sendOrderSummaryEmail();
});
$router->patch('/api/orders/{id}/status', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->updateStatus();
});
$router->post('/api/orders/{id}/payment', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->createPayment();
});
$router->get('/api/payments/stats', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->paymentStats();
});
$router->get('/api/payments', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->listPayments();
});
$router->post('/api/tickets', function () {
  require_once __DIR__ . '/controllers/TicketsController.php';
  (new TicketsController())->create();
});
$router->get('/api/tickets', function () {
  require_once __DIR__ . '/controllers/TicketsController.php';
  (new TicketsController())->index();
});
$router->get('/api/tickets/{id}', function () {
  require_once __DIR__ . '/controllers/TicketsController.php';
  (new TicketsController())->show();
});
$router->patch('/api/tickets/{id}', function () {
  require_once __DIR__ . '/controllers/TicketsController.php';
  (new TicketsController())->update();
});
$router->post('/api/tickets/{id}/reply', function () {
  require_once __DIR__ . '/controllers/TicketsController.php';
  (new TicketsController())->reply();
});
$router->post('/api/orders/{id}/evidence', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->uploadEvidence();
});
$router->get('/api/orders/{id}/evidence', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->orderEvidence();
});
$router->delete('/api/orders/{id}/evidence/{evidenceId}', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->deleteEvidence();
});
$router->post('/api/orders/{id}/budget-items', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->addBudgetItem();
});
$router->post('/api/orders/{id}/withdrawal-comment', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->withdrawalComment();
});
$router->patch('/api/settings/cost-visibility', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->costVisibility();
});
$router->get('/api/alerts', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->alerts();
});
$router->post('/api/alerts', function () {
  http_response_code(405);
  echo json_encode([
    'status' => 405,
    'body' => [],
    'statusText' => 'Alerts are generated by the server'
  ]);
});
$router->patch('/api/alerts', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->updateAlertRead();
});
$router->post('/api/alerts/read-all', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->readAllAlerts();
});
$router->get('/api/orders/{id}/whatsapp-url', function () {
  require_once __DIR__ . '/controllers/WorkshopController.php';
  (new WorkshopController())->whatsappUrl();
});

$router->post('/password-recovery', function () {
  http_response_code(501);
  echo json_encode(['status' => 501, 'body' => [], 'statusText' => 'Password recovery is not configured']);
});

$router->post('/contact-form', function () {
  require_once 'services/sendContactEmail.php';
  sendContactEmail();
});


// ==========================================
// MIDDLEWARE DE AUTENTICACIÓN GLOBAL
// ==========================================
// Todas las rutas que se ejecuten después de esta línea requerirán obligatoriamente
// el Header 'Authorization: Bearer <token>' configurado en utils.php.
// Excepto las declaradas arriba que hacen un 'return' o 'exit' inmediato.

$router->dispatch(); // Despacha las rutas explícitas o ejecuta el Fallback Automático CRUD.