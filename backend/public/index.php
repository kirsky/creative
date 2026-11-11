<?php

declare(strict_types=1);

// Placeholder front controller; the Slim application replaces it.

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/health') {
    echo '{"status":"ok"}';

    return;
}

http_response_code(404);
echo '{"error":{"code":"not_found"}}';
