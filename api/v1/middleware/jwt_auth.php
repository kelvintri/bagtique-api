<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function verifyJWT() {
    $config = require __DIR__ . '/../config/jwt.php';
    
    // Get header
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(['error' => 'No token provided']);
        exit;
    }

    // Extract token
    $auth_header = $headers['Authorization'];
    $token = str_replace('Bearer ', '', $auth_header);

    try {
        // Verify token
        $decoded = JWT::decode($token, new Key($config['secret_key'], $config['algorithm']));
        return $decoded;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
} 