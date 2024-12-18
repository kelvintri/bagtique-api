<?php
error_log('=== Admin Create Product API Request ===');
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

    // Require admin authentication
    AdminMiddleware::authenticate();

    // Get and validate input
    $data = [];
    $data['name'] = $_POST['name'] ?? null;
    $data['category_id'] = $_POST['category_id'] ?? null;
    $data['brand_id'] = $_POST['brand_id'] ?? null;
    $data['description'] = $_POST['description'] ?? null;
    $data['details'] = $_POST['details'] ?? null;
    $data['price'] = $_POST['price'] ?? null;
    $data['sale_price'] = isset($_POST['sale_price']) && $_POST['sale_price'] !== 'null' ? $_POST['sale_price'] : null;
    $data['stock'] = $_POST['stock'] ?? null;
    $data['sku'] = $_POST['sku'] ?? null;
    $data['condition_status'] = $_POST['condition_status'] ?? 'New With Tag';
    $data['is_active'] = $_POST['is_active'] ?? '1';

    // Handle file uploads
    $data['images'] = [];
    if (isset($_FILES['images'])) {
        foreach ($_FILES['images']['tmp_name'] as $index => $tmp_name) {
            if (!empty($tmp_name)) {
                $data['images'][] = [
                    'file' => $_FILES['images']['tmp_name'][$index],
                    'name' => $_FILES['images']['name'][$index],
                    'type' => $_FILES['images']['type'][$index],
                    'is_primary' => $_POST['images'][$index]['is_primary'] ?? '0',
                    'sort_order' => $_POST['images'][$index]['sort_order'] ?? $index
                ];
            }
        }
    }

    // Debug logging
    error_log('Received POST data: ' . print_r($_POST, true));
    error_log('Received FILES data: ' . print_r($_FILES, true));
    error_log('Processed data: ' . print_r($data, true));

    // Validate required fields
    $required_fields = [
        'name' => 'Product name is required',
        'category_id' => 'Category is required',
        'brand_id' => 'Brand is required',
        'price' => 'Price is required',
        'stock' => 'Stock is required',
        'sku' => 'SKU is required'
    ];

    foreach ($required_fields as $field => $message) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            throw new Exception($message, 400);
        }
    }

    // Validate numeric fields
    if (!is_numeric($data['price']) || $data['price'] < 0) {
        throw new Exception('Invalid price', 400);
    }

    if (!is_int($data['stock']) && !ctype_digit($data['stock'])) {
        throw new Exception('Stock must be a whole number', 400);
    }

    if (isset($data['sale_price']) && $data['sale_price'] !== null) {
        if (!is_numeric($data['sale_price']) || $data['sale_price'] < 0) {
            throw new Exception('Invalid sale price', 400);
        }
        if ($data['sale_price'] >= $data['price']) {
            throw new Exception('Sale price must be less than regular price', 400);
        }
    }

    // Generate slug from name
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['name'])));

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    try {
        // Start transaction
        $conn->beginTransaction();

        // Check if slug exists
        $stmt = $conn->prepare("SELECT id FROM products WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            // Append timestamp to make slug unique
            $slug .= '-' . time();
        }

        // Check if SKU exists
        $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$data['sku']]);
        if ($stmt->fetch()) {
            throw new Exception('SKU already exists', 400);
        }

        // Verify category exists
        $stmt = $conn->prepare("SELECT id FROM categories WHERE id = ?");
        $stmt->execute([$data['category_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid category', 400);
        }

        // Verify brand exists
        $stmt = $conn->prepare("SELECT id FROM brands WHERE id = ?");
        $stmt->execute([$data['brand_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid brand', 400);
        }

        // After verifying brand exists, get brand name
        $stmt = $conn->prepare("SELECT name FROM brands WHERE id = ?");
        $stmt->execute([$data['brand_id']]);
        $brand = $stmt->fetch(PDO::FETCH_ASSOC);
        $brand_name = $brand['name'];

        // Generate meta data with brand name
        $meta_title = "{$data['name']} | {$brand_name} Bags";

        // Generate meta description with brand name
        $meta_description = "Shop {$brand_name} {$data['name']}";
        if ($data['description']) {
            // Add first sentence of description, limited to ~150 chars
            $first_sentence = strtok($data['description'], '.'); // Get first sentence
            if (strlen($first_sentence) > 150) {
                $first_sentence = substr($first_sentence, 0, 147) . '...';
            }
            $meta_description .= ". {$first_sentence}";
        }

        // Create product
        $stmt = $conn->prepare("
            INSERT INTO products (
                category_id,
                brand_id,
                name,
                slug,
                description,
                details,
                meta_title,
                meta_description,
                price,
                sale_price,
                stock,
                sku,
                condition_status,
                is_active
            ) VALUES (
                :category_id,
                :brand_id,
                :name,
                :slug,
                :description,
                :details,
                :meta_title,
                :meta_description,
                :price,
                :sale_price,
                :stock,
                :sku,
                :condition_status,
                :is_active
            )
        ");

        $stmt->execute([
            ':category_id' => $data['category_id'],
            ':brand_id' => $data['brand_id'],
            ':name' => $data['name'],
            ':slug' => $slug,
            ':description' => $data['description'] ?? null,
            ':details' => $data['details'] ?? null,
            ':meta_title' => $meta_title,
            ':meta_description' => $meta_description,
            ':price' => $data['price'],
            ':sale_price' => $data['sale_price'] ?? null,
            ':stock' => $data['stock'],
            ':sku' => $data['sku'],
            ':condition_status' => $data['condition_status'] ?? 'New With Tag',
            ':is_active' => $data['is_active'] ?? true
        ]);

        $product_id = $conn->lastInsertId();

        // After getting category details
        $stmt = $conn->prepare("SELECT name, slug FROM categories WHERE id = ?");
        $stmt->execute([$data['category_id']]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        $category_slug = $category['slug'];

        // Handle images if provided
        if (isset($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $index => $image) {
                // Get file info
                $file_info = pathinfo($image['name']['file']);
                $extension = strtolower($file_info['extension']);
                
                // Generate unique filename
                $filename = $slug . '-' . uniqid() . '.' . $extension;
                
                // Determine directory based on image type
                $type_dir = $image['is_primary'] == '1' ? 'primary' : 'hover';
                $target_dir = ROOT_PATH . "/public/assets/images/{$category_slug}/{$type_dir}/";
                
                // Create directories if they don't exist
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                // Move uploaded file to appropriate directory
                $target_path = $target_dir . $filename;
                $source_path = $image['file']['file']; // This is the temporary file path
                
                if (move_uploaded_file($source_path, $target_path)) {
                    // Generate public URL
                    $public_url = "/assets/images/{$category_slug}/{$type_dir}/" . $filename;
                    
                    // Insert into database
                    $stmt = $conn->prepare("
                        INSERT INTO product_galleries (
                            product_id,
                            image_url,
                            is_primary,
                            sort_order
                        ) VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $product_id,
                        $public_url,
                        $image['is_primary'],
                        $image['sort_order']
                    ]);
                } else {
                    throw new Exception('Failed to move image file: ' . error_get_last()['message']);
                }
            }
        }

        // Get the created product with all details
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

        // Get product images
        $stmt = $conn->prepare("
            SELECT * FROM product_galleries
            WHERE product_id = ?
            ORDER BY is_primary DESC, sort_order ASC
        ");
        $stmt->execute([$product_id]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

        // After successful commit, before response formatting
        error_log('Product created successfully: ' . print_r([
            'id' => $product_id,
            'name' => $product['name'],
            'slug' => $product['slug'],
            'sku' => $product['sku']
        ], true));

        // Format response
        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Product "%s" created successfully with SKU: %s and %d images',
                $product['name'],
                $product['sku'],
                count($images)
            ),
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
                ],
                'created_at' => $product['created_at']
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        // Only rollback if a transaction is active
        if ($conn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in admin/products/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/products/create.php: ' . $e->getMessage());
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