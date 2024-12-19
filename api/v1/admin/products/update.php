<?php
error_log('=== Admin Update Product API Request ===');
require_once __DIR__ . '/../../config/constants.php';
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
    header('Access-Control-Allow-Methods: PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Method not allowed', 405);
    }

    // Require admin authentication
    AdminMiddleware::authenticate();

    // Get and validate product ID
    $product_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$product_id) {
        throw new Exception('Invalid product ID', 400);
    }

    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data');
    }

    // Map condition to condition_status if needed
    if (isset($data['condition'])) {
        $data['condition_status'] = $data['condition'];
        unset($data['condition']);
    }

    // Debug logging
    error_log('Raw input: ' . $json);
    error_log('Decoded data: ' . print_r($data, true));

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

    // Validate condition_status
    $valid_conditions = ['New With Tag', 'New', 'Like New', 'Used'];
    if (!in_array($data['condition_status'], $valid_conditions)) {
        throw new Exception('Invalid condition status. Must be one of: ' . implode(', ', $valid_conditions));
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

        // Check if product exists
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $existing_product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing_product) {
            throw new Exception('Product not found', 404);
        }

        // Check if slug exists (excluding current product)
        $stmt = $conn->prepare("SELECT id FROM products WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $product_id]);
        if ($stmt->fetch()) {
            $slug .= '-' . time();
        }

        // Check if SKU exists (excluding current product)
        $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ? AND id != ?");
        $stmt->execute([$data['sku'], $product_id]);
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
        $stmt = $conn->prepare("SELECT name FROM brands WHERE id = ?");
        $stmt->execute([$data['brand_id']]);
        $brand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$brand) {
            throw new Exception('Invalid brand', 400);
        }

        // Generate meta data
        $meta_title = "{$data['name']} | {$brand['name']} Bags";
        $meta_description = "Shop {$brand['name']} {$data['name']}";
        if ($data['description']) {
            // Extract first sentence or first 150 characters
            $first_sentence = strtok($data['description'], '.');
            if ($first_sentence) {
                if (strlen($first_sentence) > 150) {
                    $first_sentence = substr($first_sentence, 0, 147) . '...';
                }
                $meta_description .= ". {$first_sentence}";
            } else {
                // If no sentence found, use first 150 characters
                $description_excerpt = substr($data['description'], 0, 147);
                if (strlen($data['description']) > 147) {
                    $description_excerpt .= '...';
                }
                $meta_description .= ". {$description_excerpt}";
            }
        }

        // Update product
        $stmt = $conn->prepare("
            UPDATE products SET
                category_id = :category_id,
                brand_id = :brand_id,
                name = :name,
                slug = :slug,
                description = :description,
                details = :details,
                meta_title = :meta_title,
                meta_description = :meta_description,
                price = :price,
                sale_price = :sale_price,
                stock = :stock,
                sku = :sku,
                condition_status = :condition_status,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $stmt->execute([
            ':category_id' => $data['category_id'],
            ':brand_id' => $data['brand_id'],
            ':name' => $data['name'],
            ':slug' => $slug,
            ':description' => $data['description'],
            ':details' => $data['details'],
            ':meta_title' => $meta_title,
            ':meta_description' => $meta_description,
            ':price' => $data['price'],
            ':sale_price' => $data['sale_price'] ?: null,
            ':stock' => $data['stock'],
            ':sku' => $data['sku'],
            ':condition_status' => $data['condition_status'],
            ':is_active' => $data['is_active'],
            ':id' => $product_id
        ]);

        // Handle new images if provided
        if (!empty($data['images'])) {
            // Get category slug for directory structure
            $stmt = $conn->prepare("SELECT slug FROM categories WHERE id = ?");
            $stmt->execute([$data['category_id']]);
            $category_slug = $stmt->fetch(PDO::FETCH_COLUMN);

            foreach ($data['images'] as $index => $image) {
                $file_info = pathinfo($image['name']);
                $extension = strtolower($file_info['extension']);
                $filename = $slug . '-' . uniqid() . '.' . $extension;
                
                $type_dir = $image['is_primary'] == '1' ? 'primary' : 'hover';
                $target_dir = ROOT_PATH . "/public/assets/images/{$category_slug}/{$type_dir}/";
                
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                $target_path = $target_dir . $filename;
                $source_path = $image['file'];
                
                if (move_uploaded_file($source_path, $target_path)) {
                    $public_url = "/assets/images/{$category_slug}/{$type_dir}/" . $filename;
                    
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
                }
            }
        }

        // Get updated product details
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

        // Log success
        error_log('Product updated successfully: ' . print_r([
            'id' => $product_id,
            'name' => $product['name'],
            'slug' => $product['slug'],
            'sku' => $product['sku']
        ], true));

        // Format response
        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Product "%s" updated successfully with SKU: %s and %d images',
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
                'updated_at' => $product['updated_at']
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        if ($conn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in admin/products/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/products/update.php: ' . $e->getMessage());
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