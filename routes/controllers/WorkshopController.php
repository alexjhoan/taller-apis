<?php

require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../utils.php';

class WorkshopController extends Controller
{
  private const ORDER_WRITE_BLOCKLIST = ['id', 'public_token', 'created_by', 'created_at', 'updated_at', 'images', 'videos'];

  private function user(array $roles = []): array
  {
    $user = Utils::validateBearerToken();
    $role = $user['role'] ?? 'mechanic';
    if ($roles && !in_array($role, $roles, true)) {
      $this->jsonResponse([], 403, 'Insufficient permissions');
    }
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

  private function evidenceUrlsByOrder(mysqli $db, array $orders): array
  {
    $orderIds = array_values(array_unique(array_filter(
      array_map('intval', array_column($orders, 'id')),
      static fn(int $id): bool => $id > 0
    )));
    if ($orderIds === []) {
      return [];
    }

    $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
    $stmt = $db->prepare(
      "SELECT order_id, file_url FROM order_evidence
       WHERE order_id IN ({$placeholders}) ORDER BY id ASC"
    );
    $types = str_repeat('i', count($orderIds));
    $bindParams = [$types];
    foreach ($orderIds as &$orderId) {
      $bindParams[] = &$orderId;
    }
    unset($orderId);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    $stmt->execute();

    $evidenceByOrder = [];
    $seenUrls = [];
    $result = $stmt->get_result();
    while ($evidence = $result->fetch_assoc()) {
      $orderId = (int) $evidence['order_id'];
      $url = $this->absoluteEvidenceUrl((string) $evidence['file_url']);
      if (!isset($seenUrls[$orderId][$url])) {
        $evidenceByOrder[$orderId][] = $url;
        $seenUrls[$orderId][$url] = true;
      }
    }
    $stmt->close();

    foreach ($orders as $order) {
      $orderId = (int) $order['id'];
      foreach (['images', 'videos'] as $field) {
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
            $evidenceByOrder[$orderId][] = $url;
            $seenUrls[$orderId][$url] = true;
          }
        }
      }
    }

