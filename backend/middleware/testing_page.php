<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

function requireTestingPage(string $scope): array
{
    $departments = [
        'testing_desk' => [
            'roles' => ['testing_desk_clerk', 'system_administrator'],
            'label' => 'Testing centre desk',
        ],
        'laboratory' => [
            'roles' => ['laboratory_technician', 'system_administrator'],
            'label' => 'Laboratory',
        ],
        'radiology' => [
            'roles' => ['radiographer', 'system_administrator'],
            'label' => 'Radiology',
        ],
        'cardiac' => [
            'roles' => ['cardiac_technician', 'system_administrator'],
            'label' => 'Cardiac',
        ],
    ];
    if (!isset($departments[$scope])) {
        http_response_code(404);
        exit('Testing module not found.');
    }

    try {
        startSecureSession();
    } catch (Throwable $exception) {
        error_log('Unable to initialize the testing page session: '
            . $exception->getMessage());
        http_response_code(500);
        exit('Unable to start a secure session. Please try again later.');
    }

    $basePath = getProjectBasePath();
    try {
        $database = getDatabaseConnection();
        $user = getAuthenticatedUser($database);
    } catch (Throwable $exception) {
        error_log('Unable to verify testing page access: ' . $exception->getMessage());
        http_response_code(503);
        exit('The testing service is temporarily unavailable.');
    }

    if ($user === null) {
        header('Location: ' . $basePath . '/auth/login.php?message=session-expired');
        exit;
    }
    redirectIfPasswordChangeRequired($user);
    if (!in_array($user['role_name'], $departments[$scope]['roles'], true)) {
        http_response_code(403);
        exit('Access to this testing department is not authorized.');
    }

    return [
        'user' => $user,
        'base_path' => $basePath,
        'scope' => $scope,
        'label' => $departments[$scope]['label'],
    ];
}
