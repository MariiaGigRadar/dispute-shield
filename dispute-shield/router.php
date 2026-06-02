<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Route / to disputes.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/disputes.php';
    exit;
}

// Serve static files if they exist
$file = __DIR__ . $uri;
if (is_file($file)) {
    return false; // let PHP built-in server handle it
}

// Route everything else normally
$script = __DIR__ . $uri;
if (is_file($script)) {
    require $script;
    exit;
}

// Default — disputes.php
require __DIR__ . '/disputes.php';
