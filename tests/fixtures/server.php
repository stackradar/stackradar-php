<?php

file_put_contents(getenv('STACKRADAR_TEST_CAPTURE'), json_encode([
    'uri' => $_SERVER['REQUEST_URI'],
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'body' => json_decode(file_get_contents('php://input'), true),
]).PHP_EOL, FILE_APPEND | LOCK_EX);

$path = substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 3);
// A protocol transport fixture; server contract validation is exercised separately.
if ($path === '/runtime-reports') {
    $body = json_decode(file_get_contents('php://input'), true);
    $hash = $body['packages']['inventory_hash'] ?? null;
    $known = false;
    foreach (file(getenv('STACKRADAR_TEST_CAPTURE'), FILE_IGNORE_NEW_LINES) as $line) {
        $request = json_decode($line, true);
        if ($request['method'] === 'PUT' && $request['uri'] === '/v1/package-inventories/'.$hash) {
            $known = true;
        }
    }
    if ($hash !== null && ! $known) {
        http_response_code(409);
        echo json_encode(['code' => 'unknown_inventory']);
    } else {
        http_response_code(204);
    }

    return;
}

switch ($path) {
    case '/redirect':
        header('Location: /v1/accepted', true, 302);
        break;
    case '/unknown':
        http_response_code(409);
        echo json_encode(['code' => 'unknown_inventory']);
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
        http_response_code(204);
}
