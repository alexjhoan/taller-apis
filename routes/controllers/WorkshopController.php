<?php

require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../utils.php';
require_once __DIR__ . '/../services/PushSender.php';

class WorkshopController extends Controller
{
  private const ORDER_WRITE_BLOCKLIST = ['id', 'public_token', 'created_by', 'created_at', 'updated_at', 'images', 'videos'];
  private const EVIDENCE_TYPES = ['reception', 'work_progress', 'final'];

  private function user(array $roles = []): array
  {
    $user = Utils::validateBearerToken();
    $role = $user['role'] ?? 'mechanic';
    if ($roles && !in_array($role, $roles, true)) {
      $this->jsonResponse([], 403, 'Insufficient permissions');
    }
    $user['role'] = $role;
    return $user;
  }

  private function params(): array
  {
    return $GLOBALS['routeParams'] ?? [];
  }

  private function absoluteEvidenceUrl(string $path): string
  {
    $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? (
      !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http'
    )));
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    if (
      !in_array($scheme, ['http', 'https'], true)
      || !preg_match('/^(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:.]+\])(?::[0-9]{1,5})?$/D', $host)
    ) {
      throw new RuntimeException('Could not determine the public URL for order evidence');
    }

    $scriptPath = parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
    $basePath = rtrim(str_replace('\\', '/', dirname(is_string($scriptPath) ? $scriptPath : '')), '/');
    if ($basePath === '.') {
      $basePath = '';
    }

    $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    return $scheme . '://' . $host . $basePath . '/' . $encodedPath;
  }

  private function evidenceTypesForRole(string $role): array
  {
    return match ($role) {
      'admin' => self::EVIDENCE_TYPES,
      'mechanic' => ['reception', 'work_progress'],
      'reception' => ['reception'],
      default => [],
    };
  }

  private function evidenceMediaType(mixed $mediaType, string $fileUrl): ?string
  {
    if (in_array($mediaType, ['photo', 'video'], true)) {
      return $mediaType;
    }

    return match (strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION))) {
      'jpg' => 'photo',
      'mp4' => 'video',
      default => null,
    };
  }

  private function evidenceUrlsByOrder(mysqli $db, array $orders, ?array $allowedTypes = null): array
  {
    $orderIds = array_values(array_unique(array_filter(
      array_map('intval', array_column($orders, 'id')),
      static fn(int $id): bool => $id > 0
    )));
    if ($orderIds === []) {
      return [];
    }

    $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
    $allowedTypes ??= self::EVIDENCE_TYPES;
    if ($allowedTypes === []) {
      return [];
    }

    $typePlaceholders = implode(', ', array_fill(0, count($allowedTypes), '?'));
    $stmt = $db->prepare(
      "SELECT id, order_id, evidence_type, type, file_url, created_at
       FROM order_evidence
       WHERE order_id IN ({$placeholders}) AND evidence_type IN ({$typePlaceholders})
       ORDER BY FIELD(evidence_type, 'reception', 'work_progress', 'final'), created_at ASC, id ASC"
    );
    $types = str_repeat('i', count($orderIds)) . str_repeat('s', count($allowedTypes));
    $bindParams = [$types];
    foreach ($orderIds as &$orderId) {
      $bindParams[] = &$orderId;
    }
    unset($orderId);
    foreach ($allowedTypes as &$allowedType) {
      $bindParams[] = &$allowedType;
    }
    unset($allowedType);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    $stmt->execute();

    $evidenceByOrder = [];
    $seenUrls = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
      $orderId = (int) $row['order_id'];
      $url = $this->absoluteEvidenceUrl((string) $row['file_url']);
      if (!isset($seenUrls[$orderId][$url])) {
        $evidenceByOrder[$orderId][] = [
          'id' => (int) $row['id'],
          'url' => $url,
          'evidence_type' => $row['evidence_type'],
          'type' => $this->evidenceMediaType($row['type'], (string) $row['file_url']),
          'created_at' => $row['created_at'],
        ];
        $seenUrls[$orderId][$url] = true;
      }
    }
    $stmt->close();

    foreach ($orders as $order) {
      $orderId = (int) $order['id'];
      foreach (['images', 'videos'] as $field) {
        if (!in_array('work_progress', $allowedTypes, true)) {
          continue;
        }
        $filenames = explode(',', (string) ($order[$field] ?? ''));
        foreach ($filenames as $filename) {
          $filename = trim($filename);
          if ($filename === '') {
            continue;
          }
          if (basename($filename) !== $filename || str_contains($filename, '\\')) {
            throw new UnexpectedValueException("Invalid evidence filename in work_orders.{$field}");
          }

          $url = $this->absoluteEvidenceUrl('assets/evidence/' . $orderId . '/' . $filename);
          if (!isset($seenUrls[$orderId][$url])) {
            preg_match('/^(\d{8}_\d{6})_/', $filename, $timestampMatch);
            $legacyDate = isset($timestampMatch[1])
              ? DateTimeImmutable::createFromFormat('!Ymd_His', $timestampMatch[1])
              : false;
            $createdAt = $legacyDate instanceof DateTimeImmutable
              ? $legacyDate->format('Y-m-d H:i:s')
              : null;
            $evidenceByOrder[$orderId][] = [
              'id' => null,
              'url' => $url,
              'evidence_type' => 'work_progress',
              'type' => $field === 'images' ? 'photo' : 'video',
              'created_at' => $createdAt,
            ];
            $seenUrls[$orderId][$url] = true;
          }
        }
      }
    }

    $typeOrder = array_flip(self::EVIDENCE_TYPES);
    foreach ($evidenceByOrder as &$evidenceItems) {
      usort($evidenceItems, static function (array $left, array $right) use ($typeOrder): int {
        $typeComparison = $typeOrder[$left['evidence_type']] <=> $typeOrder[$right['evidence_type']];
        if ($typeComparison !== 0) {
          return $typeComparison;
        }
        $dateComparison = strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));
        if ($dateComparison !== 0) {
          return $dateComparison;
        }
        return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
      });
    }
    unset($evidenceItems);

    return $evidenceByOrder;
  }

  private function addEvidenceUrls(array $orders, array $evidenceByOrder): array
  {
    foreach ($orders as &$order) {
      $evidence = $evidenceByOrder[(int) $order['id']] ?? [];
      $this->setVisibleEvidenceMediaFields($order, $evidence);
      $order['evidence'] = $evidence;
    }
    unset($order);

    return $orders;
  }

  private function setVisibleEvidenceMediaFields(array &$order, array $evidence): void
  {
    $filenamesByField = ['images' => [], 'videos' => []];
    foreach ($evidence as $item) {
      $field = match ($item['type'] ?? null) {
        'photo' => 'images',
        'video' => 'videos',
        default => null,
      };
      if ($field !== null) {
        $path = parse_url((string) ($item['url'] ?? ''), PHP_URL_PATH);
        $filename = is_string($path) ? basename($path) : '';
        if ($filename !== '') {
          $filenamesByField[$field][] = $filename;
        }
      }
    }

    foreach ($filenamesByField as $field => $filenames) {
      if (array_key_exists($field, $order)) {
        $order[$field] = $filenames === [] ? null : implode(',', $filenames);
      }
    }
  }

  private function dbError(Throwable $error): void
  {
    error_log($error->getMessage());
    $this->jsonResponse([], 500, 'Database error');
  }

  private function assignedUserIds(mixed $value): array
  {
    if ($value === null || $value === '') {
      return [];
    }

    if (is_string($value)) {
      $decoded = json_decode($value, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        $value = $decoded;
      } elseif (preg_match('/^[1-9][0-9]*$/D', $value)) {
        $value = [(int) $value];
      } else {
        $this->jsonResponse([], 422, 'assigned_user_id must be a JSON array of user IDs');
      }
    }

    if (is_int($value)) {
      $value = [$value];
    }

    if (!is_array($value) || array_values($value) !== $value) {
      $this->jsonResponse([], 422, 'assigned_user_id must be a JSON array of user IDs');
    }

    $userIds = [];
    foreach ($value as $userId) {
      if (
        (!is_int($userId) && !is_string($userId))
        || !preg_match('/^[1-9][0-9]*$/D', (string) $userId)
        || filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
      ) {
        $this->jsonResponse([], 422, 'assigned_user_id must contain positive user IDs');
      }
      $userIds[] = (int) $userId;
    }

    if (count($userIds) !== count(array_unique($userIds))) {
      $this->jsonResponse([], 422, 'assigned_user_id must not contain duplicate user IDs');
    }

    sort($userIds, SORT_NUMERIC);
    return $userIds;
  }

  private function validateActiveMechanics(mysqli $db, array $userIds): array
  {
    if ($userIds === []) {
      return [];
    }

    $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
    $stmt = $db->prepare(
      "SELECT id, first_name, last_name, role
       FROM users
       WHERE active = 1 AND role IN ('mechanic', 'admin') AND id IN ({$placeholders})"
    );
    $types = str_repeat('i', count($userIds));
    $bindParams = [$types];
    foreach ($userIds as &$userId) {
      $bindParams[] = &$userId;
    }
    unset($userId);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    $stmt->execute();
    $mechanics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($mechanics) !== count($userIds)) {
      $this->jsonResponse([], 422, 'Every assigned_user_id must identify an active admin or mechanic');
    }

    return $mechanics;
  }

  private function authenticatedAlertActor(mysqli $db, array $user): string
  {
    $userId = (int) $user['id'];
    $stmt = $db->prepare('SELECT first_name, last_name, role FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $identity = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$identity) {
      $this->jsonResponse([], 401, 'Authenticated user no longer exists');
    }

    $actor = [
      'id' => $userId,
      'name' => trim((string) $identity['first_name'] . ' ' . (string) $identity['last_name']),
      'role' => (string) $identity['role'],
    ];
    return json_encode($actor, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
  }

  private function insertAlertBestEffort(
    mysqli $db,
    int $orderId,
    string $type,
    string $message,
    ?array $assignedUserIds = null
  ): bool
  {
    $message = preg_match('/^.{0,255}/us', $message, $matches) ? $matches[0] : substr($message, 0, 255);
    $transactionStarted = false;
    try {
      $db->begin_transaction();
      $transactionStarted = true;
      $stmt = $db->prepare('INSERT INTO alerts (order_id, type, message, targeted) VALUES (?, ?, ?, 1)');
      $stmt->bind_param('iss', $orderId, $type, $message);
      $stmt->execute();
      $alertId = (int) $stmt->insert_id;
      $stmt->close();

      if ($assignedUserIds === null) {
        $assignmentStmt = $db->prepare('SELECT assigned_user_id FROM work_orders WHERE id = ? LIMIT 1');
        $assignmentStmt->bind_param('i', $orderId);
        $assignmentStmt->execute();
        $assignment = $assignmentStmt->get_result()->fetch_assoc();
        $assignmentStmt->close();
        $assignedUserIds = $this->assignedUserIds($assignment['assigned_user_id'] ?? null);
      }

      $recipientCondition = $assignedUserIds === []
        ? "u.role = 'admin'"
        : "u.role = 'admin' OR (u.role = 'mechanic' AND u.id IN (" .
          implode(', ', array_fill(0, count($assignedUserIds), '?')) . '))';
      $recipientsStmt = $db->prepare(
        "INSERT INTO alert_recipients (alert_id, user_id)
         SELECT ?, u.id FROM users u
         WHERE u.active = 1 AND ({$recipientCondition})"
      );
      $types = 'i' . str_repeat('i', count($assignedUserIds));
      $bindParams = [$types, &$alertId];
      foreach ($assignedUserIds as &$assignedUserId) {
        $bindParams[] = &$assignedUserId;
      }
      unset($assignedUserId);
      call_user_func_array([$recipientsStmt, 'bind_param'], $bindParams);
      $recipientsStmt->execute();
      $recipientCount = $recipientsStmt->affected_rows;
      $recipientsStmt->close();
      if ($recipientCount < 1) {
        throw new RuntimeException("No active recipients for {$type} alert on order {$orderId}");
      }

      if (!$db->commit()) {
        throw new RuntimeException("Could not commit {$type} alert and recipients for order {$orderId}");
      }
      $transactionStarted = false;
      if ($type !== 'order_assigned') {
        PushSender::sendToAdmins($db, $orderId, $message);
      }
      return true;
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      error_log("Could not create {$type} alert for order {$orderId}: " . $error->getMessage());
      return false;
    }
  }

  private function notifyAcceptedBudgetBestEffort(mysqli $db, int $orderId, string $vehiclePlate): bool
  {
    try {
      $existsStmt = $db->prepare(
        "SELECT 1 FROM alerts
         WHERE order_id = ? AND type = 'budget_accepted'
           AND (
             (targeted = 0 AND read_at IS NULL)
             OR (
               targeted = 1
               AND EXISTS (
                 SELECT 1 FROM alert_recipients
                 WHERE alert_recipients.alert_id = alerts.id
                   AND alert_recipients.read_at IS NULL
               )
             )
           )
         LIMIT 1"
      );
      $existsStmt->bind_param('i', $orderId);
      $existsStmt->execute();
      $exists = (bool) $existsStmt->get_result()->fetch_row();
      $existsStmt->close();
      if ($exists) {
        return false;
      }

      $totalStmt = $db->prepare(
        'SELECT COALESCE(SUM(quantity * unit_price), 0) AS total FROM budget_items WHERE order_id = ?'
      );
      $totalStmt->bind_param('i', $orderId);
      $totalStmt->execute();
      $total = (float) $totalStmt->get_result()->fetch_assoc()['total'];
      $totalStmt->close();

      $amount = number_format($total, 0, ',', '.');
      return $this->insertAlertBestEffort(
        $db,
        $orderId,
        'budget_accepted',
        "El cliente aceptó el presupuesto de la orden {$vehiclePlate} (\$ {$amount})."
      );
    } catch (Throwable $error) {
      error_log("Could not prepare budget_accepted alert for order {$orderId}: " . $error->getMessage());
      return false;
    }
  }

  private function orderStatusLabel(mixed $status): string
  {
    return match ($status) {
      'received' => 'Recibido',
      'diagnosing' => 'En diagnóstico',
      'awaiting_approval' => 'Esperando aprobación',
      'approved' => 'Aprobado',
      'in_progress' => 'En reparación',
      'ready' => 'Listo',
      'delivered' => 'Entregado',
      'cancelled' => 'Cancelado',
      default => is_string($status) && $status !== '' ? $status : 'Sin estado',
    };
  }

  private function decodePartsForAlert(mixed $value): ?array
  {
    try {
      $parts = is_array($value)
        ? $value
        : json_decode((string) ($value ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
      if (!is_array($parts)) {
        throw new UnexpectedValueException('Order parts must be a JSON array');
      }
      return $parts;
    } catch (Throwable $error) {
      error_log('Could not inspect order parts for alerts: ' . $error->getMessage());
      return null;
    }
  }

  private function partRequestAlerts(
    mixed $beforeValue,
    mixed $afterValue,
    string $mechanicName,
    string $vehiclePlate,
    string $customerName
  ): array {
    $before = $this->decodePartsForAlert($beforeValue);
    $after = $this->decodePartsForAlert($afterValue);
    if ($before === null || $after === null) {
      return [];
    }

    $previouslyPending = [];
    foreach ($before as $part) {
      if (
        is_array($part)
        && ($part['requestedByTechnician'] ?? false) === true
        && ($part['approvalStatus'] ?? null) === 'pending'
      ) {
        $key = !empty($part['id'])
          ? (string) $part['id']
          : sha1((string) ($part['name'] ?? '') . '|' . (string) ($part['quantity'] ?? ''));
        $previouslyPending[$key] = true;
      }
    }

    $alerts = [];
    foreach ($after as $part) {
      if (
        !is_array($part)
        || ($part['requestedByTechnician'] ?? false) !== true
        || ($part['approvalStatus'] ?? null) !== 'pending'
      ) {
        continue;
      }

      $key = !empty($part['id'])
        ? (string) $part['id']
        : sha1((string) ($part['name'] ?? '') . '|' . (string) ($part['quantity'] ?? ''));
      if (isset($previouslyPending[$key])) {
        continue;
      }
      $partName = is_scalar($part['name'] ?? null) ? (string) $part['name'] : 'repuesto';
      $alerts[] = [
        'type' => 'part_requested',
        'message' => "{$mechanicName} pidió \"{$partName}\" en la orden {$vehiclePlate} ({$customerName}).",
      ];
      $previouslyPending[$key] = true;
    }

    return $alerts;
  }

  private function auditActor(mixed $updatedBy, array $authenticatedUser): array
  {
    if ($updatedBy === null || $updatedBy === '') {
      return ['id' => 0, 'name' => 'Sistema', 'role' => 'system'];
    }
    if (!is_string($updatedBy)) {
      $this->jsonResponse([], 422, 'updated_by must be a JSON string');
    }

    try {
      $actor = json_decode($updatedBy, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
      $this->jsonResponse([], 422, 'updated_by must contain valid JSON');
    }

    $actorId = is_array($actor) ? filter_var($actor['id'] ?? null, FILTER_VALIDATE_INT) : false;
    $authenticatedId = filter_var($authenticatedUser['id'] ?? null, FILTER_VALIDATE_INT);
    if ($actorId === false || $authenticatedId === false || $actorId !== $authenticatedId) {
      $this->jsonResponse([], 403, 'updated_by does not match the authenticated user');
    }

    $firstName = $actor['firstName'] ?? null;
    $lastName = $actor['lastName'] ?? '';
    if (!is_string($firstName) || !is_string($lastName)) {
      $this->jsonResponse([], 422, 'updated_by must include firstName and a valid lastName');
    }
    $name = trim($firstName . ' ' . $lastName);
    if ($name === '') {
      $this->jsonResponse([], 422, 'updated_by must include a user name');
    }

    return [
      'id' => $authenticatedId,
      'name' => $name,
      'role' => (string) ($authenticatedUser['role'] ?? 'mechanic'),
    ];
  }

  private function logOrderAction(
    mysqli $db,
    int $orderId,
    string $action,
    array $actor,
    ?array $changes = null
  ): void {
    $changesJson = $changes === null ? null : json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare(
      'INSERT INTO order_audit_log (order_id, action, user_id, user_name, user_role, changes)
       VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isisss', $orderId, $action, $actor['id'], $actor['name'], $actor['role'], $changesJson);
    $stmt->execute();
    $stmt->close();
  }

  private function orderAuditValue(mixed $value, array $column): mixed
  {
    if ($value !== null && str_contains(strtolower((string) $column['Type']), 'json') && is_string($value)) {
      return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
    return $value;
  }

  private function getOrderColumnMetadata(mysqli $db): array
  {
    $columns = [];
    $result = $db->query('SHOW COLUMNS FROM work_orders');
    while ($column = $result->fetch_assoc()) {
      $columns[$column['Field']] = $column;
    }
    return $columns;
  }

  private function normalizeOrderFieldValue(string $field, mixed $value, array $column): mixed
  {
    $databaseType = strtolower((string) $column['Type']);

    if ($field === 'assigned_user_id' && $value !== null) {
      return json_encode($this->assignedUserIds($value), JSON_THROW_ON_ERROR);
    }

    if ($field === 'status' && $value !== null && !in_array($value, ['received', 'diagnosing', 'awaiting_approval', 'approved', 'in_progress', 'ready', 'delivered', 'cancelled'], true)) {
      $this->jsonResponse([], 422, 'Invalid order status');
    }

    if ($value === null) {
      if ($column['Null'] !== 'YES') {
        $this->jsonResponse([], 422, "{$field} cannot be null");
      }
      return null;
    }

    if (str_contains($databaseType, 'json')) {
      if (is_string($value)) {
        try {
          $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
          $this->jsonResponse([], 422, "{$field} must contain valid JSON");
        }
      }
      if ($field === 'checklist' && !is_array($value)) {
        $this->jsonResponse([], 422, 'checklist must be a JSON array');
      }
      try {
        return json_encode($value, JSON_THROW_ON_ERROR);
      } catch (JsonException $error) {
        $this->jsonResponse([], 422, "{$field} contains invalid JSON data");
      }
    }

    if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $databaseType)) {
      $integer = filter_var($value, FILTER_VALIDATE_INT);
      if ($integer === false || (str_contains($databaseType, 'unsigned') && $integer < 0)) {
        $this->jsonResponse([], 422, "{$field} must be a valid integer");
      }
      return $integer;
    }

    if (preg_match('/^(decimal|numeric|float|double|real)\b/', $databaseType)) {
      if (!is_numeric($value)) {
        $this->jsonResponse([], 422, "{$field} must be numeric");
      }
      return (float) $value;
    }

    if (!is_scalar($value)) {
      $this->jsonResponse([], 422, "{$field} must be a scalar value");
    }

    return is_bool($value) ? (int) $value : (string) $value;
  }

  private function normalizeOrderPayload(array $data, array $columns): array
  {
    $normalized = [];

    foreach ($data as $field => $value) {
      if (!is_string($field) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
        $this->jsonResponse([], 422, 'Invalid order field name');
      }
      if (in_array($field, self::ORDER_WRITE_BLOCKLIST, true)) {
        $this->jsonResponse([], 422, "Order field cannot be set: {$field}");
      }
      if (!isset($columns[$field])) {
        $this->jsonResponse([], 422, "Unknown order field: {$field}");
      }
      if (preg_match('/\b(auto_increment|generated)\b/i', (string) $columns[$field]['Extra'])) {
        $this->jsonResponse([], 422, "Order field cannot be set: {$field}");
      }
      $normalized[$field] = $this->normalizeOrderFieldValue($field, $value, $columns[$field]);
    }

    return $normalized;
  }

  public function createOrder(): void
  {
    $user = $this->user(['admin', 'mechanic']);
    $payload = $this->getBody();
    if (!is_array($payload)) {
      $this->jsonResponse([], 400, 'Invalid order payload');
    }
    $actor = $this->auditActor($payload['updated_by'] ?? null, $user);
    unset($payload['updated_by']);

    $db = DataBase::getConnection();
    $transactionStarted = false;
    try {
      $columns = $this->getOrderColumnMetadata($db);
      $data = $this->normalizeOrderPayload($payload, $columns);
      $assignedMechanics = null;
      if (isset($data['assigned_user_id'])) {
        $assignedUserIds = $this->assignedUserIds($data['assigned_user_id']);
        $assignedMechanics = $this->validateActiveMechanics($db, $assignedUserIds);
      }
      $required = ['customer_name', 'customer_phone', 'vehicle_plate', 'vehicle_make', 'vehicle_model', 'mileage', 'fuel_level', 'checklist'];
      foreach ($required as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
          $this->jsonResponse([], 422, "Missing field: {$field}");
        }
      }

      $token = bin2hex(random_bytes(24));
      $createdBy = (int) $user['id'];
      $insertData = [
        'public_token' => $token,
        'created_by' => $createdBy,
      ];

      foreach ($data as $field => $value) {
        $insertData[$field] = $value;
      }

      $insertColumns = array_keys($insertData);
      $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
      $types = '';
      $values = [];
      foreach ($insertData as $value) {
        if (is_int($value)) {
          $types .= 'i';
        } elseif (is_float($value)) {
          $types .= 'd';
        } else {
          $types .= 's';
        }
        $values[] = $value;
      }
      $sql = 'INSERT INTO work_orders (' . implode(', ', array_map(fn($column) => "`{$column}`", $insertColumns)) . ') VALUES (' . $placeholders . ')';

      $stmt = $db->prepare($sql);
      $bindParams = [$types];
      foreach ($values as &$value) {
        $bindParams[] = &$value;
      }
      unset($value);
      call_user_func_array([$stmt, 'bind_param'], $bindParams);
      $db->begin_transaction();
      $transactionStarted = true;
      $stmt->execute();
      $orderId = (int) $stmt->insert_id;
      $this->logOrderAction($db, $orderId, 'created', $actor);
      if (!$db->commit()) {
        throw new RuntimeException('Could not commit order creation and audit log');
      }
      $transactionStarted = false;
      if (($user['role'] ?? '') === 'admin' && $assignedMechanics !== null && $assignedMechanics !== []) {
        $mechanicNames = array_map(
          static fn(array $mechanic): string => trim($mechanic['first_name'] . ' ' . $mechanic['last_name']),
          $assignedMechanics
        );
        $this->insertAlertBestEffort(
          $db,
          $orderId,
          'order_assigned',
          "La orden {$data['vehicle_plate']} fue asignada a " . implode(', ', $mechanicNames) . '.',
          array_column($assignedMechanics, 'id')
        );
      } elseif (($user['role'] ?? '') !== 'admin') {
        $customerName = (string) $data['customer_name'];
        $vehiclePlate = (string) $data['vehicle_plate'];
        $this->insertAlertBestEffort(
          $db,
          $orderId,
          'order_created',
          "Nueva orden de {$customerName} ({$vehiclePlate}).",
          $assignedMechanics === null ? null : array_column($assignedMechanics, 'id')
        );
      }
      if (($user['role'] ?? '') === 'admin') {
        PushSender::sendToAdmins(
          $db,
          $orderId,
          "Nueva orden de {$data['customer_name']} ({$data['vehicle_plate']})."
        );
      }
      $this->jsonResponse(['id' => $orderId, 'token' => $token], 201, 'Order created');
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->dbError($error);
    }
  }

  public function updateStatus(): void
  {
    $user = $this->user(['admin', 'mechanic']);
    $id = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT);
    $data = $this->getBody();
    if (!is_array($data)) {
      $this->jsonResponse([], 400, 'Invalid order payload');
    }
    $actor = $this->auditActor($data['updated_by'] ?? null, $user);
    unset($data['updated_by']);
    $allowed = ['received', 'diagnosing', 'awaiting_approval', 'approved', 'in_progress', 'ready', 'delivered', 'cancelled'];
    if ($id === false || $id < 1 || !in_array($data['status'] ?? '', $allowed, true)) {
      $this->jsonResponse([], 422, 'Invalid order or status');
    }
    $hasWithdrawalComment = array_key_exists('withdrawal_comment', $data);
    if (
      $hasWithdrawalComment
      && (!is_string($data['withdrawal_comment']) || trim($data['withdrawal_comment']) === '')
    ) {
      $this->jsonResponse([], 422, 'withdrawal_comment must be a non-empty string');
    }

    $db = DataBase::getConnection();
    $transactionStarted = false;
    try {
      $db->begin_transaction();
      $transactionStarted = true;
      $oldOrderStmt = $db->prepare('SELECT status, withdrawal_comment, vehicle_plate FROM work_orders WHERE id = ? FOR UPDATE');
      $oldOrderStmt->bind_param('i', $id);
      $oldOrderStmt->execute();
      $oldOrder = $oldOrderStmt->get_result()->fetch_assoc();
      $oldOrderStmt->close();
      if (!$oldOrder) {
        $db->rollback();
        $transactionStarted = false;
        $this->jsonResponse([], 404, 'Order not found');
      }

      $sql = "UPDATE work_orders SET status = ?, started_at = CASE WHEN ? = 'in_progress' AND started_at IS NULL THEN NOW() ELSE started_at END, finished_at = CASE WHEN ? IN ('ready','delivered','cancelled') THEN COALESCE(finished_at, NOW()) ELSE finished_at END";
      if ($hasWithdrawalComment) {
        $sql .= ', withdrawal_comment = ?';
      }
      $sql .= ' WHERE id = ?';
      $stmt = $db->prepare($sql);
      $status = $data['status'];
      if ($hasWithdrawalComment) {
        $withdrawalComment = trim($data['withdrawal_comment']);
        $stmt->bind_param('ssssi', $status, $status, $status, $withdrawalComment, $id);
      } else {
        $stmt->bind_param('sssi', $status, $status, $status, $id);
      }
      $stmt->execute();
      $changes = [];
      $statusChanged = $oldOrder['status'] !== $status;
      if ($statusChanged) {
        $changes['status'] = ['from' => $oldOrder['status'], 'to' => $status];
      }
      if ($hasWithdrawalComment && $oldOrder['withdrawal_comment'] !== $withdrawalComment) {
        $changes['withdrawal_comment'] = [
          'from' => $oldOrder['withdrawal_comment'],
          'to' => $withdrawalComment,
        ];
      }
      if ($changes !== []) {
        $action = $statusChanged ? match ($status) {
          'delivered' => 'delivered',
          'cancelled' => 'cancelled',
          default => 'status_changed',
        } : 'edited';
        $this->logOrderAction($db, $id, $action, $actor, $changes);
      }
      if (!$db->commit()) {
        throw new RuntimeException('Could not commit order status and audit log');
      }
      $transactionStarted = false;
      if ($statusChanged) {
        $this->insertAlertBestEffort(
          $db,
          $id,
          'order_status_changed',
          "La orden {$oldOrder['vehicle_plate']} pasó a \"{$this->orderStatusLabel($status)}\"."
        );
      }
      $this->jsonResponse(['updated' => $stmt->affected_rows >= 0], 200, 'Order status updated');
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->dbError($error);
    }
  }

  public function uploadEvidence(): void
  {
    $user = $this->user(['admin', 'mechanic', 'reception']);
    $id = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $evidenceType = $_POST['evidence_type'] ?? null;
    if (!is_string($evidenceType) || !in_array($evidenceType, self::EVIDENCE_TYPES, true)) {
      $this->jsonResponse([], 422, 'Invalid evidence_type');
    }
    if (!in_array($evidenceType, $this->evidenceTypesForRole((string) ($user['role'] ?? '')), true)) {
      $this->jsonResponse([], 403, 'Role cannot upload this evidence_type');
    }

    $file = $_FILES['file'] ?? null;
    if (
      $id === false || !is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
      || !isset($file['tmp_name'], $file['size']) || !is_string($file['tmp_name'])
      || !is_int($file['size']) || !is_uploaded_file($file['tmp_name'])
      || $file['size'] < 1 || $file['size'] > 50 * 1024 * 1024
    ) {
      $this->jsonResponse([], 422, 'Invalid evidence file');
    }

    $db = DataBase::getConnection();
    $orderStmt = $db->prepare('SELECT id FROM work_orders WHERE id = ? LIMIT 1');
    $orderStmt->bind_param('i', $id);
    $orderStmt->execute();
    if (!$orderStmt->get_result()->fetch_assoc()) {
      $this->jsonResponse([], 404, 'Order not found');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime'];
    if (!is_string($mimeType) || !in_array($mimeType, $allowed, true)) {
      $this->jsonResponse([], 422, 'Unsupported evidence file type');
    }

    $directory = __DIR__ . '/../../assets/evidence/' . $id;
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
      error_log('Could not create evidence directory: ' . $directory);
      $this->jsonResponse([], 500, 'Could not store evidence');
    }

    $extension = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true) ? 'jpg' : 'mp4';
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $temporaryPath = $directory . '/.processing-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $destinationPath = $directory . '/' . $filename;
    require_once __DIR__ . '/../services/MediaProcessor.php';
    $processor = new MediaProcessor();
    $transactionStarted = false;

    try {
      $type = $processor->process($file['tmp_name'], is_string($mimeType) ? $mimeType : '', $temporaryPath);
      if (!is_file($temporaryPath) || filesize($temporaryPath) < 1 || !@rename($temporaryPath, $destinationPath)) {
        throw new RuntimeException('Could not persist processed evidence');
      }

      $path = 'assets/evidence/' . $id . '/' . $filename;
      $filenameColumn = $type === 'photo' ? 'images' : 'videos';
      $db->begin_transaction();
      $transactionStarted = true;
      $stmt = $db->prepare('INSERT INTO order_evidence (order_id, type, evidence_type, file_url, uploaded_by) VALUES (?, ?, ?, ?, ?)');
      $stmt->bind_param('isssi', $id, $type, $evidenceType, $path, $user['id']);
      $stmt->execute();
      $filenameStmt = $db->prepare(
        "UPDATE work_orders
         SET `{$filenameColumn}` = CONCAT_WS(',', NULLIF(`{$filenameColumn}`, ''), ?)
         WHERE id = ?"
      );
      $filenameStmt->bind_param('si', $filename, $id);
      $filenameStmt->execute();
      if ($filenameStmt->affected_rows !== 1) {
        throw new RuntimeException('Could not register evidence filename on work order');
      }
      if (!$db->commit()) {
        throw new RuntimeException('Could not commit evidence metadata');
      }
      $transactionStarted = false;
      $this->jsonResponse([
        'id' => $stmt->insert_id,
        'url' => $path,
        'evidence_type' => $evidenceType,
      ], 201, 'Evidence uploaded');
    } catch (InvalidArgumentException $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      if (is_file($temporaryPath) && !@unlink($temporaryPath)) {
        error_log('Could not remove temporary evidence file: ' . $temporaryPath);
      }
      if (is_file($destinationPath) && !@unlink($destinationPath)) {
        error_log('Could not remove evidence file after rejected upload: ' . $destinationPath);
      }
      $this->jsonResponse([], 422, $error->getMessage());
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      if (is_file($temporaryPath) && !@unlink($temporaryPath)) {
        error_log('Could not remove temporary evidence file: ' . $temporaryPath);
      }
      if (is_file($destinationPath) && !@unlink($destinationPath)) {
        error_log('Could not remove evidence file after failed upload: ' . $destinationPath);
      }
      error_log(sprintf(
        'Could not process or store evidence (%s) at %s:%d: %s',
        get_class($error),
        $error->getFile(),
        $error->getLine(),
        $error->getMessage()
      ));
      $statusText = 'Could not process or store evidence';
      if (defined('DISPLAY_ERRORS') && DISPLAY_ERRORS) {
        $statusText .= ': ' . $error->getMessage();
      }
      $this->jsonResponse([], 500, $statusText);
    }
  }

  public function orderEvidence(): void
  {
    $user = $this->user(['admin', 'mechanic', 'reception']);
    $id = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
      $this->jsonResponse([], 422, 'Invalid order ID');
    }

    $roleTypes = $this->evidenceTypesForRole((string) ($user['role'] ?? ''));
    $filterType = $_GET['type'] ?? null;
    if ($filterType !== null && (!is_string($filterType) || !in_array($filterType, self::EVIDENCE_TYPES, true))) {
      $this->jsonResponse([], 422, 'Invalid evidence type');
    }
    if ($filterType !== null && !in_array($filterType, $roleTypes, true)) {
      $this->jsonResponse([], 403, 'Role cannot view this evidence_type');
    }

    $allowedTypes = $filterType === null ? $roleTypes : [$filterType];
    if ($allowedTypes === []) {
      $this->jsonResponse([], 403, 'Role cannot view evidence');
    }

    $db = DataBase::getConnection();
    try {
      $orderStmt = $db->prepare('SELECT id, images, videos FROM work_orders WHERE id = ? LIMIT 1');
      $orderStmt->bind_param('i', $id);
      $orderStmt->execute();
      $order = $orderStmt->get_result()->fetch_assoc();
      if (!$order) {
        $this->jsonResponse([], 404, 'Order not found');
      }
      $orderStmt->close();

      $evidenceByOrder = $this->evidenceUrlsByOrder($db, [$order], $allowedTypes);
      $evidence = $evidenceByOrder[(int) $id] ?? [];
      $this->jsonResponse($evidence, 200, 'success', count($evidence));
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function deleteEvidence(): void
  {
    $this->user(['admin']);
    $orderId = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $evidenceId = filter_var($this->params()['evidenceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($orderId === false || $evidenceId === false) {
      $this->jsonResponse([], 422, 'Invalid order or evidence ID');
    }

    $db = DataBase::getConnection();
    $transactionStarted = false;
    try {
      $db->begin_transaction();
      $transactionStarted = true;
      $stmt = $db->prepare(
        'SELECT id, type, file_url FROM order_evidence WHERE id = ? AND order_id = ? FOR UPDATE'
      );
      $stmt->bind_param('ii', $evidenceId, $orderId);
      $stmt->execute();
      $evidence = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if (!$evidence) {
        $db->rollback();
        $transactionStarted = false;
        $this->jsonResponse([], 404, 'Evidence not found');
      }

      $relativePath = (string) $evidence['file_url'];
      $prefix = 'assets/evidence/' . $orderId . '/';
      $filename = str_starts_with($relativePath, $prefix) ? substr($relativePath, strlen($prefix)) : '';
      if (
        $filename === ''
        || basename($filename) !== $filename
        || !preg_match('/^[A-Za-z0-9_-]+\.(?:jpg|mp4)$/D', $filename)
      ) {
        throw new UnexpectedValueException('Invalid evidence storage path');
      }

      $column = match ($this->evidenceMediaType($evidence['type'], $relativePath)) {
        'photo' => 'images',
        'video' => 'videos',
        default => throw new UnexpectedValueException('Invalid evidence media type'),
      };
      $orderStmt = $db->prepare("SELECT `{$column}` FROM work_orders WHERE id = ? FOR UPDATE");
      $orderStmt->bind_param('i', $orderId);
      $orderStmt->execute();
      $order = $orderStmt->get_result()->fetch_assoc();
      $orderStmt->close();
      if (!$order) {
        $db->rollback();
        $transactionStarted = false;
        $this->jsonResponse([], 404, 'Order not found');
      }

      $filenames = array_values(array_filter(
        array_map('trim', explode(',', (string) ($order[$column] ?? ''))),
        static fn(string $current): bool => $current !== '' && $current !== $filename
      ));
      $filenameList = $filenames === [] ? null : implode(',', $filenames);
      $updateStmt = $db->prepare("UPDATE work_orders SET `{$column}` = ? WHERE id = ?");
      $updateStmt->bind_param('si', $filenameList, $orderId);
      $updateStmt->execute();
      $updateStmt->close();

      $deleteStmt = $db->prepare('DELETE FROM order_evidence WHERE id = ? AND order_id = ?');
      $deleteStmt->bind_param('ii', $evidenceId, $orderId);
      $deleteStmt->execute();
      $deleteStmt->close();
      if (!$db->commit()) {
        throw new RuntimeException('Could not commit evidence deletion');
      }
      $transactionStarted = false;

      $filePath = __DIR__ . '/../../' . $relativePath;
      if (is_file($filePath) && !@unlink($filePath)) {
        error_log('Could not remove evidence file after deleting its database record: ' . $filePath);
        $this->jsonResponse([], 500, 'Could not remove evidence file');
      }
      $this->jsonResponse(['id' => $evidenceId], 200, 'Evidence deleted');
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->dbError($error);
    }
  }

  public function addBudgetItem(): void
  {
    $this->user(['admin']);
    $id = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT);
    $data = $this->getBody();
    if (!$id || !in_array($data['type'] ?? '', ['labor', 'part'], true) || empty($data['description']) || !is_numeric($data['quantity'] ?? null) || !is_numeric($data['unit_price'] ?? null)) {
      $this->jsonResponse([], 422, 'Invalid budget item');
    }
    $db = DataBase::getConnection();
    $stmt = $db->prepare('INSERT INTO budget_items (order_id, type, description, quantity, unit_price, supplier_cost) VALUES (?, ?, ?, ?, ?, ?)');
    $quantity = (float) $data['quantity'];
    $price = (float) $data['unit_price'];
    $cost = isset($data['supplier_cost']) ? (float) $data['supplier_cost'] : null;
    $stmt->bind_param('issddd', $id, $data['type'], $data['description'], $quantity, $price, $cost);
    try {
      $stmt->execute();
      $this->jsonResponse(['id' => $stmt->insert_id], 201, 'Budget item created');
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function costVisibility(): void
  {
    $this->user(['admin']);
    $data = $this->getBody();
    if (!isset($data['visible']) || !is_bool($data['visible'])) $this->jsonResponse([], 422, 'visible must be boolean');
    $db = DataBase::getConnection();
    $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('mechanic_cost_visibility', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $value = $data['visible'] ? '1' : '0';
    $stmt->bind_param('s', $value);
    try {
      $stmt->execute();
      $this->jsonResponse(['visible' => $data['visible']], 200, 'Setting updated');
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function publicOrder(): void
  {
    $token = $this->params()['token'] ?? '';
    $db = DataBase::getConnection();
    try {
      $stmt = $db->prepare('SELECT * FROM work_orders WHERE public_token = ? LIMIT 1');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $orderResult = $stmt->get_result();
      $total = $orderResult->num_rows;
      $order = $orderResult->fetch_assoc();
      if (!$order) {
        $this->jsonResponse([], 404, 'Order not found');
      }

      $orderId = (int) $order['id'];
      unset($order['public_token'], $order['created_by']);

      $budgetStmt = $db->prepare('SELECT id, type, description, quantity, unit_price, customer_decision, created_at FROM budget_items WHERE order_id = ? ORDER BY id ASC');
      $budgetStmt->bind_param('i', $orderId);
      $budgetStmt->execute();
      $budget = $budgetStmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $evidenceByOrder = $this->evidenceUrlsByOrder($db, [$order]);

      $this->jsonResponse([
        'order' => $order,
        'budget' => $budget,
        'evidence' => $evidenceByOrder[$orderId] ?? [],
      ], 200, 'success', $total);
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function patchPublicOrder(): void
  {
    $token = $this->params()['token'] ?? '';
    $data = $this->getBody();
    if (!is_array($data) || $data === []) {
      $this->jsonResponse([], 400, 'Invalid or empty order patch payload');
    }

    $db = DataBase::getConnection();
    $transactionStarted = false;
    try {
      $db->begin_transaction();
      $transactionStarted = true;

      $orderStmt = $db->prepare('SELECT * FROM work_orders WHERE public_token = ? FOR UPDATE');
      $orderStmt->bind_param('s', $token);
      $orderStmt->execute();
      $orderResult = $orderStmt->get_result();
      $total = $orderResult->num_rows;
      $order = $orderResult->fetch_assoc();
      if (!$order) {
        $db->rollback();
        $this->jsonResponse([], 404, 'Order not found');
      }

      $columns = $this->getOrderColumnMetadata($db);
      $sets = [];
      $types = '';
      $values = [];
      $partDecisions = [];
      $seenPartIds = [];
      $budgetAccepted = false;
      $statusChanged = false;
      foreach ($data as $field => $value) {
        if ($field === 'parts') {
          if (!is_array($value) || $value === []) {
            $db->rollback();
            $this->jsonResponse([], 422, 'parts must be a non-empty array of item decisions');
          }

          foreach ($value as $item) {
            if (
              !is_array($item)
              || !is_string($item['id'] ?? null)
              || !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $item['id'])
              || !in_array($item['decision'] ?? null, ['accepted', 'rejected'], true)
            ) {
              $db->rollback();
              $this->jsonResponse([], 422, 'Each parts item requires a UUID id and accepted/rejected decision');
            }

            $partId = strtolower($item['id']);
            if (isset($seenPartIds[$partId])) {
              $db->rollback();
              $this->jsonResponse([], 422, 'Parts item IDs must be unique');
            }
            $seenPartIds[$partId] = true;
            $partDecisions[] = ['id' => $item['id'], 'decision' => $item['decision']];
          }
          continue;
        }

        if (
          !is_string($field)
          || (!preg_match('/^budget_[a-zA-Z0-9_]+$/D', $field) && $field !== 'status')
          || !isset($columns[$field])
          || preg_match('/\b(auto_increment|generated)\b/i', (string) $columns[$field]['Extra'])
        ) {
          $db->rollback();
          $this->jsonResponse([], 422, 'Only existing writable status/budget_* fields and parts decisions can be updated');
        }

        if ($field === 'budget_accepted' && !in_array($value, [0, 1, '0', '1', false, true], true)) {
          $db->rollback();
          $this->jsonResponse([], 422, 'budget_accepted must be 0 or 1');
        }

        $normalized = $this->normalizeOrderFieldValue($field, $value, $columns[$field]);
        if ($field === 'budget_accepted') {
          $budgetAccepted = (int) ($order['budget_accepted'] ?? 0) === 0 && (int) $normalized === 1;
        }
        if ($field === 'status') {
          $statusChanged = ($order['status'] ?? null) !== $normalized;
        }
        $sets[] = "`{$field}` = ?";
        if (is_int($normalized)) {
          $types .= 'i';
        } elseif (is_float($normalized)) {
          $types .= 'd';
        } else {
          $types .= 's';
        }
        $values[] = $normalized;
      }

      $partsChanged = false;
      $changedRejectedCount = 0;
      if ($partDecisions) {
        if (
          !isset($columns['parts'])
          || preg_match('/\b(auto_increment|generated)\b/i', (string) $columns['parts']['Extra'])
        ) {
          $db->rollback();
          $this->jsonResponse([], 422, 'parts is not a writable order field');
        }

        try {
          $orderParts = json_decode((string) ($order['parts'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
          throw new UnexpectedValueException('Order parts contain invalid JSON', 0, $error);
        }
        if (!is_array($orderParts)) {
          throw new UnexpectedValueException('Order parts must be a JSON array');
        }

        foreach ($partDecisions as $partDecision) {
          $matched = false;
          foreach ($orderParts as &$part) {
            if (
              !is_array($part) || !is_string($part['id'] ?? null)
              || strcasecmp($part['id'], $partDecision['id']) !== 0
            ) {
              continue;
            }

            $matched = true;
            if (($part['approvalStatus'] ?? null) !== $partDecision['decision']) {
              $part['approvalStatus'] = $partDecision['decision'];
              $partsChanged = true;
              if ($partDecision['decision'] === 'rejected') {
                $changedRejectedCount++;
              }
            }
            break;
          }
          unset($part);

          if (!$matched) {
            $db->rollback();
            $this->jsonResponse([], 404, 'Parts item not found for this order');
          }
        }

        if ($partsChanged) {
          try {
            $partsJson = json_encode($orderParts, JSON_THROW_ON_ERROR);
          } catch (JsonException $error) {
            throw new UnexpectedValueException('Order parts contain invalid JSON data', 0, $error);
          }
          $sets[] = '`parts` = ?';
          $types .= 's';
          $values[] = $partsJson;
        }
      }

      if (!$sets && !$partDecisions) {
        $db->rollback();
        $this->jsonResponse([], 422, 'No budget fields or parts decisions to update');
      }

      if ($sets) {
        $sql = 'UPDATE work_orders SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $types .= 'i';
        $values[] = (int) $order['id'];
        $stmt = $db->prepare($sql);
        $bindParams = [$types];
        foreach ($values as &$value) {
          $bindParams[] = &$value;
        }
        unset($value);
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $stmt->execute();
        $stmt->close();
      }

      $db->commit();
      $transactionStarted = false;
      $orderId = (int) $order['id'];
      if ($statusChanged) {
        $this->insertAlertBestEffort(
          $db,
          $orderId,
          'order_status_changed',
          "La orden {$order['vehicle_plate']} pasó a \"{$this->orderStatusLabel($data['status'])}\"."
        );
      }
      if ($partsChanged) {
        if ($changedRejectedCount > 0) {
          $this->insertAlertBestEffort(
            $db,
            $orderId,
            'budget_rejected',
            "{$order['customer_name']} rechazó {$changedRejectedCount} ítem(s) del presupuesto de la orden {$order['vehicle_plate']}."
          );
        } else {
          $this->notifyAcceptedBudgetBestEffort($db, $orderId, (string) $order['vehicle_plate']);
        }
      }
      if ($budgetAccepted) {
        $this->notifyAcceptedBudgetBestEffort($db, $orderId, (string) $order['vehicle_plate']);
      }
      $this->jsonResponse([
        'id' => (int) $order['id'],
        'updated_fields' => array_keys($data),
      ], 200, 'Public order fields updated', $total);
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->dbError($error);
    }
  }

  public function getOrder(): void
  {
    $user = $this->user(['admin', 'mechanic']);
    $id = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) $this->jsonResponse([], 422, 'Invalid order');
    $db = DataBase::getConnection();
    try {
      $stmt = $db->prepare('SELECT * FROM work_orders WHERE id = ?');
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $orderResult = $stmt->get_result();
      $total = $orderResult->num_rows;
      $order = $orderResult->fetch_assoc();
      if (!$order) $this->jsonResponse([], 404, 'Order not found');
      $items = $db->query("SELECT * FROM budget_items WHERE order_id = {$id} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
      $setting = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'mechanic_cost_visibility'")->fetch_assoc();
      if (($user['role'] ?? '') === 'mechanic' && ($setting['setting_value'] ?? '0') !== '1') {
        foreach ($items as &$item) {
          unset($item['unit_price'], $item['supplier_cost']);
        }
        unset($item);
      }
      $evidenceByOrder = $this->evidenceUrlsByOrder(
        $db,
        [$order],
        $this->evidenceTypesForRole((string) ($user['role'] ?? ''))
      );
      $evidence = $evidenceByOrder[(int) $id] ?? [];
      $this->setVisibleEvidenceMediaFields($order, $evidence);
      $this->jsonResponse(['order' => $order, 'budget' => $items, 'evidence' => $evidence], 200, 'success', $total);
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function orderAuditLog(): void
  {
    $this->user(['admin']);
    $id = filter_var(
      $this->params()['id'] ?? null,
      FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 1]]
    );
    if ($id === false) {
      $this->jsonResponse([], 422, 'Invalid order ID');
    }

    $db = DataBase::getConnection();
    try {
      $orderStmt = $db->prepare('SELECT id FROM work_orders WHERE id = ? LIMIT 1');
      $orderStmt->bind_param('i', $id);
      $orderStmt->execute();
      if (!$orderStmt->get_result()->fetch_assoc()) {
        $this->jsonResponse([], 404, 'Order not found');
      }
      $orderStmt->close();

      $stmt = $db->prepare(
        'SELECT id, order_id, action, user_id, user_name, user_role, changes, created_at
         FROM order_audit_log WHERE order_id = ? ORDER BY id DESC'
      );
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $result = $stmt->get_result();
      $entries = [];
      while ($entry = $result->fetch_assoc()) {
        $entry['id'] = (int) $entry['id'];
        $entry['order_id'] = (int) $entry['order_id'];
        $entry['user_id'] = (int) $entry['user_id'];
        $entry['changes'] = $entry['changes'] === null
          ? null
          : json_decode($entry['changes'], true, 512, JSON_THROW_ON_ERROR);
        $entries[] = $entry;
      }
      $stmt->close();

      $this->jsonResponse($entries, 200, 'success');
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function sendOrderSummaryEmail(): void
  {
    $user = $this->user(['admin', 'mechanic']);

    $pathId = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($pathId === false) {
      $this->jsonResponse([], 400, 'Invalid order ID');
    }

    $prompt = $_GET['prompt'] ?? '';
    if (!is_string($prompt) || strlen($prompt) > 1000) {
      $this->jsonResponse([], 422, 'prompt must be a string of at most 1000 bytes');
    }
    $prompt = trim($prompt);
    header('Cache-Control: no-store');

    $db = DataBase::getConnection();
    try {
      $orderStmt = $db->prepare('SELECT id, customer_name, customer_email, customer_phone, vehicle_plate, vehicle_make, vehicle_model, vehicle_year, status, checklist, created_at, public_token FROM work_orders WHERE id = ? LIMIT 1');
      $orderStmt->bind_param('i', $pathId);
      $orderStmt->execute();
      $orderResult = $orderStmt->get_result();
      $total = $orderResult->num_rows;
      $order = $orderResult->fetch_assoc();
      if (!$order) {
        $this->jsonResponse([], 404, 'Order not found');
      }
      if (!is_string($order['customer_email']) || filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL) === false) {
        $this->jsonResponse([], 400, 'Customer email missing');
      }

      $checklist = json_decode((string) ($order['checklist'] ?? '[]'), true);
      if (json_last_error() !== JSON_ERROR_NONE || !is_array($checklist)) {
        throw new UnexpectedValueException('Stored order checklist is not a valid JSON array');
      }

      $checklistTotal = count($checklist);
      $checklistCompleted = 0;
      foreach ($checklist as $item) {
        if (is_bool($item) && $item) {
          $checklistCompleted++;
          continue;
        }
        if (!is_array($item)) {
          continue;
        }

        foreach (['completed', 'done', 'checked', 'is_completed'] as $field) {
          if (in_array($item[$field] ?? null, [true, 1, '1'], true)) {
            $checklistCompleted++;
            continue 2;
          }
        }
        if (in_array(strtolower((string) ($item['status'] ?? '')), ['completed', 'done'], true)) {
          $checklistCompleted++;
        }
      }

      $budgetStmt = $db->prepare('SELECT description, unit_price, quantity, customer_decision AS status FROM budget_items WHERE order_id = ? ORDER BY id');
      $budgetStmt->bind_param('i', $pathId);
      $budgetStmt->execute();
      $budget = $budgetStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (UnexpectedValueException $error) {
      error_log($error->getMessage());
      $this->jsonResponse([], 500, 'Invalid order data');
    } catch (Throwable $error) {
      $this->dbError($error);
    }

    try {
      require_once __DIR__ . '/../services/mails/OrderSummaryMailer.php';
      $mailer = new OrderSummaryMailer();
      $mailer->send($order, $budget, $checklistCompleted, $checklistTotal, $prompt);
    } catch (Throwable $error) {
      error_log('Order summary email failed: ' . $error->getMessage());
      $this->jsonResponse([], 500, 'Email send failed');
    }

    $this->jsonResponse([
      'sent' => true,
      'to' => $order['customer_email'],
      'order_id' => (int) $order['id']
    ], 200, 'Email sent', $total);
  }

  public function publicDecision(): void
  {
    $token = $this->params()['token'] ?? '';
    $data = $this->getBody();
    if (!is_array($data) || !is_array($data['items'] ?? null) || !$data['items']) {
      $this->jsonResponse([], 422, 'items are required');
    }

    $items = [];
    $seenIds = [];
    foreach ($data['items'] as $item) {
      if (
        !is_array($item)
        || !isset($item['id'], $item['decision'])
        || (!is_int($item['id']) && !is_string($item['id']))
        || (is_string($item['id']) && $item['id'] === '')
        || !in_array($item['decision'], ['accepted', 'rejected'], true)
      ) {
        $this->jsonResponse([], 422, 'Each item requires a non-empty id and a valid decision');
      }

      $rawId = $item['id'];
      $partId = (string) $rawId;
      $itemId = null;
      if ((is_int($rawId) && $rawId > 0)
        || (is_string($rawId) && preg_match('/^[1-9][0-9]*$/D', $rawId))
      ) {
        $parsedId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsedId !== false) {
          $itemId = $parsedId;
        }
      }

      if ($itemId !== null) {
        $itemKey = 'number:' . $itemId;
        $kind = 'number';
      } else {
        $isUuid = preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $partId) === 1;
        $itemKey = $isUuid ? 'uuid:' . strtolower($partId) : 'text:' . $partId;
        $kind = 'text';
      }

      if (isset($seenIds[$itemKey])) {
        $this->jsonResponse([], 422, 'Item IDs must be unique');
      }
      $seenIds[$itemKey] = true;
      $items[] = [
        'id' => $itemId ?? $partId,
        'part_id' => $partId,
        'kind' => $kind,
        'decision' => $item['decision'],
      ];
    }
    $db = DataBase::getConnection();
    $transactionStarted = false;
    try {
      $db->begin_transaction();
      $transactionStarted = true;
      $orderStmt = $db->prepare('SELECT * FROM work_orders WHERE public_token = ? FOR UPDATE');
      $orderStmt->bind_param('s', $token);
      $orderStmt->execute();
      $order = $orderStmt->get_result()->fetch_assoc();
      if (!$order) {
        $db->rollback();
        $this->jsonResponse([], 404, 'Order not found');
      }

      $orderId = (int) $order['id'];
      $orderParts = null;
      $partsChanged = false;
      $findItemStmt = $db->prepare('SELECT customer_decision FROM budget_items WHERE id = ? AND order_id = ? FOR UPDATE');
      $updateItemStmt = $db->prepare('UPDATE budget_items SET customer_decision = ? WHERE id = ? AND order_id = ?');
      $updatedCount = 0;
      foreach ($items as $item) {
        $decision = $item['decision'];
        if ($item['kind'] === 'number') {
          $itemId = $item['id'];
          $findItemStmt->bind_param('ii', $itemId, $orderId);
          $findItemStmt->execute();
          $budgetItem = $findItemStmt->get_result()->fetch_assoc();
          if ($budgetItem) {
            if ($budgetItem['customer_decision'] !== $decision) {
              $updateItemStmt->bind_param('sii', $decision, $itemId, $orderId);
              $updateItemStmt->execute();
              $updatedCount++;
            }
            continue;
          }
        }

        if ($orderParts === null) {
          try {
            $orderParts = json_decode((string) ($order['parts'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
          } catch (JsonException $error) {
            throw new UnexpectedValueException('Order parts contain invalid JSON', 0, $error);
          }
          if (!is_array($orderParts)) {
            throw new UnexpectedValueException('Order parts must be a JSON array');
          }
        }

        $matched = false;
        foreach ($orderParts as &$part) {
          if (!is_array($part) || !is_string($part['id'] ?? null)) {
            continue;
          }
          $partIdMatches = $part['id'] === $item['part_id'];
          if (
            !$partIdMatches
            && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $part['id'])
            && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $item['part_id'])
          ) {
            $partIdMatches = strcasecmp($part['id'], $item['part_id']) === 0;
          }
          if (!$partIdMatches) {
            continue;
          }
          $matched = true;
          if (($part['approvalStatus'] ?? null) !== $decision) {
            $part['approvalStatus'] = $decision;
            $partsChanged = true;
            $updatedCount++;
          }
          break;
        }
        unset($part);
        if (!$matched) {
          $db->rollback();
          $this->jsonResponse([], 404, 'Budget item not found for this order');
        }
      }

      if ($partsChanged) {
        try {
          $partsJson = json_encode($orderParts, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
          throw new UnexpectedValueException('Order parts contain invalid JSON data', 0, $error);
        }
        $partsStmt = $db->prepare('UPDATE work_orders SET parts = ? WHERE id = ?');
        $partsStmt->bind_param('si', $partsJson, $orderId);
        $partsStmt->execute();
      }

      $db->commit();
      $transactionStarted = false;
      $notified = false;
      if ($updatedCount > 0) {
        $rejectedCount = count(array_filter(
          $items,
          static fn(array $item): bool => $item['decision'] === 'rejected'
        ));
        if ($rejectedCount > 0) {
          $notified = $this->insertAlertBestEffort(
            $db,
            $orderId,
            'budget_rejected',
            "{$order['customer_name']} rechazó {$rejectedCount} ítem(s) del presupuesto de la orden {$order['vehicle_plate']}."
          );
        } else {
          $notified = $this->notifyAcceptedBudgetBestEffort(
            $db,
            $orderId,
            (string) $order['vehicle_plate']
          );
        }
      }
      $this->jsonResponse([
        'updated_count' => $updatedCount,
        'notified' => $notified,
      ], 200, 'Decision saved', count($items));
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->dbError($error);
    }
  }

  public function listOrders(): void
  {
    $user = $this->user(['admin', 'mechanic']);
    $db = DataBase::getConnection();
    try {
      $result = $db->query("SELECT * FROM work_orders WHERE status NOT IN ('cancelled', 'delivered') OR status IS NULL ORDER BY id DESC");
      $orders = $result->fetch_all(MYSQLI_ASSOC);
      $evidenceByOrder = $this->evidenceUrlsByOrder(
        $db,
        $orders,
        $this->evidenceTypesForRole((string) ($user['role'] ?? ''))
      );
      $this->jsonResponse(
        $this->addEvidenceUrls($orders, $evidenceByOrder),
        200,
        'success',
        $result->num_rows
      );
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function listDeliveredOrders(): void
  {
    $user = $this->user(['admin', 'mechanic']);

    $limitValue = $_GET['limit'] ?? '20';
    $offsetValue = $_GET['offset'] ?? '0';
    if (!is_string($limitValue) || !is_string($offsetValue)) {
      $this->jsonResponse([], 422, 'Invalid pagination parameters');
    }

    $limit = filter_var($limitValue, FILTER_VALIDATE_INT);
    $offset = filter_var($offsetValue, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 100 || $offset === false || $offset < 0) {
      $this->jsonResponse([], 422, 'Invalid pagination parameters');
    }

    $parseDate = function (string $name): ?DateTimeImmutable {
      $value = $_GET[$name] ?? null;
      if ($value === null || $value === '') {
        return null;
      }
      if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        $this->jsonResponse([], 422, "Invalid {$name}; expected YYYY-MM-DD");
      }

      $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
      $errors = DateTimeImmutable::getLastErrors();
      if (
        $date === false
        || $date->format('Y-m-d') !== $value
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
      ) {
        $this->jsonResponse([], 422, "Invalid {$name}; expected YYYY-MM-DD");
      }

      return $date;
    };

    $dateStart = $parseDate('date_start');
    $dateEnd = $parseDate('date_end') ?? new DateTimeImmutable('today');
    if ($dateStart !== null && $dateStart > $dateEnd) {
      $this->jsonResponse([], 422, 'date_start must be on or before date_end');
    }

    $endExclusive = $dateEnd->modify('+1 day')->format('Y-m-d');
    $db = DataBase::getConnection();
    try {
      if ($dateStart !== null) {
        $start = $dateStart->format('Y-m-d');
        $count = $db->prepare("SELECT COUNT(*) AS total FROM work_orders WHERE status IN ('delivered', 'cancelled') AND created_at >= ? AND created_at < ?");
        $count->bind_param('ss', $start, $endExclusive);
        $count->execute();
        $total = (int) $count->get_result()->fetch_assoc()['total'];

        $stmt = $db->prepare("SELECT * FROM work_orders WHERE status IN ('delivered', 'cancelled') AND created_at >= ? AND created_at < ? ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('ssii', $start, $endExclusive, $limit, $offset);
      } else {
        $count = $db->prepare("SELECT COUNT(*) AS total FROM work_orders WHERE status IN ('delivered', 'cancelled') AND created_at < ?");
        $count->bind_param('s', $endExclusive);
        $count->execute();
        $total = (int) $count->get_result()->fetch_assoc()['total'];

        $stmt = $db->prepare("SELECT * FROM work_orders WHERE status IN ('delivered', 'cancelled') AND created_at < ? ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('sii', $endExclusive, $limit, $offset);
      }

      $stmt->execute();
      $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $evidenceByOrder = $this->evidenceUrlsByOrder(
        $db,
        $orders,
        $this->evidenceTypesForRole((string) ($user['role'] ?? ''))
      );
      $this->jsonResponse([
        'items' => $this->addEvidenceUrls($orders, $evidenceByOrder),
        'pagination' => [
          'limit' => $limit,
          'offset' => $offset,
          'total' => $total
        ]
      ], 200, 'success', $total);
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function updateOrder(): void
  {
    $user = $this->user(['admin', 'mechanic']);
    $data = $this->getBody();
    if (!is_array($data) || !array_key_exists('id', $data)) {
      $this->jsonResponse([], 400, 'Missing order ID');
    }

    $id = filter_var($data['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
      $this->jsonResponse([], 422, 'Invalid order ID');
    }
    $actor = $this->auditActor($data['updated_by'] ?? null, $user);
    unset($data['updated_by']);

    $db = DataBase::getConnection();
    $transactionStarted = false;
    try {
      $columns = $this->getOrderColumnMetadata($db);
      unset($data['id']);
      $data = $this->normalizeOrderPayload($data, $columns);
      if (!$data) {
        $this->jsonResponse([], 422, 'No order fields to update');
      }

      $sets = [];
      $types = '';
      $values = [];
      foreach ($data as $field => $value) {
        $sets[] = "`{$field}` = ?";
        if (is_int($value)) {
          $types .= 'i';
        } elseif (is_float($value)) {
          $types .= 'd';
        } else {
          $types .= 's';
        }
        $values[] = $value;
      }

      $db->begin_transaction();
      $transactionStarted = true;
      $oldOrderStmt = $db->prepare('SELECT * FROM work_orders WHERE id = ? FOR UPDATE');
      $oldOrderStmt->bind_param('i', $id);
      $oldOrderStmt->execute();
      $oldOrder = $oldOrderStmt->get_result()->fetch_assoc();
      $oldOrderStmt->close();
      if (!$oldOrder) {
        $db->rollback();
        $transactionStarted = false;
        $this->jsonResponse([], 404, 'Order not found');
      }

      $changes = [];
      foreach ($data as $field => $value) {
        $oldValue = $this->normalizeOrderFieldValue($field, $oldOrder[$field], $columns[$field]);
        $from = $this->orderAuditValue($oldValue, $columns[$field]);
        $to = $this->orderAuditValue($value, $columns[$field]);
        if ($from !== $to) {
          $changes[$field] = ['from' => $from, 'to' => $to];
        }
      }

      $alerts = [];
      $assignmentPushUserIds = [];
      $assignmentPushBody = '';
      if (array_key_exists('assigned_user_id', $changes)) {
        if (($user['role'] ?? '') !== 'admin') {
          $db->rollback();
          $transactionStarted = false;
          $this->jsonResponse([], 403, 'Only admins can assign orders');
        }
        if ($data['assigned_user_id'] !== null) {
          $assignedUserIds = $this->assignedUserIds($data['assigned_user_id']);
          $assignedMechanics = $this->validateActiveMechanics($db, $assignedUserIds);
          $previouslyAssignedIds = $this->assignedUserIds($oldOrder['assigned_user_id'] ?? null);
          $newlyAssignedIds = array_values(array_diff($assignedUserIds, $previouslyAssignedIds));
          $nextStatus = $data['status'] ?? $oldOrder['status'] ?? null;
          if (!in_array($nextStatus, ['delivered', 'cancelled'], true)) {
            $assignmentPushUserIds = array_column(
              array_filter(
                $assignedMechanics,
                static fn(array $mechanic): bool =>
                  $mechanic['role'] === 'mechanic' && in_array((int) $mechanic['id'], $newlyAssignedIds, true)
              ),
              'id'
            );
            $assignmentPushBody = "Te asignaron la orden #{$id} — {$oldOrder['vehicle_plate']}";
          }
          $mechanicNames = array_map(
            static fn(array $mechanic): string => trim($mechanic['first_name'] . ' ' . $mechanic['last_name']),
            $assignedMechanics
          );
          $alerts[] = [
            'type' => 'order_assigned',
            'message' => $mechanicNames === []
              ? "Se quitaron las asignaciones de la orden {$oldOrder['vehicle_plate']}."
              : "La orden {$oldOrder['vehicle_plate']} fue asignada a " . implode(', ', $mechanicNames) . '.',
            'assigned_user_ids' => array_column($assignedMechanics, 'id'),
          ];
        } else {
          $alerts[] = [
            'type' => 'order_assigned',
            'message' => "Se quitaron las asignaciones de la orden {$oldOrder['vehicle_plate']}.",
            'assigned_user_ids' => [],
          ];
        }
      }
      if (isset($changes['status'])) {
        $alerts[] = [
          'type' => 'order_status_changed',
          'message' => "La orden {$oldOrder['vehicle_plate']} pasó a \"{$this->orderStatusLabel($data['status'])}\".",
        ];
      }
      if (array_key_exists('parts', $data)) {
        $mechanicName = ($actor['role'] ?? '') === 'mechanic' && $actor['name'] !== 'Sistema'
          ? $actor['name']
          : 'El mecánico';
        $alerts = array_merge(
          $alerts,
          $this->partRequestAlerts(
            $oldOrder['parts'] ?? '[]',
            $data['parts'],
            $mechanicName,
            (string) $oldOrder['vehicle_plate'],
            (string) $oldOrder['customer_name']
          )
        );
      }
      $budgetAccepted = isset($columns['budget_accepted'])
        && (int) ($oldOrder['budget_accepted'] ?? 0) === 0
        && (int) ($data['budget_accepted'] ?? 0) === 1;

      $sql = 'UPDATE work_orders SET ' . implode(', ', $sets) . ' WHERE id = ?';
      $types .= 'i';
      $values[] = $id;
      $stmt = $db->prepare($sql);
      $bindParams = [$types];
      foreach ($values as $index => &$value) {
        $bindParams[] = &$value;
      }
      unset($value);
      call_user_func_array([$stmt, 'bind_param'], $bindParams);
      $stmt->execute();
      if ($changes !== []) {
        $action = match ($changes['status']['to'] ?? null) {
          'delivered' => 'delivered',
          'cancelled' => 'cancelled',
          default => 'edited',
        };
        $this->logOrderAction($db, $id, $action, $actor, $changes);
      }
      if (!$db->commit()) {
        throw new RuntimeException('Could not commit order update and audit log');
      }
      $transactionStarted = false;
      foreach ($alerts as $alert) {
        $this->insertAlertBestEffort(
          $db,
          $id,
          $alert['type'],
          $alert['message'],
          $alert['assigned_user_ids'] ?? null
        );
      }
      if ($budgetAccepted) {
        $this->notifyAcceptedBudgetBestEffort($db, $id, (string) $oldOrder['vehicle_plate']);
      }
      if ($assignmentPushUserIds !== []) {
        PushSender::sendToUsers($db, $assignmentPushUserIds, [
          'title' => 'Mecaflow',
          'body' => $assignmentPushBody,
          'order_id' => $id,
        ]);
      }
      $this->jsonResponse(['id' => $id, 'updated' => true], 200, 'Order updated successfully', 1);
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->dbError($error);
    }
  }

  public function withdrawalComment(): void
  {
    $user = $this->user(['admin', 'mechanic']);
    $id = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT);
    $data = $this->getBody();
    if (!is_array($data)) {
      $this->jsonResponse([], 400, 'Invalid order payload');
    }
    $actor = $this->auditActor($data['updated_by'] ?? null, $user);
    $comment = trim((string) ($data['comment'] ?? ''));
    if (!$id || $comment === '') $this->jsonResponse([], 422, 'Comment is required');
    $db = DataBase::getConnection();
    $transactionStarted = false;
    try {
      $db->begin_transaction();
      $transactionStarted = true;
      $oldOrderStmt = $db->prepare('SELECT status, withdrawal_comment, vehicle_plate FROM work_orders WHERE id = ? FOR UPDATE');
      $oldOrderStmt->bind_param('i', $id);
      $oldOrderStmt->execute();
      $oldOrder = $oldOrderStmt->get_result()->fetch_assoc();
      $oldOrderStmt->close();
      if (!$oldOrder) {
        $db->rollback();
        $transactionStarted = false;
        $this->jsonResponse([], 404, 'Order not found');
      }

      $stmt = $db->prepare("UPDATE work_orders SET withdrawal_comment = ?, status = 'delivered', finished_at = COALESCE(finished_at, NOW()) WHERE id = ?");
      $stmt->bind_param('si', $comment, $id);
      $stmt->execute();
      $changes = [];
      if ($oldOrder['status'] !== 'delivered') {
        $changes['status'] = ['from' => $oldOrder['status'], 'to' => 'delivered'];
      }
      if ($oldOrder['withdrawal_comment'] !== $comment) {
        $changes['withdrawal_comment'] = ['from' => $oldOrder['withdrawal_comment'], 'to' => $comment];
      }
      if ($changes !== []) {
        $this->logOrderAction($db, $id, 'delivered', $actor, $changes);
      }
      if (!$db->commit()) {
        throw new RuntimeException('Could not commit delivery and audit log');
      }
      $transactionStarted = false;
      if ($oldOrder['status'] !== 'delivered') {
        $this->insertAlertBestEffort(
          $db,
          (int) $id,
          'order_status_changed',
          "La orden {$oldOrder['vehicle_plate']} pasó a \"Entregado\"."
        );
      }
      $this->jsonResponse([], 200, 'Withdrawal registered');
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->dbError($error);
    }
  }

  public function alerts(): void
  {
    $user = $this->user();
    $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
      $this->jsonResponse([], 401, 'Invalid authenticated user');
    }
    if (($_GET['unread'] ?? null) === '1' && ($_GET['read'] ?? null) === '1') {
      $this->jsonResponse([], 422, 'Use either read or unread, not both');
    }

    $db = DataBase::getConnection();
    try {
      $isAdmin = ($user['role'] ?? '') === 'admin';
      $where = $isAdmin
        ? '(a.targeted = 0 OR ar.user_id IS NOT NULL)'
        : '(a.targeted = 1 AND ar.user_id IS NOT NULL)';
      $orderBy = 'a.created_at DESC, a.id DESC';
      $limit = 100;
      if (($_GET['unread'] ?? null) === '1') {
        $where .= ' AND ((a.targeted = 1 AND ar.read_at IS NULL) OR (a.targeted = 0 AND a.read_at IS NULL))';
        $limit = 50;
      } elseif (($_GET['read'] ?? null) === '1') {
        $where .= ' AND ((a.targeted = 1 AND ar.read_at IS NOT NULL) OR (a.targeted = 0 AND a.read_at IS NOT NULL))';
        $orderBy = 'CASE WHEN a.targeted = 1 THEN ar.read_at ELSE a.read_at END DESC, a.id DESC';
        $limit = 50;
      }

      $stmt = $db->prepare(
        "SELECT a.id, a.order_id, a.type, a.message,
                CASE WHEN a.targeted = 1 THEN ar.read_at ELSE a.read_at END AS read_at,
                CASE WHEN a.targeted = 1 THEN ar.updated_by ELSE a.updated_by END AS updated_by,
                a.created_at
         FROM alerts a
         LEFT JOIN alert_recipients ar ON ar.alert_id = a.id AND ar.user_id = ?
         WHERE {$where}
         ORDER BY {$orderBy}
         LIMIT {$limit}"
      );
      $stmt->bind_param('i', $userId);
      $stmt->execute();
      $result = $stmt->get_result();
      $this->jsonResponse($result->fetch_all(MYSQLI_ASSOC), 200, 'success', $result->num_rows);
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function updateAlertRead(): void
  {
    $user = $this->user();
    $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
      $this->jsonResponse([], 401, 'Invalid authenticated user');
    }
    $data = $this->getBody();
    if (!is_array($data) || !array_key_exists('id', $data)) {
      $this->jsonResponse([], 422, 'Alert ID is required');
    }

    $rawId = $data['id'];
    if (
      (!is_int($rawId) && !is_string($rawId))
      || !preg_match('/^[0-9]+$/D', (string) $rawId)
      || filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
    ) {
      $this->jsonResponse([], 422, 'Alert ID must be numeric');
    }

    $id = (int) $rawId;
    $extraFields = array_diff(array_keys($data), ['id', 'read_at', 'updated_by']);
    if ($extraFields !== []) {
      $this->jsonResponse([], 422, 'Only id, read_at, and updated_by are allowed');
    }

    if (array_key_exists('updated_by', $data)) {
      if (!is_string($data['updated_by']) || $data['updated_by'] === '') {
        $this->jsonResponse([], 422, 'updated_by must be a non-empty JSON string');
      }
      $this->auditActor($data['updated_by'], $user);
    }

    $db = DataBase::getConnection();
    try {
      $authenticatedActor = $this->authenticatedAlertActor($db, $user);
      $alertStmt = $db->prepare('SELECT targeted FROM alerts WHERE id = ? LIMIT 1');
      $alertStmt->bind_param('i', $id);
      $alertStmt->execute();
      $alert = $alertStmt->get_result()->fetch_assoc();
      $alertStmt->close();
      if (!$alert) {
        $this->jsonResponse([], 404, 'Alert not found');
      }

      if ((int) $alert['targeted'] === 1) {
        $updateStmt = $db->prepare(
          'UPDATE alert_recipients
           SET read_at = COALESCE(read_at, NOW()), updated_by = COALESCE(updated_by, ?)
           WHERE alert_id = ? AND user_id = ?'
        );
        $updateStmt->bind_param('sii', $authenticatedActor, $id, $userId);
        $updateStmt->execute();
        $updateStmt->close();

        $recipientStmt = $db->prepare(
          'SELECT 1 FROM alert_recipients WHERE alert_id = ? AND user_id = ? LIMIT 1'
        );
        $recipientStmt->bind_param('ii', $id, $userId);
        $recipientStmt->execute();
        $isRecipient = (bool) $recipientStmt->get_result()->fetch_row();
        $recipientStmt->close();
        if (!$isRecipient) {
          $this->jsonResponse([], 404, 'Alert not found');
        }
      } else {
        if (($user['role'] ?? '') !== 'admin') {
          $this->jsonResponse([], 403, 'Administrator role required for legacy alerts');
        }
        $updateStmt = $db->prepare(
          'UPDATE alerts SET read_at = COALESCE(read_at, NOW()), updated_by = COALESCE(updated_by, ?) WHERE id = ?'
        );
        $updateStmt->bind_param('si', $authenticatedActor, $id);
        $updateStmt->execute();
        $updateStmt->close();
      }

      $selectStmt = $db->prepare(
        'SELECT a.id, a.order_id, a.type, a.message,
                CASE WHEN a.targeted = 1 THEN ar.read_at ELSE a.read_at END AS read_at,
                CASE WHEN a.targeted = 1 THEN ar.updated_by ELSE a.updated_by END AS updated_by,
                a.created_at
         FROM alerts a
         LEFT JOIN alert_recipients ar ON ar.alert_id = a.id AND ar.user_id = ?
         WHERE a.id = ? LIMIT 1'
      );
      $selectStmt->bind_param('ii', $userId, $id);
      $selectStmt->execute();
      $alert = $selectStmt->get_result()->fetch_assoc();
      $selectStmt->close();
      if (!$alert) {
        $this->jsonResponse([], 404, 'Alert not found');
      }

      $this->jsonResponse($alert, 200, 'success');
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function readAllAlerts(): void
  {
    $user = $this->user();
    $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
      $this->jsonResponse([], 401, 'Invalid authenticated user');
    }
    $data = $this->getBody();
    if ($data !== null && (!is_array($data) || array_diff(array_keys($data), ['updated_by']) !== [])) {
      $this->jsonResponse([], 422, 'Only updated_by is allowed');
    }
    if (isset($data['updated_by']) && !is_string($data['updated_by'])) {
      $this->jsonResponse([], 422, 'updated_by must be a JSON string');
    }

    $db = DataBase::getConnection();
    $transactionStarted = false;
    try {
      $authenticatedActor = $this->authenticatedAlertActor($db, $user);
      $db->begin_transaction();
      $transactionStarted = true;
      $stmt = $db->prepare(
        'UPDATE alert_recipients
         SET read_at = NOW(), updated_by = COALESCE(updated_by, ?)
         WHERE user_id = ? AND read_at IS NULL'
      );
      $stmt->bind_param('si', $authenticatedActor, $userId);
      $stmt->execute();
      $updated = $stmt->affected_rows;
      $stmt->close();

      if (($user['role'] ?? '') === 'admin') {
        $legacyStmt = $db->prepare(
          'UPDATE alerts
           SET read_at = NOW(), updated_by = COALESCE(updated_by, ?)
           WHERE targeted = 0 AND read_at IS NULL'
        );
        $legacyStmt->bind_param('s', $authenticatedActor);
        $legacyStmt->execute();
        $updated += $legacyStmt->affected_rows;
        $legacyStmt->close();
      }
      if (!$db->commit()) {
        throw new RuntimeException('Could not commit alert read receipts');
      }
      $transactionStarted = false;
      $this->jsonResponse(['updated' => $updated], 200, 'success');
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->dbError($error);
    }
  }

  public function whatsappUrl(): void
  {
    $this->user(['admin', 'mechanic']);
    $id = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT);
    $db = DataBase::getConnection();
    $stmt = $db->prepare('SELECT customer_phone, public_token FROM work_orders WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = $result->num_rows;
    $order = $result->fetch_assoc();
    if (!$order) $this->jsonResponse([], 404, 'Order not found');
    $base = defined('PUBLIC_PORTAL_URL') ? PUBLIC_PORTAL_URL : 'http://localhost:3000/order/';
    $url = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $order['customer_phone']) . '?text=' . rawurlencode("Consulta el estado de tu vehículo: " . $base . $order['public_token']);
    $this->jsonResponse(['url' => $url], 200, 'success', $total);
  }
}
