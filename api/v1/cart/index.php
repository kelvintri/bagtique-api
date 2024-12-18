<?php
error_log('=== Cart API Request ===');
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

    // Get cart items
    $query = "SELECT 
                c.id as cart_id,
                c.quantity,
                p.id as product_id,
                p.name,
                p.slug,
                p.price,
                p.sale_price,
                p.stock,
                (SELECT image_url FROM product_galleries 
                 WHERE product_id = p.id AND is_primary = 1 
                 LIMIT 1) as primary_image
              FROM cart c
              JOIN products p ON c.product_id = p.id
              WHERE c.user_id = ?
              AND p.is_active = 1
              AND p.deleted_at IS NULL";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare query', 500);
    }

    $success = $stmt->execute([$user_id]);
    if (!$success) {
        throw new Exception('Failed to execute query', 500);
    }

    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize response for empty cart
    if (empty($cart_items)) {
        echo json_encode([
            'success' => true,
            'data' => [
                'items' => [],
                'totals' => [
                    'subtotal' => 0,
                    'items_count' => 0,
                    'items_quantity' => 0
                ]
            ]
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Calculate totals
    $subtotal = 0;
    $items = array_map(function($item) use (&$subtotal) {
        $price = $item['sale_price'] ?? $item['price'];
        $item_total = $price * $item['quantity'];
        $subtotal += $item_total;

        return [
            'id' => $item['cart_id'],
            'product' => [
                'id' => $item['product_id'],
                'name' => $item['name'],
                'slug' => $item['slug'],
                'price' => (float)$item['price'],
                'sale_price' => $item['sale_price'] ? (float)$item['sale_price'] : null,
                'stock' => (int)$item['stock'],
                'image' => $item['primary_image']
            ],
            'quantity' => (int)$item['quantity'],
            'total' => $item_total
        ];
    }, $cart_items);

    // Format response
    $response = [
        'success' => true,
        'data' => [
            'items' => $items,
            'totals' => [
                'subtotal' => $subtotal,
                'items_count' => count($items),
                'items_quantity' => array_sum(array_column($cart_items, 'quantity'))
            ]
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in cart/index.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in cart/index.php: ' . $e->getMessage());
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