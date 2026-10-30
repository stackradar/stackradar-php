<?php

file_put_contents(getenv('STACKRADAR_TEST_CAPTURE'), json_encode([
    'uri' => $_SERVER['REQUEST_URI'],
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'body' => json_decode(file_get_contents('php://input'), true),
]).PHP_EOL, FILE_APPEND | LOCK_EX);

switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/redirect':
        header('Location: /accepted', true, 302);
        break;
    case '/rate-limit':
        header('Retry-After: 120');
        http_response_code(429);
        break;
    case '/date-retry':
        header('Retry-After: '.gmdate('D, d M Y H:i:s', time() + 120).' GMT');
        http_response_code(503);
        break;
    case '/slow':
        usleep(300000);
        break;
    case '/error':
        http_response_code(500);
        echo 'sensitive upstream response';
        break;
    default:
        http_response_code(202);
}
