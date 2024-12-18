<?php
error_log('=== Delete Address API Request ===');
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
    header('Access-Control-Allow-Methods: DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception('Method not allowed', 405);
    }

    // Require authentication
    $user_id = AuthMiddleware::authenticate();

    // Get and validate address_id
    $address_id = filter_var($_GET['address_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$address_id) {
        throw new Exception('Invalid address ID', 400);
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
        // Verify address ownership and get details
        $stmt = $conn->prepare("
            SELECT * FROM addresses 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$address_id, $user_id]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$address) {
            throw new Exception('Address not found', 404);
        }

        // Check if this is the only address
        $stmt = $conn->prepare("
            SELECT COUNT(*) as address_count 
            FROM addresses 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['address_count'];

        if ($count === 1) {
            throw new Exception('Cannot delete the only address', 400);
        }

        // If deleting default address, make another address default
        if ($address['is_default']) {
            $stmt = $conn->prepare("
                UPDATE addresses 
                SET is_default = 1 
                WHERE user_id = ? 
                AND id != ? 
                AND address_type = ?
                LIMIT 1
            ");
            $stmt->execute([$user_id, $address_id, $address['address_type']]);
        }

        // Delete address
        $stmt = $conn->prepare("
            DELETE FROM addresses 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$address_id, $user_id]);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Address deleted successfully'
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in addresses/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in addresses/delete.php: ' . $e->getMessage());
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