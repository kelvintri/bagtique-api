<?php
error_log('=== Admin Delete Category API Request ===');
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

    // Get and validate category ID
    $category_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$category_id) {
        throw new Exception('Invalid category ID', 400);
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

        // Check if category exists and get its details
        $stmt = $conn->prepare("
            SELECT c.*, 
                   COUNT(DISTINCT p.id) as product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            WHERE c.id = ?
            GROUP BY c.id
        ");
        $stmt->execute([$category_id]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            throw new Exception('Category not found', 404);
        }

        // Check if category has products
        if ($category['product_count'] > 0) {
            throw new Exception('Cannot delete category with associated products', 400);
        }

        // Delete category
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$category_id]);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Category "%s" has been deleted successfully',
                $category['name']
            ),
            'data' => [
                'id' => $category_id,
                'name' => $category['name'],
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
    error_log('Database error in admin/categories/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/categories/delete.php: ' . $e->getMessage());
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