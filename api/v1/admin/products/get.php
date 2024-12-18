<?php
error_log('=== Admin Get Product API Request ===');
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

    // Get and validate product ID
    $product_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$product_id) {
        throw new Exception('Invalid product ID', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Get product details
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            c.name as category_name,
            c.slug as category_slug,
            b.name as brand_name,
            b.slug as brand_slug,
            b.logo_url as brand_logo
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE p.id = ?
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new Exception('Product not found', 404);
    }

    // Get product images
    $stmt = $conn->prepare("
        SELECT * FROM product_galleries
        WHERE product_id = ?
        ORDER BY is_primary DESC, sort_order ASC
    ");
    $stmt->execute([$product_id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    echo json_encode([
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
            'images' => array_map(function($image) {
                return [
                    'url' => $image['image_url'],
                    'is_primary' => (bool)$image['is_primary'],
                    'sort_order' => (int)$image['sort_order']
                ];
            }, $images),
            'meta' => [
                'title' => $product['meta_title'],
                'description' => $product['meta_description']
            ]
        ]
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error in admin/products/get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/products/get.php: ' . $e->getMessage());
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