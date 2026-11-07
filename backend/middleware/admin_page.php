<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

function requireAdministratorPage(): array
{
    try {
        startSecureSession();
    } catch (Throwable $exception) {
        error_log('Unable to initialize the admin page session: '
            . $exception->getMessage());
        http_response_code(500);
        exit('Unable to start a secure session. Please try again later.');
    }

    $basePath = getProjectBasePath();

    try {
        $database = getDatabaseConnection();
        $user = getAuthenticatedUser($database);
    } catch (Throwable $exception) {
        error_log('Unable to verify admin page access: ' . $exception->getMessage());
        http_response_code(503);
        exit('The administration service is temporarily unavailable.');
    }

    if ($user === null) {
        header('Location: ' . $basePath . '/auth/login.php?message=session-expired');
        exit;
    }

    if ($user['role_name'] !== 'system_administrator') {
        http_response_code(403);
        exit('Administrator access is required.');
    }

    return [
        'database' => $database,
        'user' => $user,
        'base_path' => $basePath,
    ];
}
