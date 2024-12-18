<?php
error_log('=== Create Order API Request ===');
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
    $user = AuthMiddleware::authenticate();
    $user_id = $user['id'];

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['address_id']) || !filter_var($data['address_id'], FILTER_VALIDATE_INT)) {
        throw new Exception('Valid shipping address is required', 400);
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

        // Get user's cart items
        $stmt = $conn->prepare("
            SELECT c.*, 
                   p.name as product_name,
                   p.price,
                   p.sale_price,
                   p.stock,
                   p.weight
            FROM cart c
            JOIN products p ON c.product_id = p.id
            WHERE c.user_id = ? AND p.is_active = 1 AND p.deleted_at IS NULL
        ");
        $stmt->execute([$user_id]);
        $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cart_items)) {
            throw new Exception('Cart is empty', 400);
        }

        // Calculate totals
        $subtotal = 0;
        $total_weight = 0;
        $total_items = 0;
        foreach ($cart_items as $item) {
            $price = $item['sale_price'] ?? $item['price'];
            $subtotal += $price * $item['quantity'];
            $total_weight += $item['weight'] * $item['quantity'];
            $total_items += $item['quantity'];
        }

        // Calculate shipping cost (50000 IDR per item)
        $shipping_cost = $total_items * 50000;

        // Calculate total
        $total = $subtotal + $shipping_cost;

        // Create order
        $stmt = $conn->prepare("
            INSERT INTO orders (
                user_id,
                address_id,
                subtotal,
                shipping_cost,
                total,
                status,
                created_at
            ) VALUES (
                :user_id,
                :address_id,
                :subtotal,
                :shipping_cost,
                :total,
                'pending',
                CURRENT_TIMESTAMP
            )
        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':address_id' => $data['address_id'],
            ':subtotal' => $subtotal,
            ':shipping_cost' => $shipping_cost,
            ':total' => $total
        ]);

        $order_id = $conn->lastInsertId();

        // Create order items
        $stmt = $conn->prepare("
            INSERT INTO order_items (
                order_id,
                product_id,
                quantity,
                price,
                subtotal
            ) VALUES (
                :order_id,
                :product_id,
                :quantity,
                :price,
                :subtotal
            )
        ");

        foreach ($cart_items as $item) {
            $price = $item['sale_price'] ?? $item['price'];
            $item_subtotal = $price * $item['quantity'];

            $stmt->execute([
                ':order_id' => $order_id,
                ':product_id' => $item['product_id'],
                ':quantity' => $item['quantity'],
                ':price' => $price,
                ':subtotal' => $item_subtotal
            ]);

            // Update product stock
            $new_stock = $item['stock'] - $item['quantity'];
            if ($new_stock < 0) {
                throw new Exception("Insufficient stock for product: {$item['product_name']}", 400);
            }

            $update_stock = $conn->prepare("
                UPDATE products 
                SET stock = stock - :quantity 
                WHERE id = :product_id
            ");
            $update_stock->execute([
                ':quantity' => $item['quantity'],
                ':product_id' => $item['product_id']
            ]);
        }

        // Clear user's cart
        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt->execute([$user_id]);

        // Get order details
        $stmt = $conn->prepare("
            SELECT o.*,
                   a.recipient_name,
                   a.phone,
                   a.address_line1,
                   a.address_line2,
                   a.city,
                   a.postal_code,
                   a.state,
                   a.country
            FROM orders o
            JOIN addresses a ON o.address_id = a.id
            WHERE o.id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get order items
        $stmt = $conn->prepare("
            SELECT oi.*,
                   p.name as product_name,
                   p.slug as product_slug,
                   (SELECT image_url FROM product_galleries WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

        // Format response
        $response = [
            'success' => true,
            'message' => 'Order created successfully',
            'data' => [
                'order' => [
                    'id' => $order['id'],
                    'status' => $order['status'],
                    'subtotal' => (float)$order['subtotal'],
                    'shipping_cost' => (float)$order['shipping_cost'],
                    'total' => (float)$order['total'],
                    'created_at' => $order['created_at'],
                    'shipping_address' => [
                        'recipient_name' => $order['recipient_name'],
                        'phone' => $order['phone'],
                        'address_line1' => $order['address_line1'],
                        'address_line2' => $order['address_line2'],
                        'city' => $order['city'],
                        'postal_code' => $order['postal_code'],
                        'state' => $order['state'],
                        'country' => $order['country']
                    ],
                    'items' => array_map(function($item) {
                        return [
                            'product_id' => $item['product_id'],
                            'product_name' => $item['product_name'],
                            'product_slug' => $item['product_slug'],
                            'product_image' => $item['product_image'],
                            'quantity' => (int)$item['quantity'],
                            'price' => (float)$item['price'],
                            'subtotal' => (float)$item['subtotal']
                        ];
                    }, $order_items)
                ]
            ]
        ];

        echo json_encode($response, JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in orders/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in orders/create.php: ' . $e->getMessage());
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