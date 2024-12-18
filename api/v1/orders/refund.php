<?php
error_log('=== Request Refund API Request ===');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

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

    // Require authentication
    $user_id = AuthMiddleware::authenticate();

    // Get and validate order ID
    $order_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$order_id) {
        throw new Exception('Invalid order ID', 400);
    }

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['reason']) || trim($data['reason']) === '') {
        throw new Exception('Refund reason is required', 400);
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
        // Get order details and verify ownership
        $stmt = $conn->prepare("
            SELECT o.*, pd.id as payment_id 
            FROM orders o
            LEFT JOIN payment_details pd ON o.id = pd.order_id
            WHERE o.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$order_id, $user_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found', 404);
        }

        // Check if order can be refunded
        $allowed_statuses = ['payment_verified', 'processing', 'shipped', 'delivered'];
        if (!in_array($order['status'], $allowed_statuses)) {
            throw new Exception('Order cannot be refunded in current status', 400);
        }

        if (!$order['payment_id']) {
            throw new Exception('No payment found for this order', 400);
        }

        // Create refund request
        $stmt = $conn->prepare("
            INSERT INTO refund_requests (
                order_id,
                user_id,
                reason,
                status,
                amount
            ) VALUES (?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $order_id,
            $user_id,
            $data['reason'],
            $order['total_amount']
        ]);

        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = 'refund_requested', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$order_id]);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Refund requested successfully',
            'data' => [
                'order_id' => $order_id,
                'status' => 'refund_requested',
                'reason' => $data['reason'],
                'amount' => (float)$order['total_amount']
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in orders/refund.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in orders/refund.php: ' . $e->getMessage());
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