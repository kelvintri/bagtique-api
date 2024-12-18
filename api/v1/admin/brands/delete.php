<?php
error_log('=== Admin Delete Brand API Request ===');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../middleware/admin.php';

// Set error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    // CORS Headers
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: http://bananina.test');
    header('Access-Control-Allow-Methods: DELETE');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception('Method not allowed', 405);
    }

    // Require admin authentication
    AdminMiddleware::authenticate();

    // Get and validate brand ID
    $brand_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$brand_id) {
        throw new Exception('Invalid brand ID', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    try {
        // Start transaction
        $conn->beginTransaction();

        // Check if brand exists and get its details
        $stmt = $conn->prepare("SELECT * FROM brands WHERE id = ?");
        $stmt->execute([$brand_id]);
        $brand = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$brand) {
            throw new Exception('Brand not found', 404);
        }

        // Check if brand has any products
        $stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE brand_id = ?");
        $stmt->execute([$brand_id]);
        $product_count = $stmt->fetchColumn();

        if ($product_count > 0) {
            throw new Exception('Cannot delete brand with associated products', 400);
        }

        // Delete brand
        $stmt = $conn->prepare("DELETE FROM brands WHERE id = ?");
        $stmt->execute([$brand_id]);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Brand "%s" has been deleted successfully',
                $brand['name']
            ),
            'data' => [
                'id' => $brand_id,
                'name' => $brand['name'],
                'is_deleted' => true
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        if ($conn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in admin/brands/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/brands/delete.php: ' . $e->getMessage());
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