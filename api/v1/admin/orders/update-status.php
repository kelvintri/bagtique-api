<?php
error_log('=== Admin Update Order Status API Request ===');
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

    // Get and validate order ID
    $order_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$order_id) {
        throw new Exception('Invalid order ID', 400);
    }

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['status']) || trim($data['status']) === '') {
        throw new Exception('Status is required', 400);
    }

    // Validate status value
    $allowed_statuses = [
        'processing',
        'shipped',
        'delivered',
        'cancelled',
        'refunded'
    ];

    if (!in_array($data['status'], $allowed_statuses)) {
        throw new Exception('Invalid status value', 400);
    }

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Get current order status
        $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found', 404);
        }

        // Validate status transition
        $current_status = $order['status'];
        $new_status = $data['status'];

        // Define valid status transitions
        $valid_transitions = [
            'payment_verified' => ['processing', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'cancelled'],
            'delivered' => ['refunded'],
            'cancelled' => [],
            'refunded' => []
        ];

        if (!isset($valid_transitions[$current_status]) || 
            !in_array($new_status, $valid_transitions[$current_status])) {
            throw new Exception("Cannot change status from '$current_status' to '$new_status'", 400);
        }

        // Get admin user ID for history tracking
        $admin_id = AdminMiddleware::authenticate();

        // Get notes from request (optional)
        $notes = $data['notes'] ?? null;

        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = :status, updated_at = CURRENT_TIMESTAMP
            WHERE id = :order_id
        ");
        $stmt->execute([
            ':status' => $new_status,
            ':order_id' => $order_id
        ]);

        // Record status change in history
        $stmt = $conn->prepare("
            INSERT INTO order_status_history (
                order_id,
                status,
                notes,
                changed_by
            ) VALUES (
                :order_id,
                :status,
                :notes,
                :changed_by
            )
        ");
        $stmt->execute([
            ':order_id' => $order_id,
            ':status' => $new_status,
            ':notes' => $notes,
            ':changed_by' => $admin_id
        ]);

        // If status is cancelled, restore product stock
        if ($new_status === 'cancelled') {
            $stmt = $conn->prepare("
                SELECT oi.product_id, oi.quantity
                FROM order_items oi
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $stmt = $conn->prepare("
                    UPDATE products 
                    SET stock = stock + :quantity
                    WHERE id = :product_id
                ");
                $stmt->execute([
                    ':quantity' => $item['quantity'],
                    ':product_id' => $item['product_id']
                ]);
            }
        }

        // Get status history for response
        $stmt = $conn->prepare("
            SELECT 
                osh.*,
                u.full_name as changed_by_name
            FROM order_status_history osh
            JOIN users u ON osh.changed_by = u.id
            WHERE osh.order_id = :order_id
            ORDER BY osh.created_at DESC
        ");
        $stmt->execute([':order_id' => $order_id]);
        $status_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => [
                'order_id' => $order_id,
                'previous_status' => $current_status,
                'new_status' => $new_status,
                'notes' => $notes,
                'status_history' => array_map(function($history) {
                    return [
                        'status' => $history['status'],
                        'notes' => $history['notes'],
                        'changed_by' => $history['changed_by_name'],
                        'changed_at' => $history['created_at']
                    ];
                }, $status_history)
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in admin/orders/update-status.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/orders/update-status.php: ' . $e->getMessage());
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