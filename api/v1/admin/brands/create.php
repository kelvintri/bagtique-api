<?php
error_log('=== Admin Create Brand API Request ===');
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
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['name']) || trim($data['name']) === '') {
        throw new Exception('Brand name is required', 400);
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
        $stmt = $conn->prepare("SELECT id FROM brands WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            $slug .= '-' . time();
        }

        // Create brand
        $stmt = $conn->prepare("
            INSERT INTO brands (
                name,
                slug,
                description
            ) VALUES (
                :name,
                :slug,
                :description
            )
        ");

        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $slug,
            ':description' => $data['description'] ?? null
        ]);

        $brand_id = $conn->lastInsertId();

        // Get created brand with product counts
        $stmt = $conn->prepare("
            SELECT b.*, 
                   COUNT(DISTINCT p.id) as total_products,
                   COUNT(DISTINCT CASE WHEN p.is_active = 1 AND p.deleted_at IS NULL THEN p.id END) as active_products
            FROM brands b
            LEFT JOIN products p ON b.id = p.brand_id
            WHERE b.id = ?
            GROUP BY b.id
        ");
        $stmt->execute([$brand_id]);
        $brand = $stmt->fetch(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Brand created successfully',
            'data' => [
                'id' => $brand['id'],
                'name' => $brand['name'],
                'slug' => $brand['slug'],
                'description' => $brand['description'],
                'stats' => [
                    'total_products' => (int)$brand['total_products'],
                    'active_products' => (int)$brand['active_products']
                ]
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in admin/brands/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/brands/create.php: ' . $e->getMessage());
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