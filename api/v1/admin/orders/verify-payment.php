<?php
error_log('=== Admin Verify Payment API Request ===');
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
    $admin_id = AdminMiddleware::authenticate();

    // Get and validate order ID
    $order_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$order_id) {
        throw new Exception('Invalid order ID', 400);
    }

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    $notes = $data['notes'] ?? null;

    // Database connection
    $db = new APIDatabase();
    $conn = $db->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Get order and payment details
        $stmt = $conn->prepare("
            SELECT o.*, pd.id as payment_id 
            FROM orders o
            LEFT JOIN payment_details pd ON o.id = pd.order_id
            WHERE o.id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found', 404);
        }

        if (!$order['payment_id']) {
            throw new Exception('No payment found for this order', 400);
        }

        if ($order['status'] !== 'payment_uploaded') {
            throw new Exception('Order is not in payment uploaded status', 400);
        }

        // Update payment details
        $stmt = $conn->prepare("
            UPDATE payment_details 
            SET verified_by = :admin_id,
                verified_at = CURRENT_TIMESTAMP
            WHERE order_id = :order_id
        ");
        $stmt->execute([
            ':admin_id' => $admin_id,
            ':order_id' => $order_id
        ]);

        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = 'payment_verified',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :order_id
        ");
        $stmt->execute([':order_id' => $order_id]);

        // Record status change in history
        $stmt = $conn->prepare("
            INSERT INTO order_status_history (
                order_id,
                status,
                notes,
                changed_by
            ) VALUES (
                :order_id,
                'payment_verified',
                :notes,
                :changed_by
            )
        ");
        $stmt->execute([
            ':order_id' => $order_id,
            ':notes' => $notes,
            ':changed_by' => $admin_id
        ]);

        // Get updated payment details
        $stmt = $conn->prepare("
            SELECT 
                pd.*,
                u.full_name as verified_by_name
            FROM payment_details pd
            LEFT JOIN users u ON pd.verified_by = u.id
            WHERE pd.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get status history
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
            'message' => 'Payment verified successfully',
            'data' => [
                'order_id' => $order_id,
                'status' => 'payment_verified',
                'payment' => [
                    'verified_by' => $payment['verified_by_name'],
                    'verified_at' => $payment['verified_at'],
                    'payment_date' => $payment['payment_date'],
                    'payment_amount' => (float)$payment['payment_amount']
                ],
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
    error_log('Database error in admin/orders/verify-payment.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in admin/orders/verify-payment.php: ' . $e->getMessage());
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