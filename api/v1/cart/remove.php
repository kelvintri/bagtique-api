<?php
error_log('=== Remove from Cart API Request ===');
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

    // Get and validate input
    $cart_id = filter_var($_GET['cart_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$cart_id || $cart_id <= 0) {
        throw new Exception('Invalid cart ID', 400);
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
        // Verify cart item ownership
        $stmt = $conn->prepare("
            SELECT id 
            FROM cart 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$cart_id, $user_id]);
        $cart_item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cart_item) {
            throw new Exception('Cart item not found', 404);
        }

        // Delete cart item
        $stmt = $conn->prepare("
            DELETE FROM cart 
            WHERE id = ?
        ");
        $stmt->execute([$cart_id]);

        // Commit transaction
        $conn->commit();

        // Get updated cart totals
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as items_count,
                SUM(quantity) as total_items
            FROM cart
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $cart_totals = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Item removed from cart',
            'data' => [
                'cart_count' => (int)($cart_totals['total_items'] ?? 0),
                'items_count' => (int)($cart_totals['items_count'] ?? 0)
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in cart/remove.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in cart/remove.php: ' . $e->getMessage());
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