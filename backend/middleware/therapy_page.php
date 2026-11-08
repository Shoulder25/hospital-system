<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

function requireTherapyPage(): array
{
    try {
        startSecureSession();
    } catch (Throwable $exception) {
        error_log('Unable to initialize the therapy page session: '
            . $exception->getMessage());
        http_response_code(500);
        exit('Unable to start a secure session. Please try again later.');
    }

    $basePath = getProjectBasePath();
    try {
        $database = getDatabaseConnection();
        $user = getAuthenticatedUser($database);
    } catch (Throwable $exception) {
        error_log('Unable to verify therapy page access: ' . $exception->getMessage());
        http_response_code(503);
        exit('The therapy service is temporarily unavailable.');
    }

    if ($user === null) {
        header('Location: ' . $basePath . '/auth/login.php?message=session-expired');
        exit;
    }
    redirectIfPasswordChangeRequired($user);
    if (!in_array($user['role_name'], ['therapist', 'system_administrator'], true)) {
        http_response_code(403);
        exit('Therapist access is required.');
    }

    return [
        'user' => $user,
        'base_path' => $basePath,
    ];
}
