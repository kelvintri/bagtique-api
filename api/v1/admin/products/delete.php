<?php
error_log('=== Admin Delete Product API Request ===');
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
    header('Access-Control-Allow-Methods: DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
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

    // Get and validate product ID
    $product_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$product_id) {
        throw new Exception('Invalid product ID', 400);
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

        // Check if product exists and get its details
        $stmt = $conn->prepare("
            SELECT p.*, c.slug as category_slug 
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id = ?
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception('Product not found', 404);
        }

        // Check if product has any orders
        $stmt = $conn->prepare("
            SELECT COUNT(*) as order_count 
            FROM order_items 
            WHERE product_id = ?
        ");
        $stmt->execute([$product_id]);
        $order_count = $stmt->fetch(PDO::FETCH_ASSOC)['order_count'];

        if ($order_count > 0) {
            // If product has orders, just mark it as inactive instead of deleting
            $stmt = $conn->prepare("
                UPDATE products 
                SET is_active = 0, 
                    updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$product_id]);

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Product has existing orders. It has been deactivated instead of deleted.',
                'data' => [
                    'id' => $product_id,
                    'is_deactivated' => true
                ]
            ]);
            exit;
        }

        // Get product images for deletion
        $stmt = $conn->prepare("SELECT image_url FROM product_galleries WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $images = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete product images from storage
        foreach ($images as $image_url) {
            $file_path = ROOT_PATH . '/public' . $image_url;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        // Delete product gallery entries
        $stmt = $conn->prepare("DELETE FROM product_galleries WHERE product_id = ?");
        $stmt->execute([$product_id]);

        // Delete product
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$product_id]);

        // Commit transaction
        $conn->commit();

        // Log success
        error_log('Product deleted successfully: ' . print_r([
            'id' => $product_id,
            'name' => $product['name'],
            'images_deleted' => count($images)
        ], true));

        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Product "%s" and its %d images have been deleted successfully',
                $product['name'],
                count($images)
            ),
            'data' => [
                'id' => $product_id,
                'is_deleted' => true
            ]
        ]);

    } catch (Exception $e) {
        if ($conn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in admin/products/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/products/delete.php: ' . $e->getMessage());
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