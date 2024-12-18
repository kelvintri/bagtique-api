<?php
error_log('=== Update Profile API Request ===');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

// Set error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    // CORS Headers
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: http://bananina.test');
    header('Access-Control-Allow-Methods: PUT');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Method not allowed', 405);
    }

    // Require authentication
    $user_id = AuthMiddleware::authenticate();

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['full_name']) || trim($data['full_name']) === '') {
        throw new Exception('Full name is required', 400);
    }

    if (!isset($data['phone']) || trim($data['phone']) === '') {
        throw new Exception('Phone number is required', 400);
    }

    // Validate phone format
    if (!preg_match('/^[0-9+]{10,15}$/', $data['phone'])) {
        throw new Exception('Invalid phone number format', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Update user profile
    $stmt = $conn->prepare("
        UPDATE users 
        SET full_name = ?, phone = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $data['full_name'],
        $data['phone'],
        $user_id
    ]);

    // Get updated profile
    $stmt = $conn->prepare("
        SELECT id, email, full_name, phone, created_at, updated_at
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'data' => $user
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in profile/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in profile/update.php: ' . $e->getMessage());
    $status_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    http_response_code($status_code);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'request_error',
            'message' => $e->getMessage()
        ]
    ]);
} finally {
    restore_error_handler();
} 