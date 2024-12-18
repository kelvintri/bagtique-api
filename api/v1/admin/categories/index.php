<?php
error_log('=== Admin Categories List API Request ===');
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
    $search = $_GET['search'] ?? null;
    $sort = $_GET['sort'] ?? 'name_asc'; // name_asc, name_desc, products_asc, products_desc

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

    if ($search) {
        $where_clauses[] = '(c.name LIKE :search OR c.description LIKE :search_desc)';
        $search_param = "%$search%";
        $params[':search'] = $search_param;
        $params[':search_desc'] = $search_param;
    }

    $where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Add sorting
    $order_sql = match($sort) {
        'name_desc' => 'ORDER BY c.name DESC',
        'products_asc' => 'ORDER BY product_count ASC, c.name ASC',
        'products_desc' => 'ORDER BY product_count DESC, c.name ASC',
        default => 'ORDER BY c.name ASC'
    };

    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM categories c $where_sql";
    $stmt = $conn->prepare($count_sql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get categories with product count
    $offset = ($page - 1) * $limit;
    $sql = "SELECT 
                c.*,
                COUNT(DISTINCT p.id) as product_count,
                COUNT(DISTINCT CASE WHEN p.is_active = 1 AND p.deleted_at IS NULL THEN p.id END) as active_product_count,
                (
                    SELECT image_url 
                    FROM product_galleries pg
                    JOIN products p2 ON pg.product_id = p2.id
                    WHERE p2.category_id = c.id 
                    AND p2.is_active = 1 
                    AND p2.deleted_at IS NULL
                    AND pg.is_primary = 1
                    LIMIT 1
                ) as sample_image
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            $where_sql
            GROUP BY c.id
            $order_sql
            LIMIT :offset, :limit";

    $stmt = $conn->prepare($sql);

    // Bind all parameters
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    echo json_encode([
        'success' => true,
        'data' => [
            'categories' => array_map(function($category) {
                return [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'description' => $category['description'],
                    'sample_image' => $category['sample_image'],
                    'stats' => [
                        'total_products' => (int)$category['product_count'],
                        'active_products' => (int)$category['active_product_count']
                    ],
                    'created_at' => $category['created_at'],
                    'updated_at' => $category['updated_at']
                ];
            }, $categories),
            'pagination' => [
                'current_page' => (int)$page,
                'total_pages' => ceil($total / $limit),
                'total_records' => (int)$total,
                'limit' => (int)$limit
            ],
            'filters' => [
                'search' => $search,
                'sort' => $sort
            ]
        ]
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in admin/categories/index.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/categories/index.php: ' . $e->getMessage());
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