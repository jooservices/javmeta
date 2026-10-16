<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/healthz') {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    return;
}

if ($path !== '/v1/embeddings' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    return;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$inputs = is_array($payload['input'] ?? null) ? $payload['input'] : [];
echo json_encode([
    'data' => array_map(
        static fn(): array => ['embedding' => array_fill(0, 384, 0.01)],
        $inputs,
    ),
]);
