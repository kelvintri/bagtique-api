<?php
return [
    'secret_key' => getenv('JWT_SECRET_KEY') ?: 'your-secret-key-here',
    'expiration' => 3600, // 1 hour in seconds
    'algorithm' => 'HS256'
]; 