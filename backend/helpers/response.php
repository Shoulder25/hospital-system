<?php

declare(strict_types=1);

function jsonResponse(array $payload, int $statusCode = 200, array $headers = []): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonSuccess(
    string $message,
    array $data = [],
    int $statusCode = 200,
    array $headers = []
): void {
    jsonResponse([
        'success' => true,
        'message' => $message,
        'data' => (object) $data,
    ], $statusCode, $headers);
}

function jsonError(
    string $message,
    array $errors = [],
    int $statusCode = 400,
    array $headers = []
): void {
    jsonResponse([
        'success' => false,
        'message' => $message,
        'errors' => (object) $errors,
    ], $statusCode, $headers);
}
