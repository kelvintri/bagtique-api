<?php
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>API Documentation</title>
    <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.18.3/swagger-ui.css">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.18.3/swagger-ui-bundle.js"></script>
    <script>
        window.onload = function() {
            // Get the base path regardless of how the page is accessed
            const path = window.location.pathname;
            const apiPath = path.includes('/docs') 
                ? path.replace('/docs', '') 
                : path.replace('/documentation.php', '');
                
            const ui = SwaggerUIBundle({
                url: `${apiPath}/openapi.json`,
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIBundle.SwaggerUIStandalonePreset
                ],
            });
        }
    </script>
</body>
</html> 