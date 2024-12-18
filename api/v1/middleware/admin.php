<?php
class AdminMiddleware {
    public static function authenticate() {
        // Get user ID from auth middleware
        $user_id = AuthMiddleware::authenticate();

        // Database connection
        $db = new APIDatabase();
        $conn = $db->getConnection();
        if (!$conn) {
            throw new Exception('Database connection failed', 500);
        }

        // Check if user has admin role
        $stmt = $conn->prepare("
            SELECT r.name as role_name 
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? AND r.name = 'admin'
        ");
        $stmt->execute([$user_id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            throw new Exception('Unauthorized: Admin access required', 403);
        }

        return $user_id;
    }
} 