    return $evidenceByOrder;
  }

  private function addEvidenceUrls(array $orders, array $evidenceByOrder): array
  {
    foreach ($orders as &$order) {
      $order['evidence'] = $evidenceByOrder[(int) $order['id']] ?? [];
    }
    unset($order);

    return $orders;
  }

  private function dbError(Throwable $error): void
  {
    error_log($error->getMessage());
    $this->jsonResponse([], 500, 'Database error');
  }

  private function insertAlertBestEffort(mysqli $db, int $orderId, string $type, string $message): bool
  {
    $message = preg_match('/^.{0,255}/us', $message, $matches) ? $matches[0] : substr($message, 0, 255);
    try {
      $stmt = $db->prepare('INSERT INTO alerts (order_id, type, message) VALUES (?, ?, ?)');
      $stmt->bind_param('iss', $orderId, $type, $message);
      $stmt->execute();
      $stmt->close();
      return true;
    } catch (Throwable $error) {
      error_log("Could not create {$type} alert for order {$orderId}: " . $error->getMessage());
      return false;
    }
  }

  private function notifyAcceptedBudgetBestEffort(mysqli $db, int $orderId, string $vehiclePlate): bool
  {
    try {
      $existsStmt = $db->prepare(
        "SELECT 1 FROM alerts
         WHERE order_id = ? AND type = 'budget_accepted' AND read_at IS NULL
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
      $required = ['customer_name', 'customer_phone', 'vehicle_plate', 'vehicle_make', 'vehicle_model', 'mileage', 'fuel_level', 'failure_description', 'checklist'];
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
      if (($user['role'] ?? '') !== 'admin') {
        $customerName = (string) $data['customer_name'];
        $vehiclePlate = (string) $data['vehicle_plate'];
        $this->insertAlertBestEffort(
          $db,
          $orderId,
          'order_created',
          "Nueva orden de {$customerName} ({$vehiclePlate})."
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
    $user = $this->user(['admin', 'mechanic']);
    $id = filter_var($this->params()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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
      $stmt = $db->prepare('INSERT INTO order_evidence (order_id, type, file_url, uploaded_by) VALUES (?, ?, ?, ?)');
      $stmt->bind_param('issi', $id, $type, $path, $user['id']);
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
      $this->jsonResponse(['id' => $stmt->insert_id, 'url' => $path], 201, 'Evidence uploaded');
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
      error_log($error->getMessage());
      $this->jsonResponse([], 500, 'Could not process or store evidence');
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
      $evidenceByOrder = $this->evidenceUrlsByOrder($db, [$order]);
      $evidence = $evidenceByOrder[(int) $id] ?? [];
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
    $this->user(['admin', 'mechanic']);

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
    $this->user(['admin', 'mechanic']);
    $db = DataBase::getConnection();
    try {
      $result = $db->query("SELECT * FROM work_orders WHERE status NOT IN ('cancelled', 'delivered') OR status IS NULL ORDER BY id DESC");
      $orders = $result->fetch_all(MYSQLI_ASSOC);
      $evidenceByOrder = $this->evidenceUrlsByOrder($db, $orders);
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
    $this->user(['admin', 'mechanic']);

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
      $evidenceByOrder = $this->evidenceUrlsByOrder($db, $orders);
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
        $this->insertAlertBestEffort($db, $id, $alert['type'], $alert['message']);
      }
      if ($budgetAccepted) {
        $this->notifyAcceptedBudgetBestEffort($db, $id, (string) $oldOrder['vehicle_plate']);
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
    $this->user(['admin']);
    $db = DataBase::getConnection();
    try {
      if (($_GET['unread'] ?? null) === '1') {
        $result = $db->query(
          'SELECT * FROM alerts WHERE read_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 50'
        );
      } else {
        $result = $db->query('SELECT * FROM alerts ORDER BY created_at DESC LIMIT 100');
      }
      $this->jsonResponse($result->fetch_all(MYSQLI_ASSOC), 200, 'success', $result->num_rows);
    } catch (Throwable $error) {
      $this->dbError($error);
    }
  }

  public function updateAlertRead(): void
  {
    $this->user(['admin']);
    $data = $this->getBody();
    if (!is_array($data) || !array_key_exists('id', $data)) {
      $this->jsonResponse([], 422, 'Alert ID is required');
    }

    $rawId = $data['id'];
    if (
      (!is_int($rawId) && !is_string($rawId))
      || !preg_match('/^[0-9]+$/D', (string) $rawId)
      || filter_var($rawId, FILTER_VALIDATE_INT) === false
    ) {
      $this->jsonResponse([], 422, 'Alert ID must be numeric');
    }

    $id = (int) $rawId;
    unset($data['read_at']);
    $extraFields = array_diff(array_keys($data), ['id']);
    if ($extraFields !== []) {
      require_once __DIR__ . '/../services/patch.php';
      patchData('alerts', $data);
      return;
    }

    $db = DataBase::getConnection();
    try {
      $updateStmt = $db->prepare('UPDATE alerts SET read_at = NOW() WHERE id = ? AND read_at IS NULL');
      $updateStmt->bind_param('i', $id);
      $updateStmt->execute();
      $updateStmt->close();

      $selectStmt = $db->prepare('SELECT * FROM alerts WHERE id = ? LIMIT 1');
      $selectStmt->bind_param('i', $id);
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
    $this->user(['admin']);
    $data = $this->getBody();
    if ($data !== null && (!is_array($data) || $data !== [])) {
      $this->jsonResponse([], 422, 'Request body must be empty');
    }

    $db = DataBase::getConnection();
    try {
      $stmt = $db->prepare('UPDATE alerts SET read_at = NOW() WHERE read_at IS NULL');
      $stmt->execute();
      $updated = $stmt->affected_rows;
      $stmt->close();
      $this->jsonResponse(['updated' => $updated], 200, 'success');
    } catch (Throwable $error) {
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
