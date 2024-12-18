<?php
require_once __DIR__ . '/../config/database.php';

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
    
    // Validate required fields
    $required_fields = ['username', 'email', 'password', 'full_name', 'phone'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("$field is required");
        }
    }

    // Validate email format
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    // Validate password strength
    if (strlen($data['password']) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }

    // Validate phone number format (basic validation)
    if (!preg_match('/^[0-9+]{10,15}$/', $data['phone'])) {
        throw new Exception('Invalid phone number format');
    }

    // Add username validation after the required fields check
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $data['username'])) {
        throw new Exception('Username must be 3-20 characters long and can only contain letters, numbers, and underscores');
    }

    $db = new APIDatabase();
    $conn = $db->getConnection();

    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            throw new Exception('Email already exists');
        }

        // Check if username exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$data['username']]);
        if ($stmt->fetch()) {
            throw new Exception('Username already exists');
        }

        // Hash password
        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Insert new user
        $query = "INSERT INTO users (username, email, password, full_name, phone) 
                 VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->execute([
            $data['username'],
            $data['email'],
            $hashed_password,
            $data['full_name'],
            $data['phone']
        ]);
        
        $user_id = $conn->lastInsertId();

        // Get customer role ID
        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = 'customer'");
        $stmt->execute();
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$role) {
            throw new Exception('Customer role not found');
        }

        // Assign customer role
        $stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $role['id']]);

        // Commit transaction
        $conn->commit();

        // Start session for the new user
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['user_id'] = $user_id;
        $_SESSION['email'] = $data['email'];
        $_SESSION['roles'] = ['customer'];
        $_SESSION['is_admin'] = false;
        
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful',
            'user' => [
                'id' => $user_id,
                'username' => $data['username'],
                'email' => $data['email'],
                'full_name' => $data['full_name'],
                'roles' => ['customer'],
                'is_admin' => false
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        throw $e;
    }
    
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