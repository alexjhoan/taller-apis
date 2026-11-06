<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use GuzzleHttp\Client as GuzzleClient;

class PushSender
{
  private const MAX_PAYLOAD_BYTES = 4095;
  private const MAX_BODY_LENGTH = 200;

  public static function sendToAdmins(mysqli $db, int $orderId, string $body): void
  {
    try {
      $result = $db->query("SELECT id FROM users WHERE role = 'admin' AND active = 1");
      $userIds = array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'id'));
      self::sendToUsers($db, $userIds, [
        'title' => 'Mecaflow',
        'body' => self::truncate($body, self::MAX_BODY_LENGTH),
        'order_id' => $orderId,
      ]);
    } catch (Throwable $error) {
      error_log('Could not send admin push notification: ' . $error->getMessage());
    }
  }

  public static function sendToUsers(mysqli $db, array $userIds, array $payload): void
  {
    $userIds = array_values(array_unique(array_filter(
      array_map('intval', $userIds),
      static fn(int $userId): bool => $userId > 0
    )));
    if ($userIds === []) {
      return;
    }

    $payload['body'] = self::truncate((string) ($payload['body'] ?? ''), self::MAX_BODY_LENGTH);
    try {
      $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
      if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
        throw new RuntimeException('Web Push payload exceeds the 4096-byte limit');
      }

      $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
      $stmt = $db->prepare(
        "SELECT endpoint, p256dh, auth
         FROM push_subscriptions
         WHERE user_id IN ({$placeholders})"
      );
      $types = str_repeat('i', count($userIds));
      $bindParams = [$types];
      foreach ($userIds as &$userId) {
        $bindParams[] = &$userId;
      }
      unset($userId);
      call_user_func_array([$stmt, 'bind_param'], $bindParams);
      $stmt->execute();
      $subscriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt->close();
      if ($subscriptions === []) {
        return;
      }

      $publicKey = getenv('VAPID_PUBLIC_KEY');
      $privateKey = getenv('VAPID_PRIVATE_KEY');
      $subject = getenv('VAPID_SUBJECT');
      if (!$publicKey || !$privateKey || !$subject) {
        error_log('Web Push is not configured; notification was skipped');
        return;
      }

      $webPush = new WebPush([
        'VAPID' => [
          'subject' => $subject,
          'publicKey' => $publicKey,
          'privateKey' => $privateKey,
        ],
      ], [], new GuzzleClient([
        'timeout' => 5,
        'connect_timeout' => 2,
        'allow_redirects' => false,
      ]));
      foreach ($subscriptions as $subscription) {
        $webPush->queueNotification(
          Subscription::create([
            'endpoint' => $subscription['endpoint'],
            'keys' => [
              'p256dh' => $subscription['p256dh'],
              'auth' => $subscription['auth'],
            ],
          ]),
          $json
        );
      }

      foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
          continue;
        }

        error_log('Web Push delivery failed: ' . $report->getReason());
        if (!$report->isSubscriptionExpired()) {
          continue;
        }

        $endpointHash = hash('sha256', $report->getEndpoint());
        try {
          $deleteStmt = $db->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?');
          $deleteStmt->bind_param('s', $endpointHash);
          $deleteStmt->execute();
          $deleteStmt->close();
        } catch (Throwable $error) {
          error_log('Could not remove expired push subscription: ' . $error->getMessage());
        }
      }
    } catch (Throwable $error) {
      error_log('Could not send push notifications: ' . $error->getMessage());
    }
  }

  private static function truncate(string $value, int $maxLength): string
  {
    if (function_exists('mb_substr')) {
      return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
  }
}
