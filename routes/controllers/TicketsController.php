<?php

require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../utils.php';

class TicketsController extends Controller
{
  private const CATEGORIES = ['soporte', 'sugerencia', 'bug'];
  private const STATUSES = ['abierto', 'en_proceso', 'cerrado'];

  private function authenticatedUser(): array
  {
    $tokenUser = Utils::validateBearerToken();
    $userId = filter_var($tokenUser['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
      $this->jsonResponse([], 401, 'Invalid authenticated user');
    }

    try {
      $db = DataBase::getConnection();
      $stmt = $db->prepare(
        'SELECT id, first_name, last_name, email, role, active FROM users WHERE id = ? LIMIT 1'
      );
      $stmt->bind_param('i', $userId);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc();
      $stmt->close();
    } catch (Throwable $error) {
      $this->databaseError($error);
    }

    if (!$user || (int) $user['active'] !== 1) {
      $this->jsonResponse([], 401, 'Authenticated user is inactive or no longer exists');
    }

    return [
      'id' => (int) $user['id'],
      'name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
      'email' => (string) $user['email'],
      'role' => (string) ($user['role'] ?? ''),
      'db' => $db,
    ];
  }

  private function requireAdmin(): array
  {
    $user = $this->authenticatedUser();
    if ($user['role'] !== 'admin') {
      $this->jsonResponse([], 403, 'Administrator role required');
    }
    return $user;
  }

  private function databaseError(Throwable $error): void
  {
    error_log('Ticket operation failed: ' . $error->getMessage());
    $this->jsonResponse([], 500, 'Database error');
  }

  private function bindValues(mysqli_stmt $stmt, array &$values): void
  {
    if ($values === []) {
      $stmt->execute();
      return;
    }

    $types = '';
    $parameters = [$types];
    foreach ($values as &$value) {
      $types .= is_int($value) ? 'i' : 's';
      $parameters[] = &$value;
    }
    unset($value);
    $parameters[0] = $types;
    call_user_func_array([$stmt, 'bind_param'], $parameters);
    $stmt->execute();
  }

  private function requestBody(): array
  {
    $body = $this->getBody();
    if (!is_array($body)) {
      $this->jsonResponse([], 422, 'Invalid request body');
    }
    return $body;
  }

  private function validateUpdatedBy(array $data, array $user): void
  {
    if (!array_key_exists('updated_by', $data)) {
      return;
    }
    if (!is_string($data['updated_by']) || $data['updated_by'] === '') {
      $this->jsonResponse([], 422, 'updated_by must be a non-empty JSON string');
    }

    try {
      $updatedBy = json_decode($data['updated_by'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
      $this->jsonResponse([], 422, 'updated_by must contain valid JSON');
    }

    $updatedById = is_array($updatedBy)
      ? filter_var($updatedBy['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
      : false;
    if ($updatedById === false || $updatedById !== $user['id']) {
      $this->jsonResponse([], 403, 'updated_by does not match the authenticated user');
    }
  }

  private function ticketId(): int
  {
    $id = filter_var(
      $GLOBALS['routeParams']['id'] ?? null,
      FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 1]]
    );
    if ($id === false) {
      $this->jsonResponse([], 422, 'Invalid ticket ID');
    }
    return $id;
  }

  private function isValidDate(string $value): bool
  {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
      return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
      && $date->format('Y-m-d') === $value
      && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
  }

  private function ticketDetails(mysqli $db, int $ticketId): ?array
  {
    $stmt = $db->prepare(
      "SELECT t.id, t.user_id, TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.last_name, ''))) AS user_name,
              u.email AS user_email, t.title, t.description, t.category, t.status,
              t.assigned_admin_id,
              CASE WHEN assigned.id IS NULL THEN NULL
                   ELSE TRIM(CONCAT_WS(' ', assigned.first_name, NULLIF(assigned.last_name, '')))
              END AS assigned_admin_name,
              t.created_at, t.updated_at
       FROM tickets t
       INNER JOIN users u ON u.id = t.user_id
       LEFT JOIN users assigned ON assigned.id = t.assigned_admin_id
       WHERE t.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) {
      return null;
    }

    $ticket['id'] = (int) $ticket['id'];
    $ticket['user_id'] = (int) $ticket['user_id'];
    $ticket['assigned_admin_id'] = $ticket['assigned_admin_id'] === null
      ? null
      : (int) $ticket['assigned_admin_id'];

    $repliesStmt = $db->prepare(
      "SELECT r.id, r.user_id,
              TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.last_name, ''))) AS user_name,
              r.message, r.is_admin_reply, r.created_at
       FROM ticket_replies r
       INNER JOIN users u ON u.id = r.user_id
       WHERE r.ticket_id = ?
       ORDER BY r.id ASC"
    );
    $repliesStmt->bind_param('i', $ticketId);
    $repliesStmt->execute();
    $replies = $repliesStmt->get_result();
    $ticket['replies'] = [];
    while ($reply = $replies->fetch_assoc()) {
      $reply['id'] = (int) $reply['id'];
      $reply['user_id'] = (int) $reply['user_id'];
      $reply['is_admin_reply'] = (int) $reply['is_admin_reply'];
      $ticket['replies'][] = $reply;
    }
    $repliesStmt->close();

    return $ticket;
  }

