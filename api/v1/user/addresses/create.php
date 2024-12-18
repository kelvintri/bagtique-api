<?php
error_log('=== Create Address API Request ===');
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
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Require authentication
    $user_id = AuthMiddleware::authenticate();

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required_fields = [
        'recipient_name',
        'phone',
        'street_address',
        'district',
        'city',
        'province',
        'postal_code'
    ];

    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '$field' is required", 400);
        }
    }

    // Validate postal code format (5 digits)
    if (!preg_match('/^\d{5}$/', $data['postal_code'])) {
        throw new Exception('Postal code must be 5 digits', 400);
    }

    // Validate phone number format
    if (!preg_match('/^[0-9+]{10,15}$/', $data['phone'])) {
        throw new Exception('Invalid phone number format', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // If this is the first address, make it default
        $stmt = $conn->prepare("
            SELECT COUNT(*) as address_count 
            FROM addresses 
            WHERE user_id = ? AND address_type = ?
        ");
        $stmt->execute([$user_id, $data['address_type'] ?? 'shipping']);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['address_count'];
        $is_default = $count === 0 ? 1 : ($data['is_default'] ?? 0);

        // If setting as default, unset other defaults
        if ($is_default) {
            $stmt = $conn->prepare("
                UPDATE addresses 
                SET is_default = 0 
                WHERE user_id = ? AND address_type = ?
            ");
            $stmt->execute([$user_id, $data['address_type'] ?? 'shipping']);
        }

        // Create address
        $stmt = $conn->prepare("
            INSERT INTO addresses (
                user_id,
                address_type,
                is_default,
                recipient_name,
                phone,
                street_address,
                district,
                city,
                province,
                postal_code,
                additional_info
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $user_id,
            $data['address_type'] ?? 'shipping',
            $is_default,
            $data['recipient_name'],
            $data['phone'],
            $data['street_address'],
            $data['district'],
            $data['city'],
            $data['province'],
            $data['postal_code'],
            $data['additional_info'] ?? null
        ]);

        $address_id = $conn->lastInsertId();

        // Get the created address
        $stmt = $conn->prepare("SELECT * FROM addresses WHERE id = ?");
        $stmt->execute([$address_id]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Address created successfully',
            'data' => $address
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in addresses/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in addresses/create.php: ' . $e->getMessage());
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