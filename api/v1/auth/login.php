<?php
error_log('Login endpoint hit');
error_log('Request method: ' . $_SERVER['REQUEST_METHOD']);
error_log('Raw input: ' . file_get_contents('php://input'));

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
use Firebase\JWT\JWT;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://bananina.test');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Validate input
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data');
    }
    
    if (!isset($data['email']) || !isset($data['password'])) {
        throw new Exception('Email and password are required');
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    $db = new APIDatabase();
    $conn = $db->getConnection();
    
    // Get user with roles
    $query = "SELECT u.*, GROUP_CONCAT(r.name) as roles 
              FROM users u 
              LEFT JOIN user_roles ur ON u.id = ur.user_id 
              LEFT JOIN roles r ON ur.role_id = r.id 
              WHERE u.email = ?
              GROUP BY u.id";
              
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Database error: Failed to prepare statement');
    }
    
    $success = $stmt->execute([$data['email']]);
    if (!$success) {
        throw new Exception('Database error: Failed to execute query');
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Invalid credentials');
    }
    
    if (!password_verify($data['password'], $user['password'])) {
        // Use same message as above to prevent user enumeration
        throw new Exception('Invalid credentials');
    }
    
    // Start session only if one isn't already active
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Convert roles string to array
    $roleArray = $user['roles'] ? explode(',', $user['roles']) : [];
    
    // Store user data in session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['roles'] = $roleArray;
    $_SESSION['is_admin'] = in_array('admin', $roleArray);
    
    // Remove sensitive data before sending response
    unset($user['password']);
    $user['roles'] = $roleArray;
    
    // After successful password verification, generate JWT token
    $secret_key = "your-secret-key-here"; // Store this securely in environment variables
    $issued_at = time();
    $expiration = $issued_at + (60 * 60); // Token expires in 1 hour
    
    $payload = array(
        "iat" => $issued_at,
        "exp" => $expiration,
        "user" => [
            "id" => $user['id'],
            "email" => $user['email'],
            "roles" => $roleArray
        ]
    );
    
    $jwt = JWT::encode($payload, $secret_key, 'HS256');
    
    echo json_encode([
        'success' => true,
        'token' => $jwt,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'roles' => $roleArray,
            'is_admin' => in_array('admin', $roleArray)
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal server error occurred'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 