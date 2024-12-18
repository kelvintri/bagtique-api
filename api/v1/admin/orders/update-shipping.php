<?php
error_log('=== Admin Update Order Shipping API Request ===');
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
    $admin = AdminMiddleware::authenticate();
    $admin_id = $admin['id'];

    // Get and validate order ID
    $order_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$order_id) {
        throw new Exception('Invalid order ID', 400);
    }

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['courier_name']) || trim($data['courier_name']) === '') {
        throw new Exception('Courier name is required', 400);
    }

    if (!isset($data['tracking_number']) || trim($data['tracking_number']) === '') {
        throw new Exception('Tracking number is required', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    try {
        // Start transaction
        $conn->beginTransaction();

        // Check if order exists and get its current status
        $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found', 404);
        }

        // Check if shipping details already exist
        $stmt = $conn->prepare("SELECT id FROM shipping_details WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $existing_shipping = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_shipping) {
            // Update existing shipping details
            $stmt = $conn->prepare("
                UPDATE shipping_details SET
                    courier_name = :courier_name,
                    service_type = :service_type,
                    tracking_number = :tracking_number,
                    shipping_cost = :shipping_cost,
                    estimated_delivery_date = :estimated_delivery_date,
                    shipped_at = CURRENT_TIMESTAMP,
                    shipped_by = :shipped_by,
                    notes = :notes
                WHERE order_id = :order_id
            ");
        } else {
            // Create new shipping details
            $stmt = $conn->prepare("
                INSERT INTO shipping_details (
                    order_id,
                    courier_name,
                    service_type,
                    tracking_number,
                    shipping_cost,
                    estimated_delivery_date,
                    shipped_at,
                    shipped_by,
                    notes
                ) VALUES (
                    :order_id,
                    :courier_name,
                    :service_type,
                    :tracking_number,
                    :shipping_cost,
                    :estimated_delivery_date,
                    CURRENT_TIMESTAMP,
                    :shipped_by,
                    :notes
                )
            ");
        }

        // Parse estimated delivery date if provided
        $estimated_delivery_date = null;
        if (isset($data['estimated_delivery_date']) && trim($data['estimated_delivery_date']) !== '') {
            $estimated_delivery_date = date('Y-m-d', strtotime($data['estimated_delivery_date']));
        }

        // Execute the query
        $stmt->execute([
            ':order_id' => $order_id,
            ':courier_name' => $data['courier_name'],
            ':service_type' => $data['service_type'] ?? null,
            ':tracking_number' => $data['tracking_number'],
            ':shipping_cost' => $data['shipping_cost'] ?? 0,
            ':estimated_delivery_date' => $estimated_delivery_date,
            ':shipped_by' => $admin_id,
            ':notes' => $data['notes'] ?? null
        ]);

        // Update order status to shipped
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = 'shipped',
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$order_id]);

        // Get updated shipping details
        $stmt = $conn->prepare("
            SELECT sd.*,
                   u.name as shipped_by_name
            FROM shipping_details sd
            LEFT JOIN users u ON sd.shipped_by = u.id
            WHERE sd.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $shipping_details = $stmt->fetch(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Shipping details updated successfully',
            'data' => [
                'order_id' => $order_id,
                'shipping_details' => [
                    'courier_name' => $shipping_details['courier_name'],
                    'service_type' => $shipping_details['service_type'],
                    'tracking_number' => $shipping_details['tracking_number'],
                    'shipping_cost' => (float)$shipping_details['shipping_cost'],
                    'estimated_delivery_date' => $shipping_details['estimated_delivery_date'],
                    'shipped_at' => $shipping_details['shipped_at'],
                    'shipped_by' => $shipping_details['shipped_by_name'],
                    'notes' => $shipping_details['notes']
                ]
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in admin/orders/update-shipping.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/orders/update-shipping.php: ' . $e->getMessage());
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