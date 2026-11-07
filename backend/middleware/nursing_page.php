<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

function requireNursingPage(): array
{
    try {
        startSecureSession();
    } catch (Throwable $exception) {
        error_log('Unable to initialize the nursing page session: '
            . $exception->getMessage());
        http_response_code(500);
        exit('Unable to start a secure session. Please try again later.');
    }

    $basePath = getProjectBasePath();
    try {
        $database = getDatabaseConnection();
        $user = getAuthenticatedUser($database);
    } catch (Throwable $exception) {
        error_log('Unable to verify nursing page access: ' . $exception->getMessage());
        http_response_code(503);
        exit('The nursing service is temporarily unavailable.');
    }

    if ($user === null) {
        header('Location: ' . $basePath . '/auth/login.php?message=session-expired');
        exit;
    }

    if (!in_array($user['role_name'], ['nurse', 'system_administrator'], true)) {
        http_response_code(403);
        exit('Nurse or system administrator access is required.');
    }

    return [
        'database' => $database,
        'user' => $user,
        'base_path' => $basePath,
    ];
}
