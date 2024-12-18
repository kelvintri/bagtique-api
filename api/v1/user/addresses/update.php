<?php
error_log('=== Update Address API Request ===');
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
    header('Access-Control-Allow-Methods: PUT, OPTIONS');
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
    
    if (!isset($data['address_id'])) {
        throw new Exception('Address ID is required', 400);
    }

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
        // Verify address ownership
        $stmt = $conn->prepare("
            SELECT * FROM addresses 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$data['address_id'], $user_id]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$address) {
            throw new Exception('Address not found', 404);
        }

        // If setting as default, unset other defaults
        if (!empty($data['is_default'])) {
            $stmt = $conn->prepare("
                UPDATE addresses 
                SET is_default = 0 
                WHERE user_id = ? AND address_type = ?
            ");
            $stmt->execute([$user_id, $data['address_type'] ?? $address['address_type']]);
        }

        // Update address
        $stmt = $conn->prepare("
            UPDATE addresses SET
                recipient_name = ?,
                phone = ?,
                street_address = ?,
                district = ?,
                city = ?,
                province = ?,
                postal_code = ?,
                additional_info = ?,
                address_type = ?,
                is_default = ?
            WHERE id = ? AND user_id = ?
        ");

        $stmt->execute([
            $data['recipient_name'],
            $data['phone'],
            $data['street_address'],
            $data['district'],
            $data['city'],
            $data['province'],
            $data['postal_code'],
            $data['additional_info'] ?? null,
            $data['address_type'] ?? $address['address_type'],
            isset($data['is_default']) ? (int)$data['is_default'] : 0,
            $data['address_id'],
            $user_id
        ]);

        // Get updated address
        $stmt = $conn->prepare("SELECT * FROM addresses WHERE id = ?");
        $stmt->execute([$data['address_id']]);
        $updated_address = $stmt->fetch(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Address updated successfully',
            'data' => $updated_address
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in addresses/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in addresses/update.php: ' . $e->getMessage());
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