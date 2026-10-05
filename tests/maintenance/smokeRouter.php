<?php

// Local-only mock for mailapiSmoke.php; never sends email.
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer smoke-token' ||
    empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
    http_response_code(401);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/success/v1/messages') {
    echo '{"id":"smoke-message"}';
} elseif ($path === '/pending/v1/messages') {
    http_response_code(409);
    // Deliberately omit Retry-After to test the client fallback.
    echo '{"type":"https://mailapi.github.io/problems/idempotency-key-in-progress","title":"In progress"}';
} else {
    http_response_code(500);
    echo '{"title":"Provider failure","detail":"private-provider-detail"}';
}
