<?php
error_log('=== Upload Payment API Request ===');
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

    // Validate file upload
    if (!isset($_FILES['payment_proof'])) {
        throw new Exception('Payment proof is required', 400);
    }

    $file = $_FILES['payment_proof'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed', 400);
    }

    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG and PNG are allowed', 400);
    }

    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File too large. Maximum size is 5MB', 400);
    }

    // Load and compress image
    try {
        // Create image from uploaded file
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $image = imagecreatefromjpeg($file['tmp_name']);
                break;
            case 'image/png':
                $image = imagecreatefrompng($file['tmp_name']);
                break;
            default:
                throw new Exception('Unsupported image type', 400);
        }

        if (!$image) {
            throw new Exception('Failed to process image', 500);
        }

        // Get original dimensions
        $orig_width = imagesx($image);
        $orig_height = imagesy($image);

        // Calculate new dimensions (max 1200px width/height)
        $max_dimension = 1200;
        if ($orig_width > $max_dimension || $orig_height > $max_dimension) {
            if ($orig_width > $orig_height) {
                $new_width = $max_dimension;
                $new_height = floor($orig_height * ($max_dimension / $orig_width));
            } else {
                $new_height = $max_dimension;
                $new_width = floor($orig_width * ($max_dimension / $orig_height));
            }
        } else {
            $new_width = $orig_width;
            $new_height = $orig_height;
        }

        // Create new image with new dimensions
        $new_image = imagecreatetruecolor($new_width, $new_height);

        // Handle transparency for PNG
        if ($mime_type === 'image/png') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
        }

        // Resize image
        imagecopyresampled(
            $new_image, $image,
            0, 0, 0, 0,
            $new_width, $new_height,
            $orig_width, $orig_height
        );

        // Generate unique filename
        $extension = ($mime_type === 'image/png') ? 'png' : 'jpg';
        $filename = 'payment_' . $order_id . '_' . time() . '.' . $extension;
        $upload_path = ROOT_PATH . '/public/assets/uploads/payments/';
        $file_url = '/assets/uploads/payments/' . $filename;

        // Create directory if it doesn't exist
        if (!file_exists($upload_path)) {
            mkdir($upload_path, 0777, true);
        }

        // Save compressed image
        if ($mime_type === 'image/png') {
            // PNG: Use maximum compression (9) with no filters
            imagepng($new_image, $upload_path . $filename, 9);
        } else {
            // JPEG: Use 85% quality
            imagejpeg($new_image, $upload_path . $filename, 85);
        }

        // Free up memory
        imagedestroy($image);
        imagedestroy($new_image);

    } catch (Exception $e) {
        error_log('Image processing error: ' . $e->getMessage());
        throw new Exception('Failed to process image', 500);
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
        // Verify order ownership and status
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

        if ($order['payment_id']) {
            throw new Exception('Payment proof already uploaded', 400);
        }

        if ($order['status'] !== 'pending_payment') {
            throw new Exception('Invalid order status for payment upload', 400);
        }

        // Create payment record
        $stmt = $conn->prepare("
            INSERT INTO payment_details (
                order_id,
                payment_method,
                transfer_proof_url,
                payment_amount,
                payment_date
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $order_id,
            $order['payment_method'],
            $file_url,
            $order['total_amount']
        ]);

        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = 'payment_uploaded' 
            WHERE id = ?
        ");
        $stmt->execute([$order_id]);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Payment proof uploaded successfully',
            'data' => [
                'order_id' => $order_id,
                'payment_proof_url' => $file_url,
                'status' => 'payment_uploaded'
            ]
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Database error in upload-payment.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'type' => 'database_error',
            'message' => 'A database error occurred'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error in upload-payment.php: ' . $e->getMessage());
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