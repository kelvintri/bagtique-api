<?php
error_log('=== Orders List API Request ===');
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

    // After authentication
    error_log('User ID: ' . $user_id);

    // Get query parameters
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT);
    $status = $_GET['status'] ?? null;

    if ($page < 1) $page = 1;
    if ($limit < 1 || $limit > 50) $limit = 10;

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Build query
    $where_clauses = ['o.user_id = :user_id'];
    $params = [':user_id' => $user_id];

    if ($status) {
        $where_clauses[] = 'o.status = :status';
        $params[':status'] = $status;
    }

    $where_sql = implode(' AND ', $where_clauses);
    $offset = ($page - 1) * $limit;

    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM orders o WHERE $where_sql";
    $stmt = $conn->prepare($count_sql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get orders
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.user_id,
                o.shipping_address_id,
                o.total_amount,
                o.shipping_cost,
                o.payment_method,
                o.status,
                o.created_at,
                o.updated_at,
                a.recipient_name,
                a.phone,
                a.street_address,
                a.district,
                a.city,
                a.province,
                a.postal_code
            FROM orders o
            LEFT JOIN addresses a ON o.shipping_address_id = a.id
            WHERE $where_sql
            ORDER BY o.created_at DESC
            LIMIT :offset, :limit";

    // Convert limit and offset to integers
    $limit = (int)$limit;
    $offset = (int)$offset;

    $stmt = $conn->prepare($sql);

    // Bind all parameters
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order items if we have orders
    $items_by_order = [];
    if (!empty($orders)) {
        $order_ids = array_column($orders, 'id');
        $items_sql = "SELECT 
            oi.*,
            p.name as product_name,
            (SELECT image_url FROM product_galleries WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id IN (" . implode(',', $order_ids) . ")";
        
        $stmt = $conn->prepare($items_sql);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group items by order_id
        foreach ($items as $item) {
            $items_by_order[$item['order_id']][] = [
                'id' => $item['id'],
                'product_id' => $item['product_id'],
                'quantity' => (int)$item['quantity'],
                'price' => (float)$item['price'],
                'product_name' => $item['product_name'],
                'product_image' => $item['product_image']
            ];
        }
    }

    // Format response
    $total_pages = ceil($total / $limit);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'orders' => array_map(function($order) use ($items_by_order) {
                return [
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
                    'items' => $items_by_order[$order['id']] ?? [],
                    'total_items' => count($items_by_order[$order['id']] ?? []),
                    'total_quantity' => array_sum(array_column($items_by_order[$order['id']] ?? [], 'quantity')),
                    'created_at' => $order['created_at'],
                    'updated_at' => $order['updated_at']
                ];
            }, $orders),
            'pagination' => [
                'current_page' => (int)$page,
                'total_pages' => $total_pages,
                'total_records' => (int)$total,
                'limit' => (int)$limit
            ]
        ]
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in orders/index.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in orders/index.php: ' . $e->getMessage());
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