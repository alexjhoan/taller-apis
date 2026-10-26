<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../utils.php';

use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Users",
 *     description="Operations related to users"
 * )
 */
class UserController extends Controller
{
  /**
   * @OA\Get(
   *     path="/users",
   *     tags={"Users"},
   *     summary="Get all users",
   *     @OA\Response(
   *         response=200,
   *         description="Successful operation",
   *         @OA\JsonContent(
   *             type="object",
   *             @OA\Property(property="status", type="integer", example=200),
   *             @OA\Property(
   *                 property="body",
   *                 type="array",
   *                 @OA\Items(
   *                     type="object",
   *                     @OA\Property(property="id", type="integer"),
   *                     @OA\Property(property="email", type="string"),
   *                     @OA\Property(property="first_name", type="string")
   *                 )
   *             ),
   *             @OA\Property(property="statusText", type="string", example="success"),
   *             @OA\Property(property="total", type="integer", example=42)
   *         )
   *     ),
   *     @OA\Response(response=401, description="Unauthorized")
   * )
   */
  public function getAll()
  {
    $db = DataBase::getConnection();
    $sql = "SELECT * FROM users ORDER BY `id` DESC";
    $result = $db->query($sql);

    $users = [];
    while ($row = $result->fetch_assoc()) {
      unset($row['password']);
      $users[] = $row;
    }

    $this->jsonResponse($users, 200, 'success', $result->num_rows);
  }

  /**
   * @OA\Get(
   *     path="/user-by-id",
   *     tags={"Users"},
   *     summary="Get user by ID",
   *     @OA\Parameter(
   *         name="id",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="integer")
   *     ),
   *     @OA\Response(
   *         response=200,
   *         description="Successful operation",
   *         @OA\JsonContent(
   *             type="object",
   *             @OA\Property(property="status", type="integer", example=200),
   *             @OA\Property(
   *                 property="body",
   *                 type="array",
   *                 @OA\Items(
   *                     type="object",
   *                     @OA\Property(property="id", type="integer"),
   *                     @OA\Property(property="email", type="string")
   *                 )
   *             ),
   *             @OA\Property(property="statusText", type="string", example="success"),
   *             @OA\Property(property="total", type="integer", example=1)
   *         )
   *     ),
   *     @OA\Response(response=404, description="User not found")
   * )
   */
  public function getById()
  {
    if (!isset($_GET['id'])) {
      $this->jsonResponse([], 400, 'Missing ID parameter');
    }

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $db = DataBase::getConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();

    $total = $result->num_rows;
    if ($row = $result->fetch_assoc()) {
      unset($row['password']);
      $this->jsonResponse([$row], 200, 'success', $total);
    } else {
      $this->jsonResponse([], 404, 'User not found', $total);
    }
  }

  /**
   * @OA\Post(
   *     path="/create-user",
   *     tags={"Users"},
   *     summary="Create a new user",
   *     @OA\RequestBody(
   *         required=true,
   *         @OA\JsonContent(
   *             required={"email","password","first_name"},
   *             @OA\Property(property="email", type="string", format="email"),
   *             @OA\Property(property="password", type="string", format="password"),
   *             @OA\Property(property="first_name", type="string"),
   *             @OA\Property(property="last_name", type="string"),
   *             @OA\Property(property="phone", type="string")
   *         )
   *     ),
   *     @OA\Response(
   *         response=201,
   *         description="User created successfully"
   *     ),
   *     @OA\Response(response=409, description="Email already exists")
   * )
   */
  public function create()
  {
    $data = $this->getBody();

    if (empty($data['nickname']) || empty($data['email']) || empty($data['password'])) {
      $this->jsonResponse([], 400, 'Nickname, email and password are required');
    }

    $emailExists = Utils::isRecordExists('users', 'email', $data['email']);
    if ($emailExists['boolean']) {
      $this->jsonResponse([], 409, "El correo '{$data['email']}' ya existe", $emailExists['total']);
    }

    $nicknameExists = Utils::isRecordExists('users', 'nickname', $data['nickname']);
    if ($nicknameExists['boolean']) {
      $this->jsonResponse([], 409, "El nickname '{$data['nickname']}' ya existe", $nicknameExists['total']);
    }

    $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);

