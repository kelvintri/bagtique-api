<?php
error_log('=== Admin Products List API Request ===');
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
    $category_id = filter_var($_GET['category'] ?? null, FILTER_VALIDATE_INT);
    $brand_id = filter_var($_GET['brand'] ?? null, FILTER_VALIDATE_INT);
    $status = $_GET['status'] ?? null;
    $sort = $_GET['sort'] ?? 'newest';
    $min_price = filter_var($_GET['min_price'] ?? null, FILTER_VALIDATE_FLOAT);
    $max_price = filter_var($_GET['max_price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock_status = $_GET['stock_status'] ?? null; // in_stock, low_stock, out_of_stock

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
        $where_clauses[] = '(p.name LIKE :search OR p.description LIKE :search_desc OR p.sku LIKE :search_sku)';
        $search_param = "%$search%";
        $params[':search'] = $search_param;
        $params[':search_desc'] = $search_param;
        $params[':search_sku'] = $search_param;
    }

    if ($category_id) {
        $where_clauses[] = 'p.category_id = :category_id';
        $params[':category_id'] = $category_id;
    }

    if ($brand_id) {
        $where_clauses[] = 'p.brand_id = :brand_id';
        $params[':brand_id'] = $brand_id;
    }

    if ($status !== null) {
        if ($status === 'active') {
            $where_clauses[] = 'p.is_active = 1 AND p.deleted_at IS NULL';
        } else if ($status === 'inactive') {
            $where_clauses[] = '(p.is_active = 0 OR p.deleted_at IS NOT NULL)';
        }
    } else {
        // Default to showing only active, non-deleted products
        $where_clauses[] = 'p.is_active = 1 AND p.deleted_at IS NULL';
    }

    // Only add price filters if values are provided
    if ($min_price !== null && $min_price !== false) {
        $where_clauses[] = 'p.price >= :min_price';
        $params[':min_price'] = $min_price;
    }

    if ($max_price !== null && $max_price !== false) {
        $where_clauses[] = 'p.price <= :max_price';
        $params[':max_price'] = $max_price;
    }

    if ($stock_status) {
        switch ($stock_status) {
            case 'out_of_stock':
                $where_clauses[] = 'p.stock = 0';
                break;
            case 'low_stock':
                $where_clauses[] = 'p.stock > 0 AND p.stock <= 5';
                break;
            case 'in_stock':
                $where_clauses[] = 'p.stock > 5';
                break;
        }
    }

    $where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Add debug logging for WHERE clause
    error_log('WHERE clause: ' . $where_sql);

    // Add sorting
    $order_sql = match($sort) {
        'price_low' => 'ORDER BY p.price ASC',
        'price_high' => 'ORDER BY p.price DESC',
        'name_asc' => 'ORDER BY p.name ASC',
        'name_desc' => 'ORDER BY p.name DESC',
        'stock_low' => 'ORDER BY p.stock ASC',
        'stock_high' => 'ORDER BY p.stock DESC',
        'oldest' => 'ORDER BY p.created_at ASC',
        default => 'ORDER BY p.created_at DESC'
    };

    // Get total count
    $count_sql = "SELECT COUNT(*) as total 
                  FROM products p 
                  LEFT JOIN categories c ON p.category_id = c.id
                  LEFT JOIN brands b ON p.brand_id = b.id
                  $where_sql";

    // Log count query
    error_log('Count Query: ' . $count_sql);
    error_log('Count Parameters: ' . print_r($params, true));

    $stmt = $conn->prepare($count_sql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Log total count
    error_log('Total records found: ' . $total);

    // Get products
    $offset = ($page - 1) * $limit;
    $sql = "SELECT 
                p.*,
                c.name as category_name,
                c.slug as category_slug,
                b.name as brand_name,
                b.slug as brand_slug,
                b.logo_url as brand_logo,
                COALESCE((SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = p.id), 0) as total_orders,
                COALESCE((SELECT SUM(quantity) FROM order_items oi WHERE oi.product_id = p.id), 0) as total_sold
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            $where_sql
            $order_sql
            LIMIT :offset, :limit";

    // Log main query
    error_log('Main Query: ' . $sql);
    error_log('Main Query Parameters: ' . print_r(array_merge($params, [
        ':offset' => $offset,
        ':limit' => $limit
    ]), true));

    $stmt = $conn->prepare($sql);

    // Bind all parameters
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug logging
    error_log('SQL Query: ' . preg_replace('/\s+/', ' ', $sql)); // Clean up whitespace for readability
    error_log('WHERE Clause: ' . $where_sql);
    error_log('Order Clause: ' . $order_sql);
    error_log('Parameters: ' . print_r(array_merge($params, [
        ':offset' => $offset,
        ':limit' => $limit
    ]), true));
    error_log('Found Products Count: ' . count($products));

    if (!empty($products)) {
        error_log('First Product: ' . print_r($products[0], true));
    } else {
        error_log('No products found. Checking database directly...');
        // Check for any products
        $check_sql = "SELECT COUNT(*) as count FROM products WHERE deleted_at IS NULL AND is_active = 1";
        $stmt = $conn->prepare($check_sql);
        $stmt->execute();
        $check_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        error_log('Total active, non-deleted products in database: ' . $check_count);
    }

    // After getting products and before formatting response, initialize images_by_product
    $images_by_product = [];

    // Get product images
    if (!empty($products)) {
        $product_ids = array_column($products, 'id');
        $images_sql = "SELECT 
            product_id,
            image_url,
            is_primary,
            sort_order
        FROM product_galleries
        WHERE product_id IN (" . implode(',', $product_ids) . ")
        ORDER BY is_primary DESC, sort_order ASC";
        
        $stmt = $conn->prepare($images_sql);
        $stmt->execute();
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group images by product
        foreach ($images as $image) {
            $images_by_product[$image['product_id']][] = [
                'url' => $image['image_url'],
                'is_primary' => (bool)$image['is_primary'],
                'sort_order' => (int)$image['sort_order']
            ];
        }
    }

    // After building the query, add debug logging
    error_log('Query: ' . $sql);
    error_log('Parameters: ' . print_r($params, true));
    error_log('Offset: ' . $offset);
    error_log('Limit: ' . $limit);

    // After executing the query
    error_log('Found products: ' . print_r($products, true));

    // After building where clause
    error_log('Status filter: ' . ($status ?? 'default'));
    error_log('Final WHERE clause: ' . $where_sql);
    error_log('Final parameters: ' . print_r($params, true));

    // Format response
    echo json_encode([
        'success' => true,
        'data' => [
            'products' => array_map(function($product) use ($images_by_product) {
                return [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'slug' => $product['slug'],
                    'description' => $product['description'],
                    'details' => $product['details'],
                    'price' => (float)$product['price'],
                    'sale_price' => $product['sale_price'] ? (float)$product['sale_price'] : null,
                    'stock' => (int)$product['stock'],
                    'sku' => $product['sku'],
                    'condition' => $product['condition_status'],
                    'is_active' => (bool)$product['is_active'],
                    'category' => [
                        'id' => $product['category_id'],
                        'name' => $product['category_name'],
                        'slug' => $product['category_slug']
                    ],
                    'brand' => [
                        'id' => $product['brand_id'],
                        'name' => $product['brand_name'],
                        'slug' => $product['brand_slug'],
                        'logo' => $product['brand_logo']
                    ],
                    'images' => $images_by_product[$product['id']] ?? [],
                    'stats' => [
                        'total_orders' => (int)$product['total_orders'],
                        'total_sold' => (int)$product['total_sold']
                    ],
                    'meta' => [
                        'title' => $product['meta_title'],
                        'description' => $product['meta_description']
                    ],
                    'created_at' => $product['created_at'],
                    'updated_at' => $product['updated_at'],
                    'deleted_at' => $product['deleted_at']
                ];
            }, $products),
            'pagination' => [
                'current_page' => (int)$page,
                'total_pages' => ceil($total / $limit),
                'total_records' => (int)$total,
                'limit' => (int)$limit
            ],
            'filters' => [
                'search' => $search,
                'category_id' => $category_id,
                'brand_id' => $brand_id,
                'status' => $status,
                'stock_status' => $stock_status,
                'price_range' => [
                    'min' => $min_price,
                    'max' => $max_price
                ],
                'sort' => $sort
            ]
        ]
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in admin/products/index.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/products/index.php: ' . $e->getMessage());
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