<?php

require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../utils.php';

class PushController extends Controller
{
  private const PUSH_ENDPOINT_DOMAINS = [
    'fcm.googleapis.com',
    'push.services.mozilla.com',
    'push.apple.com',
    'notify.windows.com',
    'push.opera.com',
  ];

  private function authenticatedUser(): array
  {
    $tokenUser = Utils::validateBearerToken();
    $userId = filter_var($tokenUser['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
      $this->jsonResponse([], 401, 'Invalid authenticated user');
    }

    try {
      $db = DataBase::getConnection();
      $stmt = $db->prepare('SELECT role, active FROM users WHERE id = ? LIMIT 1');
      $stmt->bind_param('i', $userId);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc();
      $stmt->close();
    } catch (Throwable $error) {
      error_log('Could not authenticate push request: ' . $error->getMessage());
      $this->jsonResponse([], 500, 'Database error');
    }
    if (!$user || (int) $user['active'] !== 1) {
      $this->jsonResponse([], 401, 'Authenticated user is inactive or no longer exists');
    }
    if (!in_array($user['role'], ['admin', 'mechanic'], true)) {
      $this->jsonResponse([], 403, 'Push notifications are available to admins and mechanics');
    }

    return ['id' => (int) $userId, 'db' => $db];
  }

  private function endpointFromPayload(mixed $payload): ?string
  {
    $endpoint = is_array($payload) ? ($payload['endpoint'] ?? null) : null;
    $host = is_string($endpoint) ? strtolower((string) parse_url($endpoint, PHP_URL_HOST)) : '';
    $trustedHost = false;
    foreach (self::PUSH_ENDPOINT_DOMAINS as $domain) {
      if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        $trustedHost = true;
        break;
      }
    }
    if (
      !is_string($endpoint) || $endpoint === '' || strlen($endpoint) > 2048
      || filter_var($endpoint, FILTER_VALIDATE_URL) === false
      || parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
      || !$trustedHost
    ) {
      return null;
    }
    return $endpoint;
  }

  public function publicKey(): void
  {
    $this->authenticatedUser();
    $publicKey = getenv('VAPID_PUBLIC_KEY');
    if (!is_string($publicKey) || $publicKey === '') {
      $this->jsonResponse([], 503, 'Push notifications are not configured');
    }
    $this->jsonResponse(['public_key' => $publicKey]);
  }

  public function subscribe(): void
  {
    $user = $this->authenticatedUser();
    $payload = $this->getBody();
    $endpoint = $this->endpointFromPayload($payload);
    $keys = is_array($payload) ? ($payload['keys'] ?? null) : null;
    $p256dh = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
    $auth = is_array($keys) ? ($keys['auth'] ?? null) : null;
    $userAgent = is_array($payload) ? ($payload['user_agent'] ?? null) : null;

    if (
      $endpoint === null || !is_string($p256dh) || $p256dh === '' || strlen($p256dh) > 255
      || !is_string($auth) || $auth === '' || strlen($auth) > 255
      || ($userAgent !== null && (!is_string($userAgent) || strlen($userAgent) > 255))
    ) {
      $this->jsonResponse([], 422, 'Invalid push subscription payload');
    }

    $db = $user['db'];
    $endpointHash = hash('sha256', $endpoint);
    try {
      $stmt = $db->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           id = LAST_INSERT_ID(id),
           user_id = VALUES(user_id),
           endpoint = VALUES(endpoint),
           p256dh = VALUES(p256dh),
           auth = VALUES(auth),
           user_agent = VALUES(user_agent),
           created_at = CURRENT_TIMESTAMP'
      );
      $stmt->bind_param('isssss', $user['id'], $endpointHash, $endpoint, $p256dh, $auth, $userAgent);
      $stmt->execute();
      $this->jsonResponse(['id' => (int) $stmt->insert_id, 'saved' => true]);
    } catch (Throwable $error) {
      error_log('Could not save push subscription: ' . $error->getMessage());
      $this->jsonResponse([], 500, 'Could not save push subscription');
    }
  }

  public function unsubscribe(): void
  {
    $user = $this->authenticatedUser();
    $payload = $this->getBody();
    $endpoint = $this->endpointFromPayload($payload);
    if ($endpoint === null) {
      $this->jsonResponse([], 422, 'Invalid push subscription endpoint');
    }

    $endpointHash = hash('sha256', $endpoint);
    try {
      $stmt = $user['db']->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?');
      $stmt->bind_param('s', $endpointHash);
      $stmt->execute();
      $this->jsonResponse(['deleted' => $stmt->affected_rows]);
    } catch (Throwable $error) {
      error_log('Could not remove push subscription: ' . $error->getMessage());
      $this->jsonResponse([], 500, 'Could not remove push subscription');
    }
  }
}
