<?php
error_log('=== Admin Update Category API Request ===');
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
    header('Access-Control-Allow-Methods: PUT');
    header('Access-Control-Allow-Headers: Content-Type');
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

    // Get and validate category ID
    $category_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$category_id) {
        throw new Exception('Invalid category ID', 400);
    }

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['name']) || trim($data['name']) === '') {
        throw new Exception('Category name is required', 400);
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

        // Check if category exists
        $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$category_id]);
        $existing_category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing_category) {
            throw new Exception('Category not found', 404);
        }

        // Check if slug exists (excluding current category)
        $stmt = $conn->prepare("SELECT id FROM categories WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $category_id]);
        if ($stmt->fetch()) {
            $slug .= '-' . time();
        }

        // Update category
        $stmt = $conn->prepare("
            UPDATE categories SET
                name = :name,
                slug = :slug,
                description = :description
            WHERE id = :id
        ");

        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $slug,
            ':description' => $data['description'] ?? null,
            ':id' => $category_id
        ]);

        // Get updated category
        $stmt = $conn->prepare("
            SELECT c.*, 
                   COUNT(DISTINCT p.id) as total_products,
                   COUNT(DISTINCT CASE WHEN p.is_active = 1 AND p.deleted_at IS NULL THEN p.id END) as active_products
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            WHERE c.id = ?
            GROUP BY c.id
        ");
        $stmt->execute([$category_id]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => [
                'id' => $category['id'],
                'name' => $category['name'],
                'slug' => $category['slug'],
                'description' => $category['description'],
                'stats' => [
                    'total_products' => (int)$category['total_products'],
                    'active_products' => (int)$category['active_products']
                ]
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in admin/categories/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/categories/update.php: ' . $e->getMessage());
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