  public function create(): void
  {
    $user = $this->authenticatedUser();
    $data = $this->requestBody();
    if (array_diff(array_keys($data), ['title', 'description', 'category', 'updated_by']) !== []) {
      $this->jsonResponse([], 422, 'Only title, description, category, and updated_by are allowed');
    }
    $this->validateUpdatedBy($data, $user);

    $title = $data['title'] ?? null;
    $description = $data['description'] ?? null;
    $category = $data['category'] ?? 'soporte';
    if (
      !is_string($title)
      || trim($title) === ''
      || preg_match('//u', $title) !== 1
      || preg_match_all('/./us', $title) > 255
    ) {
      $this->jsonResponse([], 422, 'title is required and must be at most 255 characters');
    }
    if (
      !is_string($description)
      || trim($description) === ''
      || preg_match('//u', $description) !== 1
      || strlen($description) > 65535
    ) {
      $this->jsonResponse([], 422, 'description is required and must fit in a TEXT field');
    }
    if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
      $this->jsonResponse([], 422, 'Invalid ticket category');
    }
    $title = trim($title);
    $description = trim($description);

    $db = $user['db'];
    try {
      $stmt = $db->prepare('INSERT INTO tickets (user_id, title, description, category) VALUES (?, ?, ?, ?)');
      $stmt->bind_param('isss', $user['id'], $title, $description, $category);
      $stmt->execute();
      $ticketId = $stmt->insert_id;
      $stmt->close();

      $createdStmt = $db->prepare('SELECT created_at FROM tickets WHERE id = ? LIMIT 1');
      $createdStmt->bind_param('i', $ticketId);
      $createdStmt->execute();
      $createdAt = $createdStmt->get_result()->fetch_assoc()['created_at'] ?? null;
      $createdStmt->close();
      if (!is_string($createdAt)) {
        throw new RuntimeException('Could not load the created ticket');
      }

      $this->jsonResponse([
        'id' => (int) $ticketId,
        'title' => $title,
        'category' => $category,
        'status' => 'abierto',
        'created_at' => $createdAt,
      ], 201, 'Ticket creado');
    } catch (Throwable $error) {
      $this->databaseError($error);
    }
  }

  public function index(): void
  {
    $user = $this->requireAdmin();
    $pageValue = $_GET['page'] ?? '1';
    $perPageValue = $_GET['per_page'] ?? '20';
    if (!is_string($pageValue) || !is_string($perPageValue)) {
      $this->jsonResponse([], 422, 'Invalid pagination parameters');
    }
    $page = filter_var($pageValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $perPage = filter_var($perPageValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($page === false || $perPage === false) {
      $this->jsonResponse([], 422, 'Invalid pagination parameters');
    }
    if ($page > intdiv(PHP_INT_MAX, $perPage) + 1) {
      $this->jsonResponse([], 422, 'Pagination offset is out of range');
    }

    $conditions = [];
    $values = [];
    foreach (['status' => self::STATUSES, 'category' => self::CATEGORIES] as $field => $allowed) {
      if (!array_key_exists($field, $_GET)) {
        continue;
      }
      $value = $_GET[$field];
      if (!is_string($value) || !in_array($value, $allowed, true)) {
        $this->jsonResponse([], 422, "Invalid {$field}");
      }
      $conditions[] = "t.{$field} = ?";
      $values[] = $value;
    }

    foreach (['date_from', 'date_to'] as $field) {
      if (!array_key_exists($field, $_GET)) {
        continue;
      }
      $value = $_GET[$field];
      if (!is_string($value) || !$this->isValidDate($value)) {
        $this->jsonResponse([], 422, "Invalid {$field}; expected YYYY-MM-DD");
      }
      if ($field === 'date_from') {
        $conditions[] = 't.created_at >= ?';
        $values[] = $value . ' 00:00:00';
      } else {
        $endExclusive = (new DateTimeImmutable($value))->modify('+1 day')->format('Y-m-d');
        $conditions[] = 't.created_at < ?';
        $values[] = $endExclusive . ' 00:00:00';
      }
    }
    if (isset($_GET['date_from'], $_GET['date_to']) && $_GET['date_from'] > $_GET['date_to']) {
      $this->jsonResponse([], 422, 'date_from must be on or before date_to');
    }

    if (array_key_exists('search', $_GET)) {
      $search = $_GET['search'];
      if (!is_string($search) || strlen($search) > 255) {
        $this->jsonResponse([], 422, 'search must be a string of at most 255 bytes');
      }
      $search = trim($search);
      if ($search !== '') {
        $conditions[] = '(t.title LIKE ? OR t.description LIKE ?)';
        $values[] = '%' . $search . '%';
        $values[] = '%' . $search . '%';
      }
    }

    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    $db = $user['db'];
    try {
      $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM tickets t{$where}");
      $countValues = $values;
      $this->bindValues($countStmt, $countValues);
      $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
      $countStmt->close();

      $offset = ($page - 1) * $perPage;
      $listStmt = $db->prepare(
        "SELECT t.id, t.user_id,
                TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.last_name, ''))) AS user_name,
                t.title, t.category, t.status, t.created_at,
                (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id) AS replies_count
         FROM tickets t
         INNER JOIN users u ON u.id = t.user_id
         {$where}
         ORDER BY t.created_at DESC, t.id DESC
         LIMIT ? OFFSET ?"
      );
      $listValues = [...$values, $perPage, $offset];
      $this->bindValues($listStmt, $listValues);
      $result = $listStmt->get_result();
      $tickets = [];
      while ($ticket = $result->fetch_assoc()) {
        $ticket['id'] = (int) $ticket['id'];
        $ticket['user_id'] = (int) $ticket['user_id'];
        $ticket['replies_count'] = (int) $ticket['replies_count'];
        $tickets[] = $ticket;
      }
      $listStmt->close();

      $this->jsonResponse([
        'data' => $tickets,
        'pagination' => [
          'current_page' => $page,
          'per_page' => $perPage,
          'total' => $total,
          'last_page' => max(1, (int) ceil($total / $perPage)),
        ],
      ], 200, 'OK');
    } catch (Throwable $error) {
      $this->databaseError($error);
    }
  }

  public function show(): void
  {
    $user = $this->requireAdmin();
    $ticketId = $this->ticketId();
    try {
      $ticket = $this->ticketDetails($user['db'], $ticketId);
      if ($ticket === null) {
        $this->jsonResponse([], 404, 'Ticket not found');
      }
      $this->jsonResponse($ticket, 200, 'OK');
    } catch (Throwable $error) {
      $this->databaseError($error);
    }
  }

  public function update(): void
  {
    $user = $this->requireAdmin();
    $ticketId = $this->ticketId();
    $data = $this->requestBody();
    $allowedFields = ['status', 'category', 'assigned_admin_id', 'updated_by'];
    if (
      $data === []
      || array_diff(array_keys($data), $allowedFields) !== []
    ) {
      $this->jsonResponse([], 422, 'Only status, category, assigned_admin_id, and updated_by are allowed');
    }
    $this->validateUpdatedBy($data, $user);
    unset($data['updated_by']);
    if ($data === []) {
      $this->jsonResponse([], 422, 'Provide status, category, and/or assigned_admin_id');
    }
    if (array_key_exists('status', $data) && (!is_string($data['status']) || !in_array($data['status'], self::STATUSES, true))) {
      $this->jsonResponse([], 422, 'Invalid ticket status');
    }
    if (array_key_exists('category', $data) && (!is_string($data['category']) || !in_array($data['category'], self::CATEGORIES, true))) {
      $this->jsonResponse([], 422, 'Invalid ticket category');
    }

    $assignedAdminId = $data['assigned_admin_id'] ?? null;
    if (array_key_exists('assigned_admin_id', $data) && $assignedAdminId !== null) {
      $assignedAdminId = filter_var(
        $assignedAdminId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
      );
      if ($assignedAdminId === false) {
        $this->jsonResponse([], 422, 'assigned_admin_id must be a positive admin ID or null');
      }
    }

    $db = $user['db'];
    $transactionStarted = false;
    try {
      $db->begin_transaction();
      $transactionStarted = true;
      $lockStmt = $db->prepare('SELECT id FROM tickets WHERE id = ? FOR UPDATE');
      $lockStmt->bind_param('i', $ticketId);
      $lockStmt->execute();
      $exists = (bool) $lockStmt->get_result()->fetch_assoc();
      $lockStmt->close();
      if (!$exists) {
        $db->rollback();
        $transactionStarted = false;
        $this->jsonResponse([], 404, 'Ticket not found');
      }

      if (array_key_exists('assigned_admin_id', $data) && $assignedAdminId !== null) {
        $adminStmt = $db->prepare('SELECT id FROM users WHERE id = ? AND role = ? AND active = 1 LIMIT 1');
        $adminRole = 'admin';
        $adminStmt->bind_param('is', $assignedAdminId, $adminRole);
        $adminStmt->execute();
        $validAdmin = (bool) $adminStmt->get_result()->fetch_assoc();
        $adminStmt->close();
        if (!$validAdmin) {
          $db->rollback();
          $transactionStarted = false;
          $this->jsonResponse([], 422, 'assigned_admin_id must identify an active admin');
        }
      }

      $sets = [];
      $values = [];
      foreach (['status', 'category'] as $field) {
        if (array_key_exists($field, $data)) {
          $sets[] = "`{$field}` = ?";
          $values[] = $data[$field];
        }
      }
      if (array_key_exists('assigned_admin_id', $data)) {
        $sets[] = 'assigned_admin_id = ?';
        $values[] = $assignedAdminId;
      }
      $values[] = $ticketId;
      $updateStmt = $db->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?');
      $this->bindValues($updateStmt, $values);
      $updateStmt->close();

      $ticket = $this->ticketDetails($db, $ticketId);
      if (!$ticket || !$db->commit()) {
        throw new RuntimeException('Could not commit ticket update');
      }
      $transactionStarted = false;
      $this->jsonResponse($ticket, 200, 'Ticket actualizado');
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->databaseError($error);
    }
  }

  public function reply(): void
  {
    $user = $this->requireAdmin();
    $ticketId = $this->ticketId();
    $data = $this->requestBody();
    if (
      array_diff(array_keys($data), ['message']) !== []
      || !is_string($data['message'] ?? null)
      || trim($data['message']) === ''
      || preg_match('//u', $data['message']) !== 1
      || strlen($data['message']) > 65535
    ) {
      $this->jsonResponse([], 422, 'message is required and must fit in a TEXT field');
    }
    $message = trim($data['message']);

    $db = $user['db'];
    $transactionStarted = false;
    try {
      $db->begin_transaction();
      $transactionStarted = true;
      $ticketStmt = $db->prepare('SELECT status FROM tickets WHERE id = ? FOR UPDATE');
      $ticketStmt->bind_param('i', $ticketId);
      $ticketStmt->execute();
      $ticket = $ticketStmt->get_result()->fetch_assoc();
      $ticketStmt->close();
      if (!$ticket) {
        $db->rollback();
        $transactionStarted = false;
        $this->jsonResponse([], 404, 'Ticket not found');
      }

      $insertStmt = $db->prepare(
        'INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin_reply) VALUES (?, ?, ?, 1)'
      );
      $insertStmt->bind_param('iis', $ticketId, $user['id'], $message);
      $insertStmt->execute();
      $replyId = $insertStmt->insert_id;
      $insertStmt->close();

      if ($ticket['status'] === 'abierto') {
        $updateStmt = $db->prepare("UPDATE tickets SET status = 'en_proceso' WHERE id = ?");
        $updateStmt->bind_param('i', $ticketId);
        $updateStmt->execute();
        $updateStmt->close();
      }

      $replyStmt = $db->prepare('SELECT created_at FROM ticket_replies WHERE id = ? LIMIT 1');
      $replyStmt->bind_param('i', $replyId);
      $replyStmt->execute();
      $createdAt = $replyStmt->get_result()->fetch_assoc()['created_at'] ?? null;
      $replyStmt->close();
      if (!is_string($createdAt) || !$db->commit()) {
        throw new RuntimeException('Could not commit ticket reply');
      }
      $transactionStarted = false;
      $this->jsonResponse([
        'id' => (int) $replyId,
        'ticket_id' => $ticketId,
        'message' => $message,
        'is_admin_reply' => 1,
        'created_at' => $createdAt,
      ], 201, 'Respuesta enviada');
    } catch (Throwable $error) {
      if ($transactionStarted) {
        $db->rollback();
      }
      $this->databaseError($error);
    }
  }
}
