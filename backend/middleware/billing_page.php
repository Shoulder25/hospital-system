<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

function requireBillingPage(bool $allowReception = false): array
{
    try {
        startSecureSession();
    } catch (Throwable $exception) {
        error_log('Unable to initialize the billing page session: '
            . $exception->getMessage());
        http_response_code(500);
        exit('Unable to start a secure session. Please try again later.');
    }

    $basePath = getProjectBasePath();
    try {
        $database = getDatabaseConnection();
        $user = getAuthenticatedUser($database);
    } catch (Throwable $exception) {
        error_log('Unable to verify billing page access: ' . $exception->getMessage());
        http_response_code(503);
        exit('The billing service is temporarily unavailable.');
    }

    if ($user === null) {
        header('Location: ' . $basePath . '/auth/login.php?message=session-expired');
        exit;
    }

    $allowedRoles = ['cashier', 'system_administrator'];
    if ($allowReception) {
        $allowedRoles[] = 'receptionist';
    }
    if (!in_array($user['role_name'], $allowedRoles, true)) {
        http_response_code(403);
        exit('Billing access is not authorized.');
    }

    return [
        'user' => $user,
        'base_path' => $basePath,
    ];
}
