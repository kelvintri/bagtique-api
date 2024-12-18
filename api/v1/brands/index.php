<?php
error_log('=== Brands API Request ===');
require_once __DIR__ . '/../config/database.php';

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

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed', 405);
    }

    // Get and validate parameters
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $sort = $_GET['sort'] ?? 'name_asc';

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Build query
    $where_clauses = [];
    $params = [];

    if ($search) {
        $where_clauses[] = '(b.name LIKE :search OR b.description LIKE :search_desc)';
        $search_param = "%$search%";
        $params[':search'] = $search_param;
        $params[':search_desc'] = $search_param;
    }

    $where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Add sorting
    $order_sql = match($sort) {
        'name_desc' => 'ORDER BY b.name DESC',
        'products_high' => 'ORDER BY product_count DESC, b.name ASC',
        'products_low' => 'ORDER BY product_count ASC, b.name ASC',
        default => 'ORDER BY b.name ASC'
    };

    $query = "SELECT 
                b.id,
                b.name,
                b.slug,
                b.logo_url,
                b.description,
                COUNT(DISTINCT CASE WHEN p.is_active = 1 AND p.deleted_at IS NULL THEN p.id END) as product_count
              FROM brands b
              LEFT JOIN products p ON b.id = p.brand_id
              $where_sql
              GROUP BY b.id
              $order_sql";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare query', 500);
    }

    // Bind search parameters if they exist
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $success = $stmt->execute();
    if (!$success) {
        throw new Exception('Failed to execute query', 500);
    }

    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $response = [
        'success' => true,
        'data' => array_map(function($brand) {
            return [
                'id' => $brand['id'],
                'name' => $brand['name'],
                'slug' => $brand['slug'],
                'logo_url' => $brand['logo_url'],
                'description' => $brand['description'],
                'product_count' => (int)$brand['product_count']
            ];
        }, $brands)
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in brands/index.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in brands/index.php: ' . $e->getMessage());
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