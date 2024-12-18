<?php
error_log('=== Change Password API Request ===');
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
    if (!isset($data['current_password']) || trim($data['current_password']) === '') {
        throw new Exception('Current password is required', 400);
    }

    if (!isset($data['new_password']) || trim($data['new_password']) === '') {
        throw new Exception('New password is required', 400);
    }

    if (!isset($data['confirm_password']) || $data['new_password'] !== $data['confirm_password']) {
        throw new Exception('Passwords do not match', 400);
    }

    // Validate password strength
    if (strlen($data['new_password']) < 8) {
        throw new Exception('Password must be at least 8 characters long', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Get current user
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify current password
    if (!password_verify($data['current_password'], $user['password'])) {
        throw new Exception('Current password is incorrect', 400);
    }

    // Update password
    $new_password_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("
        UPDATE users 
        SET password = ?
        WHERE id = ?
    ");
    $stmt->execute([$new_password_hash, $user_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Password updated successfully'
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in profile/password.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in profile/password.php: ' . $e->getMessage());
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