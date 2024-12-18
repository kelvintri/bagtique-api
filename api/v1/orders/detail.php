<?php
error_log('=== Order Detail API Request ===');
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

    // Get and validate order ID
    $order_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$order_id) {
        throw new Exception('Invalid order ID', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Get order with shipping address
    $sql = "SELECT 
                o.*,
                a.recipient_name,
                a.phone,
                a.street_address,
                a.district,
                a.city,
                a.province,
                a.postal_code,
                pd.transfer_proof_url,
                pd.payment_date,
                pd.payment_amount,
                pd.verified_at,
                u.full_name as verified_by_name
            FROM orders o
            LEFT JOIN addresses a ON o.shipping_address_id = a.id
            LEFT JOIN payment_details pd ON o.id = pd.order_id
            LEFT JOIN users u ON pd.verified_by = u.id
            WHERE o.id = :order_id AND o.user_id = :user_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':order_id' => $order_id,
        ':user_id' => $user_id
    ]);
    
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception('Order not found', 404);
    }

    // Get order items with product details
    $items_sql = "SELECT 
        oi.*,
        p.name as product_name,
        p.slug as product_slug,
        (SELECT image_url FROM product_galleries WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = :order_id";

    $stmt = $conn->prepare($items_sql);
    $stmt->execute([':order_id' => $order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'total_amount' => (float)$order['total_amount'],
            'shipping_cost' => (float)$order['shipping_cost'],
            'payment_method' => $order['payment_method'],
            'shipping_address' => [
                'recipient_name' => $order['recipient_name'],
                'phone' => $order['phone'],
                'street_address' => $order['street_address'],
                'district' => $order['district'],
                'city' => $order['city'],
                'province' => $order['province'],
                'postal_code' => $order['postal_code']
            ],
            'payment' => [
                'status' => $order['status'],
                'amount' => (float)$order['payment_amount'],
                'transfer_proof_url' => $order['transfer_proof_url'],
                'payment_date' => $order['payment_date'],
                'verified_at' => $order['verified_at'],
                'verified_by' => $order['verified_by_name'],
                'needs_upload' => $order['status'] === 'pending_payment',
                'can_upload' => $order['status'] === 'pending_payment',
                'is_verified' => !empty($order['verified_at'])
            ],
            'items' => array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_slug' => $item['product_slug'],
                    'product_image' => $item['product_image'],
                    'quantity' => (int)$item['quantity'],
                    'price' => (float)$item['price'],
                    'total' => (float)($item['price'] * $item['quantity'])
                ];
            }, $items),
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ]
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in orders/detail.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in orders/detail.php: ' . $e->getMessage());
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