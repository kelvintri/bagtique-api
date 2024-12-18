<?php
error_log('=== User Profile API Request ===');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

// Set error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    // CORS Headers
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: http://bananina.test');
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed', 405);
    }

    // Require authentication
    $user_id = AuthMiddleware::authenticate();

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Get user profile
    $stmt = $conn->prepare("
        SELECT 
            id,
            email,
            full_name,
            phone,
            created_at,
            updated_at
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found', 404);
    }

    // Get default addresses
    $stmt = $conn->prepare("
        SELECT * FROM addresses 
        WHERE user_id = ? AND is_default = 1
        ORDER BY address_type
    ");
    $stmt->execute([$user_id]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $default_addresses = [
        'shipping' => null,
        'billing' => null
    ];

    foreach ($addresses as $address) {
        $default_addresses[$address['address_type']] = [
            'id' => $address['id'],
            'recipient_name' => $address['recipient_name'],
            'phone' => $address['phone'],
            'street_address' => $address['street_address'],
            'district' => $address['district'],
            'city' => $address['city'],
            'province' => $address['province'],
            'postal_code' => $address['postal_code']
        ];
    }

    // Format response
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'default_addresses' => $default_addresses,
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ]
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in user/profile.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in user/profile.php: ' . $e->getMessage());
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