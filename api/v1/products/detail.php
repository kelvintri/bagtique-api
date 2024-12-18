<?php
error_log('=== Product Detail API Request ===');
error_log('Raw REQUEST_URI: ' . $_SERVER['REQUEST_URI']);
error_log('GET parameters: ' . print_r($_GET, true));

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

    // Get and validate slug
    $slug = isset($_GET['slug']) ? urldecode($_GET['slug']) : null;
    error_log('Extracted slug: ' . $slug);
    
    if (!$slug) {
        throw new Exception('Product slug is required', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    error_log('Looking for product with slug: ' . $slug);

    // Build query
    $query = "SELECT 
                p.*,
                b.name as brand_name,
                b.slug as brand_slug,
                b.logo_url as brand_logo,
                c.name as category_name,
                c.slug as category_slug
              FROM products p
              LEFT JOIN brands b ON p.brand_id = b.id
              LEFT JOIN categories c ON p.category_id = c.id
              WHERE p.slug = ?
              AND p.is_active = 1
              AND p.deleted_at IS NULL";

    // Get product details
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare query', 500);
    }

    $success = $stmt->execute([$slug]);
    if (!$success) {
        throw new Exception('Failed to execute query', 500);
    }

    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new Exception('Product not found', 404);
    }

    // Get all product images
    $imagesQuery = "SELECT 
                        image_url,
                        is_primary,
                        sort_order
                    FROM product_galleries
                    WHERE product_id = ?
                    ORDER BY is_primary DESC, sort_order ASC";
    
    $stmt = $conn->prepare($imagesQuery);
    $stmt->execute([$product['id']]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $response = [
        'success' => true,
        'data' => [
            'id' => $product['id'],
            'name' => $product['name'],
            'slug' => $product['slug'],
            'description' => $product['description'],
            'details' => $product['details'],
            'price' => (float)$product['price'],
            'sale_price' => $product['sale_price'] ? (float)$product['sale_price'] : null,
            'stock' => (int)$product['stock'],
            'condition' => $product['condition_status'],
            'sku' => $product['sku'],
            'created_at' => $product['created_at'],
            'meta' => [
                'title' => $product['meta_title'],
                'description' => $product['meta_description']
            ],
            'images' => array_map(function($img) {
                return [
                    'url' => $img['image_url'],
                    'is_primary' => (bool)$img['is_primary'],
                    'sort_order' => (int)$img['sort_order']
                ];
            }, $images),
            'brand' => [
                'name' => $product['brand_name'],
                'slug' => $product['brand_slug'],
                'logo' => $product['brand_logo']
            ],
            'category' => [
                'name' => $product['category_name'],
                'slug' => $product['category_slug']
            ]
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in products/detail.php:');
    error_log('Message: ' . $e->getMessage());
    error_log('Code: ' . $e->getCode());
    error_log('File: ' . $e->getFile() . ':' . $e->getLine());
    error_log('Trace: ' . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in products/detail.php:');
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
    restore_error_handler();
} 