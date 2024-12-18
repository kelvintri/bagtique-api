<?php
error_log('=== Products API Request ===');
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

    // Validate and sanitize input parameters
    $category_id = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
    $brand_id = filter_input(INPUT_GET, 'brand', FILTER_VALIDATE_INT);
    $search = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search']), ENT_QUOTES, 'UTF-8') : '';
    $sort = in_array($_GET['sort'] ?? '', ['newest', 'oldest', 'price_low', 'price_high']) 
        ? $_GET['sort'] 
        : 'newest';
    $page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1);
    $per_page = 12;

    error_log('Validated parameters:');
    error_log(json_encode([
        'category_id' => $category_id,
        'brand_id' => $brand_id,
        'search' => $search,
        'sort' => $sort,
        'page' => $page
    ]));

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Build query
    $query = "SELECT 
                p.id,
                p.name,
                p.slug,
                p.description,
                p.price,
                p.sale_price,
                p.stock,
                p.condition_status,
                p.sku,
                p.created_at,
                b.name as brand_name,
                b.slug as brand_slug,
                b.logo_url as brand_logo,
                c.name as category_name,
                c.slug as category_slug,
                (SELECT image_url 
                 FROM product_galleries 
                 WHERE product_id = p.id 
                 AND is_primary = 1 
                 LIMIT 1) as primary_image,
                (SELECT image_url 
                 FROM product_galleries 
                 WHERE product_id = p.id 
                 AND is_primary = 0 
                 ORDER BY sort_order ASC 
                 LIMIT 1) as hover_image
              FROM products p
              LEFT JOIN brands b ON p.brand_id = b.id
              LEFT JOIN categories c ON p.category_id = c.id
              WHERE p.is_active = 1 
              AND (p.deleted_at IS NULL)";
    
    $params = [];

    // Add search condition
    if ($search) {
        $query .= " AND (p.name LIKE ? OR p.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Add category filter
    if ($category_id) {
        $query .= " AND p.category_id = ?";
        $params[] = $category_id;
    }

    // Add brand filter
    if ($brand_id) {
        $query .= " AND p.brand_id = ?";
        $params[] = $brand_id;
    }

    // Add sorting
    switch ($sort) {
        case 'price_low':
            $query .= " ORDER BY p.price ASC";
            break;
        case 'price_high':
            $query .= " ORDER BY p.price DESC";
            break;
        case 'oldest':
            $query .= " ORDER BY p.created_at ASC";
            break;
        case 'newest':
        default:
            $query .= " ORDER BY p.created_at DESC";
    }

    error_log('Executing query: ' . $query);
    error_log('Parameters: ' . print_r($params, true));

    // Get total count for pagination first (before adding LIMIT)
    $countQuery = preg_replace('/SELECT.*?FROM/', 'SELECT COUNT(*) FROM', $query);
    $stmt = $conn->prepare($countQuery);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    error_log('Total records: ' . $total);

    // Add pagination to the main query
    $offset = ($page - 1) * $per_page;
    $query .= sprintf(" LIMIT %d OFFSET %d", $per_page, $offset);
    
    error_log('Final query: ' . $query);
    error_log('Final parameters: ' . print_r($params, true));

    // Validate pagination
    if ($total > 0 && $page > ceil($total / $per_page)) {
        throw new Exception('Page number exceeds available pages', 400);
    }

    // Get products
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare query', 500);
    }

    $success = $stmt->execute($params);
    if (!$success) {
        throw new Exception('Failed to execute query', 500);
    }

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($products) && $page > 1) {
        throw new Exception('No products found for this page', 404);
    }

    // Format response
    $response = [
        'success' => true,
        'data' => [
            'products' => array_map(function($product) {
                return [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'slug' => $product['slug'],
                    'description' => $product['description'],
                    'price' => (float)$product['price'],
                    'sale_price' => $product['sale_price'] ? (float)$product['sale_price'] : null,
                    'stock' => (int)$product['stock'],
                    'condition' => $product['condition_status'],
                    'sku' => $product['sku'],
                    'created_at' => $product['created_at'],
                    'images' => [
                        'primary' => $product['primary_image'],
                        'hover' => $product['hover_image']
                    ],
                    'brand' => [
                        'name' => $product['brand_name'],
                        'slug' => $product['brand_slug'],
                        'logo' => $product['brand_logo']
                    ],
                    'category' => [
                        'name' => $product['category_name'],
                        'slug' => $product['category_slug']
                    ]
                ];
            }, $products),
            'pagination' => [
                'current_page' => (int)$page,
                'per_page' => $per_page,
                'total' => (int)$total,
                'total_pages' => ceil($total / $per_page)
            ],
            'filters' => [
                'category_id' => $category_id,
                'brand_id' => $brand_id,
                'search' => $search,
                'sort' => $sort
            ]
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in products/index.php:');
    error_log('Message: ' . $e->getMessage());
    error_log('Code: ' . $e->getCode());
    error_log('File: ' . $e->getFile() . ':' . $e->getLine());
    error_log('Trace: ' . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred',
            'code' => $e->getCode()
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in products/index.php:');
    error_log('Message: ' . $e->getMessage());
    error_log('Code: ' . $e->getCode());
    error_log('File: ' . $e->getFile() . ':' . $e->getLine());
    error_log('Trace: ' . $e->getTraceAsString());

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
    // Restore error handler
    restore_error_handler();
}