<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $type = $_FILES['image']['type'];
    $data = file_get_contents($_FILES['image']['tmp_name']);
    $base64 = 'data:' . $type . ';base64,' . base64_encode($data);
    
    // Create complete product test payload
    $testPayload = [
        "name" => "Test Product",
        "category_id" => 1,
        "brand_id" => 1,
        "description" => "Test description",
        "details" => "Test details",
        "price" => 100,
        "sale_price" => null,
        "stock" => 10,
        "sku" => "TEST-" . time(),
        "condition_status" => "New With Tag",
        "is_active" => true,
        "images" => [
            [
                "data" => $base64,
                "type" => $type,
                "is_primary" => true,
                "sort_order" => 0
            ]
        ]
    ];
    
    // Output formatted for easy copying
    header('Content-Type: text/html');
    echo '<pre style="background: #f5f5f5; padding: 15px; border-radius: 5px;">';
    echo htmlspecialchars(json_encode($testPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo '</pre>';
    
    // Add copy button
    echo '<button onclick="copyToClipboard()">Copy JSON</button>';
    echo '<script>
    function copyToClipboard() {
        const json = ' . json_encode(json_encode($testPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . ';
        navigator.clipboard.writeText(json).then(() => {
            alert("JSON copied to clipboard!");
        });
    }
    </script>';
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Product Test JSON Generator</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        form { margin: 20px 0; }
        button { padding: 10px 20px; cursor: pointer; }
        .note { color: #666; margin-top: 10px; }
    </style>
</head>
<body>
    <h2>Generate Product Test JSON</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="image" accept="image/*" required>
        <button type="submit">Generate JSON</button>
    </form>
    <div class="note">
        The generated JSON will include a test product with your uploaded image in base64 format.
        You can copy and use it directly in your API test endpoint.
    </div>
</body>
</html>