<?php
error_log('=== Add to Cart API Request ===');
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
    
    if (!isset($data['product_id']) || !isset($data['quantity'])) {
        throw new Exception('Product ID and quantity are required', 400);
    }

    $product_id = filter_var($data['product_id'], FILTER_VALIDATE_INT);
    $quantity = filter_var($data['quantity'], FILTER_VALIDATE_INT);

    if (!$product_id || $product_id <= 0) {
        throw new Exception('Invalid product ID', 400);
    }

    if (!$quantity || $quantity <= 0) {
        throw new Exception('Quantity must be greater than 0', 400);
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
        // Check if product exists and is available
        $stmt = $conn->prepare("
            SELECT id, stock, is_active 
            FROM products 
            WHERE id = ? 
            AND is_active = 1 
            AND deleted_at IS NULL
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception('Product not found or unavailable', 404);
        }

        if ($product['stock'] < $quantity) {
            throw new Exception('Not enough stock available', 400);
        }

        // Check if product is already in cart
        $stmt = $conn->prepare("
            SELECT id, quantity 
            FROM cart 
            WHERE user_id = ? AND product_id = ?
        ");
        $stmt->execute([$user_id, $product_id]);
        $cart_item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cart_item) {
            // Update existing cart item
            $new_quantity = $cart_item['quantity'] + $quantity;
            if ($new_quantity > $product['stock']) {
                throw new Exception('Cannot add more items than available in stock', 400);
            }

            $stmt = $conn->prepare("
                UPDATE cart 
                SET quantity = ? 
                WHERE id = ?
            ");
            $stmt->execute([$new_quantity, $cart_item['id']]);
        } else {
            // Add new cart item
            $stmt = $conn->prepare("
                INSERT INTO cart (user_id, product_id, quantity) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$user_id, $product_id, $quantity]);
        }

        // Commit transaction
        $conn->commit();

        // Get updated cart count
        $stmt = $conn->prepare("
            SELECT SUM(quantity) as total_items
            FROM cart
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $cart_count = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Product added to cart',
            'data' => [
                'cart_count' => (int)($cart_count['total_items'] ?? 0)
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in cart/add.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in cart/add.php: ' . $e->getMessage());
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