    $db = DataBase::getConnection();
    $stmt = $db->prepare("INSERT INTO users (first_name, last_name, nickname, email, password, phone, role, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $firstName = $data['first_name'] ?? '';
    $lastName = $data['last_name'] ?? '';
    $nickname = trim((string) ($data['nickname'] ?? ''));
    $phone = $data['phone'] ?? '';
    $role = in_array($data['role'] ?? 'mechanic', ['admin', 'mechanic'], true) ? $data['role'] : 'mechanic';
    $active = 1;

    $stmt->bind_param("sssssssi", $firstName, $lastName, $nickname, $data['email'], $passwordHash, $phone, $role, $active);

    try {
      $stmt->execute();
      $this->jsonResponse(['id' => $stmt->insert_id], 201, 'Created successfully', 1);
    } catch (Exception $e) {
      $this->jsonResponse([], 500, $e->getMessage(), 0);
    }
  }

  /**
   * @OA\Post(
   *     path="/login-user",
   *     tags={"Users"},
   *     summary="User login",
   *     @OA\RequestBody(
   *         required=true,
   *         @OA\JsonContent(
   *             required={"login","password"},
   *             @OA\Property(property="login", type="string", description="users.email or users.nickname"),
   *             @OA\Property(property="email", type="string", format="email", description="Legacy alias for login"),
   *             @OA\Property(property="password", type="string", format="password")
   *         )
   *     ),
   *     @OA\Response(
   *         response=200,
   *         description="Login successful",
   *         @OA\JsonContent(
   *             type="object",
   *             @OA\Property(property="status", type="integer", example=200),
   *             @OA\Property(
   *                 property="body",
   *                 type="object",
   *                 @OA\Property(property="id", type="integer"),
   *                 @OA\Property(property="token", type="string"),
   *                 @OA\Property(property="first_name", type="string"),
   *                 @OA\Property(property="lastName", type="string", nullable=true),
   *                 @OA\Property(property="nickname", type="string"),
   *                 @OA\Property(property="role", type="string")
   *             ),
   *             @OA\Property(property="statusText", type="string"),
   *             @OA\Property(property="total", type="integer", example=1)
   *         )
   *     ),
   *     @OA\Response(response=401, description="Invalid credentials")
   * )
   */
  public function login()
  {
    $data = $this->getBody();
    $login = trim((string) ($data['login'] ?? $data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    if ($login === '' || $password === '') {
      $this->jsonResponse([], 400, 'Login and password are required');
    }

    $db = DataBase::getConnection();
    $stmt = $db->prepare('SELECT id, email, nickname, password, first_name, last_name, role FROM users WHERE active = 1 AND (email = ? OR nickname = ?) LIMIT 1');
    $stmt->bind_param('ss', $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = $result->num_rows;
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
      $token = Utils::JwtEncode([
        'id' => $user['id'],
        'email' => $user['email'],
        'nickname' => $user['nickname'],
        'role' => $user['role'] ?? 'mechanic'
      ]);

      $tokenStmt = $db->prepare("UPDATE users SET user_token=?, exp_token=? WHERE id = ?");
      $tokenStmt->bind_param("ssi", $token['jwt'], $token['exp'], $user['id']);
      $tokenStmt->execute();

      $responseBody = [
        'id' => $user['id'],
        'token' => $token['jwt'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'] ?? '',
        'nickname' => $user['nickname'],
        'role' => $user['role'] ?? 'mechanic'
      ];

      $fullName = trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''));
      $this->jsonResponse($responseBody, 200, 'Bienvenido ' . $fullName, $total);
    }

    $this->jsonResponse([], 401, 'Los datos ingresados son incorrectos', $total);
  }
}
