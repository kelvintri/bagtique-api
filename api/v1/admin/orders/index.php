<?php
error_log('=== Admin Orders List API Request ===');
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

    // Require admin authentication
    AdminMiddleware::authenticate();

    // Get query parameters
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT);
    $status = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;

    if ($page < 1) $page = 1;
    if ($limit < 1 || $limit > 50) $limit = 10;

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Build query
    $where_clauses = [];
    $params = [];

    if ($status) {
        $where_clauses[] = 'o.status = :status';
        $params[':status'] = $status;
    }

    if ($search) {
        $where_clauses[] = '(o.order_number LIKE :search OR u.email LIKE :search_email OR u.full_name LIKE :search_name)';
        $search_param = "%$search%";
        $params[':search'] = $search_param;
        $params[':search_email'] = $search_param;
        $params[':search_name'] = $search_param;
    }

    if ($start_date) {
        $where_clauses[] = 'DATE(o.created_at) >= :start_date';
        $params[':start_date'] = $start_date;
    }

    if ($end_date) {
        $where_clauses[] = 'DATE(o.created_at) <= :end_date';
        $params[':end_date'] = $end_date;
    }

    $where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    $offset = ($page - 1) * $limit;

    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM orders o 
                  LEFT JOIN users u ON o.user_id = u.id 
                  $where_sql";
    $stmt = $conn->prepare($count_sql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get orders
    $sql = "SELECT 
                o.*,
                u.email as user_email,
                u.full_name as user_name,
                u.phone as user_phone,
                pd.transfer_proof_url,
                pd.payment_date,
                pd.verified_at,
                a.recipient_name,
                a.phone,
                a.street_address,
                a.district,
                a.city,
                a.province,
                a.postal_code,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as total_items,
                (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id) as total_quantity
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN payment_details pd ON o.id = pd.order_id
            LEFT JOIN addresses a ON o.shipping_address_id = a.id
            $where_sql
            ORDER BY o.created_at DESC
            LIMIT :offset, :limit";

    $stmt = $conn->prepare($sql);

    // Bind all parameters including pagination
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);

    // Bind where clause parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order items
    $order_items = [];
    if (!empty($orders)) {
        $order_ids = array_column($orders, 'id');
        $items_sql = "SELECT 
            oi.*,
            p.name as product_name,
            p.slug as product_slug,
            (SELECT image_url FROM product_galleries WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id IN (" . implode(',', $order_ids) . ")";
        
        $stmt = $conn->prepare($items_sql);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $order_items[$item['order_id']][] = [
                'id' => $item['id'],
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'product_slug' => $item['product_slug'],
                'product_image' => $item['product_image'],
                'quantity' => (int)$item['quantity'],
                'price' => (float)$item['price']
            ];
        }
    }

    // Format response
    $total_pages = ceil($total / $limit);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'orders' => array_map(function($order) use ($order_items) {
                return [
                    'id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'user' => [
                        'id' => $order['user_id'],
                        'email' => $order['user_email'],
                        'name' => $order['user_name'],
                        'phone' => $order['user_phone']
                    ],
                    'status' => $order['status'],
                    'total_amount' => (float)$order['total_amount'],
                    'shipping_cost' => (float)$order['shipping_cost'],
                    'payment_method' => $order['payment_method'],
                    'payment' => [
                        'transfer_proof_url' => $order['transfer_proof_url'],
                        'payment_date' => $order['payment_date'],
                        'verified_at' => $order['verified_at']
                    ],
                    'shipping_address' => [
                        'recipient_name' => $order['recipient_name'],
                        'phone' => $order['phone'],
                        'street_address' => $order['street_address'],
                        'district' => $order['district'],
                        'city' => $order['city'],
                        'province' => $order['province'],
                        'postal_code' => $order['postal_code']
                    ],
                    'items' => $order_items[$order['id']] ?? [],
                    'total_items' => (int)$order['total_items'],
                    'total_quantity' => (int)$order['total_quantity'],
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
    error_log('Database error in admin/orders/index.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/orders/index.php: ' . $e->getMessage());
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