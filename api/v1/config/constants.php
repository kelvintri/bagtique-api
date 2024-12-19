<?php
// Base path for the project
define('ROOT_PATH', realpath(__DIR__ . '/../../../'));

// Upload paths
define('UPLOAD_PATH', ROOT_PATH . '/public/assets/images');
define('UPLOAD_URL', '/assets/images');

// Image types
define('ALLOWED_IMAGE_TYPES', [
    'image/jpeg',
    'image/png',
    'image/webp'
]);

// Image directories
define('IMAGE_DIRS', [
    'primary' => 'primary',
    'hover' => 'hover'
]); 